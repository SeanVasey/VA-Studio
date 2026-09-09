<?php

// Invoked only by StripeWebhookConcurrencyTest with committed synthetic test data.
use App\Domain\Commerce\Payments\ReceiveStripeWebhook;
use App\Domain\Commerce\Payments\StripeWebhookException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\StripeWebhookFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql') {
        throw new LogicException('Webhook race workers require a testing MySQL connection.');
    }
    $directory = getenv('VASEY_STRIPE_RACE_DIRECTORY');
    $worker = getenv('VASEY_STRIPE_RACE_WORKER');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true)) {
        throw new LogicException('Missing isolated webhook race configuration.');
    }
    StripeWebhookFixtures::configure();
    $body = stream_get_contents(STDIN, 65536);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
    $barrierPassed = false;
    DB::connection()->beforeExecuting(function (string $sql) use ($directory, $worker, $connectionId, &$barrierPassed): void {
        if ($barrierPassed || ! preg_match('/\Ainsert\b/i', $sql) || ! str_contains($sql, 'stripe_webhook_receipts')) {
            return;
        }
        $barrierPassed = true;
        if (file_put_contents($directory.'/ready-'.$worker, (string) $connectionId) === false) {
            throw new RuntimeException('Cannot signal webhook race barrier.');
        }
        $deadline = microtime(true) + 18;
        do {
            clearstatcache(true, $directory.'/release');
            if (is_file($directory.'/release')) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Webhook race barrier timed out.');
    });
    try {
        $receipt = app(ReceiveStripeWebhook::class)->handle($body, StripeWebhookFixtures::signature($body));
        $result = ['result' => 'accepted', 'receipt_id' => $receipt->id, 'fingerprint' => $receipt->event_fingerprint];
    } catch (StripeWebhookException $exception) {
        $result = ['result' => 'rejected', 'error_code' => $exception->errorCode, 'status' => $exception->status];
    }
    if (! $barrierPassed || DB::transactionLevel() !== 0) {
        throw new LogicException('Worker did not complete the committed insert race.');
    }
    echo json_encode($result + ['pid' => getmypid()], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $exception) {
    echo json_encode(['result' => 'worker_failed', 'exception' => $exception::class], JSON_THROW_ON_ERROR);
    exit(1);
}
