<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\Models\InventoryReservation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\InventoryFixtures as F;
use Tests\TestCase;

class SharedInventoryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Independent-process shared inventory requires MySQL.'); }
    }

    public static function races(): array
    {
        return array_map(fn ($name) => [$name], ['variants', 'same_quote', 'expired_hold', 'pending_hold',
            'same_attempt', 'different_attempt', 'admin_hold', 'expiry_vs_attempt', 'multiple_scopes']);
    }

    #[DataProvider('races')]
    public function test_independent_connections_serialize_shared_inventory(string $scenario): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure();
        $a = F::selection(); $b = F::selection($a['scope']);
        $service = app(ReserveQuoteInventory::class); $old = null;
        if (in_array($scenario, ['expired_hold', 'pending_hold', 'same_attempt', 'different_attempt', 'expiry_vs_attempt'], true)) {
            $old = $service->hold($a['quote']->public_id, F::OWNER);
        }
        if ($scenario === 'pending_hold') { $service->beginAttempt($a['quote']->public_id, F::OWNER, (string) Str::uuid()); }
        $first = $a; $second = $b;
        if (in_array($scenario, ['expired_hold', 'pending_hold'], true)) {
            $this->travelTo($old->expires_at); $first = $b; $second = F::selection($a['scope']);
        }
        if ($scenario === 'expiry_vs_attempt') { $this->travelTo($old->expires_at); }
        if ($scenario === 'multiple_scopes') {
            $c = F::selection(); $d = F::selection($c['scope']);
            $first['quote'] = app(CreateQuote::class)->handle(F::OWNER, (string) Str::uuid(), [...$a['items'], ...$c['items']]);
            $second['quote'] = app(CreateQuote::class)->handle(F::OWNER, (string) Str::uuid(), [...$d['items'], ...$b['items']]);
        }
        $same = in_array($scenario, ['same_quote', 'same_attempt', 'different_attempt'], true);
        if ($same) { $second = $first; }
        $attempt = (string) Str::uuid(); $inputs = [];
        foreach ([$first, $second] as $index => $fixture) {
            $action = in_array($scenario, ['same_attempt', 'different_attempt'], true) || ($scenario === 'expiry_vs_attempt' && $index === 0) ? 'attempt' : 'hold';
            if ($scenario === 'admin_hold' && $index === 1) { $action = 'block'; }
            $inputs[] = ['quote' => $fixture['quote']->public_id, 'owner' => F::OWNER, 'policy' => F::policy(),
                'action' => $action, 'attempt' => $scenario === 'different_attempt' && $index === 1 ? (string) Str::uuid() : $attempt,
                'scope' => $a['scope']->id, 'actor' => $a['actor']->id,
                'now' => $scenario === 'expiry_vs_attempt' && $index === 0 ? $old->expires_at->subSecond()->toIso8601ZuluString() : now()->toIso8601ZuluString(),
                'barrier' => $same ? 'quotes' : 'rights_scopes'];
        }
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/inventory-race-'.Str::uuid());
        $filesystem = new Filesystem; $filesystem->makeDirectory($directory, 0700, true); $processes = [];
        try {
            foreach ($inputs as $index => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/inventory-race-worker.php')], base_path(),
                    $this->environment($directory, $index), json_encode($input, JSON_THROW_ON_ERROR), 35);
                $process->start(); $processes[] = $process;
            }
            $paths = [$directory.'/ready-0', $directory.'/ready-1']; $deadline = microtime(true) + 20;
            do {
                clearstatcache();
                if (count(array_filter($paths, 'is_file')) === 2) { break; }
                foreach ($processes as $process) {
                    if (! $process->isRunning()) { $this->fail('Inventory worker exited: '.$process->getOutput()); }
                    $process->checkTimeout();
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            foreach ($paths as $path) { $this->assertFileExists($path, 'Worker missed the shared lock barrier.'); }
            $connections = array_map(fn ($path) => (int) file_get_contents($path), $paths);
            $connections[] = (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
            $this->assertCount(3, array_unique($connections)); touch($directory.'/release'); $results = [];
            foreach ($processes as $process) {
                $process->wait(); $this->assertSame(0, $process->getExitCode(), $process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            }
            $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            $outcomes = array_column($results, 'result'); sort($outcomes);
            if ($scenario === 'admin_hold') {
                $this->assertSame('ok', $results[1]['result']);
                $this->assertDatabaseHas('rights_scopes', ['id' => $a['scope']->id, 'blocked' => true, 'control_version' => 1]);
                try { $service->hold($first['quote']->public_id, F::OWNER); $this->fail('Admin hold was bypassed.'); }
                catch (\App\Domain\Commerce\QuoteException $e) { $this->assertSame('INVENTORY_BLOCKED', $e->errorCode); }
            } else {
                $this->assertSame(match ($scenario) {
                    'same_quote', 'same_attempt' => ['ok', 'ok'], 'pending_hold' => ['rejected', 'rejected'],
                    default => ['ok', 'rejected'],
                }, $outcomes);
                foreach ($results as $result) {
                    if ($result['result'] === 'rejected') {
                        $this->assertContains($result['code'], $scenario === 'different_attempt' ? ['INVENTORY_ATTEMPT_CONFLICT'] :
                            ($scenario === 'expiry_vs_attempt' ? ['INVENTORY_EXPIRED', 'INVENTORY_UNAVAILABLE'] : ['INVENTORY_UNAVAILABLE']));
                    }
                }
                if (in_array($scenario, ['same_quote', 'same_attempt'], true)) { $this->assertSame($results[0]['effect_id'], $results[1]['effect_id']); }
                $active = DB::table('inventory_reservations')->join('inventory_claims', 'inventory_reservations.id', '=', 'inventory_claims.inventory_reservation_id')
                    ->where('inventory_claims.rights_scope_id', $a['scope']->id)
                    ->where(fn ($query) => $query->where('state', 'pending')->orWhere(fn ($held) => $held->where('state', 'held')->where('expires_at', '>', now())))->count();
                $this->assertSame(1, $active);
            }
            if ($scenario === 'expired_hold') { $this->assertSame('expired', $old->refresh()->state); }
            if ($scenario === 'pending_hold') { $this->assertSame('pending', $old->refresh()->state); }
            $count = InventoryReservation::count();
            $this->assertSame($count, DB::table('audit_events')->where('action', 'commerce.inventory.held')->count());
            $this->assertSame(InventoryReservation::where('state', 'pending')->count(), DB::table('audit_events')->where('action', 'commerce.inventory.pending')->count());
        } finally {
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(1); } }
            $filesystem->deleteDirectory($directory);
        }
    }

    private function environment(string $directory, int $worker): array
    {
        $db = DB::connection()->getConfig();

        return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VASEY_INVENTORY_RACE_DIRECTORY' => $directory, 'VASEY_INVENTORY_RACE_WORKER' => (string) $worker,
            'VASEY_INVENTORY_RACE_MEDIA_ROOT' => Storage::disk('local')->path('')];
    }
}
