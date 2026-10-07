<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\TestCase;

class ServiceProjectConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Exact independent service-project record waits require native MySQL.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
    }

    public function test_two_staff_quotes_wait_on_the_exact_project_and_current_reads_reject_old_mvcc_state(): void
    {
        $f = F::setup();
        $other = LicenseFixtures::admin();
        $directory = storage_path('framework/testing/service-project-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $processes = [];
        try {
            $processes['first'] = $this->worker($directory, ['name' => 'first', 'actor' => $f['operator']->id, 'project' => $f['project']['id'], 'body' => F::command($f['project'], 'author_quote', ['quote' => F::quote()]), 'pause' => true]);
            $processes['second'] = $this->worker($directory, ['name' => 'second', 'actor' => $other->id, 'project' => $f['project']['id'], 'body' => F::command($f['project'], 'author_quote', ['quote' => F::quote(['scope' => 'Competing stale scope'])]), 'snapshot' => true]);
            foreach ($processes as $name => $process) {
                $this->await(fn (): bool => is_file($directory.'/ready-'.$name), $processes);
            }
            $first = json_decode(file_get_contents($directory.'/ready-first'), true, 16, JSON_THROW_ON_ERROR);
            $second = json_decode(file_get_contents($directory.'/ready-second'), true, 16, JSON_THROW_ON_ERROR);
            $this->assertCount(3, array_unique([getmypid(), $first['pid'], $second['pid']]));
            touch($directory.'/start-first');
            $this->await(fn (): bool => is_file($directory.'/locked-first'), $processes);
            touch($directory.'/start-second');
            $projectId = (int) DB::table('service_projects')->where('public_id', $f['project']['id'])->value('id');
            $sql = <<<'SQL'
SELECT requested.INDEX_NAME AS index_name
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requester ON requester.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocker ON blocker.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requester.PROCESSLIST_ID = ? AND blocker.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = 'service_projects'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING'
  AND ((requested.INDEX_NAME = 'PRIMARY' AND requested.LOCK_DATA = ?)
    OR (requested.INDEX_NAME = 'service_projects_public' AND requested.LOCK_DATA = ?))
LIMIT 1
SQL;
            $this->await(fn (): bool => DB::selectOne($sql, [$second['connection'], $first['connection'], DB::getDatabaseName(), (string) $projectId, "'".$f['project']['id']."', ".$projectId]) !== null, $processes);
            $this->assertFileExists($directory.'/snapshot-second');
            touch($directory.'/release-first');
            $results = [];
            foreach ($processes as $name => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[$name] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
                $this->assertSame(0, $results[$name]['transactionLevel']);
            }
            $this->assertSame('saved', $results['first']['result']);
            $this->assertSame('rejected', $results['second']['result']);
            $this->assertTrue($results['second']['snapshot']);
            $this->assertDatabaseCount('service_project_events', 1);
            $project = $f['journey']->staffShow($f['project']['id'], $f['operator']);
            $this->assertSame(F::quote()['scope'], $project['quotes'][0]['scope']);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    private function worker(string $directory, array $input): Process
    {
        $db = config('database.connections.mysql');
        $process = new Process([PHP_BINARY, base_path('tests/Support/service-project-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        ], json_encode($input + ['directory' => $directory], JSON_THROW_ON_ERROR), 40);
        $process->start();

        return $process;
    }

    private function await(callable $ready, array $processes): void
    {
        $deadline = microtime(true) + 15;
        do {
            clearstatcache();
            if ($ready()) {
                return;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), $process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Service worker did not reach the exact native record wait.');
    }
}
