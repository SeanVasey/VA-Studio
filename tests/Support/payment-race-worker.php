<?php

use App\Domain\Commerce\Payments\ProcessStripeReceipt;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PaymentFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') { throw new LogicException('Payment race requires test MySQL.'); }
    $directory = getenv('VASEY_PAYMENT_RACE_DIRECTORY'); $worker = getenv('VASEY_PAYMENT_RACE_WORKER');
    $mediaRoot = getenv('VASEY_PAYMENT_RACE_MEDIA_ROOT');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true) || ! is_string($mediaRoot) || ! is_dir($mediaRoot)) {
        throw new LogicException('Missing payment race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 32768), true, 32, JSON_THROW_ON_ERROR);
    PaymentFixtures::configure();
    config(['filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local');
    \Illuminate\Support\Carbon::setTestNow($input['now']); \Carbon\CarbonImmutable::setTestNow($input['now']);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
    $waitFor = function (string $path): void {
        $deadline = microtime(true) + 35;
        do { clearstatcache(true, $path); if (is_file($path)) { return; } usleep(10000); }
        while (microtime(true) < $deadline);
        throw new RuntimeException('Payment worker barrier timed out.');
    };
    $gateway = PaymentFixtures::gateway(); $gateway->session = $input['session']; $gateway->payment = $input['payment'];
    $gateway->onPayment = function (string $id) use ($directory, $worker, $input, $waitFor): array {
        if (DB::transactionLevel() !== 0 || DB::table('stripe_receipt_work')->where('stripe_webhook_receipt_id', $input['receipt'])->where('state', 'processing')->count() !== 1) {
            throw new LogicException('Provider I/O preceded a committed claim or retained a transaction.');
        }
        touch($directory.'/provider-'.$worker); $waitFor($directory.'/release-'.$worker);

        if ($input['fail'] ?? false) { throw new RuntimeException('Synthetic late provider failure.'); }

        return $input['payment'];
    };
    $app->instance(StripeCheckoutGateway::class, $gateway); $app->instance(StripePaymentGateway::class, $gateway);
    file_put_contents($directory.'/ready-'.$worker, (string) $connectionId); $waitFor($directory.'/start-'.$worker);
    $result = app(ProcessStripeReceipt::class)->handle($input['receipt']);
    echo json_encode(['outcome' => $result, 'pid' => getmypid(), 'calls' => $gateway->calls], JSON_THROW_ON_ERROR); exit(0);
} catch (Throwable $error) {
    echo json_encode(['outcome' => 'worker_failed', 'exception' => $error::class], JSON_THROW_ON_ERROR); exit(1);
}
