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
    if (isset($input['promotion'])) { config(['commerce.test_promotions' => json_encode([$input['promotion']], JSON_THROW_ON_ERROR)]); }
    if (isset($input['exclusive_policy'])) { config(['commerce.test_exclusive_selection_policy' => json_encode($input['exclusive_policy'], JSON_THROW_ON_ERROR)]); }
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
            'priced_hold' => app(\App\Domain\Commerce\ReservePricedQuote::class)->hold($input['quote'], $input['owner'], $input['promotion']['code'] ?? null)['reservation'],
            'priced_attempt' => app(\App\Domain\Commerce\ReservePricedQuote::class)->beginAttempt($input['quote'], $input['owner'], $input['attempt'])['reservation'],
            'prepare' => app(\App\Domain\Catalog\PrepareExclusiveOffer::class)->handle(\App\Domain\Catalog\Models\Offer::findOrFail($input['offer']), $input['scope'], 'SYNTHETIC-RACE-LINK', User::findOrFail($input['actor'])),
            'activate' => app(\App\Domain\Catalog\ActivateExclusiveOffer::class)->handle(\App\Domain\Catalog\Models\Offer::findOrFail($input['offer']), $input['revision'], User::findOrFail($input['actor'])),
            'publish_successor' => app(\App\Domain\Catalog\PublishOffer::class)->handle(app(\App\Domain\Catalog\SaveOfferDraft::class)->handle(
                \App\Domain\Catalog\Models\Offer::findOrFail($input['offer']), ['price_minor' => 5555], User::findOrFail($input['actor'])), User::findOrFail($input['actor'])),
            'attempt' => $service->beginAttempt($input['quote'], $input['owner'], $input['attempt']),
            'block' => app(ManageRightsScope::class)->block($input['scope'], true, 0, 'SYNTHETIC-RACE-HOLD', User::findOrFail($input['actor'])),
            default => $service->hold($input['quote'], $input['owner']),
        };
        $result = ['result' => 'ok', 'effect_id' => in_array($input['action'], ['prepare', 'activate', 'publish_successor'], true) ? $effect->id : $effect->public_id];
    } catch (\Illuminate\Validation\ValidationException $error) { $result = ['result' => 'rejected', 'code' => 'EXCLUSIVE_PREPARATION_BLOCKED'];
    } catch (QuoteException $error) { $result = ['result' => 'rejected', 'code' => $error->errorCode]; }
    if (! $passed) { throw new LogicException('Operation missed the intended shared lock.'); }
    echo json_encode($result + ['pid' => getmypid()], JSON_THROW_ON_ERROR); exit(0);
} catch (Throwable $error) {
    echo json_encode(['result' => 'worker_failed', 'exception' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR); exit(1);
}
