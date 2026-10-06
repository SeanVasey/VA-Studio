<?php

use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\PublicCatalog;
use App\Domain\Inquiries\InquiryPolicy;
use App\Domain\Inquiries\OrderInquiry;
use App\Domain\Inquiries\SubmitInquiry;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationRevision;
use App\Domain\SiteBuilder\SiteContent;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CustomerFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\InquiryConversationFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\SiteEditorialFixtures;

// Subprocess regression fixture only. Synthetic scanners/providers are explicit; this is not native media acceptance.
$phase = 'isolation';
try {
    $directory = getenv('VASEY_BROWSER_DIRECTORY');
    $operation = $argv[1] ?? null;
    $project = $argv[2] ?? null;
    if (PHP_SAPI !== 'cli' || ! is_string($directory) || realpath($directory) !== $directory || is_link($directory)
        || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
        || preg_match('/\Avasey-browser-[a-f0-9]+\z/D', basename($directory)) !== 1
        || getenv('VASEY_INQUIRY_CATALOG_PROBE') !== 'isolated-subprocess-regression'
        || getenv('APP_ENV') !== 'local' || getenv('LARAVEL_STORAGE_PATH') !== $directory
        || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== $directory.'/database.sqlite'
        || getenv('APP_CONFIG_CACHE') !== $directory.'/config.php' || is_file($directory.'/config.php')
        || ! in_array($operation, ['prepare', 'snapshot', 'fail-withdrawal-audit'], true)
        || ! in_array($project, ['chromium-desktop', 'webkit-mobile'], true) || count($argv) !== 3) {
        throw new RuntimeException;
    }
    $phase = 'application_boot';
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (storage_path() !== $directory || ! $app->environment('local')
        || config('database.connections.sqlite.database') !== $directory.'/database.sqlite') {
        throw new RuntimeException;
    }
    $path = $directory.'/inquiry-conversation-'.$project.'.json';
    if ($operation === 'prepare') {
        if (filesize($directory.'/database.sqlite') !== 0 || is_file($path)) {
            throw new RuntimeException;
        }
        $phase = 'migrations';
        if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
            throw new RuntimeException;
        }
        $phase = 'operator';
        $operator = User::factory()->create(['email' => 'browser-operator@example.test', 'is_admin' => true]);
        $operator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $app->detectEnvironment(fn () => 'testing');
        $phase = 'customer';
        $account = CustomerFixtures::account();
        $phase = 'purchase';
        $paid = CustomerFixtures::ready($account['user'], 'CATALOGCLEANUP');
        $phase = 'catalog';
        $line = $paid['order']->lines()->sole();
        $revision = OfferRevision::findOrFail($line->offer_revision_id);
        $track = $revision->track;
        $unrelated = getenv('VASEY_INQUIRY_CATALOG_UNRELATED') === '1' ? QuoteFixtures::selection()['track']->id : null;
        $app->detectEnvironment(fn () => 'local');
        $phase = 'site';
        $site = app(SiteContent::class);
        $release = $site->create(SiteEditorialFixtures::content(), 'Synthetic inquiry regression', $operator);
        $site->publish($release->id, 0, $operator);
        $restore = SitePublicationRevision::where('revision', 0)->sole()->release_id;
        $values = ['name' => 'Synthetic fixture visitor', 'email' => 'synthetic-fixture@example.test', 'subject' => 'Synthetic private inquiry',
            'message' => 'Retained private conversation fixture.', 'website' => ''];
        $body = $values + ['requestKey' => (string) Str::uuid(),
            'noticeToken' => app(InquiryPolicy::class)->publicSetup($site->current())['noticeToken']];
        $phase = 'generic_inquiry';
        app(SubmitInquiry::class)->handle($body, InquiryConversationFixtures::OWNER);
        $body['requestKey'] = (string) Str::uuid();
        $phase = 'linked_inquiry';
        app(OrderInquiry::class)->submit($paid['order']->public_id, $paid['principal']->ownerKey,
            InquiryConversationFixtures::OWNER, $body, $paid['principal'], $account['user']);
        $phase = 'manifest';
        $fixture = ['marker' => getenv('VASEY_BROWSER_INQUIRY_MARKER'), 'project' => $project,
            'releaseId' => $release->id, 'restoreReleaseId' => $restore, 'restored' => false,
            'orderSupport' => ['capability' => bin2hex(random_bytes(32)), 'items' => [['trackId' => $track->id,
                'offerId' => $revision->offer_id, 'licenseVersionId' => $revision->license_version_id, 'offerRevisionId' => $revision->id]],
                'slug' => $track->slug, 'publicationVersion' => $track->publication_version]];
        file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        file_put_contents($directory.'/fixtures.json', json_encode(['chromium-desktop' => [], 'webkit-mobile' => []], JSON_THROW_ON_ERROR));
        file_put_contents($directory.'/inquiry-fixture-marker.json', json_encode(['marker' => getenv('VASEY_BROWSER_INQUIRY_MARKER'),
            'database' => $directory.'/database.sqlite', 'origin' => 'http://127.0.0.1:8173', 'operatorId' => 1], JSON_THROW_ON_ERROR));
        echo json_encode(['trackId' => $track->id, 'unrelatedId' => $unrelated, 'releaseId' => $release->id,
            'restoreReleaseId' => $restore, 'publicationVersion' => $track->publication_version], JSON_THROW_ON_ERROR)."\n";
    } elseif ($operation === 'fail-withdrawal-audit') {
        DB::unprepared("CREATE TRIGGER inquiry_cleanup_test_audit_refusal BEFORE INSERT ON audit_events WHEN NEW.action = 'catalog.track.unpublished' BEGIN SELECT RAISE(ABORT, 'SYNTHETIC WITHDRAWAL AUDIT REFUSAL'); END");
        echo "{\"installed\":true}\n";
    } else {
        // Projection uses the explicit test engine only in testing. The actual restore runs in local.
        $app->detectEnvironment(fn () => 'testing');
        $tables = ['tracks', 'audit_events', 'site_publications', 'site_publication_revisions', 'customer_inquiries', 'inquiry_messages', 'inquiry_order_contexts'];
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }
        $files = [];
        $private = $directory.'/app/private';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($private, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($private) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);
        echo json_encode(['catalog' => app(PublicCatalog::class)->page(Request::create('/api/catalog'))['tracks'],
            'tracks' => $rows['tracks'], 'publication' => SitePublication::findOrFail(1)->getAttributes(),
            'withdrawals' => AuditEvent::where('action', 'catalog.track.unpublished')->get()->map->getAttributes()->all(),
            'commerceHash' => CanonicalJson::hash(DeliveryFixtures::retained()),
            'inquiryHash' => CanonicalJson::hash(array_intersect_key($rows, array_flip(['customer_inquiries', 'inquiry_messages', 'inquiry_order_contexts']))),
            'filesHash' => CanonicalJson::hash($files), 'graphHash' => CanonicalJson::hash($rows),
            'inquiries' => count($rows['customer_inquiries']), 'contexts' => count($rows['inquiry_order_contexts'])], JSON_THROW_ON_ERROR)."\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Isolated catalog cleanup regression operation failed: '.$phase.' '.get_class($exception)."\n");
    exit(1);
}
