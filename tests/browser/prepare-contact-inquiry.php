<?php

use App\Domain\Inquiries\InquiryPolicy;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationRevision;
use App\Domain\SiteBuilder\SiteContent;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\SiteEditorialFixtures;

// No web route invokes this helper. Every operation is restricted to the wrapper's disposable SQLite fixture.
$writtenPath = null;
try {
    $directory = getenv('VASEY_BROWSER_DIRECTORY');
    $marker = getenv('VASEY_BROWSER_INQUIRY_MARKER');
    $mode = $argv[1] ?? null;
    $project = $argv[2] ?? null;
    if (PHP_SAPI !== 'cli' || ! is_string($directory) || is_link($directory) || realpath($directory) !== $directory
        || realpath(dirname($directory)) !== realpath(sys_get_temp_dir()) || ! preg_match('/\Avasey-browser-[A-Za-z0-9]+\z/D', basename($directory))
        || getenv('APP_ENV') !== 'local' || getenv('APP_URL') !== 'http://127.0.0.1:8173'
        || getenv('LARAVEL_STORAGE_PATH') !== $directory || getenv('DB_CONNECTION') !== 'sqlite'
        || getenv('DB_DATABASE') !== $directory.'/database.sqlite' || getenv('DB_URL') !== ''
        || realpath($directory.'/database.sqlite') !== $directory.'/database.sqlite' || is_link($directory.'/database.sqlite')
        || ! is_file($directory.'/database.sqlite') || filesize($directory.'/database.sqlite') === 0
        || getenv('APP_CONFIG_CACHE') !== $directory.'/config.php' || is_file($directory.'/config.php')
        || getenv('APP_ROUTES_CACHE') !== $directory.'/routes.php' || getenv('APP_EVENTS_CACHE') !== $directory.'/events.php'
        || file_exists($directory.'/routes.php') || file_exists($directory.'/events.php')
        || ! is_string($marker) || preg_match('/\A[a-f0-9]{64}\z/D', $marker) !== 1
        || ! in_array($project, ['chromium-desktop', 'webkit-mobile'], true)
        || ! in_array($mode, ['prepare', 'verify', 'restore'], true) || count($argv) !== ($mode === 'verify' ? 5 : 3)) {
        throw new RuntimeException('Not an isolated inquiry browser run.');
    }
    foreach (['fixtures.json', 'inquiry-fixture-marker.json'] as $file) {
        if (! is_file($directory.'/'.$file) || is_link($directory.'/'.$file) || realpath($directory.'/'.$file) !== $directory.'/'.$file) {
            throw new RuntimeException('Missing isolated fixture marker.');
        }
    }
    $fixtures = json_decode(file_get_contents($directory.'/fixtures.json'), true, 16, JSON_THROW_ON_ERROR);
    $identity = json_decode(file_get_contents($directory.'/inquiry-fixture-marker.json'), true, 8, JSON_THROW_ON_ERROR);
    if (! is_array($fixtures) || array_keys($fixtures) !== ['chromium-desktop', 'webkit-mobile']
        || $identity !== ['marker' => $marker, 'database' => $directory.'/database.sqlite', 'origin' => 'http://127.0.0.1:8173', 'operatorId' => 1]) {
        throw new RuntimeException('Fixture identity mismatch.');
    }
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('local') || config('app.url') !== 'http://127.0.0.1:8173'
        || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $directory.'/database.sqlite'
        || storage_path() !== $directory || config('filesystems.disks.local.root') !== $directory.'/app/private'
        || config('inquiries.enabled') !== true || config('inquiries.operator_user_id') !== '1'
        || config('inquiries.privacy_notice') !== 'Synthetic browser privacy notice. Inquiries are saved privately for verification.'
        || config('inquiries.retention_policy_reference') !== 'SYNTHETIC-BROWSER-ONLY') {
        throw new RuntimeException('Effective inquiry browser configuration does not match.');
    }
    $operator = User::findOrFail(1);
    if ($operator->email !== 'browser-operator@example.test' || ! Gate::forUser($operator)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($operator)) {
        throw new RuntimeException('Synthetic operator identity mismatch.');
    }
    $path = $directory.'/inquiry-'.$project.'.json';
    if (is_link($path)) {
        throw new RuntimeException('Unsafe fixture record.');
    }
    $site = app(SiteContent::class);
    if ($mode === 'prepare') {
        if (file_exists($path)) {
            throw new RuntimeException('Inquiry fixture already prepared.');
        }
        $fixture = DB::transaction(function () use ($site, $path, $project, $operator, $marker, &$writtenPath): array {
            $before = SitePublication::lockForUpdate()->findOrFail(1);
            $values = ['name' => 'Synthetic inquiry visitor '.$project, 'email' => 'inquiry-'.$project.'@example.test',
                'subject' => 'Synthetic inquiry subject '.$project, 'message' => "Synthetic private message.\nKeep exact Unicode: é 🎧.\n<script>synthetic escaped text</script>", 'website' => ''];
            $release = $site->create(SiteEditorialFixtures::content('SYNTHETIC INQUIRY '.$project), 'Synthetic inquiry '.$project, $operator);
            $preview = $site->create(SiteEditorialFixtures::content('PRIVATE SYNTHETIC INQUIRY '.$project), 'Private synthetic inquiry '.$project, $operator);
            $site->publish($release->id, $before->revision, $operator);
            $restoreId = $before->active_release_id ?? SitePublicationRevision::where('revision', 0)->sole()->release_id;
            $fixture = ['marker' => $marker, 'project' => $project, 'releaseId' => $release->id, 'releaseHash' => $release->content_hash, 'restoreReleaseId' => $restoreId,
                'previewPath' => '/admin/site-releases/'.$preview->id.'/preview/contact', 'privacyNotice' => config('inquiries.privacy_notice'),
                'values' => $values, 'inquiryCount' => CustomerInquiry::count(), 'orders' => DB::table('orders')->count(),
                'grants' => DB::table('license_grants')->count(), 'inboxAuditLastId' => (int) (AuditEvent::where('action', 'inquiry.inbox_viewed')->max('id') ?? 0), 'restored' => false];
            if (app(InquiryPolicy::class)->publicSetup($site->current()) === null) {
                throw new RuntimeException('Synthetic public intake did not become eligible.');
            }
            // An exclusive private metadata write is part of the transaction: failed retention rolls publication back.
            umask(0077);
            $stream = fopen($path, 'x');
            if ($stream === false) {
                throw new RuntimeException('Unable to retain disposable fixture evidence.');
            }
            $writtenPath = $path;
            try {
                $json = json_encode($fixture, JSON_THROW_ON_ERROR);
                if (! chmod($path, 0600) || fwrite($stream, $json) !== strlen($json) || ! fflush($stream)) {
                    throw new RuntimeException('Unable to retain disposable fixture evidence.');
                }
            } finally {
                fclose($stream);
            }

            return $fixture;
        });
        $writtenPath = null; // The committed fixture is now retained for the journey's mandatory restoration.
        echo json_encode($fixture, JSON_THROW_ON_ERROR)."\n";
    } else {
        if (! is_file($path) || realpath($path) !== $path) {
            throw new RuntimeException('Missing prepared inquiry fixture.');
        }
        $fixture = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (($fixture['marker'] ?? null) !== $marker || ($fixture['project'] ?? null) !== $project) {
            throw new RuntimeException('Prepared fixture identity mismatch.');
        }
        if ($mode === 'restore') {
            $current = SitePublication::findOrFail(1);
            if ($fixture['restored'] !== true) {
                if ($current->active_release_id !== $fixture['releaseId']) {
                    throw new RuntimeException('Another publication replaced the fixture.');
                }
                $site->rollback($fixture['restoreReleaseId'], $current->revision, $operator);
                $fixture['restored'] = true;
                if (file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                    throw new RuntimeException('Unable to retain restoration evidence.');
                }
            }
            if (SitePublication::findOrFail(1)->active_release_id !== $fixture['restoreReleaseId']) {
                throw new RuntimeException('The prior publication was not restored.');
            }
            echo "{\"restored\":true}\n";
        } else {
            $receipt = $argv[3] ?? '';
            $state = $argv[4] ?? '';
            $version = ['new' => 0, 'read' => 1, 'archived' => 2][$state] ?? null;
            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $receipt) !== 1 || $version === null) {
                throw new RuntimeException('Invalid verification arguments.');
            }
            $inquiry = CustomerInquiry::where('public_id', $receipt)->sole();
            $raw = DB::table('customer_inquiries')->where('id', $inquiry->id)->sole();
            if ($inquiry->payload !== $fixture['values'] || $inquiry->state !== $state || $inquiry->version !== $version
                || $inquiry->privacy_notice !== $fixture['privacyNotice'] || $inquiry->privacy_notice_hash !== hash('sha256', $fixture['privacyNotice'])
                || $inquiry->payload_hash !== CanonicalJson::hash($fixture['values']) || $inquiry->site_release_id !== $fixture['releaseId']
                || $inquiry->site_content_hash !== $fixture['releaseHash']
                || $inquiry->operator_user_id !== 1 || $inquiry->retention_policy_reference !== 'SYNTHETIC-BROWSER-ONLY'
                || CustomerInquiry::count() !== $fixture['inquiryCount'] + 1 || DB::table('orders')->count() !== $fixture['orders']
                || DB::table('license_grants')->count() !== $fixture['grants']) {
                throw new RuntimeException('Exact inquiry persistence verification failed.');
            }
            foreach (array_filter($fixture['values']) as $value) {
                if (str_contains($raw->payload, $value) || str_contains($raw->privacy_notice, $fixture['privacyNotice'])) {
                    throw new RuntimeException('Private input was retained as plaintext.');
                }
            }
            foreach (['received' => 1, 'read' => $version >= 1 ? 1 : 0, 'archived' => $version === 2 ? 1 : 0] as $action => $count) {
                $events = AuditEvent::where('subject_type', CustomerInquiry::class)->where('subject_id', $inquiry->id)->where('action', 'inquiry.'.$action)->get();
                if ($events->count() !== $count || $events->contains(fn (AuditEvent $event): bool => $event->actor_id !== ($action === 'received' ? null : 1))) {
                    throw new RuntimeException('Inquiry transition audit did not match.');
                }
            }
            if ($version >= 1 && ! AuditEvent::where('subject_type', CustomerInquiry::class)->where('subject_id', $inquiry->id)->where('action', 'inquiry.viewed')->where('actor_id', 1)->exists()) {
                throw new RuntimeException('The operator detail was not audited.');
            }
            if ($version >= 1 && ! AuditEvent::where('action', 'inquiry.inbox_viewed')->where('actor_id', 1)->where('id', '>', $fixture['inboxAuditLastId'])->exists()) {
                throw new RuntimeException('The operator inbox was not freshly audited.');
            }
            foreach (AuditEvent::where('action', 'like', 'inquiry.%')->get() as $event) {
                $context = json_encode($event->context, JSON_THROW_ON_ERROR);
                foreach (array_filter($fixture['values']) as $value) {
                    if (str_contains($context, $value)) {
                        throw new RuntimeException('Private input appeared in audit context.');
                    }
                }
            }
            echo json_encode(['state' => $state, 'version' => $version, 'exactEncryptedInput' => true, 'audited' => true], JSON_THROW_ON_ERROR)."\n";
        }
    }
} catch (Throwable) {
    if (is_string($writtenPath)) {
        unlink($writtenPath); // Only the exclusively created file of a rolled-back preparation is removed.
    }
    fwrite(STDERR, "Isolated inquiry fixture operation failed; no private details are printed.\n");
    exit(1);
}
