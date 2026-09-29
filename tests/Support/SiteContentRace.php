<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Two independent workers must demonstrably contend on the singleton publication row. */
final class SiteContentRace
{
    /**
     * @param  (callable(array, int, int): void)|null  $outcomes  Asserts worker results by winner and loser; the default
     *                                                            expects one publication and one stale-revision rejection.
     * @param  int|null  $first  Worker released to take the lock first, so each serialization order is tested; null races both.
     */
    public static function run(TestCase $test, array $jobs, ?callable $outcomes = null, ?int $first = null): array
    {
        $test->assertCount(2, $jobs);
        $test->assertSame(0, DB::transactionLevel(), 'Race fixtures must be committed.');
        $directory = storage_path('framework/testing/site-content-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($jobs as $worker => $job) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/site-content-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
                    'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_SITE_RACE_DIRECTORY' => $directory, 'VASEY_SITE_RACE_WORKER' => (string) $worker,
                ], json_encode($job, JSON_THROW_ON_ERROR), 50);
                $process->start();
                $processes[] = $process;
            }
            self::until($test, $processes, fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), 'ready');
            $ids = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1')];
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $test->assertCount(3, array_unique([...$ids, $parent]));
            if ($first === null) {
                touch($directory.'/start');
            } else {
                $test->assertContains($first, [0, 1]);
                touch($directory.'/start-'.$first);
                self::until($test, $processes, fn () => is_file($directory.'/locked-'.$first), 'first publication lock');
                touch($directory.'/start-'.(1 - $first));
            }
            self::until($test, $processes, fn () => is_file($directory.'/locked-0') || is_file($directory.'/locked-1'), 'publication lock');
            $winner = is_file($directory.'/locked-0') ? 0 : 1;
            $loser = 1 - $winner;
            $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS lock_status
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = 'site_publications' AND requested.INDEX_NAME = 'PRIMARY'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = '1'
LIMIT 1
SQL;
            self::until($test, $processes, fn () => DB::selectOne($sql, [$ids[$loser], $ids[$winner], $database['database']])?->lock_status === 'WAITING', 'same-row contention');
            touch($directory.'/commit');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $test->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            $test->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            if ($outcomes === null) {
                $test->assertSame('published', $results[$winner]['result']);
                $test->assertSame('rejected', $results[$loser]['result']);
                $test->assertArrayHasKey('publication', $results[$loser]['errors']);
                $test->assertStringContainsString('published site changed', $results[$loser]['errors']['publication'][0]);
            } else {
                $outcomes($results, $winner, $loser);
            }
            foreach ($results as $result) {
                $test->assertSame(0, $result['transaction_level']);
            }

            return ['winner' => $winner, 'loser' => $loser, 'results' => $results];
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
                $test->assertTrue($process->isRunning(), 'CMS worker exited before '.$stage.': '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $test->fail('CMS workers missed '.$stage.' barrier.');
    }
}
