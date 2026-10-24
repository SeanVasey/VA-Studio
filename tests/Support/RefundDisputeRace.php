<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Two independent PHP processes and MySQL connections. Worker 0 pauses inside its write transaction; worker 1 then
 * starts. When `$expectWait` names a table, the parent requires worker 1 to be waiting on worker 0's exact InnoDB
 * record lock in that table's primary key before releasing worker 0.
 */
final class RefundDisputeRace
{
    public static function run(TestCase $test, array $input, ?string $expectWait, ?int $lockedId): array
    {
        $test->assertSame('mysql', DB::getDriverName());
        $test->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/refund-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        try {
            foreach ([0, 1] as $index) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/refund-dispute-race-worker.php')], base_path(),
                    self::environment($directory, $index), json_encode($input, JSON_THROW_ON_ERROR), 90);
                $process->start();
                $processes[] = $process;
            }
            self::until($test, $processes, fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), 'startup');
            $ids = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1')];
            $test->assertCount(3, array_unique([...$ids, (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]));
            touch($directory.'/start-0');
            self::until($test, $processes, fn () => is_file($directory.'/locked-0'), 'first writer holds the order');
            touch($directory.'/start-1');
            $observed = null;
            if ($expectWait !== null) {
                $sql = <<<'SQL'
SELECT requesting_thread.PROCESSLIST_ID AS requesting_connection, blocking_thread.PROCESSLIST_ID AS blocking_connection,
       requested.OBJECT_NAME AS object_name, requested.INDEX_NAME AS index_name, requested.LOCK_STATUS AS lock_status, requested.LOCK_DATA AS lock_data
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = DATABASE() AND requested.OBJECT_NAME = ? AND requested.INDEX_NAME = 'PRIMARY'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = ?
LIMIT 1
SQL;
                self::until($test, $processes, function () use ($sql, $ids, $expectWait, $lockedId, &$observed): bool {
                    $observed = DB::selectOne($sql, [$ids[1], $ids[0], $expectWait, (string) $lockedId]);

                    return $observed !== null;
                }, 'second writer waits on the order row');
            } else {
                self::until($test, $processes, fn () => ! $processes[1]->isRunning(), 'independent second worker finishes while the first holds its locks');
            }
            touch($directory.'/commit');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $test->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            foreach ($results as $index => $result) {
                $test->assertSame($ids[$index], $result['connection_id']);
                $test->assertSame(0, $result['transaction_level']);
            }
            $test->assertTrue($results[0]['paused']);

            return ['results' => $results, 'wait' => $observed === null ? null : (array) $observed];
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $filesystem->deleteDirectory($directory);
        }
    }

    private static function until(TestCase $test, array $processes, callable $predicate, string $stage): void
    {
        $deadline = microtime(true) + 40;
        do {
            clearstatcache();
            if ($predicate()) {
                return;
            }
            foreach ($processes as $process) {
                $process->checkTimeout();
                if (! $process->isRunning() && $process->getExitCode() !== 0) {
                    $test->fail('Refund/dispute worker failed at '.$stage.': '.$process->getOutput().$process->getErrorOutput());
                }
            }
            usleep(5000);
        } while (microtime(true) < $deadline);
        $test->fail('Refund/dispute workers missed the '.$stage.' barrier.');
    }

    private static function environment(string $directory, int $worker): array
    {
        $db = DB::connection()->getConfig();

        return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VASEY_REFUND_RACE_DIRECTORY' => $directory, 'VASEY_REFUND_RACE_WORKER' => (string) $worker,
            'VASEY_REFUND_RACE_MEDIA_ROOT' => Storage::disk('local')->path('')];
    }
}
