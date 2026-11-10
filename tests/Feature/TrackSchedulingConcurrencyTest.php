<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\Models\TrackPublicationSchedule;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\TrackPublicationSchedules;
use App\Domain\Media\VerifiedMedia;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/**
 * Native MySQL evidence for the schedule claim race (BL-41). SQLite has one writer and cannot show two runners, or a runner and
 * a cancel, queuing behind each other's actor and schedule locks; it also cannot run the MySQL trigger bodies.
 */
class TrackSchedulingConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent schedule runners require native MySQL; SQLite is not concurrency evidence.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** A committed, due schedule: the track is scheduled and the clock is a minute past its instant. */
    private function due(): array
    {
        $fixture = QuoteFixtures::selection();
        $track = app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        $at = CarbonImmutable::now('UTC')->startOfMinute()->addMinutes(10);
        $schedule = app(TrackPublicationSchedules::class)->schedule($track, $fixture['actor'], $at->setTimezone('America/Chicago')->format('Y-m-d\TH:i'), 'published', $track->metadata_version, $track->publication_version);

        return [$schedule, $fixture, $at->addMinute()];
    }

    public function test_native_lifecycle_blocked_attempt_and_forged_rows_hold_under_the_mysql_triggers(): void
    {
        [$schedule, $fixture, $now] = $this->due();
        $track = $fixture['track'];
        // A forged row is refused by the MySQL trigger bodies exactly as by the SQLite ones.
        foreach ([
            fn () => DB::table('tracks')->where('id', $track->id)->update(['status' => 'archived']),
            fn () => DB::table('tracks')->where('id', $track->id)->update(['status' => 'published']),
            fn () => DB::table('tracks')->where('id', $track->id)->update(['status' => 'draft']),
            fn () => DB::table('track_publication_schedules')->where('id', $schedule->id)->delete(),
            fn () => DB::table('track_publication_schedules')->where('id', $schedule->id)->update(['target_status' => 'unlisted']),
            fn () => DB::table('track_publication_schedules')->insert(['track_id' => $track->id, 'target_status' => 'published', 'publish_at' => $now->addDay()->format('Y-m-d H:i:s'),
                'entry_zone' => 'UTC', 'entry_local' => 'x', 'created_by' => $fixture['actor']->id, 'created_at' => now()->utc()->format('Y-m-d H:i:s'), 'state' => 'pending', 'pending_slot' => 1]),
        ] as $forged) {
            try {
                $forged();
                $this->fail('MySQL accepted a forged track state or schedule.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Track visibility or publication schedule evidence is invalid or immutable', $exception->getMessage());
            }
        }
        // A failed attempt updates only the alert evidence (the update trigger's attempt branch) and keeps the schedule pending.
        $path = app(VerifiedMedia::class)->path($fixture['media']['master_wav']);
        $original = file_get_contents($path);
        file_put_contents($path, 'tampered');
        $this->travelTo($now);
        $this->assertSame(1, Artisan::call('vasey:publish-scheduled-tracks'));
        $row = $schedule->fresh();
        $this->assertSame(['pending', 1, 1], [$row->state, $row->pending_slot, $row->attempts]);
        $this->assertNotNull($row->last_failure_reason);
        $this->assertSame('scheduled', $track->fresh()->status);
        // Fixed, and past the retry interval: it publishes, the schedule resolves, and the track guard accepts that exact row.
        file_put_contents($path, $original);
        $this->travelTo($now->addMinutes(TrackPublicationSchedules::RETRY_MINUTES + 1));
        $this->assertSame(0, Artisan::call('vasey:publish-scheduled-tracks'));
        $this->assertSame('published', $track->fresh()->status);
        $this->assertSame(['published', null], [$schedule->fresh()->state, $schedule->fresh()->pending_slot]);
        $this->assertSame($track->fresh()->publication_version, $schedule->fresh()->publication_version);
        // Idempotent.
        $this->assertSame(0, Artisan::call('vasey:publish-scheduled-tracks'));
        $this->assertSame(1, AuditEvent::where('action', 'catalog.track.published')->where('context->trigger', 'schedule')->count());
    }

    public function test_native_status_comparisons_are_byte_exact_under_the_case_and_space_insensitive_collation(): void
    {
        $fixture = QuoteFixtures::selection();
        $id = $fixture['track']->id;
        $this->assertSame('published', $fixture['track']->fresh()->status);
        // utf8mb4_unicode_ci treats these as equal to 'published'/'draft'; the triggers must not.
        foreach (['Published', 'published ', 'PUBLISHED', 'Unlisted', 'unlisted ', 'Draft', 'draft '] as $spelling) {
            try {
                DB::table('tracks')->where('id', $id)->update(['status' => $spelling]);
                $this->fail('MySQL accepted the status spelling '.json_encode($spelling).'.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Track visibility or publication schedule evidence is invalid or immutable', $exception->getMessage(), $spelling);
            }
        }
        foreach (['Draft', 'draft ', 'Published'] as $spelling) {
            try {
                DB::table('tracks')->insert(['title' => 'Spelling', 'slug' => 'spelling-'.Str::slug($spelling).'-'.strlen($spelling), 'status' => $spelling]);
                $this->fail('MySQL accepted an inserted status spelling '.json_encode($spelling).'.');
            } catch (QueryException) {
                // 'Published' is also refused by the earlier published-URL guard, which answers first with its own message.
                $this->assertSame(0, DB::table('tracks')->where('title', 'Spelling')->count(), $spelling);
            }
        }
        $this->assertSame('published', $fixture['track']->fresh()->status);
        // The exact spellings still move: published to unlisted keeps its reserved URL.
        DB::table('tracks')->where('id', $id)->update(['status' => 'unlisted']);
        $this->assertSame('unlisted', $fixture['track']->fresh()->status);
    }

    public function test_native_two_runners_that_selected_the_same_due_schedule_publish_it_exactly_once(): void
    {
        [$schedule, $fixture, $now] = $this->due();
        $before = $fixture['track']->fresh();
        $common = ['track_id' => $before->id, 'actor_id' => $fixture['actor']->id, 'media_root' => Storage::disk('local')->path(''), 'now' => $now->toISOString(),
            'metadata_version' => $before->metadata_version, 'publication_version' => $before->publication_version,
            'config' => ['commerce' => config('commerce'), 'media' => config('media'), 'payments' => config('payments')]];
        $this->race([$common + ['operation' => 'run', 'pause_table' => 'track_publication_schedules'], $common + ['operation' => 'run']],
            function ($directory, $processes, $connections) use ($fixture): void {
                touch($directory.'/start-0');
                $this->await(fn () => is_file($directory.'/locked-0'), $processes);
                touch($directory.'/start-1');
                // The second runner has already read the schedule as due and now queues on the scheduling actor's row.
                $this->observeWait($connections[1], $connections[0], 'users', $fixture['actor']->id, $processes);
                touch($directory.'/commit');
                $results = $this->results($processes, $connections);
                $this->assertSame(['published', 'already_resolved'], array_column($results, 'result'), json_encode($results));
                $this->assertSame([0, 0], array_column($results, 'transactionLevel'));
            });
        $track = $fixture['track']->fresh();
        $this->assertSame(['published', $before->publication_version + 1], [$track->status, $track->publication_version]);
        $this->assertSame(1, AuditEvent::where('action', 'catalog.track.published')->where('subject_id', $track->id)->where('context->trigger', 'schedule')->count());
        $this->assertSame(['published', $track->publication_version], [$schedule->fresh()->state, $schedule->fresh()->publication_version]);
        $this->assertSame(1, TrackPublicationSchedule::where('track_id', $track->id)->count());
    }

    public function test_native_a_cancel_that_loses_the_race_to_an_activation_changes_nothing(): void
    {
        [$schedule, $fixture, $now] = $this->due();
        $before = $fixture['track']->fresh();
        $common = ['track_id' => $before->id, 'actor_id' => $fixture['actor']->id, 'media_root' => Storage::disk('local')->path(''), 'now' => $now->toISOString(),
            'metadata_version' => $before->metadata_version, 'publication_version' => $before->publication_version,
            'config' => ['commerce' => config('commerce'), 'media' => config('media'), 'payments' => config('payments')]];
        $this->race([$common + ['operation' => 'run', 'pause_table' => 'track_publication_schedules'], $common + ['operation' => 'cancel']],
            function ($directory, $processes, $connections) use ($fixture): void {
                touch($directory.'/start-0');
                $this->await(fn () => is_file($directory.'/locked-0'), $processes);
                touch($directory.'/start-1');
                $this->observeWait($connections[1], $connections[0], 'users', $fixture['actor']->id, $processes);
                touch($directory.'/commit');
                $this->assertSame(['published', 'rejected'], array_column($this->results($processes, $connections), 'result'));
            });
        $this->assertSame('published', $fixture['track']->fresh()->status);
        $this->assertSame('published', $schedule->fresh()->state);
        $this->assertSame(0, AuditEvent::where('action', 'catalog.track.schedule_cancelled')->count());
    }

    public function test_native_an_activation_that_loses_the_race_to_a_cancel_publishes_nothing(): void
    {
        [$schedule, $fixture, $now] = $this->due();
        $before = $fixture['track']->fresh();
        $common = ['track_id' => $before->id, 'actor_id' => $fixture['actor']->id, 'media_root' => Storage::disk('local')->path(''), 'now' => $now->toISOString(),
            'metadata_version' => $before->metadata_version, 'publication_version' => $before->publication_version,
            'config' => ['commerce' => config('commerce'), 'media' => config('media'), 'payments' => config('payments')]];
        $this->race([$common + ['operation' => 'cancel', 'pause_table' => 'track_publication_schedules'], $common + ['operation' => 'run']],
            function ($directory, $processes, $connections) use ($fixture): void {
                touch($directory.'/start-0');
                $this->await(fn () => is_file($directory.'/locked-0'), $processes);
                touch($directory.'/start-1');
                $this->observeWait($connections[1], $connections[0], 'users', $fixture['actor']->id, $processes);
                touch($directory.'/commit');
                $this->assertSame(['cancelled', 'already_resolved'], array_column($this->results($processes, $connections), 'result'));
            });
        $track = $fixture['track']->fresh();
        $this->assertSame('draft', $track->status);
        $this->assertSame(['cancelled', null], [$schedule->fresh()->state, $schedule->fresh()->pending_slot]);
        $this->assertSame(0, AuditEvent::where('action', 'catalog.track.published')->where('subject_id', $track->id)->where('context->trigger', 'schedule')->count());
        $this->assertSame($before->publication_version + 1, $track->publication_version);
    }

    private function race(array $inputs, callable $assertions): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'Independent workers require committed fixtures.');
        $directory = storage_path('framework/testing/track-schedule-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/track-schedule-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_TRACK_SCHEDULE_RACE_DIRECTORY' => $directory, 'VASEY_TRACK_SCHEDULE_RACE_WORKER' => (string) $worker,
                ], json_encode($input, JSON_THROW_ON_ERROR), 60);
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
            $this->assertSame(0, $process->getExitCode(), 'Track schedule worker failed: '.$process->getOutput().$process->getErrorOutput());
            $this->assertTrue(json_validate($process->getOutput()), 'Worker returned non-JSON: '.$process->getOutput().$process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        }
        $this->assertSame($connections, array_column($results, 'connection_id'));
        $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));

        return $results;
    }

    private function await(callable $ready, array $processes): void
    {
        $deadline = microtime(true) + 20;
        do {
            clearstatcache();
            if ($ready()) {
                return;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Worker exited before observed lock/barrier: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Track schedule workers never reached the required observed lock/barrier.');
    }
}
