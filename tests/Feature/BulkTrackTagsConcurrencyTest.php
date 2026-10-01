<?php

namespace Tests\Feature;

use App\Domain\Catalog\BulkAddTrackTags;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class BulkTrackTagsConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent-process bulk tag and authority locking requires MySQL; SQLite is not concurrency evidence.');
        }
    }

    public function test_overlapping_batches_with_separate_actors_wait_on_the_shared_track_and_the_loser_has_no_partial_changes(): void
    {
        $actors = [LicenseFixtures::admin(), LicenseFixtures::admin()];
        $tracks = array_map(fn ($i) => app(SaveTrackMetadata::class)->handle(null, ['title' => 'Synthetic overlap '.$i, 'slug' => 'synthetic-overlap-'.$i, 'tags' => ['Original '.$i]], $actors[0]), range(1, 3));
        $command = app(BulkAddTrackTags::class);
        $reviews = [$command->review([$tracks[0]->id, $tracks[1]->id], ['Winner 0'], $actors[0]), $command->review([$tracks[2]->id, $tracks[1]->id], ['Winner 1'], $actors[1])];
        $inputs = array_map(fn ($i) => ['mode' => 'overlap', 'operation' => 'apply', 'actor_id' => $actors[$i]->id, 'review' => $reviews[$i]], [0, 1]);
        $before = array_map(fn ($track) => $track->fresh()->getAttributes(), $tracks);
        $this->race($inputs, function ($directory, $processes, $connections) use ($tracks, $before, $actors) {
            touch($directory.'/start');
            $this->await(fn () => is_file($directory.'/locked-0') || is_file($directory.'/locked-1'), $processes);
            $winner = is_file($directory.'/locked-0') ? 0 : 1;
            $loser = 1 - $winner;
            $this->observeWait($connections[$loser], $connections[$winner], 'tracks', $tracks[1]->id, $processes);
            touch($directory.'/commit');
            $results = $this->results($processes, $connections);
            $this->assertSame('saved', $results[$winner]['result']);
            $this->assertSame('rejected', $results[$loser]['result']);
            $this->assertStringContainsString('changed after review', $results[$loser]['errors']['additions'][0]);
            $untouchedIndex = $winner === 0 ? 2 : 0;
            $this->assertSame($before[$untouchedIndex], $tracks[$untouchedIndex]->fresh()->getAttributes());
            foreach ([$winner === 0 ? 0 : 2, 1] as $index) {
                $this->assertSame(['Original '.($index + 1), 'Winner '.$winner], $tracks[$index]->fresh()->tags);
                $this->assertSame(2, $tracks[$index]->fresh()->metadata_version);
            }
            $this->assertSame(2, AuditEvent::where('action', 'catalog.track.metadata_updated')->count());
            $this->assertSame(0, AuditEvent::where('action', 'catalog.track.metadata_updated')->where('subject_id', $tracks[$untouchedIndex]->id)->count());
            $audits = AuditEvent::where('action', 'catalog.track.metadata_updated')->orderBy('subject_id')->get();
            $expectedIds = [$tracks[$winner === 0 ? 0 : 2]->id, $tracks[1]->id];
            sort($expectedIds, SORT_NUMERIC);
            $this->assertSame($expectedIds, $audits->pluck('subject_id')->all());
            $this->assertSame([$actors[$winner]->id, $actors[$winner]->id], $audits->pluck('actor_id')->all());
            foreach ($audits as $audit) {
                $this->assertSame(['tags'], $audit->context['changed_fields']);
                $this->assertSame(2, $audit->context['metadata_version']);
            }
        });
    }

    public static function authorityOrdering(): array
    {
        return ['batch locks first' => [0], 'withdrawal locks first' => [1]];
    }

    #[DataProvider('authorityOrdering')]
    public function test_authority_withdrawal_and_batch_serialize_on_the_persisted_actor_row(int $first): void
    {
        $actor = LicenseFixtures::admin();
        $track = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Synthetic authority race', 'slug' => 'synthetic-authority-race', 'tags' => ['Original']], $actor);
        $review = app(BulkAddTrackTags::class)->review([$track->id], ['Added'], $actor);
        $before = $track->fresh()->getAttributes();
        $inputs = [['mode' => 'authority', 'operation' => 'apply', 'actor_id' => $actor->id, 'review' => $review], ['mode' => 'authority', 'operation' => 'withdraw', 'actor_id' => $actor->id]];
        $this->race($inputs, function ($directory, $processes, $connections) use ($actor, $track, $before, $first) {
            $second = 1 - $first;
            touch($directory.'/start-'.$first);
            $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
            touch($directory.'/start-'.$second);
            $this->observeWait($connections[$second], $connections[$first], 'users', $actor->id, $processes);
            touch($directory.'/commit');
            $results = $this->results($processes, $connections);
            $this->assertSame('withdrawn', $results[1]['result']);
            $this->assertFalse(User::findOrFail($actor->id)->is_admin);
            if ($first === 0) {
                $this->assertSame('saved', $results[0]['result']);
                $this->assertSame(['Original', 'Added'], $track->fresh()->tags);
                $this->assertSame(1, AuditEvent::where('action', 'catalog.track.metadata_updated')->count());
                $audit = AuditEvent::where('action', 'catalog.track.metadata_updated')->sole();
                $this->assertSame($track->id, $audit->subject_id);
                $this->assertSame($actor->id, $audit->actor_id);
                $this->assertSame(['tags'], $audit->context['changed_fields']);
                $this->assertSame(2, $audit->context['metadata_version']);
            } else {
                $this->assertSame('denied', $results[0]['result']);
                $this->assertSame($before, $track->fresh()->getAttributes());
                $this->assertSame(0, AuditEvent::where('action', 'catalog.track.metadata_updated')->count());
            }
        });
    }

    private function race(array $inputs, callable $assertions): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'Independent workers require committed fixtures.');
        $directory = storage_path('framework/testing/bulk-tags-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/bulk-track-tags-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_BULK_TAG_RACE_DIRECTORY' => $directory, 'VASEY_BULK_TAG_RACE_WORKER' => (string) $worker,
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

    private function observeWait(int $requester, int $blocker, string $table, int $id, array $processes): void
    {
        $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS lock_status
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = ? AND requested.INDEX_NAME = 'PRIMARY'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = ?
LIMIT 1
SQL;
        $this->await(fn () => DB::selectOne($sql, [$requester, $blocker, DB::connection()->getDatabaseName(), $table, (string) $id])?->lock_status === 'WAITING', $processes);
    }

    private function results(array $processes, array $connections): array
    {
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), 'Bulk tag worker failed: '.$process->getOutput().$process->getErrorOutput());
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
                $this->assertTrue($process->isRunning(), 'Worker exited before the observed lock/barrier: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Bulk tag workers never reached the required observed lock/barrier state.');
    }
}
