<?php

namespace Tests\Feature;

use App\Domain\Media\Models\StemsRecording;
use App\Support\Audit\AuditEvent;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\RecordingFixtures;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StemsRecordingConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent-process recording association locking is verified on MySQL, not SQLite.');
        }
    }

    public static function confirmations(): array
    {
        return [
            'conflicting / root transaction' => [false, false],
            'identical / root transaction' => [true, false],
            'conflicting / old caller snapshot' => [false, true],
            'identical / old caller snapshot' => [true, true],
        ];
    }

    #[DataProvider('confirmations')]
    public function test_conflicting_associations_wait_on_the_track_and_preserve_the_winning_attestation(bool $identical, bool $callerTransaction): void
    {
        $this->fakePrivateMediaStorage();
        ['actor' => $actor, 'track' => $track, 'stems' => $stems, 'data' => $data] = RecordingFixtures::draft();
        // Distinct authorized actors let both workers reach the shared track fence.
        $actors = [$actor, LicenseFixtures::admin()];
        $this->assertNotSame($actors[0]->id, $actors[1]->id);
        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed before separate processes read them.');
        $directory = storage_path('framework/testing/stems-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ([0, 1] as $worker) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/stems-recording-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_STEMS_RACE_DIRECTORY' => $directory, 'VASEY_STEMS_RACE_WORKER' => (string) $worker,
                ], json_encode(['stems_id' => $stems->id, 'actor_id' => $actors[$worker]->id, 'data' => $data,
                    'verification_reference' => $identical ? 'SYNTHETIC SAME EXPORT' : 'SYNTHETIC EXPORT '.$worker,
                    'caller_transaction' => $callerTransaction, 'media_root' => Storage::disk('local')->path('')], JSON_THROW_ON_ERROR), 40);
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
                $this->assertSame(0, $process->getExitCode(), 'Recording worker failed: '.$process->getOutput().$process->getErrorOutput());
                $output = $process->getOutput();
                $this->assertJson($output, 'Recording worker emitted invalid JSON: '.$output.$process->getErrorOutput());
                $results[] = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
            }
            $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            $this->assertSame('saved', $results[$winner]['result']);
            if ($identical) {
                $this->assertSame('saved', $results[$loser]['result']);
                $this->assertSame($results[$winner]['binding_id'], $results[$loser]['binding_id']);
            } else {
                $this->assertSame('rejected', $results[$loser]['result']);
                $this->assertStringContainsString('already associated', $results[$loser]['errors']['master_asset_id'][0]);
            }
            foreach ($results as $worker => $result) {
                $this->assertSame($ids[$worker], $result['connection_id']);
                $this->assertSame($callerTransaction ? 1 : 0, $result['caller_transaction_level']);
                $this->assertSame(0, $result['transaction_level']);
                if ($callerTransaction) {
                    $this->assertSame(0, $result['snapshot_before']);
                    // The loser still cannot see the winning row through its ordinary caller snapshot.
                    $this->assertSame($worker === $winner ? 1 : 0, $result['snapshot_after']);
                }
            }
            $binding = StemsRecording::sole();
            $this->assertSame($identical ? 'SYNTHETIC SAME EXPORT' : 'SYNTHETIC EXPORT '.$winner, $binding->verification_reference);
            $this->assertSame($results[$winner]['binding_id'], $binding->id);
            $this->assertSame($actors[$winner]->id, $binding->verified_by);
            $this->assertSame(1, AuditEvent::where('action', 'media.stems.recording_associated')->count());
            $this->assertSame($actors[$winner]->id, AuditEvent::where('action', 'media.stems.recording_associated')->sole()->actor_id);
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
        $this->fail('Recording workers never reached the required lock/barrier state.');
    }
}
