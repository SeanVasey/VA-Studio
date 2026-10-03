<?php

namespace Tests\Feature;

use App\Domain\Catalog\SaveTrackMetadata;
use App\Support\Audit\AuditEvent;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class TrackMetadataConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent-process metadata locking is verified on MySQL, not SQLite.');
        }
    }

    public function test_overlapping_edits_wait_on_the_same_row_and_only_one_revision_is_saved(): void
    {
        // Distinct actors let both workers reach the shared track lock after their actor locks.
        $actors = [LicenseFixtures::admin(), LicenseFixtures::admin()];
        $track = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Synthetic original', 'slug' => 'race-fixture'], $actors[0]);
        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed before separate processes read them.');
        $directory = storage_path('framework/testing/track-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ([0, 1] as $worker) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/track-metadata-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_TRACK_RACE_DIRECTORY' => $directory, 'VASEY_TRACK_RACE_WORKER' => (string) $worker,
                ], json_encode(['track_id' => $track->id, 'actor_id' => $actors[$worker]->id, 'metadata_version' => 1], JSON_THROW_ON_ERROR), 40);
                $process->start();
                $processes[] = $process;
            }
            $this->await(function () use ($directory) {
                return is_file($directory.'/ready-0') && is_file($directory.'/ready-1');
            }, $processes);
            $ids = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1')];
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertCount(3, array_unique([...$ids, $parent]));
            touch($directory.'/start');
            $this->await(fn () => is_file($directory.'/locked-0') || is_file($directory.'/locked-1'), $processes);
            $winner = is_file($directory.'/locked-0') ? 0 : 1;
            $loser = 1 - $winner;
            $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS lock_status
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = 'tracks' AND requested.INDEX_NAME = 'PRIMARY'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = ?
LIMIT 1
SQL;
            // The parent is an independent autocommit observer; verify the exact blocker and record.
            $this->await(fn () => DB::selectOne($sql, [$ids[$loser], $ids[$winner], $database['database'], (string) $track->id])?->lock_status === 'WAITING', $processes);
            touch($directory.'/commit');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), 'Metadata worker failed: '.$process->getOutput().$process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            $this->assertSame('saved', $results[$winner]['result']);
            $this->assertSame('rejected', $results[$loser]['result']);
            $this->assertStringContainsString('changed since you opened', $results[$loser]['errors']['title'][0]);
            $this->assertSame($results[$winner]['title'], $track->fresh()->title);
            $this->assertSame(2, $track->fresh()->metadata_version);
            $this->assertSame(1, AuditEvent::where('action', 'catalog.track.metadata_updated')->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $filesystem->deleteDirectory($directory);
        }
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
                $this->assertTrue($process->isRunning(), 'Worker exited before the barrier: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Metadata workers never reached the required lock/barrier state.');
    }
}
