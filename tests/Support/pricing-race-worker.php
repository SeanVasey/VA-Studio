<?php

// Independent MySQL connection used only by QuotePricingConcurrencyTest.
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\QuoteException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
        throw new LogicException('Pricing race requires test MySQL.');
    }
    $directory = getenv('VASEY_PRICING_RACE_DIRECTORY');
    $worker = getenv('VASEY_PRICING_RACE_WORKER');
    $mediaRoot = getenv('VASEY_PRICING_RACE_MEDIA_ROOT');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true) || ! is_string($mediaRoot) || ! is_dir($mediaRoot)) {
        throw new LogicException('Missing isolated pricing race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 16384), true, 16, JSON_THROW_ON_ERROR);
    config(['commerce.test_pricing_policy' => json_encode($input['policy'], JSON_THROW_ON_ERROR),
        'filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local');
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
    $passed = false;
    DB::connection()->beforeExecuting(function (string $query) use ($directory, $worker, $connectionId, &$passed): void {
        if ($passed || ! preg_match('/\Aselect\b/i', $query) || ! str_contains($query, 'from `quotes`') || ! str_contains($query, 'for update')) {
            return;
        }
        $passed = true;
        if (file_put_contents($directory.'/ready-'.$worker, (string) $connectionId) === false) {
            throw new RuntimeException('Cannot signal pricing race barrier.');
        }
        $deadline = microtime(true) + 18;
        do {
            clearstatcache(true, $directory.'/release');
            if (is_file($directory.'/release')) { return; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Pricing race barrier timed out.');
    });
    try {
        $pricing = app(PriceQuote::class)->create($input['quote'], $input['owner']);
        $result = ['result' => 'created', 'pricing_id' => $pricing->public_id, 'snapshot_hash' => $pricing->snapshot_hash];
    } catch (QuoteException $exception) {
        $result = ['result' => 'conflict', 'error_code' => $exception->errorCode, 'status' => $exception->status];
    }
    if (! $passed) { throw new LogicException('Pricing did not reach the quote-lock barrier.'); }
    echo json_encode($result + ['pid' => getmypid()], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $exception) {
    echo json_encode(['result' => 'worker_failed', 'exception' => $exception::class], JSON_THROW_ON_ERROR);
    exit(1);
}
