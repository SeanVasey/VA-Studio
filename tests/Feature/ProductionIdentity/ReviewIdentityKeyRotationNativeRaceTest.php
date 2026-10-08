<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\IdentityRequests;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

/**
 * Independent review of PR #57: per-candidate exact locking reads under real InnoDB sessions with two configured keys.
 * Lock shapes and deadlock counters are written to REVIEW_EVIDENCE_DIR when it is set.
 */
class ReviewIdentityKeyRotationNativeRaceTest extends TestCase
{
    use ProductionIdentityFixture;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent native MySQL sessions and exact InnoDB record waits required.');
        }
        $this->useKeys(self::key('1'));
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

    public function test_two_candidate_recoveries_wait_on_the_exact_old_key_fence_then_serialize_without_deadlock(): void
    {
        $this->completeIdentity($this->requestIdentity());
        $fence = (array) DB::table('production_identity_addresses')->sole();
        $binding = DB::table('production_identity_verifications')->value('credential_binding');
        $this->useKeys(self::key('2'), [self::key('1')]);
        $rotated = [self::key('2'), self::key('1')];
        $one = $this->start($rotated, ['purpose' => 'recover', 'email' => 'buyer@example.test', 'request_key' => str_repeat('1', 64)]);
        $two = $this->start($rotated, ['purpose' => 'recover', 'email' => 'buyer@example.test', 'request_key' => str_repeat('2', 64)]);
        $deadlocks = $this->deadlocks();
        DB::beginTransaction();
        DB::table('production_identity_addresses')->where('id', $fence['id'])->lockForUpdate()->first();
        $shapes = [];
        // The first worker waits on the parent's PRIMARY record; the second queues behind the first on the same
        // fence's unique-index record. Either way each waits on the one exact old-key fence and nothing else.
        foreach ([$one, $two] as $worker) {
            touch($worker['directory'].'/start');
            $this->await(fn () => $this->waiting($worker['connection_id'], (string) $fence['id'], $fence['address_hash']), $worker['process']);
        }
        foreach ([$one, $two] as $worker) {
            $shapes[] = $this->locks($worker['connection_id']);
        }
        DB::commit();
        $this->assertSame(['saved', 'saved'], [$this->finish($one), $this->finish($two)]);
        $this->assertSame(0, $this->deadlocks() - $deadlocks);
        $this->assertSame([$fence], DB::table('production_identity_addresses')->get()->map(fn ($row) => (array) $row)->all());
        $recoveries = DB::table('production_identity_challenges')->where('purpose', 'recover')->get();
        $this->assertCount(2, $recoveries);
        foreach ($recoveries as $recovery) {
            $this->assertSame((int) $fence['id'], (int) $recovery->address_id);
            $this->assertSame('pending', $recovery->availability);
            $this->assertSame($binding, $recovery->bound_credential_binding);
        }
        // While waiting, each worker holds only its gap lock for the absent current-key fence (no table or PRIMARY
        // range lock) and waits on the one exact old-key fence record.
        // Lock shape while waiting: never a table lock or a PRIMARY range; PRIMARY only on the exact fence row and the
        // unique index only on that fence's record plus the gap of the absent current-key candidate.
        foreach ($shapes as $shape) {
            foreach ($shape as $lock) {
                if ($lock['table'] !== 'production_identity_addresses') {
                    continue;
                }
                if ($lock['type'] === 'TABLE') {
                    $this->assertSame('IX', $lock['mode']);
                } elseif ($lock['index'] === 'PRIMARY') {
                    $this->assertSame([(string) $fence['id'], 'X,REC_NOT_GAP'], [$lock['data'], $lock['mode']]);
                } else {
                    $this->assertSame('pia_address_unique', $lock['index']);
                    $this->assertTrue($lock['data'] === 'supremum pseudo-record' || str_contains((string) $lock['data'], $fence['address_hash']),
                        'Unique-index lock outside the exact fence and the absent candidate gap: '.json_encode($lock));
                }
            }
        }
        $this->evidence('two-candidate-recovery-wait', ['fence_id' => $fence['id'], 'deadlock_delta' => $this->deadlocks() - $deadlocks, 'worker_locks' => $shapes]);
    }

    public function test_concurrent_first_requests_for_new_addresses_create_one_fence_with_one_or_two_keys(): void
    {
        $report = [];
        foreach (['single-key' => [self::key('2')], 'two-candidate' => [self::key('2'), self::key('1')]] as $label => $keys) {
            $this->useKeys(...[$keys[0], array_slice($keys, 1)]);
            $deadlocks = $this->deadlocks();
            for ($round = 0; $round < 4; $round++) {
                $email = $label.'-'.$round.'@example.test';
                $workers = [$this->start($keys, ['purpose' => 'enroll', 'email' => $email, 'request_key' => str_repeat('a', 64)]),
                    $this->start($keys, ['purpose' => 'enroll', 'email' => $email, 'request_key' => str_repeat('b', 64)])];
                foreach ($workers as $worker) {
                    touch($worker['directory'].'/start');
                }
                $this->assertSame(['saved', 'saved'], array_map(fn ($worker) => $this->finish($worker), $workers), $label);
                $hashes = IdentityPolicy::digests('address', $email);
                $this->assertSame(1, DB::table('production_identity_addresses')->whereIn('address_hash', $hashes)->count(), $label);
                $this->assertSame($hashes[0], DB::table('production_identity_addresses')->whereIn('address_hash', $hashes)->value('address_hash'));
                $this->assertSame(2, DB::table('production_identity_challenges')->where('address_id',
                    DB::table('production_identity_addresses')->where('address_hash', $hashes[0])->value('id'))->count(), $label);
            }
            $report[$label] = ['rounds' => 4, 'deadlock_delta' => $this->deadlocks() - $deadlocks];
        }
        $this->evidence('concurrent-first-requests', $report);
    }

    /** Finding reproduced natively: a rolling deploy (old-key-only and rotated processes) can create two fences. */
    public function test_mixed_configuration_processes_create_two_fences_and_the_address_is_then_refused(): void
    {
        $email = 'mixed@example.test';
        $old = $this->start([self::key('1')], ['purpose' => 'enroll', 'email' => $email, 'request_key' => str_repeat('a', 64)]);
        $new = $this->start([self::key('2'), self::key('1')], ['purpose' => 'enroll', 'email' => $email, 'request_key' => str_repeat('b', 64)]);
        touch($new['directory'].'/start');
        $this->assertSame('saved', $this->finish($new));
        touch($old['directory'].'/start');
        $this->assertSame('saved', $this->finish($old));
        $this->assertSame(2, DB::table('production_identity_addresses')->count());
        $this->useKeys(self::key('2'), [self::key('1')]);
        $count = DB::table('production_identity_challenges')->count();
        try {
            (new IdentityRequests)->request('enroll', $email, str_repeat('c', 64));
            $this->fail('Two fences must be refused.');
        } catch (IdentityException) {
            $this->assertSame($count, DB::table('production_identity_challenges')->count());
        }
        $this->evidence('mixed-configuration-two-fences', ['fences' => 2, 'rotated_request' => 'IdentityException (422), nothing written']);
    }

    private function start(array $keys, array $input): array
    {
        $directory = storage_path('framework/testing/review-identity-rotation-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $database = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => $keys[0], 'APP_PREVIOUS_KEYS' => implode(',', array_slice($keys, 1)),
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VA_PRODUCTION_IDENTITY_RACE_DIRECTORY' => $directory];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) $database[$key];
        }
        $process = new Process([PHP_BINARY, base_path('tests/Support/review-identity-rotation-race-worker.php')], base_path(), $environment,
            json_encode($input + ['at' => now()->toIso8601String()], JSON_THROW_ON_ERROR), 60);
        $process->start();
        $worker = compact('process', 'directory');
        $this->workers[] = $worker;
        $this->await(fn () => is_file($directory.'/ready'), $process, 40);
        $ready = json_decode(file_get_contents($directory.'/ready'), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame(count($keys), $ready['keys']);
        $this->assertNotSame($this->connectionId(), $ready['connection_id']);

        return $worker + $ready;
    }

    private function finish(array $worker): string
    {
        $worker['process']->wait();
        $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());
        $result = json_decode($worker['process']->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        $this->assertNull($result['error_class']);
        $this->assertSame(0, $result['transaction_level']);

        return $result['result'];
    }

    private function deadlocks(): int
    {
        return (int) DB::selectOne("SELECT `COUNT` AS c FROM information_schema.INNODB_METRICS WHERE NAME='lock_deadlocks'")->c;
    }

    private function locks(int $connection): array
    {
        return array_map(fn ($row) => (array) $row, DB::select(<<<'SQL'
SELECT l.OBJECT_NAME AS `table`, l.INDEX_NAME AS `index`, l.LOCK_TYPE AS `type`, l.LOCK_MODE AS `mode`, l.LOCK_STATUS AS `status`, l.LOCK_DATA AS `data`
FROM performance_schema.data_locks l JOIN performance_schema.threads t ON t.THREAD_ID=l.THREAD_ID
WHERE t.PROCESSLIST_ID=? AND l.OBJECT_SCHEMA=? ORDER BY l.OBJECT_NAME, l.INDEX_NAME, l.LOCK_TYPE, l.LOCK_MODE
SQL, [$connection, DB::getDatabaseName()]));
    }

    private function connectionId(): int
    {
        return (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
    }

    private function waiting(int $requester, string $id, string $hash): bool
    {
        foreach (DB::select(<<<'SQL'
SELECT requested.INDEX_NAME AS idx, requested.LOCK_DATA AS row_key FROM performance_schema.data_lock_waits waits
JOIN performance_schema.threads requester ON requester.THREAD_ID=waits.REQUESTING_THREAD_ID
JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID=waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE=waits.ENGINE
WHERE waits.ENGINE='INNODB' AND requester.PROCESSLIST_ID=? AND requested.OBJECT_SCHEMA=? AND requested.OBJECT_NAME='production_identity_addresses'
AND requested.LOCK_TYPE='RECORD' AND requested.LOCK_STATUS='WAITING'
SQL, [$requester, DB::getDatabaseName()]) as $row) {
            if (($row->idx === 'PRIMARY' && trim($row->row_key, "'\"") === $id)
                || ($row->idx === 'pia_address_unique' && $row->row_key === "'".$hash."', ".$id)) {
                return true;
            }
        }

        return false;
    }

    private function await(callable $condition, Process $process, int $seconds = 15): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            } $this->assertTrue($process->isRunning(), 'Worker ended before exact native barrier: '.$process->getErrorOutput().$process->getOutput());
            $process->checkTimeout();
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Worker did not reach exact native record wait. Diagnostics: '.json_encode([
            'waits' => DB::select('SELECT * FROM performance_schema.data_lock_waits'),
            'locks' => DB::select('SELECT t.PROCESSLIST_ID, l.OBJECT_NAME, l.INDEX_NAME, l.LOCK_TYPE, l.LOCK_MODE, l.LOCK_STATUS, l.LOCK_DATA FROM performance_schema.data_locks l JOIN performance_schema.threads t ON t.THREAD_ID=l.THREAD_ID'),
            'processlist' => DB::select('SELECT ID, COMMAND, TIME, STATE, INFO FROM information_schema.PROCESSLIST WHERE DB IS NOT NULL'),
        ]));
    }

    private function evidence(string $name, array $data): void
    {
        $directory = getenv('REVIEW_EVIDENCE_DIR');
        if (is_string($directory) && $directory !== '' && is_dir($directory)) {
            file_put_contents($directory.'/native-'.$name.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }
    }

    private function useKeys(string $current, array $previous = []): void
    {
        config(['app.key' => $current, 'app.previous_keys' => $previous]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private static function key(string $fill): string
    {
        return 'base64:'.base64_encode(str_repeat($fill, 32));
    }
}
