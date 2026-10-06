<?php

namespace Tests\Feature;

use App\Domain\Customers\Models\CustomerIdentityChallenge;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CustomerFixtures;
use Tests\Support\CustomerIdentityFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class CustomerIdentityConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private array $workers = [];

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Customer identity races require independent MySQL sessions and exact row waits.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        F::configure();
        $this->travelTo(now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($this->workers as $worker) {
            if ($worker['process']->isRunning()) {
                $worker['process']->stop(1);
            }
            (new Filesystem)->deleteDirectory($worker['directory']);
        }
        parent::tearDown();
    }

    public static function competingCompletions(): array
    {
        return ['enroll duplicate' => ['enroll', false], 'enroll conflict' => ['enroll', true],
            'recover duplicate' => ['recover', false], 'recover conflict' => ['recover', true]];
    }

    #[DataProvider('competingCompletions')]
    public function test_competing_completions_wait_on_the_exact_address_and_write_one_credential(string $purpose, bool $conflict): void
    {
        $customer = $purpose === 'recover' ? CustomerFixtures::account(['email' => 'race@example.test']) : null;
        $body = F::request($purpose, 'race@example.test');
        $other = $body;
        if ($conflict) {
            $other['password'] = F::REPLACEMENT;
            $other['requestKey'] = (string) Str::uuid();
        }
        $challenge = CustomerIdentityChallenge::sole();
        $beforeOwner = $customer['account']->owner_key ?? null;
        $one = $this->start($body);
        $two = $this->start($other);
        $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        DB::beginTransaction();
        DB::table('customer_identity_addresses')->where('address_key', $challenge->address_key)->lockForUpdate()->first();
        foreach ([$one, $two] as $worker) {
            touch($worker['directory'].'/start');
            $this->await(fn () => $this->waiting($worker['connection_id'], $parent, 'customer_identity_addresses', $challenge->address_key), $worker['process']);
        }
        DB::commit();
        $results = [$this->finish($one), $this->finish($two)];
        sort($results);
        $this->assertSame($conflict ? ['denied', 'saved'] : ['saved', 'saved'], $results);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customer_accounts', 1);
        $this->assertDatabaseCount('quote_owners', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'customer.test_identity.'.$purpose)->count());
        $this->assertSame('completed', $challenge->fresh()->state);
        if ($customer) {
            $this->assertSame($beforeOwner, $customer['account']->fresh()->owner_key);
        }
        $user = User::sole();
        $this->assertTrue(Hash::check(F::PASSWORD, $user->password) || ($conflict && Hash::check(F::REPLACEMENT, $user->password)));
    }

    public static function withdrawals(): array
    {
        return array_map(fn ($value) => [$value], ['account', 'password', 'staff', 'verification', 'email']);
    }

    #[DataProvider('withdrawals')]
    public function test_committed_withdrawal_wins_the_exact_user_fence_before_recovery(string $change): void
    {
        $customer = CustomerFixtures::account(['email' => 'race@example.test']);
        $body = F::request('recover', $customer['user']->email);
        $worker = $this->start($body);
        $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        DB::beginTransaction();
        $user = User::whereKey($customer['user']->id)->lockForUpdate()->firstOrFail();
        touch($worker['directory'].'/start');
        $this->await(fn () => $this->waiting($worker['connection_id'], $parent, 'users', (string) $user->id), $worker['process']);
        if ($change === 'account') {
            $account = $customer['account']->newQuery()->whereKey($customer['account']->id)->lockForUpdate()->firstOrFail();
            $account->update(['active' => false, 'access_version' => $account->access_version + 1]);
        } else {
            $user->forceFill(match ($change) {
                'password' => ['password' => Hash::make(F::REPLACEMENT)],
                'staff' => ['is_admin' => true],
                'verification' => ['email_verified_at' => null],
                'email' => ['email' => 'new-address@example.test'],
            })->save();
        }
        DB::commit();
        $before = $customer['user']->fresh()->getAttributes();
        $this->assertSame('denied', $this->finish($worker));
        $this->assertSame($before, $customer['user']->fresh()->getAttributes());
        $this->assertSame('pending', CustomerIdentityChallenge::sole()->state);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'customer.test_identity.recover')->count());
    }

    private function start(array $body): array
    {
        $directory = storage_path('framework/testing/customer-identity-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $database = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/customer-identity-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
            'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'VASEY_CUSTOMER_IDENTITY_RACE_DIRECTORY' => $directory,
        ], json_encode(['body' => $body, 'media_root' => Storage::disk('local')->path(''), 'at' => now()->toIso8601String()], JSON_THROW_ON_ERROR), 40);
        $process->start();
        $worker = compact('process', 'directory');
        $this->workers[] = $worker;
        $this->await(fn () => is_file($directory.'/ready'), $process);
        $ready = json_decode(file_get_contents($directory.'/ready'), true, 8, JSON_THROW_ON_ERROR);
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $ready['connection_id']);
        $this->assertNotSame(getmypid(), $ready['pid']);

        return $worker + $ready;
    }

    private function finish(array $worker): string
    {
        $worker['process']->wait();
        $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());
        $result = json_decode($worker['process']->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame($worker['connection_id'], $result['connection_id']);
        $this->assertSame($worker['pid'], $result['pid']);
        $this->assertSame(0, $result['transaction_level']);
        $this->assertNull($result['error_class']);
        $this->assertSame('customer_identity_addresses', $result['locks'][0]);

        return $result['result'];
    }

    private function waiting(int $requester, int $blocker, string $table, string $key): bool
    {
        $row = DB::selectOne(<<<'SQL'
SELECT requested.LOCK_DATA AS row_key
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requester ON requester.THREAD_ID=waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocker ON blocker.THREAD_ID=waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID=waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE=waits.ENGINE
WHERE waits.ENGINE='INNODB' AND requester.PROCESSLIST_ID=? AND blocker.PROCESSLIST_ID=?
AND requested.OBJECT_SCHEMA=? AND requested.OBJECT_NAME=? AND requested.INDEX_NAME='PRIMARY'
AND requested.LOCK_TYPE='RECORD' AND requested.LOCK_STATUS='WAITING' LIMIT 1
SQL, [$requester, $blocker, DB::getDatabaseName(), $table]);

        return $row !== null && trim($row->row_key, "'\"") === $key;
    }

    private function await(callable $condition, Process $process): void
    {
        $deadline = microtime(true) + 15;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            $this->assertTrue($process->isRunning(), 'Customer identity worker ended before its barrier: '.$process->getOutput().$process->getErrorOutput());
            $process->checkTimeout();
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Customer identity worker never reached the exact record wait.');
    }
}
