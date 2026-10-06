<?php

use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Customers\CustomerAccounts;
use App\Domain\Delivery\DeliveryAccessEvidence;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Delivery\ReadTestOwnerDelivery;
use App\Domain\Media\MalwareScanner;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\CustomerFixtures;

// This CLI-only fixture builder must never touch an existing installation or database.
$setupPhase = 'isolation';
try {
    $directory = getenv('VASEY_BROWSER_DIRECTORY');
    $password = getenv('VASEY_BROWSER_PASSWORD');
    if (PHP_SAPI !== 'cli' || ! is_string($directory) || is_link($directory) || realpath($directory) !== $directory
        || realpath(dirname($directory)) !== realpath(sys_get_temp_dir()) || ! preg_match('/\Avasey-browser-[A-Za-z0-9]+\z/D', basename($directory))
        || getenv('LARAVEL_STORAGE_PATH') !== $directory || getenv('DB_DATABASE') !== $directory.'/database.sqlite'
        || getenv('APP_CONFIG_CACHE') !== $directory.'/config.php' || is_file($directory.'/config.php')
        || ! is_file($directory.'/database.sqlite') || is_link($directory.'/database.sqlite') || filesize($directory.'/database.sqlite') !== 0
        || ! is_string($password) || strlen($password) < 40 || getenv('APP_ENV') !== 'local' || getenv('DB_CONNECTION') !== 'sqlite'
        || getenv('APP_URL') !== 'http://127.0.0.1:8173') {
        throw new RuntimeException('Refusing browser fixtures outside a fresh isolated local run.');
    }
    // Only the dedicated runner's existing stage/marker pair selects its empty-commerce baseline.
    // Malformed or partial stage identity must not silently omit ordinary customer coverage.
    $setupPhase = 'stage_identity';
    $stage = getenv('VASEY_BROWSER_RELATED_STAGE');
    $marker = getenv('VASEY_BROWSER_RELATED_MARKER');
    $relatedStage = $stage === '1' && is_string($marker) && preg_match('/\A[a-f0-9]{64}\z/D', $marker) === 1;
    $ordinaryStage = in_array($stage, [false, ''], true) && in_array($marker, [false, ''], true);
    if (! $relatedStage && ! $ordinaryStage) {
        throw new RuntimeException('Refusing an incomplete browser fixture stage identity.');
    }
    $setupPhase = 'scanner_prerequisite';
    if ($ordinaryStage && (! is_executable('/usr/bin/clamscan') || realpath('/usr/bin/clamscan') !== '/usr/bin/clamscan')) {
        throw new RuntimeException('Native customer fixtures require the genuine scanner and current signatures.');
    }
    $setupPhase = 'application_boot';
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $setupPhase = 'effective_configuration';
    if (! $app->environment('local') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $directory.'/database.sqlite'
        || storage_path() !== $directory || config('filesystems.disks.local.root') !== $directory.'/app/private') {
        throw new RuntimeException('Effective browser configuration does not match the isolated run.');
    }
    $setupPhase = 'migrations';
    if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
        throw new RuntimeException('Fresh browser database migration failed.');
    }
    $setupPhase = 'operator_provisioning';
    $tester = new CommandTester($kernel->all()['vasey:create-admin']);
    $tester->setInputs(['Synthetic Browser Operator', 'browser-operator@example.test', $password]);
    if ($tester->execute([], ['interactive' => true]) !== 0) {
        throw new RuntimeException('Synthetic operator provisioning failed.');
    }
    $setupPhase = 'customer_provisioning';
    $actor = User::where('email', 'browser-operator@example.test')->sole();
    $customer = User::factory()->create(['name' => 'Synthetic Customer', 'email' => 'browser-customer@example.test', 'password' => $password, 'is_admin' => false, 'email_verified_at' => now()]);
    if ($ordinaryStage) {
        app(CustomerAccounts::class)->provision($customer);
    }
    $setupPhase = 'catalog_fixtures';
    $fixtures = [];
    foreach (['chromium-desktop', 'webkit-mobile'] as $project) {
        $editable = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Synthetic editable '.$project, 'slug' => 'editable-'.$project], $actor);
        $retained = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Synthetic retained URL '.$project, 'slug' => 'retained-'.$project], $actor);
        // A retained draft URL is synthetic history only; no readiness, rights or media are fabricated.
        DB::table('tracks')->where('id', $retained->id)->update(['published_slug' => $retained->slug]);
        $fixtures[$project] = ['editable' => ['title' => $editable->title, 'slug' => $editable->slug], 'retained' => ['title' => $retained->title, 'slug' => $retained->slug]];
    }
    $setupPhase = 'catalog_manifest';
    file_put_contents($directory.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
    chmod($directory.'/fixtures.json', 0600);
    $setupPhase = 'installation_diagnostics';
    if (Artisan::call('vasey:doctor', ['--json' => true]) !== 0) {
        fwrite(STDERR, Artisan::output()); // This report contains only fixed, redacted messages.
        throw new RuntimeException('The isolated installation did not pass its required diagnostics.');
    }
    if ($ordinaryStage) {
        // The ordinary native suite needs retained customer purchases. Related-track preparation
        // instead proves its original four-track, two-user, empty-commerce census independently.
        $customerFixtures = ['projects' => []];
        $paidFixtures = [];
        $scanner = new MalwareScanner;
        $ordinaryScannerPath = config('media.clamscan');
        $app->detectEnvironment(fn () => 'testing');
        config(['media.clamscan' => '/usr/bin/clamscan']);
        try {
            foreach (['chromium-desktop' => 'CHROMIUM', 'webkit-mobile' => 'WEBKIT'] as $project => $suffix) {
                $setupPhase = 'customer_purchase';
                $paid = CustomerFixtures::ready($customer, $suffix, true, $scanner);
                $setupPhase = 'customer_manifest';
                $customerFixtures['projects'][$project] = CustomerFixtures::browserManifest($paid);
                $paidFixtures[] = $paid;
            }
        } finally {
            config(['media.clamscan' => $ordinaryScannerPath]);
            app()->instance(MalwareScanner::class, $scanner);
            $app->detectEnvironment(fn () => 'local');
        }
        // Prove preparation in the same local environment used by the HTTP server.
        // This reads private snapshots without issuing tokens, recording attempts or changing purchase evidence.
        foreach ($paidFixtures as $paid) {
            $setupPhase = 'delivery_evidence';
            $evidence = app(DeliveryAccessEvidence::class);
            $source = $evidence->source($paid['order'], config('payments.stripe.account_id'));
            $items = app(ReadTestOwnerDelivery::class)->handle($paid['order']->public_id, $paid['principal']->ownerKey)['items'];
            foreach ($items as $item) {
                $target = $evidence->target($source, $item['grantId'], $item['kind']);
                $setupPhase = 'delivery_stream';
                $prepared = app(PrepareTestDeliveryStream::class)->handle($target['file']);
                $prepared->close();
                $setupPhase = 'delivery_evidence';
            }
        }
        $setupPhase = 'customer_manifest_write';
        file_put_contents($directory.'/customer-fixtures.json', json_encode($customerFixtures, JSON_THROW_ON_ERROR));
        chmod($directory.'/customer-fixtures.json', 0600);
    }
    echo "Fresh SQLite migrations, interactive operator command and installation diagnostics passed.\n";
} catch (Throwable $exception) {
    // Laravel's plain-script exception renderer can finish with status 0. Fail explicitly.
    fwrite(STDERR, "Isolated browser fixture setup failed; no browser run was started.\n");
    // Fixed milestones and bounded class syntax identify the failing boundary without
    // exposing messages, traces, private fixture values or anonymous-class file paths.
    $phases = ['isolation', 'stage_identity', 'scanner_prerequisite', 'application_boot', 'effective_configuration',
        'migrations', 'operator_provisioning', 'customer_provisioning', 'catalog_fixtures', 'catalog_manifest',
        'installation_diagnostics', 'customer_purchase', 'customer_manifest', 'delivery_evidence', 'delivery_stream', 'customer_manifest_write'];
    $class = get_class($exception);
    $safeClass = strlen($class) <= 160 && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/D', $class) === 1
        ? $class : 'Throwable';
    fwrite(STDERR, json_encode([
        'phase' => in_array($setupPhase, $phases, true) ? $setupPhase : 'unknown',
        'exception_class' => $safeClass,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    exit(1);
}
