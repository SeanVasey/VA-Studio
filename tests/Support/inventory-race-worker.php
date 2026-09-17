<?php

use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\QuoteException;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') { throw new LogicException('Inventory race requires test MySQL.'); }
    $directory = getenv('VASEY_INVENTORY_RACE_DIRECTORY'); $worker = getenv('VASEY_INVENTORY_RACE_WORKER');
    $mediaRoot = getenv('VASEY_INVENTORY_RACE_MEDIA_ROOT');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true) || ! is_string($mediaRoot) || ! is_dir($mediaRoot)) {
        throw new LogicException('Missing isolated inventory race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 16384), true, 16, JSON_THROW_ON_ERROR);
    config(['commerce.test_inventory_policy' => json_encode($input['policy'], JSON_THROW_ON_ERROR),
        'filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local'); \Illuminate\Support\Carbon::setTestNow($input['now']); \Carbon\CarbonImmutable::setTestNow($input['now']);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id; $passed = false;
    DB::connection()->beforeExecuting(function (string $query) use ($input, $directory, $worker, $connectionId, &$passed): void {
        if ($passed || ! preg_match('/\Aselect\b/i', $query) || ! str_contains($query, 'from `'.$input['barrier'].'`') || ! str_contains($query, 'for update')) { return; }
        $passed = true;
        if (file_put_contents($directory.'/ready-'.$worker, (string) $connectionId) === false) { throw new RuntimeException('Cannot signal inventory barrier.'); }
        $deadline = microtime(true) + 25;
        do {
            clearstatcache(true, $directory.'/release'); if (is_file($directory.'/release')) { return; } usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Inventory barrier timed out.');
    });
    try {
        $service = app(ReserveQuoteInventory::class);
        $effect = match ($input['action']) {
            'attempt' => $service->beginAttempt($input['quote'], $input['owner'], $input['attempt']),
            'block' => app(ManageRightsScope::class)->block($input['scope'], true, 0, 'SYNTHETIC-RACE-HOLD', User::findOrFail($input['actor'])),
            default => $service->hold($input['quote'], $input['owner']),
        };
        $result = ['result' => 'ok', 'effect_id' => $effect->public_id];
    } catch (QuoteException $error) { $result = ['result' => 'rejected', 'code' => $error->errorCode]; }
    if (! $passed) { throw new LogicException('Operation missed the intended shared lock.'); }
    echo json_encode($result + ['pid' => getmypid()], JSON_THROW_ON_ERROR); exit(0);
} catch (Throwable $error) {
    echo json_encode(['result' => 'worker_failed', 'exception' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR); exit(1);
}
