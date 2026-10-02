<?php

use App\Application\Media\IngestMediaUpload;
use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublicCatalog;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Commerce\Inventory\SelectionInventory;
use App\Domain\Media\BoundedMediaProcess;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\VerifiedLicense;
use App\Domain\Rights\VerifyRightsDeclaration;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/** CLI-only disposable fixture orchestration. No application route or default browser wrapper invokes it. */
final class RelatedTrackBrowserFixture
{
    public const PROJECTS = ['chromium-desktop', 'webkit-mobile'];

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private static string $phase = 'invocation';

    /** Fixed diagnostic labels only: exception messages, paths, SQL and tool output never leave the disposable fixture. */
    public static function failureSummary(Throwable $error): string
    {
        $category = match (true) {
            $error instanceof MediaFailure => 'media',
            $error instanceof \Illuminate\Validation\ValidationException => 'validation',
            $error instanceof \Illuminate\Database\QueryException => 'database',
            $error instanceof \Illuminate\Auth\Access\AuthorizationException => 'authorization',
            $error instanceof \Illuminate\Database\Eloquent\ModelNotFoundException => 'missing-record',
            $error instanceof \Symfony\Component\Process\Exception\ExceptionInterface => 'process',
            $error instanceof TypeError => 'type',
            $error instanceof Error => 'php-error',
            $error instanceof RuntimeException => 'runtime',
            default => 'unexpected',
        };
        $code = $error instanceof MediaFailure && in_array($error->failureCode, ['tool_unavailable', 'processor_timeout',
            'processor_failed', 'processor_output_limit', 'scanner_unavailable', 'scanner_signatures_stale', 'scan_not_clean',
            'storage_failed', 'invalid_audio', 'unsupported_wav', 'invalid_wav', 'invalid_tag', 'silent_tag', 'duration_mismatch',
            'invalid_waveform', 'unsupported_source', 'profile_changed', 'source_changed', 'tag_not_configured', 'tag_hash_mismatch',
            'track_published', 'claim_lost', 'processing_failed', 'unsafe_storage', 'unsafe_path', 'missing_source', 'invalid_size',
            'unsupported_artwork', 'invalid_artwork'], true) ? $error->failureCode : 'unclassified';
        $exitStatus = $error instanceof MediaFailure && is_int($error->exitCode) && $error->exitCode >= 0
            && $error->exitCode <= 255 ? $error->exitCode : null;
        $fields = [];
        if ($error instanceof \Illuminate\Validation\ValidationException) {
            $allowed = ['authored_source', 'structured_terms', 'effective_from', 'effective_until', 'license', 'review_hash',
                'title', 'slug', 'artist', 'bpm', 'musical_key', 'genre', 'mood', 'tags', 'description', 'upload', 'role',
                'media', 'offer', 'deliverable_asset_ids', 'currency', 'price_minor', 'rights'];
            foreach (array_keys($error->errors()) as $field) {
                $root = explode('.', $field, 2)[0];
                if (in_array($root, $allowed, true)) {
                    $fields[] = $root;
                }
            }
            $fields = array_values(array_unique($fields));
            sort($fields);
        }

        return json_encode(['phase' => self::$phase, 'category' => $category, 'code' => $code, 'exitStatus' => $exitStatus,
            'fields' => $fields], self::JSON_FLAGS);
    }

    public static function guard(array $env, array $arguments): array
    {
        $directory = $env['VASEY_BROWSER_DIRECTORY'] ?? null;
        $marker = $env['VASEY_BROWSER_RELATED_MARKER'] ?? null;
        $prepare = $arguments === ['prepare'];
        $verify = count($arguments) === 3 && $arguments[0] === 'verify'
            && in_array($arguments[1], self::PROJECTS, true) && in_array($arguments[2], ['published', 'withdrawn'], true);
        self::require(PHP_SAPI === 'cli' && ($prepare || $verify) && is_string($directory) && ! is_link($directory)
            && realpath($directory) === $directory && realpath(dirname($directory)) === realpath(sys_get_temp_dir())
            && preg_match('/\Avasey-browser-[A-Za-z0-9]+\z/D', basename($directory)) === 1
            && (fileperms($directory) & 0777) === 0700 && fileowner($directory) === posix_geteuid()
            && is_string($marker) && preg_match('/\A[a-f0-9]{64}\z/D', $marker) === 1, 'isolated invocation');
        foreach (['VASEY_BROWSER_RELATED_STAGE' => '1', 'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8173',
            'LARAVEL_STORAGE_PATH' => $directory, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite',
            'DB_URL' => '', 'APP_CONFIG_CACHE' => $directory.'/config.php', 'APP_ROUTES_CACHE' => $directory.'/routes.php',
            'APP_EVENTS_CACHE' => $directory.'/events.php', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'FILESYSTEM_DISK' => 'local', 'STRIPE_WEBHOOK_ENABLED' => 'false'] as $key => $expected) {
            self::require(($env[$key] ?? null) === $expected, 'environment identity');
        }
        foreach (['config.php', 'routes.php', 'events.php'] as $cache) {
            self::require(! file_exists($directory.'/'.$cache) && ! is_link($directory.'/'.$cache), 'uncached configuration');
        }
        foreach (['database.sqlite', 'fixtures.json', 'related-track-fixture-marker.json'] as $file) {
            self::privateFile($directory.'/'.$file, $file === 'database.sqlite' ? 33554432 : 65536);
        }
        foreach (['app', 'app/private'] as $child) {
            $path = $directory.'/'.$child;
            self::require(is_dir($path) && ! is_link($path) && realpath($path) === $path, 'private directory');
        }
        $identity = self::json($directory.'/related-track-fixture-marker.json', 65536);
        self::require($identity === ['marker' => $marker, 'database' => $directory.'/database.sqlite',
            'origin' => 'http://127.0.0.1:8173', 'operatorId' => 1], 'marker identity');
        $fixtures = self::json($directory.'/fixtures.json', 65536, false);
        self::require(array_keys($fixtures) === self::PROJECTS, 'bootstrap projects');
        foreach ($fixtures as $fixture) {
            self::require(is_array($fixture) && array_keys($fixture) === ['editable', 'retained'], 'bootstrap fixture shape');
            foreach ($fixture as $track) {
                self::require(is_array($track) && array_keys($track) === ['title', 'slug'] && is_string($track['title'])
                    && is_string($track['slug']) && $track['title'] !== '' && $track['slug'] !== '', 'bootstrap track shape');
            }
        }
        $manifest = $directory.'/related-track-fixtures.json';
        self::require(! is_link($manifest) && ($prepare ? ! file_exists($manifest) : is_file($manifest)), 'exclusive fixture manifest');

        return ['directory' => $directory, 'marker' => $marker, 'prepare' => $prepare, 'fixtures' => $fixtures];
    }

    public static function execute(array $arguments, array $env): array
    {
        self::$phase = 'invocation';
        $guard = self::guard($env, $arguments);
        self::$phase = 'application-bootstrap';
        require_once __DIR__.'/../../vendor/autoload.php';
        $app = require __DIR__.'/../../bootstrap/app.php';
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
        self::$phase = 'effective-configuration';
        $directory = $guard['directory'];
        self::require($app->environment('local') && config('app.debug') === false && config('app.url') === 'http://127.0.0.1:8173'
            && config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === $directory.'/database.sqlite'
            && storage_path() === $directory && config('filesystems.default') === 'local'
            && config('filesystems.disks.local.root') === $directory.'/app/private'
            && app(PrivateMediaFiles::class)->root() === $directory.'/app/private'
            && config('mail.default') === 'array' && get_class(app('queue')->connection()) === SyncQueue::class, 'effective configuration');
        foreach (['webhook_enabled', 'checkout_enabled', 'processing_enabled', 'finalization_enabled'] as $flag) {
            self::require(config('payments.stripe.'.$flag) === false, 'disabled payment providers');
        }
        $operator = User::findOrFail(1);
        self::require($operator->email === 'browser-operator@example.test' && Gate::forUser($operator)->allows('administer-catalog')
            && AdminMultiFactor::satisfiedBy($operator), 'operator authority');
        self::require(User::where('email', 'browser-customer@example.test')->count() === 1, 'bootstrap customer');

        if (! $guard['prepare']) {
            self::$phase = 'retained-evidence-verification';
            self::require(config('media.clamscan') === $directory.'/no-clamscan', 'unchanged HTTP scanner configuration');
            $manifest = self::json($directory.'/related-track-fixtures.json', 262144);
            self::verify($manifest, $guard, $operator, $arguments[1], $arguments[2]);

            return ['verified' => true, 'project' => $arguments[1], 'state' => $arguments[2], 'evidenceHash' => $manifest['evidenceHash'],
                'retainedEvidence' => true, 'currentEligibility' => true];
        }

        self::$phase = 'source-and-bootstrap-census';
        $sourceIdentity = self::source(); // Fail on an untracked or dirty helper before scans, scratch writes or domain commands.
        self::require(get_class(app(MalwareScanner::class)) === MalwareScanner::class
            && get_class(app(BoundedMediaProcess::class)) === BoundedMediaProcess::class
            && config('media.clamscan') === '/usr/bin/clamscan', 'genuine scanner binding');
        self::require(Track::count() === 4 && User::count() === 2 && DB::table('media_assets')->count() === 0
            && DB::table('license_versions')->count() === 0 && DB::table('rights_declarations')->count() === 0, 'fresh bootstrap census');
        foreach ($guard['fixtures'] as $fixture) {
            foreach (['editable', 'retained'] as $kind) {
                $track = Track::where('slug', $fixture[$kind]['slug'] ?? null)->sole();
                self::require($track->title === ($fixture[$kind]['title'] ?? null) && $track->status === 'draft', 'bootstrap track identity');
            }
        }
        self::$phase = 'scanner-and-tool-integrity';
        $scanner = self::scannerIdentity();
        $tools = [];
        foreach (['ffmpeg', 'ffprobe', 'prlimit'] as $tool) {
            $tools[$tool] = self::elf(config('media.'.$tool));
        }
        $input = $directory.'/related-track-inputs';
        self::require(! file_exists($input) && ! is_link($input) && mkdir($input, 0700), 'exclusive input directory');
        self::$phase = 'genuine-detection-canary';
        $eicar = $input.'/detection-canary.txt';
        // Assemble the public antivirus canary only inside this disposable private directory, never as a committed test file.
        self::writeExclusive($eicar, 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$'.'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
        try {
            app(MalwareScanner::class)->scan($eicar);
            throw new RuntimeException('Genuine detection canary was accepted.');
        } catch (MediaFailure $failure) {
            self::require($failure->failureCode === 'scan_not_clean', 'genuine detection and signature freshness');
        } finally {
            unlink($eicar);
        }
        self::$phase = 'scanner-version';
        $runner = app(BoundedMediaProcess::class);
        $scanner['version'] = trim($runner->run(['/usr/bin/clamscan', '--version'], $input, 15));
        self::require(preg_match('~\AClamAV [^/\r\n]+/[0-9]+/[^\r\n]+\z~D', $scanner['version']) === 1, 'genuine scanner version');
        self::$phase = 'synthetic-tag-generation';
        $tagDirectory = $directory.'/app/private/approved-tags';
        self::require(! file_exists($tagDirectory) && ! is_link($tagDirectory) && mkdir($tagDirectory, 0700), 'exclusive synthetic tag');
        $tag = $tagDirectory.'/related-native.wav';
        self::tone($tag, 0.2, 1800, $input);
        config(['media.tag_path' => 'approved-tags/related-native.wav', 'media.tag_sha256' => hash_file('sha256', $tag)]);
        self::$phase = 'independent-console-reviewer';
        $counts = self::counts();
        self::require($counts === ['orders' => 0, 'license_grants' => 0, 'stripe_webhook_receipts' => 0], 'empty commerce');
        $auditStart = (int) (AuditEvent::max('id') ?? 0);
        $password = $env['VASEY_BROWSER_PASSWORD'] ?? null;
        self::require(is_string($password) && strlen($password) >= 40, 'console fixture password');
        $tester = new CommandTester($kernel->all()['vasey:create-admin']);
        $tester->setInputs(['Synthetic Related Track Reviewer', 'browser-related-reviewer@example.test', $password]);
        self::require($tester->execute([], ['interactive' => true]) === 0, 'independent console reviewer');
        $reviewer = User::where('email', 'browser-related-reviewer@example.test')->sole();
        self::require($reviewer->id !== $operator->id && Gate::forUser($reviewer)->allows('administer-catalog')
            && AdminMultiFactor::satisfiedBy($reviewer), 'independent reviewer authority');
        self::$phase = 'license-draft';
        $template = LicenseTemplate::create(['name' => 'NONBINDING RELATED NATIVE FIXTURE', 'slug' => 'synthetic-related-native', 'type' => 'non-exclusive']);
        $license = app(CreateLicenseDraft::class)->handle($template, [
            'authored_source' => 'NONBINDING SYNTHETIC NATIVE FIXTURE. No real rights, price or payment obligation.',
            'structured_terms' => ['schema_version' => 1, 'features' => ['NONBINDING synthetic WAV fixture only'], 'required_asset_roles' => ['master_wav']],
        ], $operator);
        self::$phase = 'license-review-submission';
        $submitted = app(ReviewLicense::class)->submit($license, $operator);
        self::$phase = 'independent-license-approval';
        $approved = app(ReviewLicense::class)->approve($submitted, $reviewer, ['approval_reference' => 'SYNTHETIC-RELATED-NATIVE-ONLY',
            'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true]);
        self::$phase = 'license-publication';
        $license = app(PublishLicense::class)->handle($approved, $operator);
        self::require(app(VerifiedLicense::class)->available($license), 'published reviewed fixture license');
        $path = $directory.'/related-track-fixtures.json';
        $stream = fopen($path, 'x');
        self::require($stream !== false && chmod($path, 0600), 'exclusive private manifest');
        try {
            $projects = [];
            $records = [];
            foreach (self::PROJECTS as $project) {
                $projects[$project] = ['tracks' => []];
                foreach ([0, 1] as $position) {
                    self::$phase = 'track-metadata';
                    $title = $position === 0 ? str_pad('SyntheticRelated'.str_replace('-', '', $project), 255, 'W')
                        : 'Synthetic related second track '.$project;
                    $track = app(SaveTrackMetadata::class)->handle(null, ['title' => $title, 'slug' => 'related-'.$project.'-'.($position + 1),
                        'artist' => 'Synthetic native fixture', 'genre' => 'Synthetic fixture', 'bpm' => 90, 'musical_key' => 'C minor'], $operator);
                    self::$phase = 'rights-declaration';
                    $rights = RightsDeclaration::create(['track_id' => $track->id, 'status' => 'pending',
                        'provenance_reference' => 'SYNTHETIC-NATIVE-GENERATED-'.strtoupper($project).'-'.$position,
                        'sample_disclosure' => 'NONBINDING generated tone and generated pixels; no imported sample or production clearance.']);
                    app(VerifyRightsDeclaration::class)->handle($rights, $operator);
                    self::$phase = 'synthetic-track-media-generation';
                    $wav = $input.'/'.$project.'-'.$position.'.wav';
                    self::tone($wav, 1.2, 440 + count($records) * 80, $input);
                    $png = $input.'/'.$project.'-'.$position.'.png';
                    $image = imagecreatetruecolor(32, 32);
                    self::require($image !== false, 'synthetic artwork allocation');
                    imagefill($image, 0, 0, imagecolorallocate($image, 40 + count($records) * 20, 80, 160));
                    self::require(imagepng($image, $png) && chmod($png, 0600), 'synthetic artwork');
                    imagedestroy($image);
                    foreach (['master_wav' => $wav, 'artwork' => $png] as $role => $file) {
                        self::$phase = $role === 'master_wav' ? 'master-intake' : 'artwork-intake';
                        // PHP's upload-origin flag is a CLI adapter for these exclusively generated temporary inputs, not a scanner double.
                        $source = app(IngestMediaUpload::class)->handle($track, new UploadedFile($file, basename($file), null, UPLOAD_ERR_OK, true), $role, $operator);
                        self::$phase = $role === 'master_wav' ? 'master-processing' : 'artwork-processing';
                        $run = app(QueueMediaProcessing::class)->handle($source, $operator)->fresh();
                        self::require($run->status === 'completed', 'ordinary synchronous processing');
                        foreach ([$run->evidence['source_scan'] ?? null, ...($role === 'master_wav' ? [$run->evidence['tag_scan'] ?? null] : [])] as $scan) {
                            self::require(is_array($scan) && ($scan['engine'] ?? null) === 'clamav' && ($scan['status'] ?? null) === 'clean'
                                && ($scan['version'] ?? null) === $scanner['version'], 'genuine persisted scan');
                        }
                    }
                    self::$phase = 'offer-draft';
                    $master = $track->assets()->where('role', 'master_wav')->where('status', 'ready')->sole();
                    $offer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id, 'license_version_id' => $license->id,
                        'price_minor' => 1, 'currency' => 'USD', 'deliverable_asset_ids' => [$master->id]], $operator);
                    self::$phase = 'offer-publication';
                    $revision = app(PublishOffer::class)->handle($offer, $operator);
                    self::$phase = 'track-publication';
                    $track = app(PublishTrack::class)->handle($track, $operator)->fresh();
                    self::require(app(PublicationReadiness::class)->blockers($track) === [] && app(SelectionInventory::class)->available($revision->id), 'current public readiness');
                    $summary = ['id' => $track->id, 'title' => $track->title, 'artist' => $track->artist, 'slug' => $track->slug,
                        'href' => route('tracks.show', $track->slug, false)];
                    self::require($track->published_slug === $track->slug && app(PublicCatalog::class)->relatedLinks([$track->id])
                        === [array_intersect_key($summary, array_flip(['title', 'artist', 'href']))], 'current eligible projection');
                    $projects[$project]['tracks'][] = $summary;
                    self::$phase = 'retained-track-graph';
                    $records[] = self::trackEvidence($track);
                }
            }
            self::$phase = 'final-evidence-verification';
            self::require(self::scannerIdentity() === array_diff_key($scanner, ['version' => true]), 'unchanged genuine signatures');
            self::require(self::source() === $sourceIdentity, 'unchanged preparation source');
            $evidence = ['source' => $sourceIdentity, 'initialCounts' => $counts, 'operatorId' => $operator->id, 'reviewerId' => $reviewer->id,
                'license' => self::licenseEvidence($license), 'scanner' => $scanner, 'tools' => $tools,
                'detectionCanary' => 'scan_not_clean', 'tracks' => $records, 'audits' => self::audits(AuditEvent::where('id', '>', $auditStart)->orderBy('id')->get())];
            $manifest = ['schemaVersion' => 1, 'marker' => $guard['marker'], 'origin' => 'http://127.0.0.1:8173',
                'database' => $directory.'/database.sqlite', 'projects' => $projects, 'evidence' => $evidence, 'evidenceHash' => CanonicalJson::hash($evidence)];
            foreach (self::PROJECTS as $project) {
                self::verify($manifest, $guard, $operator, $project, 'published');
            }
            $json = json_encode($manifest, self::JSON_FLAGS);
            self::require(strlen($json) <= 262144 && fwrite($stream, $json) === strlen($json) && fflush($stream), 'complete retained manifest');
        } finally {
            fclose($stream); // Failed preparation retains only its own incomplete marker; it never resets evidence or retries.
        }

        return ['state' => 'prepared', 'projects' => 2, 'tracks' => 4, 'evidenceHash' => $manifest['evidenceHash']];
    }

    private static function verify(array $manifest, array $guard, User $operator, string $project, string $state): void
    {
        self::require(array_keys($manifest) === ['schemaVersion', 'marker', 'origin', 'database', 'projects', 'evidence', 'evidenceHash']
            && $manifest['schemaVersion'] === 1 && $manifest['marker'] === $guard['marker'] && $manifest['origin'] === 'http://127.0.0.1:8173'
            && $manifest['database'] === $guard['directory'].'/database.sqlite' && array_keys($manifest['projects']) === self::PROJECTS
            && CanonicalJson::hash($manifest['evidence']) === $manifest['evidenceHash'], 'retained manifest identity');
        $evidence = $manifest['evidence'];
        self::require($evidence['operatorId'] === $operator->id && self::counts() === $evidence['initialCounts']
            && self::source() === $evidence['source'], 'unchanged source and commerce');
        $license = LicenseVersion::findOrFail($evidence['license']['id']);
        $reviewer = User::findOrFail($evidence['reviewerId']);
        self::require(app(VerifiedLicense::class)->available($license) && self::licenseEvidence($license) === $evidence['license']
            && $license->author_id === $operator->id && $license->approved_by === $evidence['reviewerId']
            && $license->approved_by !== $operator->id && $reviewer->email === 'browser-related-reviewer@example.test'
            && Gate::forUser($reviewer)->allows('administer-catalog') && AdminMultiFactor::satisfiedBy($reviewer), 'retained independent review');
        $auditIds = array_column($evidence['audits'], 'id');
        self::require(self::audits(AuditEvent::whereIn('id', $auditIds)->orderBy('id')->get()) === $evidence['audits'], 'retained exact audits');
        self::auditCensus($evidence);
        self::require(count($evidence['tracks']) === 4, 'four distinct track graphs');
        $projectIds = [];
        foreach ($manifest['projects'] as $fixture) {
            self::require(is_array($fixture) && array_keys($fixture) === ['tracks'] && is_array($fixture['tracks'])
                && array_is_list($fixture['tracks']) && count($fixture['tracks']) === 2, 'exact project graph shape');
            array_push($projectIds, ...array_column($fixture['tracks'], 'id'));
        }
        self::require(count(array_unique($projectIds, SORT_REGULAR)) === 4
            && $projectIds === array_column($evidence['tracks'], 'trackId'), 'distinct ordered project graph binding');
        foreach ($evidence['tracks'] as $record) {
            self::require(self::trackEvidence(Track::findOrFail($record['trackId'])) === $record, 'retained media rights and offer graph');
        }
        $tracks = $manifest['projects'][$project]['tracks'];
        self::require(is_array($tracks) && array_is_list($tracks) && count($tracks) === 2 && $tracks[0]['id'] !== $tracks[1]['id'], 'ordered project track identities');
        $visible = [];
        foreach ($tracks as $position => $summary) {
            $track = Track::findOrFail($summary['id']);
            self::require(array_keys($summary) === ['id', 'title', 'artist', 'slug', 'href'] && is_int($summary['id'])
                && $track->only(['id', 'title', 'artist', 'slug']) === array_diff_key($summary, ['href' => true])
                && $track->published_slug === $summary['slug'] && route('tracks.show', $track->slug, false) === $summary['href'], 'exact reserved track summary');
            $withdrawn = $state === 'withdrawn' && $position === 0;
            self::require($track->status === ($withdrawn ? 'draft' : 'published'), 'ordinary current track state');
            $withdrawals = AuditEvent::where('action', 'catalog.track.unpublished')->where('subject_type', Track::class)->where('subject_id', $track->id)->get();
            self::require($withdrawals->count() === ($withdrawn ? 1 : 0)
                && $withdrawals->every(fn (AuditEvent $audit): bool => $audit->actor_id === $operator->id && $audit->context === [
                    'schema_version' => 1, 'metadata_version' => 1, 'previous_publication_version' => 1, 'publication_version' => 2,
                ]), 'exact ordinary withdrawal audit');
            if (! $withdrawn) {
                self::require(app(PublicationReadiness::class)->blockers($track) === [], 'current track readiness');
                $visible[] = array_intersect_key($summary, array_flip(['title', 'artist', 'href']));
            }
        }
        self::require(app(PublicCatalog::class)->relatedLinks(array_column($tracks, 'id')) === $visible, 'fresh current public projection');
        self::require(app(PublicCatalog::class)->relatedLinks(array_column($tracks, 'id'), false)
            === array_map(fn (array $entry): array => array_diff_key($entry, ['href' => true]), $visible), 'private nonactionable projection');
    }

    private static function trackEvidence(Track $track): array
    {
        $rights = $track->rightsDeclarations()->latest('id')->firstOrFail();
        $offer = $track->offers()->sole();
        $revision = $offer->currentRevision()->firstOrFail();
        $sources = [];
        foreach ($track->assets()->whereNull('parent_asset_id')->orderBy('id')->get() as $source) {
            $run = $source->runs()->sole();
            self::require($source->status === 'processed' && $run->status === 'completed', 'complete ordinary source run');
            $outputs = [];
            foreach ($run->outputs()->orderBy('id')->get() as $asset) {
                $proof = app(VerifiedMedia::class)->evidence($asset);
                self::require($proof !== null && app(VerifiedMedia::class)->available($asset)
                    && ($run->evidence['source_scan']['engine'] ?? null) === 'clamav', 'intact genuinely verified output');
                $outputs[] = ['id' => $asset->id, 'role' => $asset->role, 'sha256' => $asset->sha256,
                    'sizeBytes' => (int) $asset->size_bytes, 'verifiedEvidenceHash' => self::mediaEvidenceHash($proof)];
            }
            $sources[] = ['id' => $source->id, 'role' => $source->role, 'sha256' => $source->sha256, 'sizeBytes' => (int) $source->size_bytes,
                'runId' => $run->id, 'profileFingerprint' => $run->profile_fingerprint, 'evidenceHash' => CanonicalJson::hash($run->evidence), 'outputs' => $outputs];
        }
        self::require(count($sources) === 2 && $rights->status === 'verified' && $offer->is_active
            && hash_equals($revision->snapshot_hash, CanonicalJson::hash($revision->snapshot)), 'exact ordinary graph');

        return ['trackId' => $track->id, 'rightsId' => $rights->id, 'rightsHash' => CanonicalJson::hash($rights->toArray()),
            'offerId' => $offer->id, 'revisionId' => $revision->id, 'revisionHash' => $revision->snapshot_hash, 'sources' => $sources];
    }

    private static function licenseEvidence(LicenseVersion $license): array
    {
        $review = $license->reviewEvidence()->sole();

        return ['id' => $license->id, 'templateId' => $license->license_template_id, 'submissionHash' => $license->submission_hash,
            'sourceHash' => $license->source_hash, 'modelHash' => $license->model_hash, 'reviewId' => $review->id, 'evidenceHash' => $review->evidence_hash];
    }

    /** Fixture-only digest of the complete media proof, including measured fractional durations and waveform peaks. */
    private static function mediaEvidenceHash(array $proof): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    return array_map($normalize, $value);
                }
                ksort($value, SORT_STRING);
                $object = new stdClass;
                foreach ($value as $key => $item) {
                    $object->{(string) $key} = $normalize($item);
                }

                return $object;
            }
            if (is_null($value) || is_bool($value) || is_int($value) || is_string($value)
                || (is_float($value) && is_finite($value))) {
                return $value;
            }
            throw new InvalidArgumentException('Media fixture proof must contain only finite JSON values.');
        };

        return hash('sha256', json_encode($normalize($proof), self::JSON_FLAGS | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function audits(iterable $events): array
    {
        $result = [];
        foreach ($events as $event) {
            $result[] = ['id' => $event->id, 'action' => $event->action, 'subjectType' => $event->subject_type,
                'subjectId' => $event->subject_id, 'actorId' => $event->actor_id, 'contextHash' => CanonicalJson::hash($event->context)];
        }

        return $result;
    }

    /** Require every expected ordinary command's subject and actor, rather than merely recording whatever appeared. */
    private static function auditCensus(array $evidence): void
    {
        $expected = [['access.operator.created', User::class, $evidence['reviewerId'], null]];
        foreach (['draft_created', 'review_requested', 'approved', 'published'] as $operation) {
            $expected[] = ['rights.license.'.$operation, LicenseVersion::class, $evidence['license']['id'],
                $operation === 'approved' ? $evidence['reviewerId'] : $evidence['operatorId']];
        }
        foreach ($evidence['tracks'] as $record) {
            foreach (['created', 'published'] as $operation) {
                $expected[] = ['catalog.track.'.$operation, Track::class, $record['trackId'], $evidence['operatorId']];
            }
            $expected[] = ['rights.declaration.verified', RightsDeclaration::class, $record['rightsId'], $evidence['operatorId']];
            foreach (['draft_saved', 'revision_published'] as $operation) {
                $expected[] = ['catalog.offer.'.$operation, Offer::class, $record['offerId'], $evidence['operatorId']];
            }
            foreach ($record['sources'] as $source) {
                $expected[] = ['media.upload.quarantined', MediaAsset::class, $source['id'], $evidence['operatorId']];
                foreach (['queued', 'completed'] as $operation) {
                    $expected[] = ['media.processing.'.$operation, MediaProcessingRun::class, $source['runId'], $evidence['operatorId']];
                }
            }
        }
        $actual = array_map(fn (array $audit): array => [$audit['action'], $audit['subjectType'], $audit['subjectId'], $audit['actorId']], $evidence['audits']);
        sort($expected);
        sort($actual);
        self::require($actual === $expected && count($actual) === 49, 'complete command audit census');
    }

    private static function counts(): array
    {
        return array_combine(['orders', 'license_grants', 'stripe_webhook_receipts'], array_map(fn (string $table): int => DB::table($table)->count(), ['orders', 'license_grants', 'stripe_webhook_receipts']));
    }

    private static function source(): array
    {
        $root = dirname(__DIR__, 2);
        $values = [];
        foreach (['commit' => 'HEAD', 'tree' => 'HEAD^{tree}'] as $key => $ref) {
            $process = new Process(['git', 'rev-parse', $ref], $root);
            $process->setTimeout(10)->mustRun();
            $values[$key] = trim($process->getOutput());
            self::require(preg_match('/\A[a-f0-9]{40}\z/D', $values[$key]) === 1, 'source identity');
        }
        $clean = new Process(['git', 'diff', '--quiet', 'HEAD', '--'], $root);
        $clean->setTimeout(10)->mustRun();
        $tracked = new Process(['git', 'ls-files', '--error-unmatch', 'tests/browser/prepare-related-tracks.php'], $root);
        $tracked->setTimeout(10)->mustRun();

        return $values;
    }

    private static function scannerIdentity(): array
    {
        $result = [];
        foreach (['scanner' => 'clamav', 'updater' => 'clamav-freshclam'] as $name => $package) {
            $path = $name === 'scanner' ? '/usr/bin/clamscan' : '/usr/bin/freshclam';
            $binary = self::elf($path);
            $checksums = file_get_contents('/var/lib/dpkg/info/'.$package.'.md5sums', false, null, 0, 1048576);
            self::require(is_string($checksums) && preg_match('~^([a-f0-9]{32})  '.preg_quote(ltrim($path, '/'), '~').'$~m', $checksums, $match) === 1
                && hash_equals($match[1], hash_file('md5', $path)), 'packaged genuine scanner integrity');
            $process = new Process(['/usr/bin/dpkg-query', '-W', '-f=${Status}\t${Version}', $package]);
            $process->setTimeout(10)->mustRun();
            $version = $process->getOutput();
            self::require(str_starts_with($version, "install ok installed\t") && strlen($version) < 256, 'installed scanner package');
            $result[$name] = $binary + ['package' => $package, 'packageVersion' => substr($version, 21)];
        }
        self::require(realpath('/var/lib/clamav') === '/var/lib/clamav' && ! is_link('/var/lib/clamav'), 'official signature directory');
        $result['signatures'] = [];
        foreach (['main', 'daily', 'bytecode'] as $database) {
            $files = array_values(array_filter(['/var/lib/clamav/'.$database.'.cvd', '/var/lib/clamav/'.$database.'.cld'], file_exists(...)));
            self::require(count($files) === 1, 'official signature set');
            $path = $files[0];
            self::require(is_file($path) && ! is_link($path) && realpath($path) === $path && is_readable($path)
                && filesize($path) >= 512 && filesize($path) <= 536870912 && str_starts_with((string) file_get_contents($path, false, null, 0, 512), 'ClamAV-VDB:'), 'official signature file');
            $result['signatures'][] = ['name' => basename($path), 'sizeBytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
        }

        return $result;
    }

    private static function elf(mixed $path): array
    {
        self::require(is_string($path) && str_starts_with($path, '/') && realpath($path) === $path && is_file($path)
            && ! is_link($path) && is_executable($path) && filesize($path) > 4 && filesize($path) <= 33554432
            && file_get_contents($path, false, null, 0, 4) === "\x7fELF", 'native executable');

        return ['path' => $path, 'sha256' => hash_file('sha256', $path)];
    }

    private static function tone(string $path, float $duration, int $frequency, string $cwd): void
    {
        self::require(! file_exists($path) && ! is_link($path), 'new synthetic WAV');
        app(BoundedMediaProcess::class)->run([config('media.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-n', '-f', 'lavfi',
            '-i', 'sine=frequency='.$frequency.':sample_rate=44100:duration='.$duration, '-threads', '1', '-c:a', 'pcm_s16le', '-ac', '2', $path], $cwd, 15);
        self::require(chmod($path, 0600), 'private synthetic WAV');
    }

    private static function privateFile(string $path, int $maximum): void
    {
        self::require(is_file($path) && ! is_link($path) && realpath($path) === $path && is_readable($path)
            && filesize($path) > 0 && filesize($path) <= $maximum && (fileperms($path) & 0777) === 0600
            && function_exists('posix_geteuid') && fileowner($path) === posix_geteuid(), 'private bounded fixture file');
    }

    private static function json(string $path, int $maximum, bool $canonical = true): array
    {
        self::privateFile($path, $maximum);
        $bytes = file_get_contents($path);
        $value = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        self::require(is_array($value) && (! $canonical || json_encode($value, self::JSON_FLAGS) === $bytes), 'strict fixture JSON');

        return $value;
    }

    private static function writeExclusive(string $path, string $bytes): void
    {
        $file = fopen($path, 'x');
        self::require($file !== false, 'exclusive scratch file');
        try {
            self::require(chmod($path, 0600) && fwrite($file, $bytes) === strlen($bytes) && fflush($file), 'private scratch bytes');
        } finally {
            fclose($file);
        }
    }

    private static function require(bool $condition, string $check): void
    {
        if (! $condition) {
            throw new RuntimeException('Related-track fixture refused: '.$check.'.');
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        umask(0077);
        $result = RelatedTrackBrowserFixture::execute(array_slice($argv, 1), getenv());
        echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'Related-track fixture diagnostic: '.RelatedTrackBrowserFixture::failureSummary($error)."\n");
        if ($error instanceof RuntimeException && str_starts_with($error->getMessage(), 'Related-track fixture refused: ')) {
            fwrite(STDERR, $error->getMessage()."\n"); // Only fixed check labels, never arbitrary domain/tool output.
        }
        fwrite(STDERR, "Genuine isolated related-track fixture failed; no native proof is established.\n");
        exit(1);
    }
}
