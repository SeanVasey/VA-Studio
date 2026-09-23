<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\PrepareExclusiveOffer;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Commerce\Models\RightsScopeOffer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ExclusiveOfferFixtures as F;
use Tests\Support\InventoryFixtures;
use Tests\TestCase;

class ExclusiveOfferConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Independent-process exclusive preparation requires MySQL.'); }
    }

    public static function races(): array
    {
        return [['same_offer'], ['shared_scope_variants'], ['administrative_block']];
    }

    #[DataProvider('races')]
    public function test_independent_connections_preserve_preparation_identity_and_scope_controls(string $scenario): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond());
        $a = F::draft(); $b = $scenario === 'shared_scope_variants' ? F::draft($a['scope']) : $a;
        $directory = storage_path('framework/testing/exclusive-offer-race-'.Str::uuid());
        $filesystem = new Filesystem; $filesystem->makeDirectory($directory, 0700, true); $processes = [];
        $this->assertSame(0, DB::transactionLevel());
        try {
            foreach ([$a, $b] as $index => $f) {
                $input = ['action' => $scenario === 'administrative_block' && $index === 1 ? 'block' : 'prepare',
                    'offer' => $f['offer']->id, 'scope' => $f['scope']->id, 'actor' => $f['actor']->id,
                    'policy' => InventoryFixtures::policy(), 'now' => now()->toIso8601ZuluString(),
                    'barrier' => $scenario === 'same_offer' ? 'tracks' : 'rights_scopes'];
                $process = new Process([PHP_BINARY, base_path('tests/Support/inventory-race-worker.php')], base_path(),
                    $this->environment($directory, $index), json_encode($input, JSON_THROW_ON_ERROR), 35);
                $process->start(); $processes[] = $process;
            }
            $paths = [$directory.'/ready-0', $directory.'/ready-1']; $deadline = microtime(true) + 20;
            do {
                clearstatcache();
                if (count(array_filter($paths, 'is_file')) === 2) { break; }
                foreach ($processes as $process) {
                    if (! $process->isRunning()) { $this->fail('Exclusive worker exited: '.$process->getOutput()); }
                    $process->checkTimeout();
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            foreach ($paths as $path) { $this->assertFileExists($path, 'Worker missed its lock barrier.'); }
            $connections = array_map(fn ($path) => (int) file_get_contents($path), $paths);
            $connections[] = (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
            $this->assertCount(3, array_unique($connections)); touch($directory.'/release'); $results = [];
            foreach ($processes as $process) {
                $process->wait(); $this->assertSame(0, $process->getExitCode(), $process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            }
            $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            if ($scenario === 'administrative_block') {
                $this->assertSame('ok', $results[1]['result']);
                $this->assertContains($results[0]['result'], ['ok', 'rejected']);
                if ($results[0]['result'] === 'rejected') {
                    $this->assertSame('EXCLUSIVE_PREPARATION_BLOCKED', $results[0]['code']);
                    $this->assertNull($a['offer']->refresh()->current_revision_id);
                }
                $this->assertDatabaseHas('rights_scopes', ['id' => $a['scope']->id, 'blocked' => true]);
                try {
                    app(PrepareExclusiveOffer::class)->handle($a['offer'], $a['scope']->id, 'SYNTHETIC-RACE-LINK', $a['actor']);
                    $this->fail('Committed scope block was bypassed.');
                } catch (ValidationException) {}
            } else {
                $this->assertSame(['ok', 'ok'], array_column($results, 'result'));
                if ($scenario === 'same_offer') { $this->assertSame($results[0]['effect_id'], $results[1]['effect_id']); }
                else { $this->assertNotSame($results[0]['effect_id'], $results[1]['effect_id']); }
                foreach ([$a, $b] as $f) {
                    $revision = $f['offer']->refresh()->currentRevision;
                    $this->assertSame(1, $revision->revision);
                    $this->assertSame([], app(PublicationReadiness::class)->preparedExclusiveBlockers($f['offer'], $revision));
                    $this->assertSame($f['scope']->id, $revision->snapshot['inventory']['scope_id']);
                }
            }
            $prepared = OfferRevision::where('offer_id', $a['offer']->id)->count();
            if ($scenario === 'shared_scope_variants') { $prepared += OfferRevision::where('offer_id', $b['offer']->id)->count(); }
            $this->assertSame($prepared, RightsScopeOffer::count());
            $this->assertSame($prepared, DB::table('audit_events')->where('action', 'catalog.offer.exclusive_prepared')->count());
            foreach ([$a, $b] as $f) { $this->assertFalse($f['offer']->refresh()->is_active); }
            $this->assertDatabaseCount('inventory_reservations', 0); $this->assertDatabaseCount('inventory_claims', 0);
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
