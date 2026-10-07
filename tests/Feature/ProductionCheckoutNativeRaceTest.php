<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

class ProductionCheckoutNativeRaceTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Actual independent MySQL connections and InnoDB row waits required.');
        }
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('j', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true]);
        Queue::fake();
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

    public static function races(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('races')]
    public function test_competing_assent_waits_on_exact_original_buyer_and_preserves_one_order(bool $conflict): void
    {
        $f = $this->payable(false);
        $input = ['review_id' => $f['review']['reviewId'], 'review_hash' => $f['review']['reviewHash'],
            'key' => 'synthetic-native-order', 'owner_ids' => [$f['catalog']['actor']->id], 'private_root' => Storage::disk('local')->path('')];
        $one = $this->start($input);
        $two = $this->start([...$input, 'review_hash' => $conflict ? str_repeat('a', 64) : $input['review_hash']]);
        $parent = (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
        DB::beginTransaction();
        DB::table('users')->where('id', $f['buyer']['user']->id)->lockForUpdate()->first();
        foreach ([$one, $two] as $worker) {
            touch($worker['directory'].'/start');
            $this->await(fn (): bool => $this->waiting($worker['connection_id'], $parent, $f['buyer']['user']->id), $worker['process']);
        }
        DB::commit();
        $results = [$this->finish($one), $this->finish($two)];
        $kinds = array_column($results, 'result');
        sort($kinds);
        $this->assertSame($conflict ? ['denied', 'saved'] : ['saved', 'saved'], $kinds);
        if (! $conflict) {
            $this->assertSame($results[0]['order_id'], $results[1]['order_id']);
        }
        $this->assertDatabaseCount(CheckoutSchema::TABLES['order'], 1);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['line'], 1);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['attempt'], 1);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 0);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }

    private function start(array $input): array
    {
        $directory = storage_path('framework/testing/production-checkout-race-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $database = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'VA_CHECKOUT_RACE_ONLY' => '1', 'VA_CHECKOUT_RACE_DIRECTORY' => $directory];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) $database[$key];
        }
        $process = new Process([PHP_BINARY, base_path('tests/Support/production-checkout-race-worker.php')], base_path(), $environment, json_encode($input, JSON_THROW_ON_ERROR), 35);
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
        $this->assertSame(0, $worker['process']->getExitCode(), 'Synthetic native worker failed; no private provider output is shown.');
        $result = json_decode($worker['process']->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        $this->assertNull($result['error_class']);
        $this->assertSame(0, $result['transaction_level']);
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
        $deadline = microtime(true) + 15;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            $this->assertTrue($process->isRunning(), 'Worker ended before the native record barrier.');
            $process->checkTimeout();
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Worker did not reach the exact original-buyer row wait.');
    }
}
