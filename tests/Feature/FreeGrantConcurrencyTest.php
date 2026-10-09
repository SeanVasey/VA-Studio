<?php

namespace Tests\Feature;

use App\Domain\Grants\Free\FreeGrants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CustomerFixtures;
use Tests\Support\DisposableNativeDatabase;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FreeGrantFixtures;
use Tests\TestCase;

final class FreeGrantConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent InnoDB capacity contention requires native MySQL.');
        }
        $this->assertTrue(DisposableNativeDatabase::isAdmitted('vaseyaudio_free_grants'), 'A dedicated synthetic free-grant schema, or the CI job\'s disposable database, is required.');
    }

    public function test_observed_two_buyer_scope_wait_cannot_overfill_one_explicit_free_origin_capacity(): void
    {
        $this->fakePrivateMediaStorage();
        $f = FreeGrantFixtures::source();
        $d = FreeGrantFixtures::publish($f, [...$f['input'], 'maxOrigins' => 1]);
        $other = CustomerFixtures::account();
        $directory = storage_path('framework/testing/free-race-'.Str::uuid());
        mkdir($directory, 0700, true);
        $db = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'];
        $processes = [];
        DB::beginTransaction();
        DB::table('rights_scopes')->where('id', $f['scope']->id)->lockForUpdate()->sole();
        try {
            foreach ([$f['customer'], $other] as $index => $customer) {
                $declaredName = 'Synthetic capacity buyer '.$index;
                $request = ['requestKey' => (string) Str::uuid(), 'definitionHash' => $d['definitionHash'], 'reviewHash' => $d['reviewHash'],
                    'expectedVersion' => $d['version'], 'declaredName' => $declaredName, 'affirmed' => true, 'assentHash' => (new FreeGrants)->assentHash($d, $declaredName)];
                $process = new Process([PHP_BINARY, base_path('tests/Support/free-grant-race-worker.php')], base_path(), $environment,
                    json_encode(['userId' => $customer['user']->id, 'definitionId' => $d['id'], 'request' => $request, 'ready' => $directory.'/ready-'.$index], JSON_THROW_ON_ERROR), 60);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 20;
            $connections = [];
            do {
                clearstatcache();
                if (is_file($directory.'/ready-0') && is_file($directory.'/ready-1')) {
                    $connections = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1')];
                    $waiting = DB::select('SELECT DISTINCT t.PROCESSLIST_ID AS id FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE=w.ENGINE JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE l.OBJECT_SCHEMA=? AND l.OBJECT_NAME=? AND t.PROCESSLIST_ID IN (?,?)', [$db['database'], 'rights_scopes', ...$connections]);
                    if (count($waiting) === 2) {
                        break;
                    }
                }
                foreach ($processes as $p) {
                    $this->assertTrue($p->isRunning(), 'Worker must reach the observed scope lock barrier.');
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertCount(2, $connections);
            $this->assertCount(2, array_unique($connections));
            $this->assertCount(2, $waiting ?? [], 'Both independent workers must actually wait on the exact InnoDB scope row.');
            DB::commit();
            $results = [];
            foreach ($processes as $p) {
                $p->wait();
                $this->assertSame(0, $p->getExitCode());
                $results[] = json_decode($p->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([200, 409], $statuses);
            $this->assertDatabaseCount('free_origins', 1);
            $this->assertDatabaseCount('free_document_work', 1);
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('license_grants', 0);
            $winner = (array) DB::table('free_origins')->sole();
            $this->assertContains((int) $winner['account_id'], [$f['customer']['account']->id, $other['account']->id]);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop(1);
                }
            }
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            } rmdir($directory);
        }
    }
}
