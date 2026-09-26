<?php

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\QuoteException;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\PaymentFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') { throw new LogicException('Finalization race requires test MySQL.'); }
    $directory = getenv('VASEY_FINALIZATION_RACE_DIRECTORY'); $worker = getenv('VASEY_FINALIZATION_RACE_WORKER');
    $mediaRoot = getenv('VASEY_FINALIZATION_RACE_MEDIA_ROOT');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true) || ! is_string($mediaRoot) || ! is_dir($mediaRoot)) {
        throw new LogicException('Missing finalization race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 32768), true, 32, JSON_THROW_ON_ERROR);
    FinalizationFixtures::configure();
    config(['filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local');
    \Illuminate\Support\Carbon::setTestNow($input['now']); \Carbon\CarbonImmutable::setTestNow($input['now']);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
    $waitFor = function (string $path): void {
        $deadline = microtime(true) + 35;
        do { clearstatcache(true, $path); if (is_file($path)) { return; } usleep(10000); }
        while (microtime(true) < $deadline);
        throw new RuntimeException('Finalization worker barrier timed out.');
    };
    $gateway = PaymentFixtures::gateway();
    $app->instance(StripeCheckoutGateway::class, $gateway); $app->instance(StripePaymentGateway::class, $gateway);
    $isMutex = fn (string $sql): bool => preg_match('/\Aselect\b/i', $sql) && str_contains($sql, 'from `'.$input['mutex'].'`') && str_contains($sql, 'for update');
    $attempted = false; $locked = false;
    DB::connection()->beforeExecuting(function (string $sql) use ($isMutex, $directory, $worker, &$attempted): void {
        if (! $attempted && $isMutex($sql)) { $attempted = true; touch($directory.'/attempting-'.$worker); }
    });
    DB::listen(function ($query) use ($isMutex, $directory, $worker, $waitFor, &$locked): void {
        if (! $locked && $isMutex($query->sql)) {
            $locked = true;
            if (DB::transactionLevel() < 1) { throw new LogicException('Mutex was acquired outside a transaction.'); }
            touch($directory.'/locked-'.$worker);
            if ($worker === '0') { $waitFor($directory.'/release'); }
        }
    });
    file_put_contents($directory.'/ready-'.$worker, (string) $connectionId); $waitFor($directory.'/start-'.$worker);
    try {
        $result = match ($input['operation']) {
            'finalize' => app(FinalizeTestPayment::class)->handle($input['payment_id']),
            'block' => (function () use ($input): string {
                app(ManageRightsScope::class)->block($input['scope_id'], true, 0, 'SYNTHETIC-CONCURRENT-BLOCK', User::findOrFail($input['actor_id']));
                return 'blocked';
            })(),
            'read_inventory' => (function () use ($input): string {
                app(ReserveQuoteInventory::class)->read($input['quote_id'], InventoryFixtures::OWNER);
                return 'reserved';
            })(),
        };
    } catch (QuoteException $error) { $result = $error->errorCode; }
    if (! $attempted || ! $locked) { throw new LogicException('Finalization race missed the intended mutex.'); }
    echo json_encode(['outcome' => $result, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel(),
        'provider_calls' => $gateway->calls], JSON_THROW_ON_ERROR); exit(0);
} catch (Throwable $error) {
    echo json_encode(['outcome' => 'worker_failed', 'exception' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR); exit(1);
}
