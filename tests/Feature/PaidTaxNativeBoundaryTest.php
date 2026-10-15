<?php

namespace Tests\Feature;

use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Domain\Grants\PaidTax\PaidTaxRecords;
use App\Domain\Grants\PaidTax\PaidTaxSchema;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\TestCase;

class PaidTaxNativeBoundaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidTaxGrantFixtures;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePaidTaxFixture();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($this->workers as $worker) {
            $worker['process']->stop(0);
            (new Filesystem)->deleteDirectory($worker['directory']);
        }
        parent::tearDown();
    }

    public function test_native_provider_replays_serialize_one_origin_and_refuse_temporary_or_nontransactional_owned_objects(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL collation can conflate byte-distinct provider identities.');
        }
        $first = $this->paidTaxOrder();
        $second = $this->secondPaidTaxOrder($first, 'cs_test_synthetictax', 'pi_synthetictax');
        $grants = new PaidTaxGrants;
        $a = $grants->finalize($first['buyer']['principal'], $first['buyer']['user'], $first['order']['orderId']);
        $this->assertNativeObjectsRefuseBeforeOwnedDml($second);
        $input = ['order_id' => $second['order']['orderId'], 'identity' => config('production-customer-identity'),
            'tax' => config('production-tax-checkout'), 'consumer' => config('paid-tax-grants'), 'private_root' => Storage::disk('local')->path('')];
        $one = $this->start($input);
        $two = $this->start($input);
        $parent = (int) DB::getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->assertNotSame($one['connection_id'], $two['connection_id']);
        DB::beginTransaction();
        DB::table('users')->where('id', $first['buyer']['user']->id)->lockForUpdate()->first();
        foreach ([$one, $two] as $worker) {
            touch($worker['directory'].'/start');
            $this->await(fn (): bool => $this->waiting($worker['connection_id'], $parent, $first['buyer']['user']->id), $worker['process']);
        }
        DB::commit();
        $results = [$this->finish($one), $this->finish($two)];
        $this->assertSame(['saved', 'saved'], array_column($results, 'result'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame([$second['order']['orderId'], $second['order']['orderId']], array_column($results, 'order_id'));
        $b = null;
        $collision = null;
        try {
            $b = $grants->finalize($second['buyer']['principal'], $second['buyer']['user'], $second['order']['orderId']);
        } catch (\PDOException $error) {
            $collision = $error;
        }
        $this->assertNull($collision, 'Byte-distinct, verified provider payments collided in the owned consumer schema.');
        $this->assertNotSame($a['id'], $b['id']);
        $this->assertSame([$first['order']['orderId'], $second['order']['orderId']], [$a['orderId'], $b['orderId']]);
        $this->assertSame($a, $grants->finalize($first['buyer']['principal'], $first['buyer']['user'], $first['order']['orderId']));
        $this->assertSame($b, $grants->finalize($second['buyer']['principal'], $second['buyer']['user'], $second['order']['orderId']));
        $rows = DB::table('paid_tax_order_origins')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $this->assertSame(['pi_SYNTHETICTAX', 'pi_synthetictax'], array_column($rows, 'provider_payment_id'));
        $this->assertSame(['pi_SYNTHETICTAX', 'pi_synthetictax'], array_map(fn (array $row): string => PaidTaxRecords::decode($row)['source_identity']['provider_payment_id'], $rows));
        $this->assertDatabaseCount('paid_tax_grant_origins', 2);
        $this->assertDatabaseCount('paid_order_origins', 0);
    }

    private function assertNativeObjectsRefuseBeforeOwnedDml(array $f): void
    {
        $snapshot = static function (): array {
            $rows = [];
            foreach (array_keys(PaidTaxSchema::specs()) as $table) {
                $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
            }

            return $rows;
        };
        $before = $snapshot();
        foreach (array_keys(PaidTaxSchema::specs()) as $table) {
            DB::unprepared('CREATE TEMPORARY TABLE `'.$table.'` (id BIGINT UNSIGNED NOT NULL PRIMARY KEY)');
            try {
                $this->refusesOrigin($f);
            } finally {
                DB::unprepared('DROP TEMPORARY TABLE `'.$table.'`');
            }
            $this->assertSame($before, $snapshot(), 'A temporary shadow allowed an owned write.');
        }
        DB::unprepared('ALTER TABLE paid_tax_fulfillments DROP FOREIGN KEY paid_tax_fulfillments_batch');
        DB::unprepared('ALTER TABLE paid_tax_fulfillments ENGINE=MyISAM');
        try {
            $this->refusesOrigin($f);
            $this->assertSame($before, $snapshot(), 'A nontransactional owned object allowed a write.');
        } finally {
            DB::unprepared('ALTER TABLE paid_tax_fulfillments ENGINE=InnoDB');
            DB::unprepared('ALTER TABLE paid_tax_fulfillments ADD CONSTRAINT paid_tax_fulfillments_batch FOREIGN KEY (batch_id) REFERENCES paid_tax_order_origins(id) ON DELETE RESTRICT');
        }
        PaidTaxSchema::assertInstalled();
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
    }

    private function refusesOrigin(array $f): void
    {
        try {
            (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('An unadmitted owned object reached consumer DML.');
        } catch (PaidTaxException $error) {
            $this->assertSame(503, $error->status);
        }
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
    }

    private function start(array $input): array
    {
        $directory = storage_path('framework/testing/paid-tax-origin-race-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $database = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VA_PAID_TAX_RACE_ONLY' => '1', 'VA_PAID_TAX_RACE_DIRECTORY' => $directory];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) $database[$key];
        }
        $process = new Process([PHP_BINARY, base_path('tests/Support/paid-tax-origin-race-worker.php')], base_path(), $environment, json_encode($input, JSON_THROW_ON_ERROR), 90);
        $process->start();
        $worker = compact('process', 'directory');
        $this->workers[] = $worker;
        $this->await(fn (): bool => is_file($directory.'/ready'), $process);
        $ready = json_decode(file_get_contents($directory.'/ready'), true, 8, JSON_THROW_ON_ERROR);
        $this->assertNotSame(getmypid(), $ready['pid']);

        return $worker + $ready;
    }

    private function finish(array $worker): array
    {
        $worker['process']->wait();
        $this->assertSame(0, $worker['process']->getExitCode(), 'The isolated native worker did not complete.');
        $result = json_decode($worker['process']->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        $this->assertNull($result['error_class']);
        $this->assertSame(0, $result['transaction_level']);
        $this->assertFalse($result['in_transaction']);
        $this->assertSame($worker['connection_id'], $result['connection_id']);

        return $result;
    }

    private function waiting(int $requester, int $blocker, int $userId): bool
    {
        $row = DB::selectOne(<<<'SQL'
SELECT requested.LOCK_DATA AS row_key FROM performance_schema.data_lock_waits waits
JOIN performance_schema.threads requester ON requester.THREAD_ID=waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads blocker ON blocker.THREAD_ID=waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID=waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE=waits.ENGINE
WHERE waits.ENGINE='INNODB' AND requester.PROCESSLIST_ID=? AND blocker.PROCESSLIST_ID=?
AND requested.OBJECT_SCHEMA=? AND requested.OBJECT_NAME='users' AND requested.INDEX_NAME='PRIMARY'
AND requested.LOCK_TYPE='RECORD' AND requested.LOCK_STATUS='WAITING' LIMIT 1
SQL, [$requester, $blocker, DB::getDatabaseName()]);

        return $row !== null && trim($row->row_key, "'\"") === (string) $userId;
    }

    private function await(callable $condition, Process $process): void
    {
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            $this->assertTrue($process->isRunning(), 'Native worker ended before its authenticated barrier.');
            $process->checkTimeout();
            usleep(10000);
        } while (hrtime(true) < $deadline);
        $this->fail('Native worker did not reach its exact original-buyer row wait.');
    }
}
