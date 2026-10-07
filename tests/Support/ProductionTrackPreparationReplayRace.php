<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Both committed historical misses precede creation; the loser then really waits on the creator's primary record. */
final class ProductionTrackPreparationReplayRace
{
    public static function run(TestCase $test, array $inputs, int $first): array
    {
        $test->assertSame('mysql', DB::getDriverName());
        $test->assertSame(0, DB::transactionLevel());
        $test->assertCount(2, $inputs);
        $test->assertSame($inputs[0]['actor_id'], $inputs[1]['actor_id']);
        $directory = storage_path('framework/testing/production-packet-replay-'.Str::uuid());
        $files = new Filesystem;
        $files->makeDirectory($directory, 0700, true);
        $database = DB::connection()->getConfig();
        $processes = [];
        try {
            foreach ($inputs as $worker => $input) {
                $input['pause_creation'] = $worker === $first;
                $process = new Process([PHP_BINARY, base_path('tests/Support/production-track-preparation-replay-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => (string) $database['database'],
                    'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => '',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_PACKET_REPLAY_DIRECTORY' => $directory, 'VASEY_PACKET_REPLAY_WORKER' => (string) $worker,
                ], json_encode($input, JSON_THROW_ON_ERROR), 40);
                $process->start();
                $processes[] = $process;
            }
            self::await($test, fn (): bool => is_file($directory.'/missed-0') && is_file($directory.'/missed-1'), $processes);
            $misses = array_map(fn (int $worker): array => json_decode(file_get_contents($directory.'/missed-'.$worker), true, 16, JSON_THROW_ON_ERROR), [0, 1]);
            $connections = array_column($misses, 'connection_id');
            $test->assertCount(3, array_unique([...$connections, (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]));
            $test->assertCount(3, array_unique([...array_column($misses, 'pid'), getmypid()]));
            foreach ($misses as $miss) {
                $test->assertSame(0, $miss['transaction_level']);
                $test->assertSame(0, $miss['packet_count']);
            }
            touch($directory.'/create-'.$first);
            self::await($test, fn (): bool => is_file($directory.'/locked-'.$first), $processes);
            touch($directory.'/create-'.(1 - $first));
            $wait = null;
            self::await($test, function () use ($inputs, $connections, $first, &$wait): bool {
                $wait = self::recordWait($connections[1 - $first], $connections[$first], $inputs[$first]['actor_id']);

                return $wait !== null;
            }, $processes);
            $test->assertSame('WAITING', $wait->lock_status);
            echo json_encode(['packet_replay_record_wait' => (array) $wait, 'first' => $first, 'historical_misses' => $misses], JSON_THROW_ON_ERROR)."\n";
            touch($directory.'/release-'.$first);
            $results = [];
            foreach ($processes as $worker => $process) {
                $process->wait();
                $test->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
                $test->assertSame($connections[$worker], $result['connection_id']);
                $test->assertSame($misses[$worker]['pid'], $result['pid']);
                $test->assertSame(0, $result['transaction_level']);
                $results[] = $result;
            }
            $test->assertTrue($results[$first]['paused']);

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $files->deleteDirectory($directory);
        }
    }

    private static function recordWait(int $requester, int $blocker, int $actorId): ?object
    {
        $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS lock_status, requested.LOCK_TYPE AS lock_type, requested.INDEX_NAME AS index_name,
       requested.OBJECT_NAME AS object_name, requested.LOCK_DATA AS lock_data
FROM performance_schema.data_lock_waits waits
JOIN performance_schema.threads requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = 'users' AND requested.INDEX_NAME = 'PRIMARY'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = ?
LIMIT 1
SQL;

        return DB::selectOne($sql, [$requester, $blocker, DB::connection()->getConfig('database'), (string) $actorId]);
    }

    private static function await(TestCase $test, callable $ready, array $processes): void
    {
        $deadline = microtime(true) + 15;
        do {
            clearstatcache();
            if ($ready()) {
                return;
            }
            foreach ($processes as $process) {
                $test->assertTrue($process->isRunning(), $process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $test->fail('Packet replay workers missed the required committed-miss or exact actor-record barrier.');
    }
}
