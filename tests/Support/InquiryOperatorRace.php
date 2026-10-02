<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real independent MySQL workers; the observed mutex is the configured operator's users PRIMARY row. */
final class InquiryOperatorRace
{
    public static function run(TestCase $test, array $payload, string $owner, int $operatorId, string $field, int $first): array
    {
        $test->assertSame(0, DB::transactionLevel(), 'Operator race fixtures must be committed.');
        $test->assertContains($first, [0, 1]);
        $directory = storage_path('framework/testing/inquiry-operator-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        $jobs = [
            ['operation' => 'submit', 'payload' => $payload, 'owner_hash' => $owner, 'operator_id' => $operatorId],
            ['operation' => 'revoke', 'field' => $field, 'operator_id' => $operatorId],
        ];

        try {
            foreach ($jobs as $worker => $job) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/inquiry-operator-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
                    'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                    'CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED' => 'false',
                    'VASEY_INQUIRY_OPERATOR_RACE_DIRECTORY' => $directory, 'VASEY_INQUIRY_OPERATOR_RACE_WORKER' => (string) $worker,
                ], json_encode($job, JSON_THROW_ON_ERROR), 50);
                $process->start();
                $processes[] = $process;
            }
            self::until($test, $processes, fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), 'ready');
            $ids = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1')];
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $test->assertCount(3, array_unique([...$ids, $parent]));
            touch($directory.'/start-'.$first);
            self::until($test, $processes, fn () => is_file($directory.'/locked-'.$first), 'first operator lock');
            touch($directory.'/start-'.(1 - $first));

            $sql = <<<'SQL'
SELECT requesting_thread.PROCESSLIST_ID AS requesting_connection, blocking_thread.PROCESSLIST_ID AS blocking_connection,
       requested.OBJECT_SCHEMA AS object_schema, requested.OBJECT_NAME AS object_name, requested.INDEX_NAME AS index_name,
       requested.LOCK_TYPE AS lock_type, requested.LOCK_STATUS AS lock_status, requested.LOCK_DATA AS lock_data
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = 'users' AND requested.INDEX_NAME = 'PRIMARY'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = ?
LIMIT 1
SQL;
            $observed = null;
            self::until($test, $processes, function () use ($sql, $ids, $first, $database, $operatorId, &$observed): bool {
                $observed = DB::selectOne($sql, [$ids[1 - $first], $ids[$first], $database['database'], (string) $operatorId]);

                return $observed !== null;
            }, 'same operator row contention');
            $test->assertSame($ids[1 - $first], (int) $observed->requesting_connection);
            $test->assertSame($ids[$first], (int) $observed->blocking_connection);
            $test->assertSame($database['database'], $observed->object_schema);
            $test->assertSame(['users', 'PRIMARY', 'RECORD', 'WAITING', (string) $operatorId],
                [$observed->object_name, $observed->index_name, $observed->lock_type, $observed->lock_status, $observed->lock_data]);
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
            $test->assertTrue($results[0]['snapshot_eligible'], 'Admission must start with the old valid RR read view.');

            return ['winner' => $first, 'results' => $results, 'observed_wait' => (array) $observed];
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
                $test->assertTrue($process->isRunning(), 'Operator worker exited before '.$stage.': '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $test->fail('Operator workers missed '.$stage.' barrier.');
    }
}
