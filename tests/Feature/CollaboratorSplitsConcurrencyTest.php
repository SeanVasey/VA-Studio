<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Rights\CollaboratorSplitReport;
use App\Domain\Rights\CollaboratorSplits;
use App\Domain\Rights\Models\Collaborator;
use App\Support\Audit\AuditEvent;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/** BL-05 (FP-035). Two independent MySQL connections; every name and share is synthetic test data. */
class CollaboratorSplitsConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent row locking and Repeatable Read snapshots require MySQL; SQLite is not concurrency evidence.');
        }
    }

    /** A track whose master set already holds 5000 bps, and two more collaborators who each want 3000. */
    private function fixture(): array
    {
        $actors = [LicenseFixtures::admin(), LicenseFixtures::admin()];
        $command = app(CollaboratorSplits::class);
        $track = Track::create(['title' => 'Synthetic contended track', 'slug' => 'synthetic-contended-'.Str::uuid()]);
        $people = array_map(fn (string $name): Collaborator => $command->saveCollaborator(null,
            ['display_name' => 'Synthetic Collaborator '.$name, 'role' => 'producer', 'source_reference' => null], $actors[0]), ['A', 'B', 'C']);
        $command->addSplit(['track_id' => $track->id, 'collaborator_id' => $people[0]->id, 'split_type' => 'master', 'share_bps' => 5000], $actors[0]);

        return [$actors, $track, $people];
    }

    public function test_two_editors_adding_shares_to_one_track_wait_on_its_row_and_cannot_exceed_10000_basis_points(): void
    {
        [$actors, $track, $people] = $this->fixture();
        $inputs = array_map(fn (int $worker): array => ['operation' => 'service', 'actor_id' => $actors[$worker]->id, 'track_id' => $track->id,
            'collaborator_id' => $people[$worker + 1]->id, 'share_bps' => 3000], [0, 1]);
        $this->race($inputs, function (string $directory, array $processes, array $connections) use ($actors, $track, $people): void {
            touch($directory.'/start-0');
            touch($directory.'/start-1');
            $this->await(fn () => is_file($directory.'/locked-0') || is_file($directory.'/locked-1'), $processes);
            $winner = is_file($directory.'/locked-0') ? 0 : 1;
            $loser = 1 - $winner;
            $this->observeWait($connections[$loser], $connections[$winner], $track->id, $processes);
            touch($directory.'/commit');
            $results = $this->results($processes, $connections);
            $this->assertSame('saved', $results[$winner]['result']);
            $this->assertSame('rejected', $results[$loser]['result']);
            $this->assertStringContainsString('cannot total more than 10000', $results[$loser]['errors']['share_bps'][0]);
            $this->assertStringContainsString('The other shares total 8000', $results[$loser]['errors']['share_bps'][0]);
            $this->assertSame(['status' => 'incomplete', 'total_bps' => 8000, 'collaborators' => 2],
                app(CollaboratorSplitReport::class)->forTrack($track->id)['types']['master']);
            $this->assertSame([$people[0]->id, $people[$winner + 1]->id],
                DB::table('track_collaborator_splits')->where('track_id', $track->id)->orderBy('id')->pluck('collaborator_id')->all());
            // One audit event per change: the fixture's first share and the winner's. The refused editor left none.
            $added = AuditEvent::where('action', 'rights.collaborator_split.added')->orderBy('id')->get();
            $this->assertSame([$actors[0]->id, $actors[$winner]->id], $added->pluck('actor_id')->all());
            $this->assertSame([5000, 8000], $added->map(fn (AuditEvent $event) => $event->context['set_total_bps'])->all());
        });
    }

    public function test_the_database_guard_alone_serializes_two_raw_writers_whose_snapshots_hide_each_other(): void
    {
        [, $track, $people] = $this->fixture();
        $inputs = array_map(fn (int $worker): array => ['operation' => 'raw', 'track_id' => $track->id,
            'collaborator_id' => $people[$worker + 1]->id, 'share_bps' => 3000], [0, 1]);
        $this->race($inputs, function (string $directory, array $processes, array $connections) use ($track): void {
            touch($directory.'/start-0');
            touch($directory.'/start-1');
            $this->await(fn () => is_file($directory.'/locked-0') || is_file($directory.'/locked-1'), $processes);
            $winner = is_file($directory.'/locked-0') ? 0 : 1;
            $loser = 1 - $winner;
            $this->observeWait($connections[$loser], $connections[$winner], $track->id, $processes);
            touch($directory.'/commit');
            $results = $this->results($processes, $connections);
            $this->assertSame('saved', $results[$winner]['result']);
            $this->assertSame('refused_by_database', $results[$loser]['result']);
            $this->assertStringContainsString('cannot exceed 10000 basis points', $results[$loser]['message']);
            // Both began from a snapshot showing 5000, and the refused writer's plain read still showed 5000 after the
            // winner committed 3000 more: only the guard's locking read saw the true 8000.
            $this->assertSame([5000, 5000], array_column($results, 'snapshot_total_bps'));
            $this->assertSame(5000, $results[$loser]['plain_read_bps']);
            $this->assertSame(8000, (int) DB::table('track_collaborator_splits')->where('track_id', $track->id)->where('split_type', 'master')->sum('share_bps'));
            $this->assertSame(2, DB::table('track_collaborator_splits')->where('track_id', $track->id)->count());
        });
    }

    private function race(array $inputs, callable $assertions): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'Independent workers require committed fixtures.');
        $directory = storage_path('framework/testing/collaborator-splits-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/collaborator-splits-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_SPLIT_RACE_DIRECTORY' => $directory, 'VASEY_SPLIT_RACE_WORKER' => (string) $worker,
                ], json_encode($input, JSON_THROW_ON_ERROR), 40);
                $process->start();
                $processes[] = $process;
            }
            $this->await(fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), $processes);
            $connections = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1')];
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertCount(3, array_unique([...$connections, $parent]));
            $assertions($directory, $processes, $connections);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $filesystem->deleteDirectory($directory);
        }
    }

    /** The waiting editor is blocked by the other on the exact track row, as observed by the server itself. */
    private function observeWait(int $requester, int $blocker, int $trackId, array $processes): void
    {
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
        $this->await(fn () => DB::selectOne($sql, [$requester, $blocker, DB::connection()->getDatabaseName(), (string) $trackId])?->lock_status === 'WAITING', $processes);
    }

    private function results(array $processes, array $connections): array
    {
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), 'Collaborator split worker failed: '.$process->getOutput().$process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        }
        $this->assertSame($connections, array_column($results, 'connection_id'));
        $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));

        return $results;
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
                $this->assertTrue($process->isRunning(), 'Worker exited before the observed lock or barrier: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Collaborator split workers never reached the required observed lock or barrier.');
    }
}
