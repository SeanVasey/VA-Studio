<?php

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Delivery\ActivateTestFulfillment;
use App\Domain\Delivery\DeliveryAssets;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivationFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\PaymentFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') { throw new LogicException('Activation races require test MySQL.'); }
    $directory = getenv('VASEY_ACTIVATION_RACE_DIRECTORY'); $worker = getenv('VASEY_ACTIVATION_RACE_WORKER');
    $mediaRoot = getenv('VASEY_ACTIVATION_RACE_MEDIA_ROOT');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true) || ! is_string($mediaRoot) || ! is_dir($mediaRoot)) {
        throw new LogicException('Missing activation race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 32768), true, 32, JSON_THROW_ON_ERROR);
    config(['filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local'); ActivationFixtures::configure();
    \Illuminate\Support\Carbon::setTestNow($input['now']); \Carbon\CarbonImmutable::setTestNow($input['now']);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
    $waitFor = function (string $path): void {
        $deadline = microtime(true) + 35;
        do { clearstatcache(true, $path); if (is_file($path)) { return; } usleep(10000); }
        while (microtime(true) < $deadline);
        throw new RuntimeException('Activation worker barrier timed out.');
    };
    $gateway = PaymentFixtures::gateway(); $renderer = ContractFixtures::renderer(); $assets = ActivationFixtures::observingAssets();
    $app->instance(StripeCheckoutGateway::class, $gateway); $app->instance(StripePaymentGateway::class, $gateway);
    $app->instance(ContractRenderer::class, $renderer); $app->instance(DeliveryAssets::class, $assets);
    $assets->afterVerify = function () use ($directory, $worker, $waitFor): void {
        if (DB::transactionLevel() !== 0) { throw new LogicException('Activation preflight held a database transaction.'); }
        touch($directory.'/verified-'.$worker); $waitFor($directory.'/release');
    };
    file_put_contents($directory.'/ready-'.$worker, (string) $connectionId); $waitFor($directory.'/start');
    $outcome = app(ActivateTestFulfillment::class)->handle($input['order_id']);
    $activationId = TestFulfillmentActivation::where('order_id', $input['order_id'])->value('public_id');
    echo json_encode(['outcome' => $outcome, 'activation_id' => $activationId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel(),
        'provider_calls' => $gateway->calls, 'render_calls' => $renderer->calls,
        'verification_transaction_levels' => $assets->transactionLevels], JSON_THROW_ON_ERROR); exit(0);
} catch (Throwable $error) {
    echo json_encode(['outcome' => 'worker_failed', 'exception' => $error::class], JSON_THROW_ON_ERROR); exit(1);
}
