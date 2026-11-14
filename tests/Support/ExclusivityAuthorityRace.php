<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Two independent PHP processes and MySQL connections attempting an exclusivity authority transition from the same
 * ledger head (bounded analogue of InquiryNotificationRace). With `$first`, that worker takes the ledger head lock
 * and holds it until the other is observed waiting on the same table in performance_schema; without it both start
 * together and InnoDB decides.
 */
final class ExclusivityAuthorityRace
{
    public static function run(TestCase $test, array $jobs, ?int $first): array
    {
        $test->assertCount(2, $jobs);
        $test->assertSame(0, DB::transactionLevel(), 'Authority race fixtures must be committed.');
        $directory = storage_path('framework/testing/exclusivity-authority-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($jobs as $worker => $job) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/exclusivity-authority-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
                    'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_AUTHORITY_RACE_DIRECTORY' => $directory, 'VASEY_AUTHORITY_RACE_WORKER' => (string) $worker,
                ], json_encode($job, JSON_THROW_ON_ERROR), 60);
                $process->start();
                $processes[] = $process;
            }
            self::until($test, $processes, fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), 'ready');
            $ids = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1')];
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $test->assertCount(3, array_unique([...$ids, $parent]));
            $observedWait = false;
            if ($first === null) {
                touch($directory.'/start');
            } else {
                touch($directory.'/start-'.$first);
                self::until($test, $processes, fn () => is_file($directory.'/locked-'.$first), 'first ledger lock');
                touch($directory.'/start-'.(1 - $first));
                $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS lock_status
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = 'legacy_exclusivity_authority_events' AND requested.LOCK_STATUS = 'WAITING'
LIMIT 1
SQL;
                self::until($test, $processes, fn () => DB::selectOne($sql, [$ids[1 - $first], $ids[$first], $database['database']])?->lock_status === 'WAITING', 'ledger head contention');
                $observedWait = true;
            }
            touch($directory.'/commit');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $test->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            $test->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            foreach ($results as $worker => $result) {
                $test->assertSame($ids[$worker], $result['connection_id']);
                $test->assertSame(0, $result['transaction_level']);
            }

            return ['results' => $results, 'observed_wait' => $observedWait];
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $filesystem->deleteDirectory($directory);
        }
    }

    private static function until(TestCase $test, array $processes, callable $ready, string $stage): void
    {
        $deadline = microtime(true) + 20;
        do {
            clearstatcache();
            if ($ready()) {
                return;
            }
            foreach ($processes as $process) {
                $test->assertTrue($process->isRunning(), 'Authority worker exited before '.$stage.': '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $test->fail('Authority workers missed the '.$stage.' barrier.');
    }
}
