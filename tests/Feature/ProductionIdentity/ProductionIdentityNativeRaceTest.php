<?php

namespace Tests\Feature\ProductionIdentity;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

class ProductionIdentityNativeRaceTest extends TestCase
{
    use ProductionIdentityFixture;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent native MySQL sessions and exact InnoDB record waits required.');
        }
        $this->identitySetup();
        $this->travelTo(now()->startOfSecond());
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

    #[DataProvider('completions')]
    public function test_competing_completion_waits_on_exact_address_and_retains_one_verification(string $purpose, bool $conflict): void
    {
        if ($purpose === 'recover') {
            $this->completeIdentity($this->requestIdentity());
        }
        $challenge = $this->requestIdentity($purpose);
        [$id, $proof] = $this->proofFor($challenge);
        $body = [$id, $proof, 'MailboxPassword123', 'Declared buyer', str_repeat('b', 64)];
        $other = $body;
        if ($conflict) {
            $other[2] = 'OtherPassword456';
            $other[4] = str_repeat('c', 64);
        }
        $one = $this->start(['action' => 'complete', 'body' => $body]);
        $two = $this->start(['action' => 'complete', 'body' => $other]);
        $parent = $this->connectionId();
        DB::beginTransaction();
        DB::table('production_identity_addresses')->where('id', $challenge['address_id'])->lockForUpdate()->first();
        foreach ([$one, $two] as $worker) {
            touch($worker['directory'].'/start');
            $this->await(fn () => $this->waiting($worker['connection_id'], $parent, 'production_identity_addresses', (string) $challenge['address_id']), $worker['process']);
        }
        DB::commit();
        $results = [$this->finish($one), $this->finish($two)];
        sort($results);
        $this->assertSame($conflict ? ['denied', 'saved'] : ['saved', 'saved'], $results);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('production_identity_origins')->count());
        $this->assertSame($purpose === 'enroll' ? 1 : 2, DB::table('production_identity_verifications')->count());
    }

    public static function completions(): array
    {
        return [['enroll', false], ['enroll', true], ['recover', false], ['recover', true]];
    }

    public function test_competing_workers_wait_on_exact_address_then_handoff_only_once(): void
    {
        $challenge = $this->requestIdentity();
        $id = DB::table('production_identity_notices')->value('id');
        $one = $this->start(['action' => 'notice', 'notice_id' => $id]);
        $two = $this->start(['action' => 'notice', 'notice_id' => $id]);
        $parent = $this->connectionId();
        DB::beginTransaction();
        DB::table('production_identity_addresses')->where('id', $challenge['address_id'])->lockForUpdate()->first();
        foreach ([$one, $two] as $worker) {
            touch($worker['directory'].'/start');
            $this->await(fn () => $this->waiting($worker['connection_id'], $parent, 'production_identity_addresses', (string) $challenge['address_id']), $worker['process']);
        }
        DB::commit();
        $this->assertSame('saved', $this->finish($one));
        $this->assertSame('saved', $this->finish($two));
        $calls = (int) is_file($one['directory'].'/handoff') + (int) is_file($two['directory'].'/handoff');
        $this->assertSame(1, $calls);
        $this->assertSame(1, DB::table('production_identity_attempts')->count());
        $this->assertSame('accepted', DB::table('production_identity_outcomes')->value('status'));
    }

    public function test_committed_credential_withdrawal_wins_exact_user_fence_before_recovery(): void
    {
        $this->completeIdentity($this->requestIdentity());
        $challenge = $this->requestIdentity('recover');
        [$id, $proof] = $this->proofFor($challenge);
        $worker = $this->start(['action' => 'complete', 'body' => [$id, $proof, 'RecoveredPassword456', 'Declared buyer', str_repeat('b', 64)]]);
        $parent = $this->connectionId();
        DB::beginTransaction();
        $user = DB::table('users')->lockForUpdate()->first();
        touch($worker['directory'].'/start');
        $this->await(fn () => $this->waiting($worker['connection_id'], $parent, 'users', (string) $user->id), $worker['process']);
        DB::table('users')->where('id', $user->id)->update(['password' => 'withdrawn']);
        DB::commit();
        $this->assertSame('denied', $this->finish($worker));
        $this->assertSame('withdrawn', DB::table('users')->value('password'));
        $this->assertSame(1, DB::table('production_identity_verifications')->count());
    }

    private function start(array $input): array
    {
        $directory = storage_path('framework/testing/production-identity-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $database = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'VA_PRODUCTION_IDENTITY_RACE_DIRECTORY' => $directory];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) $database[$key];
        }
        $process = new Process([PHP_BINARY, base_path('tests/Support/production-identity-race-worker.php')], base_path(), $environment, json_encode($input + ['at' => now()->toIso8601String()], JSON_THROW_ON_ERROR), 35);
        $process->start();
        $worker = compact('process', 'directory');
        $this->workers[] = $worker;
        $this->await(fn () => is_file($directory.'/ready'), $process);
        $ready = json_decode(file_get_contents($directory.'/ready'), true, 8, JSON_THROW_ON_ERROR);
        $this->assertNotSame($this->connectionId(), $ready['connection_id']);
        $this->assertNotSame(getmypid(), $ready['pid']);

        return $worker + $ready;
    }

    private function finish(array $worker): string
    {
        $worker['process']->wait();
        $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());
        $output = $worker['process']->getOutput();
        try {
            $result = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            file_put_contents('/tmp/va-production-identity-race-output.txt', $output);
            chmod('/tmp/va-production-identity-race-output.txt', 0600);
            $this->fail('Worker output was not a JSON receipt; private synthetic failure captured.');
        }
        $this->assertNull($result['error_class']);
        $this->assertSame(0, $result['transaction_level']);
        $this->assertSame($worker['connection_id'], $result['connection_id']);

        return $result['result'];
    }

    private function connectionId(): int
    {
        return (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
    }

    private function waiting(int $requester, int $blocker, string $table, string $key): bool
    {
        $row = DB::selectOne(<<<'SQL'
SELECT requested.LOCK_DATA AS row_key FROM performance_schema.data_lock_waits waits
JOIN performance_schema.threads requester ON requester.THREAD_ID=waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads blocker ON blocker.THREAD_ID=waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID=waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE=waits.ENGINE
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
            } $this->assertTrue($process->isRunning(), 'Worker ended before exact native barrier.');
            $process->checkTimeout();
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Worker did not reach exact native record wait.');
    }
}
