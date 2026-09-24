<?php

use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\QuoteException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') { throw new LogicException('Order race requires test MySQL.'); }
    $directory = getenv('VASEY_ORDER_RACE_DIRECTORY'); $worker = getenv('VASEY_ORDER_RACE_WORKER');
    $mediaRoot = getenv('VASEY_ORDER_RACE_MEDIA_ROOT');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true) || ! is_string($mediaRoot) || ! is_dir($mediaRoot)) {
        throw new LogicException('Missing isolated order race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 32768), true, 24, JSON_THROW_ON_ERROR);
    config(['commerce.test_inventory_policy' => json_encode($input['inventory_policy'], JSON_THROW_ON_ERROR),
        'commerce.test_pricing_policy' => json_encode($input['pricing_policy'], JSON_THROW_ON_ERROR),
        'commerce.test_order_policy' => json_encode($input['order_policy'], JSON_THROW_ON_ERROR),
        'commerce.test_promotions' => json_encode($input['promotions'], JSON_THROW_ON_ERROR),
        'commerce.test_exclusive_selection_policy' => json_encode($input['exclusive_policy'], JSON_THROW_ON_ERROR),
        'filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local'); \Illuminate\Support\Carbon::setTestNow($input['now']); \Carbon\CarbonImmutable::setTestNow($input['now']);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id; $passed = false;
    DB::connection()->beforeExecuting(function (string $query) use ($input, $directory, $worker, $connectionId, &$passed): void {
        // INSERT IGNORE can acquire a mutex lock too. Never wait behind one before both workers are ready.
        if ($passed || ! str_contains($query, '`quote_owners`')) { return; }
        $passed = true;
        if (file_put_contents($directory.'/ready-'.$worker, (string) $connectionId) === false) { throw new RuntimeException('Cannot signal order barrier.'); }
        $deadline = microtime(true) + 25;
        do {
            clearstatcache(true, $directory.'/release');
            if (is_file($directory.'/release')) {
                if (isset($input['after_release_now'])) {
                    \Illuminate\Support\Carbon::setTestNow($input['after_release_now']);
                    \Carbon\CarbonImmutable::setTestNow($input['after_release_now']);
                }
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Order barrier timed out.');
    });
    try {
        $order = app(PrepareOrder::class)->handle($input['owner'], $input['key'], $input['request']);
        $result = ['result' => 'ok', 'effect_id' => $order->public_id];
    } catch (QuoteException $error) { $result = ['result' => 'rejected', 'code' => $error->errorCode]; }
    if (! $passed) { throw new LogicException('Operation missed the intended owner mutex.'); }
    echo json_encode($result + ['pid' => getmypid()], JSON_THROW_ON_ERROR); exit(0);
} catch (Throwable $error) {
    // All inputs are synthetic; avoid reflecting raw exception messages or the submitted identity.
    echo json_encode(['result' => 'worker_failed', 'exception' => $error::class], JSON_THROW_ON_ERROR); exit(1);
}
