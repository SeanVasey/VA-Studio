<?php

namespace Tests\Feature;

use App\Domain\Memberships\CreditLedger;
use App\Domain\Memberships\MembershipPlans;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures as F;
use Tests\TestCase;

class MembershipCreditConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Membership credit races require independent MySQL sessions and exact PRIMARY waits.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        $this->travelTo(now()->utc()->startOfSecond());
    }

    public static function races(): array
    {
        return ['last credit' => ['last-credit'], 'duplicate invoice fixture' => ['grant-replay'], 'stale reviewed revision' => ['plan-review'],
            'consume before release' => ['consume-release'], 'release before consume' => ['release-consume'],
            'expire before release' => ['expire-release'], 'reverse before expiry' => ['reverse-expire']];
    }

    #[DataProvider('races')]
    public function test_independent_primary_processes_serialize_exact_ledger_and_plan_effects(string $case): void
    {
        $ledger = app(CreditLedger::class);
        $expected = ['success', 'denied'];
        if ($case === 'grant-replay') {
            $f = F::plan(['allowance' => 1]) + CustomerFixtures::account();
            $other = F::operator();
            $first = ['operation' => 'grant', 'actor_id' => $f['operator']->id, 'version_id' => $f['version']->id,
                'account_id' => $f['account']->id, 'source' => 'synthetic:native_duplicate_invoice'];
            $second = array_replace($first, ['actor_id' => $other->id]);
            $expected = ['success', 'success'];
            $waitTable = 'users';
            $waitId = $f['user']->id;
        } elseif ($case === 'plan-review') {
            $f = F::plan();
            $other = F::operator();
            $command = app(MembershipPlans::class);
            $first = ['operation' => 'apply', 'actor_id' => $f['operator']->id,
                'review' => $command->reviewRevision($f['plan'], F::data(['allowance' => 4]), $f['operator'])];
            $second = ['operation' => 'apply', 'actor_id' => $other->id,
                'review' => $command->reviewRevision($f['plan'], F::data(['allowance' => 7]), $other)];
            $waitTable = 'membership_plans';
            $waitId = $f['plan']->id;
        } else {
            $f = F::bucket(['allowance' => $case === 'last-credit' ? 1 : 3, 'validity_seconds' => 10]);
            $base = ['actor_id' => $f['user']->id, 'principal' => $this->principal($f), 'bucket_id' => $f['grant']['bucket_id']];
            if ($case === 'last-credit') {
                $first = $base + ['operation' => 'reserve', 'amount' => 1, 'resource' => 'synthetic:first_redemption', 'key' => 'first'];
                $second = $base + ['operation' => 'reserve', 'amount' => 1, 'resource' => 'synthetic:second_redemption', 'key' => 'second'];
            } else {
                $reserve = $ledger->reserve($f['grant']['bucket_id'], 1, 'synthetic:native_reserved', 'reserved', $f['principal'], $f['user']);
                if (in_array($case, ['consume-release', 'release-consume'], true)) {
                    [$a, $b] = explode('-', $case);
                    $first = $base + ['operation' => $a, 'event_id' => $reserve['event_id'], 'key' => 'first'];
                    $second = $base + ['operation' => $b, 'event_id' => $reserve['event_id'], 'key' => 'second'];
                } else {
                    $this->travel(10)->seconds();
                    if ($case === 'expire-release') {
                        $first = ['operation' => 'expire', 'actor_id' => $f['operator']->id, 'bucket_id' => $f['grant']['bucket_id'], 'key' => 'first'];
                        $second = $base + ['operation' => 'release', 'event_id' => $reserve['event_id'], 'key' => 'second'];
                    } else {
                        $this->travel(-10)->seconds();
                        $consume = $ledger->consume($reserve['event_id'], 'consumed', $f['principal'], $f['user']);
                        $this->travel(10)->seconds();
                        $first = ['operation' => 'reverse', 'actor_id' => $f['operator']->id, 'event_id' => $consume['event_id'], 'key' => 'first'];
                        $second = ['operation' => 'expire', 'actor_id' => $f['operator']->id, 'bucket_id' => $f['grant']['bucket_id'], 'key' => 'second'];
                    }
                    $expected = ['success', 'success'];
                }
            }
            $waitTable = 'users';
            $waitId = $case === 'reverse-expire' ? $f['operator']->id : $f['user']->id;
        }
        $untouched = $this->commerce();
        $authority = DB::table('users')->orderBy('id')->get()->toJson();
        $accounts = DB::table('customer_accounts')->orderBy('id')->get()->toJson();
        $directories = [$this->directory(), $this->directory()];
        $workers = [];
        try {
            $workers[0] = $this->worker($first + ['hold' => true], $directories[0]);
            $this->await(fn () => is_file($directories[0].'/ready'), $workers);
            $readyFirst = $this->marker($directories[0], 'ready');
            touch($directories[0].'/start');
            $this->await(fn () => is_file($directories[0].'/held'), $workers);
            $held = $this->marker($directories[0], 'held');
            $this->assertSame(1, $held['transaction_level']);
            $workers[1] = $this->worker($second + ['hold' => false], $directories[1]);
            $this->await(fn () => is_file($directories[1].'/ready'), $workers);
            $readySecond = $this->marker($directories[1], 'ready');
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertCount(3, array_unique([$parent, $readyFirst['connection_id'], $readySecond['connection_id']]));
            $this->assertCount(3, array_unique([getmypid(), $readyFirst['pid'], $readySecond['pid']]));
            touch($directories[1].'/start');
            $this->await(fn () => $this->waiting($readySecond['connection_id'], $readyFirst['connection_id'], $waitTable, $waitId), $workers);
            touch($directories[0].'/release');
            $results = [];
            foreach ($workers as $index => $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $result = json_decode($worker->getOutput(), true, 64, JSON_THROW_ON_ERROR);
                $results[] = $result;
                $this->assertSame($expected[$index], $result['result'], json_encode($result));
                $this->assertSame(0, $result['transaction_level']);
                $this->assertSame($index === 0 ? $readyFirst['connection_id'] : $readySecond['connection_id'], $result['connection_id']);
                $this->assertSame('users', $result['locks'][0]);
                if (isset($first['bucket_id'])) {
                    $this->assertTrue(array_search('customer_accounts', $result['locks'], true) < array_search('membership_plans', $result['locks'], true));
                    $this->assertTrue(array_search('membership_plans', $result['locks'], true) < array_search('membership_credit_buckets', $result['locks'], true));
                }
            }
            if ($case === 'grant-replay') {
                $this->assertSame($results[0]['value'], $results[1]['value']);
                $this->assertSame(1, DB::table('membership_credit_buckets')->count());
                $this->assertSame(1, DB::table('membership_credit_events')->count());
                $this->assertSame(1, DB::table('audit_events')->where('action', 'membership.test_credit.grant')->count());
            } elseif ($case === 'plan-review') {
                $this->assertSame(2, DB::table('membership_plan_versions')->count());
                $this->assertSame(4, $results[0]['value']['policy']['allowance']);
                $this->assertSame(1, DB::table('audit_events')->where('action', 'membership.test_plan.revised')->count());
            } else {
                $current = $ledger->read($f['grant']['bucket_id'], $f['principal'], $f['user']);
                $this->assertSame($case === 'last-credit' ? 1 : 3, array_sum($current['balance']));
                if (str_contains($case, 'expire')) {
                    $this->assertSame(0, $current['balance']['available']);
                    $this->assertSame(3, $current['balance']['expired']);
                } elseif ($case === 'last-credit') {
                    $this->assertSame(0, $current['balance']['available']);
                    $this->assertSame(1, $current['balance']['reserved']);
                    $this->assertSame(2, DB::table('membership_credit_events')->count());
                } else {
                    $this->assertSame(3, DB::table('membership_credit_events')->count());
                    $this->assertSame(0, $current['balance']['reserved']);
                    $this->assertSame($case === 'consume-release' ? 1 : 0, $current['balance']['consumed']);
                    $this->assertSame($case === 'consume-release' ? 2 : 3, $current['balance']['available']);
                }
            }
            $this->assertSame($untouched, $this->commerce());
            $this->assertSame($authority, DB::table('users')->orderBy('id')->get()->toJson());
            $this->assertSame($accounts, DB::table('customer_accounts')->orderBy('id')->get()->toJson());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            foreach ($directories as $directory) {
                (new Filesystem)->deleteDirectory($directory);
            }
        }
    }

    public static function withdrawals(): array
    {
        return ['grant role' => ['grant', 'role'], 'reviewed apply MFA' => ['apply', 'mfa'],
            'reserve account' => ['reserve', 'account'], 'consume credential' => ['consume', 'password'], 'read account' => ['read', 'account']];
    }

    #[DataProvider('withdrawals')]
    public function test_committed_authority_withdrawal_wins_exact_user_fence_before_replay_or_write(string $operation, string $withdraw): void
    {
        $f = F::bucket();
        $actor = in_array($operation, ['grant', 'apply'], true) ? $f['operator'] : $f['user'];
        $input = ['operation' => $operation, 'actor_id' => $actor->id, 'hold' => false, 'principal' => $this->principal($f),
            'bucket_id' => $f['grant']['bucket_id'], 'version_id' => $f['version']->id, 'account_id' => $f['account']->id,
            'source' => 'synthetic:invoice_fixture_one', 'amount' => 1, 'resource' => 'synthetic:withdrawal', 'key' => 'withdrawal'];
        if ($operation === 'consume') {
            $reservation = app(CreditLedger::class)->reserve($f['grant']['bucket_id'], 1, 'synthetic:held', 'held', $f['principal'], $f['user']);
            $input['event_id'] = $reservation['event_id'];
        }
        if ($operation === 'apply') {
            $input['review'] = app(MembershipPlans::class)->reviewRevision($f['plan'], F::data(['allowance' => 6]), $f['operator']);
        }
        $before = $this->membership();
        $directory = $this->directory();
        $worker = null;
        try {
            $worker = $this->worker($input, $directory);
            $this->await(fn () => is_file($directory.'/ready'), [$worker]);
            $ready = $this->marker($directory, 'ready');
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertNotSame($parent, $ready['connection_id']);
            $this->assertNotSame(getmypid(), $ready['pid']);
            DB::beginTransaction();
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            touch($directory.'/start');
            $this->await(fn () => $this->waiting($ready['connection_id'], $parent, 'users', $actor->id), [$worker]);
            if ($withdraw === 'account') {
                DB::table('customer_accounts')->where('id', $f['account']->id)->lockForUpdate()->first();
                DB::table('customer_accounts')->where('id', $f['account']->id)->update(['active' => false, 'access_version' => 2, 'updated_at' => now()]);
            } else {
                DB::table('users')->where('id', $actor->id)->update(match ($withdraw) {
                    'role' => ['is_admin' => false], 'mfa' => ['app_authentication_secret' => null],
                    default => ['password' => Hash::make('Synthetic-withdrawn-credential')],
                });
            }
            DB::commit();
            $worker->wait();
            $result = json_decode($worker->getOutput(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $this->assertSame('denied', $result['result'], json_encode($result));
            $this->assertSame('users', $result['locks'][0]);
            $this->assertSame(0, $result['transaction_level']);
            $this->assertSame($before, $this->membership());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker?->isRunning()) {
                $worker->stop(1);
            }
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    private function worker(array $input, string $directory): Process
    {
        $database = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/membership-credit-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
            'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'VASEY_MEMBERSHIP_RACE_DIRECTORY' => $directory,
        ], json_encode($input + ['at' => now()->utc()->toIso8601String()], JSON_THROW_ON_ERROR), 40);
        $process->start();

        return $process;
    }

    private function principal(array $f): array
    {
        $p = $f['principal'];

        return [$p->accountId, $p->userId, $p->ownerKey, $p->accessVersion, $p->credentialStamp];
    }

    private function directory(): string
    {
        $path = storage_path('framework/testing/membership-race-'.Str::uuid());
        (new Filesystem)->makeDirectory($path, 0700, true);

        return $path;
    }

    private function marker(string $directory, string $name): array
    {
        return json_decode(file_get_contents($directory.'/'.$name), true, 16, JSON_THROW_ON_ERROR);
    }

    private function waiting(int $requester, int $blocker, string $table, int $id): bool
    {
        $sql = <<<'SQL'
SELECT requested.ENGINE AS engine, requested.OBJECT_SCHEMA AS database_name, requested.OBJECT_NAME AS table_name,
requested.INDEX_NAME AS index_name, requested.LOCK_TYPE AS lock_type, requested.LOCK_STATUS AS status, requested.LOCK_DATA AS row_id
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requester ON requester.THREAD_ID=waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocker ON blocker.THREAD_ID=waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID=waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE=waits.ENGINE
WHERE waits.ENGINE='INNODB' AND requester.PROCESSLIST_ID=? AND blocker.PROCESSLIST_ID=?
AND requested.OBJECT_SCHEMA=? AND requested.OBJECT_NAME=? AND requested.INDEX_NAME='PRIMARY'
AND requested.LOCK_TYPE='RECORD' AND requested.LOCK_STATUS='WAITING' AND requested.LOCK_DATA=? LIMIT 1
SQL;
        $row = DB::selectOne($sql, [$requester, $blocker, DB::getDatabaseName(), $table, (string) $id]);
        if ($row === null) {
            return false;
        }
        echo 'MEMBERSHIP_LOCK_WAIT '.json_encode(['requester' => $requester, 'blocker' => $blocker, ...(array) $row], JSON_THROW_ON_ERROR).PHP_EOL;

        return true;
    }

    private function await(callable $condition, array $workers): void
    {
        $deadline = microtime(true) + 15;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            foreach ($workers as $worker) {
                $this->assertTrue($worker->isRunning(), 'Membership worker ended before its exact barrier: '.$worker->getOutput().$worker->getErrorOutput());
                $worker->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Membership worker did not reach its exact PRIMARY row wait within the original 15-second budget.');
    }

    private function membership(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['membership_plans', 'membership_plan_versions', 'membership_credit_buckets', 'membership_credit_events', 'audit_events']);
    }

    private function commerce(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['quotes', 'orders', 'license_grants']);
    }
}
