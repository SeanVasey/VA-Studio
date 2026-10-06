<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Customers\CustomerPurchaseClaims;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ContractFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class CustomerPurchaseClaimConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Purchase claim races require independent MySQL sessions and exact row waits.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        CustomerFixtures::configure();
        DeliveryFixtures::configure();
        config(['customer.test_purchase_claims_enabled' => true]);
    }

    public function test_competing_accounts_serialize_at_the_exact_order_and_only_one_claim_wins(): void
    {
        [$paid, $inputs, $accounts] = $this->fixtures(false);
        $results = $this->runWorkers($inputs, 'orders', $paid['order']->id);
        $outcomes = array_column($results, 'result');
        sort($outcomes);
        $this->assertSame(['denied', 'saved'], $outcomes);
        $this->assertDatabaseCount('customer_purchase_claims', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'customer.test_purchase.saved')->count());
        foreach ($results as $result) {
            $this->assertSame(['users', 'customer_accounts', 'orders', 'customer_purchase_challenges', 'customer_purchase_claims'], array_slice($result['locks'], 0, 5));
        }
    }

    public function test_simultaneous_exact_retry_returns_one_immutable_claim_and_one_audit(): void
    {
        [$paid, $inputs, $accounts] = $this->fixtures(true);
        $results = $this->runWorkers($inputs, 'users', $accounts[0]['user']->id);
        $this->assertSame(['saved', 'saved'], array_column($results, 'result'));
        $this->assertCount(1, array_unique(array_column($results, 'claim')));
        $this->assertDatabaseCount('customer_purchase_claims', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'customer.test_purchase.saved')->count());
    }

    public function test_withdrawal_at_the_exact_user_lock_prevents_claim_commit(): void
    {
        [$paid, $inputs, $accounts] = $this->fixtures(true);
        $results = $this->runWorkers([$inputs[0]], 'users', $accounts[0]['user']->id, function () use ($accounts): void {
            $account = $accounts[0]['account']->newQuery()->whereKey($accounts[0]['account']->id)->lockForUpdate()->firstOrFail();
            $account->update(['active' => false, 'access_version' => $account->access_version + 1]);
        });
        $this->assertSame('denied', $results[0]['result']);
        $this->assertSame(['users', 'customer_accounts'], $results[0]['locks']);
        $this->assertDatabaseCount('customer_purchase_claims', 0);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'customer.test_purchase.saved')->count());
    }

    private function fixtures(bool $same): array
    {
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $paid = DeliveryFixtures::ready($gateway);
        $first = CustomerFixtures::account();
        $accounts = [$first, $same ? $first : CustomerFixtures::account()];
        $claims = app(CustomerPurchaseClaims::class);
        $inputs = [];
        foreach ($accounts as $index => $account) {
            $p = $account['principal'];
            $marker = $same && $index === 1 ? $inputs[0]['marker'] : $claims->bind($claims->stage($paid['order']->public_id, $paid['order']->owner_key), $p);
            $inputs[] = ['order' => $paid['order']->public_id, 'marker' => $marker, 'user' => $p->userId,
                'principal' => [$p->accountId, $p->userId, $p->ownerKey, $p->accessVersion, $p->credentialStamp], 'at' => now()->toIso8601String()];
        }

        return [$paid, $inputs, $accounts];
    }

    private function runWorkers(array $inputs, ?string $table = null, ?int $rowId = null, ?callable $mutate = null): array
    {
        $directory = storage_path('framework/testing/purchase-claim-'.Str::uuid());
        $fs = new Filesystem;
        $fs->makeDirectory($directory, 0700, true);
        $db = DB::connection()->getConfig();
        $processes = [];
        $ready = [];
        try {
            foreach ($inputs as $index => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/customer-purchase-claim-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
                    'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_PURCHASE_CLAIM_RACE' => $directory, 'VASEY_PURCHASE_CLAIM_WORKER' => (string) $index,
                ], json_encode($input, JSON_THROW_ON_ERROR), 40);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $index => $process) {
                $this->until(fn () => is_file($directory.'/ready-'.$index), $processes);
                $ready[] = json_decode(file_get_contents($directory.'/ready-'.$index), true, 8, JSON_THROW_ON_ERROR);
            }
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertCount(count($ready) + 1, array_unique([...array_column($ready, 'connection'), $parent]));
            $this->assertCount(count($ready) + 1, array_unique([...array_column($ready, 'pid'), getmypid()]));
            if ($table) {
                DB::beginTransaction();
                DB::table($table)->where('id', $rowId)->lockForUpdate()->first();
            }
            touch($directory.'/start');
            if ($table) {
                foreach ($ready as $worker) {
                    $this->until(fn () => $this->waiting($worker['connection'], $parent, $table, $rowId), $processes);
                }
                if ($mutate) {
                    $mutate();
                }
                DB::commit();
            }
            $results = [];
            foreach ($processes as $index => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
                $this->assertSame($ready[$index]['connection'], $result['connection']);
                $this->assertSame($ready[$index]['pid'], $result['pid']);
                $this->assertSame(0, $result['transaction_level']);
                $this->assertNull($result['error']);
                $results[] = $result;
            }

            return $results;
        } finally {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $fs->deleteDirectory($directory);
        }
    }

    private function waiting(int $requester, int $blocker, string $table, int $rowId): bool
    {
        return DB::selectOne("SELECT requested.LOCK_STATUS AS status FROM performance_schema.data_lock_waits AS waits
            JOIN performance_schema.threads AS requester ON requester.THREAD_ID=waits.REQUESTING_THREAD_ID
            JOIN performance_schema.threads AS blocker ON blocker.THREAD_ID=waits.BLOCKING_THREAD_ID
            JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID=waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE=waits.ENGINE
            WHERE waits.ENGINE='INNODB' AND requester.PROCESSLIST_ID=? AND blocker.PROCESSLIST_ID=? AND requested.OBJECT_SCHEMA=?
            AND requested.OBJECT_NAME=? AND requested.INDEX_NAME='PRIMARY' AND requested.LOCK_TYPE='RECORD' AND requested.LOCK_STATUS='WAITING' AND requested.LOCK_DATA=? LIMIT 1",
            [$requester, $blocker, DB::connection()->getConfig('database'), $table, (string) $rowId])?->status === 'WAITING';
    }

    private function until(callable $condition, array $processes): void
    {
        $deadline = microtime(true) + 15;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            foreach ($processes as $process) {
                $process->checkTimeout();
                $this->assertTrue($process->isRunning(), $process->getOutput().$process->getErrorOutput());
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Purchase claim worker missed the exact barrier.');
    }
}
