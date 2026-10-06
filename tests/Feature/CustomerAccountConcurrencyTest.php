<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Delivery\IssueTestDelivery;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CustomerFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class CustomerAccountConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Customer withdrawal fences require independent MySQL sessions and exact row waits.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        F::configure();
        $this->travelTo(now()->startOfSecond());
    }

    public static function operations(): array
    {
        $cases = [];
        foreach (['create', 'price', 'review', 'prepare', 'checkout', 'issue', 'redeem'] as $operation) {
            $cases[$operation] = [$operation, 'account'];
        }
        $cases['sign-in / password reset'] = ['sign-in', 'password'];
        $cases['sign-in / account withdrawn'] = ['sign-in', 'account'];

        return $cases;
    }

    #[DataProvider('operations')]
    public function test_current_withdrawal_wins_exact_user_fence_before_every_customer_entrypoint(string $operation, string $withdraw): void
    {
        $f = F::account();
        $paid = F::ready($f['user']);
        $order = $paid['order'];
        $quote = Quote::findOrFail($order->quote_id);
        $input = ['operation' => $operation, 'user_id' => $f['user']->id,
            'principal' => [$f['principal']->accountId, $f['principal']->userId, $f['principal']->ownerKey, $f['principal']->accessVersion, $f['principal']->credentialStamp],
            'order_id' => $order->public_id, 'quote_id' => $quote->public_id, 'items' => $quote->request, 'key' => (string) Str::uuid(),
            'request' => app(ReadOrder::class)->verify($order)['request'], 'grant_id' => $paid['grant']->public_id,
            'media_root' => Storage::disk('local')->path(''), 'at' => now()->toIso8601String()];
        if ($operation === 'redeem') {
            $authorization = app(IssueTestDelivery::class)->handle($order->public_id, $f['principal']->ownerKey, $paid['grant']->public_id, 'contract', (string) Str::uuid(), $f['user'], $f['principal']);
            $input += ['authorization_id' => $authorization->authorizationId, 'token' => $authorization->token()];
        }
        $tables = ['quotes', 'quote_pricings', 'orders', 'checkout_intents', 'checkout_sessions', 'test_delivery_authorizations', 'test_delivery_redemptions', 'audit_events'];
        $counts = array_map(fn ($table) => DB::table($table)->count(), $tables);
        $directory = storage_path('framework/testing/customer-access-'.Str::uuid());
        $fs = new Filesystem;
        $fs->makeDirectory($directory, 0700, true);
        $process = null;
        $database = DB::connection()->getConfig();
        try {
            $process = new Process([PHP_BINARY, base_path('tests/Support/customer-access-worker.php')], base_path(), [
                'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => (string) $database['database'],
                'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'VASEY_CUSTOMER_RACE_DIRECTORY' => $directory,
            ], json_encode($input, JSON_THROW_ON_ERROR), 40);
            $process->start();
            $this->await(fn () => is_file($directory.'/ready'), $process);
            $ready = json_decode(file_get_contents($directory.'/ready'), true, 16, JSON_THROW_ON_ERROR);
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertNotSame($parent, $ready['connection_id']);
            $this->assertNotSame(getmypid(), $ready['pid']);
            DB::beginTransaction();
            User::whereKey($f['user']->id)->lockForUpdate()->firstOrFail();
            touch($directory.'/start');
            $this->await(fn () => $this->waiting($ready['connection_id'], $parent, $f['user']->id), $process);
            if ($withdraw === 'password') {
                User::whereKey($f['user']->id)->update(['password' => Hash::make('Replacement-password-after-exact-wait')]);
            } else {
                $account = $f['account']->newQuery()->whereKey($f['account']->id)->lockForUpdate()->firstOrFail();
                $account->update(['active' => false, 'access_version' => $account->access_version + 1]);
            }
            DB::commit();
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            $this->assertSame('denied', $result['result'], json_encode($result));
            $this->assertSame($ready['connection_id'], $result['connection_id']);
            $this->assertSame($ready['pid'], $result['pid']);
            $this->assertSame('users', $result['locks'][0]);
            $this->assertSame(0, $result['transaction_level']);
            $this->assertSame(0, $result['provider_calls']);
            $this->assertSame($counts, array_map(fn ($table) => DB::table($table)->count(), $tables));
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop(1);
            }
            $fs->deleteDirectory($directory);
        }
    }

    private function waiting(int $requester, int $blocker, int $id): bool
    {
        $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS status
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requester ON requester.THREAD_ID=waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocker ON blocker.THREAD_ID=waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID=waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE=waits.ENGINE
WHERE waits.ENGINE='INNODB' AND requester.PROCESSLIST_ID=? AND blocker.PROCESSLIST_ID=?
AND requested.OBJECT_SCHEMA=? AND requested.OBJECT_NAME='users' AND requested.INDEX_NAME='PRIMARY'
AND requested.LOCK_TYPE='RECORD' AND requested.LOCK_STATUS='WAITING' AND requested.LOCK_DATA=? LIMIT 1
SQL;

        return DB::selectOne($sql, [$requester, $blocker, DB::connection()->getConfig('database'), (string) $id])?->status === 'WAITING';
    }

    private function await(callable $condition, Process $process): void
    {
        $deadline = microtime(true) + 15;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            $this->assertTrue($process->isRunning(), 'Customer worker ended before its barrier: '.$process->getOutput().$process->getErrorOutput());
            $process->checkTimeout();
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Customer worker never reached the exact user-row wait.');
    }
}
