<?php

namespace Tests\Feature;

use App\Console\Commands\StagingBeatStarsImport;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalMedia;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\StemsRecording;
use App\Domain\Media\RecordingAssociation;
use App\Domain\Migration\BeatStars\BeatStarsExportNormalizer;
use App\Domain\Migration\CatalogOnboarding\NormalizedSourceSnapshot;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Closure;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

/**
 * The staging BeatStars import: admission refusals before any write, and a real run over synthetic files through the draft
 * importer and the ordinary media services. Media uses real ffmpeg derivatives and the real pipeline; the malware verdict
 * comes from the test-only scanner and the seller tag is the test-only fixture tag. Nothing here reads a real export, path or file.
 */
class StagingBeatStarsImportTest extends TestCase
{
    // The draft importer and the media services own standalone transactions, so no test transaction may wrap them.
    use FinalizationDatabaseMigrations;

    private const OPERATOR = 'beatstars-import-operator@example.test';

    private const REVIEWER = 'beatstars-import-reviewer@example.test';

    private const FIXTURES = __DIR__.'/../Fixtures/migration/beatstars-synthetic/';

    private const CENSUS = ['users', 'audit_events', 'tracks', 'rights_declarations', 'media_assets', 'media_processing_runs', 'license_templates',
        'license_versions', 'offers', 'offer_revisions', 'catalog_import_batches', 'catalog_import_mappings', 'quotes', 'orders', 'license_grants',
        'verified_payments', 'stripe_webhook_receipts'];

    private array $passwords = [];

    /** @var array{source: string, files: string, report: string, base: string} */
    private array $staged;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        config(['staging-beatstars-import.enabled' => true, 'payments.stripe.mode' => 'test', 'payments.stripe.secret_key' => null,
            'production_checkout.funds_mode' => null, 'production_checkout.secret_key' => null]);
    }

    private function operators(string $operatorName = 'Import Operator', string $reviewerName = 'Import Reviewer'): array
    {
        foreach ([[$operatorName, self::OPERATOR], [$reviewerName, self::REVIEWER]] as $index => [$name, $email]) {
            $this->passwords[$index] = 'BeatStarsImport'.random_int(100000, 999999).'Pass';
            $this->artisan('vasey:create-admin')
                ->expectsQuestion('Operator name', $name)
                ->expectsQuestion('Email', $email)
                ->expectsQuestion('Password (at least 16 characters)', $this->passwords[$index])
                ->assertSuccessful();
        }

        return [User::where('email', self::OPERATOR)->sole(), User::where('email', self::REVIEWER)->sole()];
    }

    /**
     * A private import workspace: the staged snapshot (catalog.json plus the hashed raw export), the media root and a private report
     * directory, laid out as the operator guide stages them. `$files` maps a listed path to the bytes written under the root.
     */
    private function stage(array $files = [], bool $media = true): array
    {
        $base = sys_get_temp_dir().'/vasey-bs-import-'.bin2hex(random_bytes(10));
        mkdir($base, 0700);
        foreach (['source', 'source/raw', 'files', 'reports'] as $directory) {
            mkdir($base.'/'.$directory, 0700);
        }
        $this->beforeApplicationDestroyed(static fn () => (new Filesystem)->deleteDirectory($base));
        $csv = file_get_contents(self::FIXTURES.'import-export.csv');
        $normalized = (new BeatStarsExportNormalizer)->normalize($csv, 'import-export.csv', file_get_contents(self::FIXTURES.'import-mapping.json'));
        $this->assertSame(3, $normalized['counts']['normalized']);
        file_put_contents($base.'/source/raw/import-export.csv', $csv);
        file_put_contents($base.'/source/catalog.json', CanonicalJson::encode($normalized['snapshot'])."\n");
        chmod($base.'/source/raw/import-export.csv', 0600);
        chmod($base.'/source/catalog.json', 0600);
        if ($media) {
            $workspace = $base.'/work';
            mkdir($workspace, 0700);
            $definition = ['slug' => 'bs-import', 'duration' => 3, 'sources' => ['sine=frequency=220:sample_rate=44100:duration=3', 'sine=frequency=330:sample_rate=44100:duration=3']];
            $generator = app(SyntheticRehearsalMedia::class);
            $files += [
                '/SyntheticStudio/Masters/one.wav' => file_get_contents($generator->master($definition, $workspace)),
                '/SyntheticStudio/Stems/one.zip' => file_get_contents($generator->stems($definition, $workspace)),
                '/SyntheticStudio/Previews/one.mp3' => 'not used',
                '/SyntheticStudio/Art/one.png' => MediaFixtures::png(),
                '/SyntheticStudio/Art/one-hires.png' => MediaFixtures::png(),
                '/SyntheticStudio/Masters/two.wav' => file_get_contents($generator->master($definition, $workspace)),
                '/SyntheticStudio/Previews/two.mp3' => 'not used',
                '/SyntheticStudio/Stems/three.txt' => 'not a zip',
            ];
        }
        foreach ($files as $listed => $bytes) {
            @mkdir(dirname($base.'/files'.$listed), 0755, true);
            file_put_contents($base.'/files'.$listed, $bytes);
        }
        if ($media) {
            // A listed artwork that is a symlink to a real file must be refused, not followed.
            @mkdir($base.'/files/SyntheticStudio/Art', 0755, true);
            symlink($base.'/files/SyntheticStudio/Art/one.png', $base.'/files/SyntheticStudio/Art/linked.png');
            file_put_contents($base.'/escape.wav', 'outside the root');
        }
        chmod($base.'/files', 0750);

        return $this->staged = ['source' => $base.'/source', 'files' => $base.'/files', 'report' => $base.'/reports/readiness.json', 'base' => $base];
    }

    private function command(array $options = [], ?array $answers = null): PendingCommand
    {
        $staged = $this->staged ?? $this->stage(media: false);
        $defaults = ['--operator' => self::OPERATOR, '--reviewer' => self::REVIEWER, '--source' => $staged['source'], '--files-root' => $staged['files'],
            '--release-commit' => str_repeat('a', 40), '--release-tree' => str_repeat('b', 40)];
        if (! isset($options['--check'])) {
            $defaults['--report'] = $staged['report'];
        }
        $pending = $this->artisan('vasey:staging-beatstars-import', $options + $defaults);
        $answers ??= isset($options['--check']) ? [] : [StagingBeatStarsImport::OPERATOR_PROMPT => $this->passwords[0],
            StagingBeatStarsImport::REVIEWER_PROMPT => $this->passwords[1]];
        foreach ($answers as $prompt => $answer) {
            $pending->expectsQuestion($prompt, $answer)->doesntExpectOutputToContain($answer);
        }

        return $pending;
    }

    private function inEnvironment(string $detected, Closure $probe, ?string $configured = null): void
    {
        $this->app->detectEnvironment(fn (): string => $detected);
        config(['app.env' => $configured ?? $detected]);
        try {
            $probe();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
            config(['app.env' => 'testing']);
        }
    }

    private function census(): array
    {
        return array_combine(self::CENSUS, array_map(fn (string $table): int => DB::table($table)->count(), self::CENSUS));
    }

    private function assertRefusedWithoutWrites(array $options, string $message, ?array $answers = []): void
    {
        $before = $this->census();
        $this->command($options, $answers)->expectsOutputToContain($message)->assertExitCode(1);
        $this->assertSame($before, $this->census());
    }

    private function enroll(User ...$accounts): void
    {
        $provider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];
        $this->assertInstanceOf(AppAuthentication::class, $provider);
        foreach ($accounts as $account) {
            $account->saveAppAuthenticationSecret($provider->generateSecret());
        }
    }

    private function report(): array
    {
        return json_decode(file_get_contents($this->staged['report']), true, 32, JSON_THROW_ON_ERROR);
    }

    private function entry(array $report, string $sourceId): array
    {
        return collect($report['tracks'])->firstWhere('source_id', $sourceId);
    }

    public function test_production_is_refused_even_with_the_flag_and_test_mode(): void
    {
        $this->operators();
        $this->stage(media: false);
        $this->inEnvironment('production', function (): void {
            $this->assertRefusedWithoutWrites([], 'refused in production');
            $this->assertRefusedWithoutWrites(['--check' => true], 'refused in production');
        });
    }

    public function test_a_production_configuration_is_refused_when_the_running_environment_says_staging_and_a_mismatch_is_refused(): void
    {
        [$operator, $reviewer] = $this->operators();
        $this->enroll($operator, $reviewer);
        $this->stage(media: false);
        $this->inEnvironment('staging', function (): void {
            $this->assertRefusedWithoutWrites([], 'refused in production');
        }, 'production');
        foreach ([['staging', 'testing'], ['testing', 'staging'], ['local', 'staging']] as [$detected, $configured]) {
            $this->inEnvironment($detected, function (): void {
                $this->assertRefusedWithoutWrites([], 'running environment differ');
                $this->assertRefusedWithoutWrites(['--check' => true], 'running environment differ');
            }, $configured);
        }
        foreach (['development', 'Staging', 'staging-eu', 'prod', ''] as $environment) {
            $this->inEnvironment($environment, function (): void {
                $this->assertRefusedWithoutWrites([], 'runs only when APP_ENV is local, testing or staging');
            });
        }
    }

    public function test_the_default_off_flag_and_stripe_test_mode_are_required_before_any_write(): void
    {
        $this->operators();
        $this->stage(media: false);
        foreach ([false, null, 'true', 1] as $value) {
            config(['staging-beatstars-import.enabled' => $value]);
            $this->assertRefusedWithoutWrites([], 'VASEY_STAGING_BEATSTARS_IMPORT_ENABLED=true');
        }
        $this->assertFalse((require base_path('config/staging-beatstars-import.php'))['enabled']);
        config(['staging-beatstars-import.enabled' => true]);
        foreach ([['payments.stripe.mode' => 'live'], ['payments.stripe.mode' => null],
            ['payments.stripe.secret_key' => 'sk_'.'live_'.str_repeat('a', 24)], ['production_checkout.funds_mode' => 'live'],
            ['production_checkout.secret_key' => 'sk_'.'live_'.str_repeat('b', 24)]] as $override) {
            config($override);
            $this->assertRefusedWithoutWrites([], 'requires Stripe test mode');
            config(['payments.stripe.mode' => 'test', 'payments.stripe.secret_key' => null, 'production_checkout.funds_mode' => null, 'production_checkout.secret_key' => null]);
        }
    }

    public function test_operators_must_be_named_staff_distinct_and_satisfy_the_panel_mfa_rule(): void
    {
        $this->stage(media: false);
        $this->assertRefusedWithoutWrites(['--operator' => null], '--operator=EMAIL and --reviewer=EMAIL');
        $this->assertRefusedWithoutWrites([], 'Create it first with `php artisan vasey:create-admin`');
        [$operator, $reviewer] = $this->operators('Synthetic Catalog Operator', 'Import Reviewer');
        $this->assertRefusedWithoutWrites([], 'is a synthetic rehearsal account');
        $operator->forceFill(['name' => 'Import Operator', 'is_admin' => false])->save();
        $this->assertRefusedWithoutWrites([], 'lacks catalog authority');
        $operator->forceFill(['is_admin' => true])->save();
        $this->assertRefusedWithoutWrites(['--reviewer' => self::OPERATOR], 'must be different accounts');

        $this->inEnvironment('staging', function () use ($operator, $reviewer): void {
            $this->assertRefusedWithoutWrites([], "does not satisfy the admin panel's MFA rule");
            $this->enroll($operator);
            $this->assertRefusedWithoutWrites([], "does not satisfy the admin panel's MFA rule");
            $this->enroll($reviewer);
            $before = $this->census();
            $this->command(['--check' => true])->expectsOutputToContain('Admission passed')->expectsOutputToContain('Environment: staging')->assertSuccessful();
            $this->assertSame($before, $this->census());
        });
    }

    public function test_the_synthetic_rehearsal_catalog_must_be_reset_first(): void
    {
        [$operator] = $this->operators();
        $this->stage(media: false);
        app(SaveTrackMetadata::class)->handle(null, ['title' => '[SYNTHETIC] leftover', 'slug' => 'synthetic-rehearsal-01', 'artist' => 'Synthetic Test Content',
            'bpm' => 90, 'musical_key' => 'A minor', 'genre' => 'Synthetic', 'tags' => [], 'description' => 'leftover'], $operator);
        $this->assertRefusedWithoutWrites([], 'synthetic rehearsal catalog is still present');
    }

    public function test_the_source_and_media_root_must_be_private_snapshot_and_a_safe_root(): void
    {
        $this->operators();
        $staged = $this->stage(media: false);
        $this->assertRefusedWithoutWrites(['--source' => $staged['base'].'/missing'], 'not a valid private snapshot');
        chmod($staged['source'], 0755);
        $this->assertRefusedWithoutWrites([], 'not a valid private snapshot');
        chmod($staged['source'], 0700);
        $this->assertRefusedWithoutWrites(['--files-root' => $staged['base'].'/nothing'], 'media root must be a canonical absolute directory');
        symlink($staged['files'], $staged['base'].'/files-link');
        $this->assertRefusedWithoutWrites(['--files-root' => $staged['base'].'/files-link'], 'media root must be a canonical absolute directory');
        chmod($staged['files'], 0770);
        $this->assertRefusedWithoutWrites([], 'media root must be a canonical absolute directory');
        chmod($staged['files'], 0750);
        // Raw export bytes that no longer match the hash the snapshot binds.
        file_put_contents($staged['source'].'/raw/import-export.csv', "tampered\n");
        $this->assertRefusedWithoutWrites([], 'not a valid private snapshot');
        // The report can never replace the hashed snapshot manifest or sit in the snapshot directory or the media root.
        $guard = $this->stage(media: false);
        $manifest = file_get_contents($guard['source'].'/catalog.json');
        $this->assertRefusedWithoutWrites(['--report' => $guard['source'].'/catalog.json'], 'report path must be a .json file name');
        $this->assertRefusedWithoutWrites(['--report' => $guard['source'].'/raw/report.json'], 'report path must be a .json file name');
        $this->assertSame($manifest, file_get_contents($guard['source'].'/catalog.json'));
        mkdir($guard['files'].'/inner', 0700);
        $this->assertRefusedWithoutWrites(['--report' => $guard['files'].'/inner/report.json'], 'report path must be a .json file name');
        // An existing file that is not an earlier report of this import is never overwritten.
        file_put_contents($guard['base'].'/reports/readiness.json', '{"something":"else"}');
        chmod($guard['base'].'/reports/readiness.json', 0600);
        $this->assertRefusedWithoutWrites([], 'report path must be a .json file name');
        $this->assertSame('{"something":"else"}', file_get_contents($guard['base'].'/reports/readiness.json'));
        // The report must be a plain .json name in a private directory.
        $fresh = $this->stage(media: false);
        $this->assertRefusedWithoutWrites(['--report' => $fresh['base'].'/reports/readiness.txt'], 'report path must be a .json file name');
        chmod($fresh['base'].'/reports', 0755);
        $this->assertRefusedWithoutWrites([], 'report path must be a .json file name');
        $this->assertSame([], glob($fresh['base'].'/reports/*') ?: []);
    }

    public function test_a_write_run_needs_an_interactive_session_both_passwords_and_in_staging_an_attestation(): void
    {
        [$operator, $reviewer] = $this->operators();
        $this->stage(media: false);
        $this->assertRefusedWithoutWrites(['--no-interaction' => true], 'requires an interactive session', []);
        // The operator's wrong password stops the run before the second account is asked.
        $this->assertRefusedWithoutWrites([], 'a staff account password was not confirmed', [StagingBeatStarsImport::OPERATOR_PROMPT => 'wrong password value']);
        $this->assertRefusedWithoutWrites([], 'a staff account password was not confirmed', [StagingBeatStarsImport::OPERATOR_PROMPT => $this->passwords[0],
            StagingBeatStarsImport::REVIEWER_PROMPT => $this->passwords[0]]);
        $this->assertRefusedWithoutWrites(['--check' => true, '--attest' => true], '--attest does not apply to --check');
        $this->enroll($operator, $reviewer);
        $this->inEnvironment('staging', function (): void {
            $this->assertRefusedWithoutWrites([], 'Re-run with --attest', null);
        });
        $this->assertFileDoesNotExist($this->staged['report']);
    }

    public function test_check_mode_prints_the_plan_and_writes_nothing(): void
    {
        $this->operators();
        $this->stage();
        $before = $this->census();
        $this->command(['--check' => true])->expectsOutputToContain('Nothing was written.')
            ->expectsOutputToContain('records=3 already_mapped=0 to_create=3')
            ->expectsOutputToContain('review: create_draft=3 skip=0 conflict=0')
            ->expectsOutputToContain('files master_wav: ok=2 refused=1')
            ->expectsOutputToContain('files stems_zip: not_listed=1 ok=1 refused=1')
            ->doesntExpectOutputToContain($this->staged['base'])->doesntExpectOutputToContain('SYNTHETIC Import')->assertSuccessful();
        $this->assertSame($before, $this->census());
        $this->assertFileDoesNotExist($this->staged['report']);
    }

    public function test_a_run_imports_drafts_with_media_and_creates_nothing_else(): void
    {
        [$operator] = $this->operators();
        $this->stage();
        $auditStart = (int) AuditEvent::max('id');
        $this->command()->expectsOutputToContain('drafts created=3 already_mapped=0')
            ->doesntExpectOutputToContain($this->staged['base'])->doesntExpectOutputToContain('SYNTHETIC Import')->doesntExpectOutputToContain('bs-import-')
            ->expectsOutputToContain('Nothing was published and no offer, license, rights declaration or price was created.')->assertSuccessful();

        $this->assertSame(3, Track::count());
        $this->assertSame(['draft'], Track::distinct()->pluck('status')->all());
        $this->assertSame(0, Track::whereNotNull('published_slug')->orWhereNotNull('published_at')->count());
        $this->assertSame(1, DB::table('catalog_import_batches')->count());
        $this->assertSame(3, DB::table('catalog_import_mappings')->count());
        // Nothing that could sell, license, clear or publish exists.
        foreach (['offers', 'offer_revisions', 'rights_declarations', 'license_templates', 'license_versions', 'quotes', 'orders', 'license_grants', 'verified_payments'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        $one = Track::where('slug', 'synthetic-import-one')->sole();
        $this->assertSame(95, $one->bpm);
        $ready = $one->assets()->where('status', 'ready')->pluck('role')->sort()->values()->all();
        $this->assertSame(['artwork', 'download_mp3', 'master_wav', 'preview_tagged', 'stems_zip'], $ready);
        // The import never confirms that the stems are the master's recording: no association exists and the track says so.
        $stems = $one->assets()->where('role', 'stems_zip')->where('status', 'ready')->sole();
        $this->assertFalse(app(RecordingAssociation::class)->matches($stems, $one->assets()->where('role', 'preview_tagged')->sole()));
        $this->assertSame(0, StemsRecording::count());
        foreach ($one->assets()->whereNull('parent_asset_id')->get() as $source) {
            $this->assertSame('processed', $source->status);
            $this->assertSame($operator->id, $source->technical_metadata['acquired_by']);
        }
        // The listed BeatStars preview MP3 is never taken in: only the pipeline's tagged preview exists.
        $this->assertSame(['artwork', 'master_wav', 'stems_zip'], MediaAsset::whereNull('parent_asset_id')->distinct()->orderBy('role')->pluck('role')->all());
        $this->assertSame(0, MediaAsset::where('original_name', 'like', '%.mp3')->whereNull('parent_asset_id')->count());
        // High-resolution artwork is preferred over the standard file.
        $this->assertSame('one-hires.png', $one->assets()->where('role', 'artwork')->whereNull('parent_asset_id')->sole()->original_name);

        $two = Track::where('slug', 'synthetic-import-two')->sole();
        $this->assertSame(['download_mp3', 'master_wav', 'preview_tagged'], $two->assets()->where('status', 'ready')->pluck('role')->sort()->values()->all());
        $three = Track::where('slug', 'synthetic-import-three')->sole();
        $this->assertSame(0, $three->assets()->count());

        // Source ids and record hashes are preserved in the retained mapping evidence.
        $this->assertSame(3, DB::table('catalog_import_mappings')->whereIn('record_key', array_map(
            fn (string $id): string => (new NormalizedSourceSnapshot)->recordKey('synthetic/beatstars-import:fixture', $id),
            ['bs-import-0001', 'bs-import-0002', 'bs-import-0003']))->count());

        $audits = AuditEvent::where('id', '>', $auditStart)->get()->countBy('action')->all();
        ksort($audits);
        $this->assertSame(['catalog.track.created' => 3, 'media.processing.completed' => 4, 'media.processing.queued' => 4,
            'media.upload.quarantined' => 4, 'migration.catalog_batch.accepted' => 1,
            'migration.catalog_draft.mapped' => 3], $audits);

        $report = $this->report();
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->staged['report'])), -4));
        $this->assertSame(3, $report['summary']['counts']['imported_drafts']);
        $this->assertSame(0, $report['summary']['counts']['published']);
        $this->assertSame(2, $report['summary']['counts']['master_ready']);
        $this->assertSame(1, $report['summary']['counts']['artwork_ready']);
        $this->assertSame([0, 1], [$report['summary']['counts']['stems_associated'], $report['summary']['counts']['stems_unconfirmed']]);
        $first = $this->entry($report, 'bs-import-0001');
        $this->assertSame(['ready', 'ready', 'ready'], [$first['media']['master_wav']['state'], $first['media']['artwork']['state'], $first['media']['stems_zip']['state']]);
        $this->assertSame('unconfirmed', $first['stems_association']);
        $this->assertContains('stems_association_unconfirmed', $first['publication_blockers']);
        $this->assertArrayHasKey('stems_association_unconfirmed', $report['summary']['publication_blockers']);
        $this->assertEquals(['listed_source_mp3' => 'ok', 'listed_source_mp3_used' => false, 'state' => 'generated_by_pipeline'], $first['preview']);
        $this->assertContains('The latest rights declaration must be verified.', $first['publication_blockers']);
        $this->assertContains('At least one active license offer is required.', $first['publication_blockers']);
        $this->assertSame($first['publication_blockers'], [...app(PublicationReadiness::class)->blockers($one), 'stems_association_unconfirmed']);
        $second = $this->entry($report, 'bs-import-0002');
        $this->assertSame(['artwork'], $second['media_missing']);
        $this->assertSame(['artwork_high_res' => 'missing', 'artwork' => 'missing'], $second['media']['artwork']['files']);
        $this->assertSame('not_listed', $second['media']['stems_zip']['files']['stems_zip']);
        $this->assertSame('media_missing', $second['last_attempt']['roles']['artwork']['outcome']);
        $this->assertSame(['high_res' => 'media_missing', 'standard' => 'media_missing'], array_map(fn (array $candidate): string => $candidate['outcome'], $second['last_attempt']['roles']['artwork']['candidates']));
        $third = $this->entry($report, 'bs-import-0003');
        $this->assertSame('refused:path_traversal', $third['media']['master_wav']['files']['master_wav']);
        $this->assertSame('refused:wrong_extension', $third['media']['stems_zip']['files']['stems_zip']);
        $this->assertSame('refused:symlink', $third['media']['artwork']['files']['artwork']);
        $this->assertSame(['master_wav', 'artwork', 'stems_zip'], $third['media_missing']);
        $this->assertSame(3, $third['files_refused']);
        $this->assertStringNotContainsString($this->staged['base'], file_get_contents($this->staged['report']));
        $this->assertStringNotContainsString('/SyntheticStudio', file_get_contents($this->staged['report']));
        $this->assertSame([], glob(rtrim(config('filesystems.disks.local.root'), '/').'/processing/*') ?: []);
    }

    public function test_a_rerun_is_idempotent_and_does_not_ask_the_importer_about_tracks_that_gained_media(): void
    {
        $this->operators();
        $this->stage();
        $this->command()->assertSuccessful();
        $before = $this->census();
        $this->command()->expectsOutputToContain('drafts created=0 already_mapped=3')->assertSuccessful();
        $this->assertSame($before, $this->census());
        $report = $this->report();
        $this->assertSame(3, $report['summary']['counts']['imported_drafts']);
        // The report keeps the earlier attempt of a draft this run did not need to touch.
        $this->assertSame('attached', $this->entry($report, 'bs-import-0001')['last_attempt']['roles']['master_wav']['outcome'] ?? 'already_attached');
        $this->command(['--check' => true])->expectsOutputToContain('review: every record is already a draft')->assertSuccessful();
    }

    public function test_limit_and_from_batch_the_media_pass_and_resume_without_duplicates(): void
    {
        $this->operators();
        $this->stage();
        $this->command(['--limit' => '1'])->expectsOutputToContain('drafts created=3')->assertSuccessful();
        $this->assertSame(3, MediaAsset::whereNull('parent_asset_id')->count());
        $one = Track::where('slug', 'synthetic-import-one')->sole();
        $two = Track::where('slug', 'synthetic-import-two')->sole();
        $this->assertSame(3, $one->assets()->whereNull('parent_asset_id')->count());
        $this->assertSame(0, $two->assets()->count());
        $auditCount = AuditEvent::count();
        $this->command(['--limit' => '1', '--from' => 'bs-import-0002'])->expectsOutputToContain('drafts created=0 already_mapped=3')->assertSuccessful();
        $this->assertSame(['download_mp3', 'master_wav', 'preview_tagged'], $two->assets()->where('status', 'ready')->pluck('role')->sort()->values()->all());
        $this->assertSame(3, $one->assets()->whereNull('parent_asset_id')->count());
        $this->assertSame(1, MediaAsset::where('track_id', $two->id)->whereNull('parent_asset_id')->count());
        $this->assertGreaterThan($auditCount, AuditEvent::count());
        foreach (['0', '101', '1.5', 'x', ''] as $bad) {
            $this->assertRefusedWithoutWrites(['--limit' => $bad], '--limit must be a whole number from 1 to 100');
        }
    }

    public function test_a_record_that_collides_with_an_existing_track_is_a_conflict_and_nothing_is_applied(): void
    {
        [$operator] = $this->operators();
        $this->stage();
        app(SaveTrackMetadata::class)->handle(null, ['title' => 'Already here', 'slug' => 'synthetic-import-two', 'artist' => 'Someone', 'bpm' => 90,
            'musical_key' => 'A minor', 'genre' => 'Other', 'tags' => [], 'description' => 'pre-existing'], $operator);
        $before = $this->census();
        $this->command(['--check' => true])->expectsOutputToContain('conflict target_slug_collision x1')->assertExitCode(1);
        $this->command()->expectsOutputToContain('target_slug_collision x1')->assertExitCode(1);
        $this->assertSame($before['tracks'], DB::table('tracks')->count());
        $this->assertSame($before['catalog_import_mappings'], DB::table('catalog_import_mappings')->count());
        $this->assertSame($before['media_assets'], DB::table('media_assets')->count());
    }

    public function test_a_rejected_file_is_recorded_and_the_run_continues(): void
    {
        $this->operators();
        // A master whose bytes are not a WAV is refused by the ordinary intake; the other draft's media still arrives.
        $this->stage(['/SyntheticStudio/Masters/two.wav' => 'RIFF but not a wave file']);
        $this->command()->expectsOutputToContain('master_wav=rejected:intake_validation')->assertExitCode(1);
        $report = $this->report();
        $two = $this->entry($report, 'bs-import-0002');
        $this->assertSame('absent', $two['media']['master_wav']['state']);
        $this->assertTrue($two['last_attempt']['failed']);
        $this->assertSame('rejected', $two['last_attempt']['roles']['master_wav']['outcome']);
        $this->assertSame('ready', $this->entry($report, 'bs-import-0001')['media']['master_wav']['state']);
        $this->assertSame(0, Track::where('slug', 'synthetic-import-two')->sole()->assets()->count());
    }

    public function test_attestation_is_recorded_for_both_accounts_before_any_write(): void
    {
        [$operator, $reviewer] = $this->operators();
        $this->enroll($operator, $reviewer);
        // No media root content: the test-only scanner and seller tag run only under APP_ENV=testing, so this staging-mode run covers drafts and attestation.
        $this->stage(media: false);
        $this->inEnvironment('staging', function () use ($operator, $reviewer): void {
            $this->command(['--attest' => true, '--limit' => '1'])->expectsOutputToContain('attestation=recorded')->assertSuccessful();
            $attestations = AuditEvent::where('action', StaffWriteAuthority::ATTESTATION_ACTION)->orderBy('id')->get();
            $this->assertSame([$operator->id, $reviewer->id], $attestations->pluck('actor_id')->all());
            $this->assertSame(['beatstars_import.operator', 'beatstars_import.reviewer'], $attestations->pluck('context.action')->all());
            $this->assertLessThan(AuditEvent::where('action', 'catalog.track.created')->min('id'), $attestations->first()->id);
        });
    }
}
