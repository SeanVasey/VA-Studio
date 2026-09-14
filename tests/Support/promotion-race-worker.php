<?php

// Independent MySQL connection used only by PromotionConcurrencyTest.
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PromotionUsage;
use App\Domain\Commerce\QuoteException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') { throw new LogicException('Promotion races require test MySQL.'); }
    $directory = getenv('VASEY_PROMOTION_RACE_DIRECTORY'); $worker = getenv('VASEY_PROMOTION_RACE_WORKER');
    $mediaRoot = getenv('VASEY_PROMOTION_RACE_MEDIA_ROOT');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true) || ! is_string($mediaRoot) || ! is_dir($mediaRoot)) {
        throw new LogicException('Missing isolated promotion race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 16384), true, 16, JSON_THROW_ON_ERROR);
    config(['commerce.test_pricing_policy' => null, 'commerce.test_promotions' => json_encode([$input['policy']], JSON_THROW_ON_ERROR),
        'filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local');
    \Illuminate\Support\Carbon::setTestNow($input['now']); \Carbon\CarbonImmutable::setTestNow($input['now']);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
    $passed = false;
    DB::connection()->beforeExecuting(function (string $query) use ($input, $directory, $worker, $connectionId, &$passed): void {
        if ($passed || ! preg_match('/\Aselect\b/i', $query) || ! str_contains($query, 'from `'.$input['barrier'].'`') || ! str_contains($query, 'for update')) { return; }
        $passed = true;
        if (file_put_contents($directory.'/ready-'.$worker, (string) $connectionId) === false) { throw new RuntimeException('Cannot signal promotion barrier.'); }
        $deadline = microtime(true) + 22;
        do {
            clearstatcache(true, $directory.'/release');
            if (is_file($directory.'/release')) { return; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Promotion barrier timed out.');
    });
    try {
        if ($input['action'] === 'attempt') {
            $use = app(PromotionUsage::class)->beginAttempt($input['quote'], $input['owner'], $input['attempt']);
            $result = ['result' => 'ok', 'effect_id' => $use->id, 'fingerprint' => $use->attempt_id];
        } else {
            $pricing = app(PriceQuote::class)->createWithPromotion($input['quote'], $input['owner'], 'SYNTHETIC');
            $result = ['result' => 'ok', 'effect_id' => $pricing->public_id, 'fingerprint' => $pricing->snapshot_hash];
        }
    } catch (QuoteException $e) {
        $result = ['result' => 'rejected', 'code' => $e->errorCode, 'status' => $e->status];
    }
    if (! $passed) { throw new LogicException('Promotion operation did not reach its intended barrier.'); }
    echo json_encode($result + ['pid' => getmypid()], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $e) {
    echo json_encode(['result' => 'worker_failed', 'exception' => $e::class], JSON_THROW_ON_ERROR);
    exit(1);
}
