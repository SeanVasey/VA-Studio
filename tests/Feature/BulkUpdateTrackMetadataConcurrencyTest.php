<?php

namespace Tests\Feature;

use App\Domain\Catalog\BulkUpdateTrackMetadata;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class BulkUpdateTrackMetadataConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent bulk metadata row/authority locks and Repeatable Read authority checks require MySQL; SQLite is not concurrency evidence.');
        }
    }

    private function changes(string $mood): array
    {
        return array_replace(array_fill_keys(['artist', 'bpm', 'musical_key', 'genre', 'mood'], ['mode' => 'keep']),
            ['mood' => ['mode' => 'set', 'value' => $mood]]);
    }

    private function drafts(User $actor, int $count = 3): array
    {
        return array_map(fn ($index) => app(SaveTrackMetadata::class)->handle(null, ['title' => 'Synthetic metadata race '.$index,
            'slug' => 'synthetic-metadata-race-'.$index, 'mood' => 'Original '.$index, 'tags' => ['Retained '.$index]], $actor), range(1, $count));
    }

    public function test_overlapping_batches_with_distinct_actors_wait_on_the_exact_shared_row_and_the_loser_has_no_partial_changes(): void
    {
        $actors = [LicenseFixtures::admin(), LicenseFixtures::admin()];
        $tracks = $this->drafts($actors[0]);
        $command = app(BulkUpdateTrackMetadata::class);
        $reviews = [$command->review([$tracks[0]->id, $tracks[1]->id], $this->changes('Winner 0'), $actors[0]),
            $command->review([$tracks[2]->id, $tracks[1]->id], $this->changes('Winner 1'), $actors[1])];
        $inputs = array_map(fn ($index) => ['mode' => 'overlap', 'operation' => 'apply', 'actor_id' => $actors[$index]->id, 'review' => $reviews[$index]], [0, 1]);
        $before = array_map(fn ($track) => $track->fresh()->getAttributes(), $tracks);
        $this->race($inputs, function ($directory, $processes, $connections) use ($tracks, $actors, $before): void {
            touch($directory.'/start');
            $this->await(fn () => is_file($directory.'/locked-0') || is_file($directory.'/locked-1'), $processes);
            $winner = is_file($directory.'/locked-0') ? 0 : 1;
            $loser = 1 - $winner;
            $this->observeWait($connections[$loser], $connections[$winner], 'tracks', $tracks[1]->id, $processes);
            touch($directory.'/commit');
            $results = $this->results($processes, $connections);
            $this->assertSame('saved', $results[$winner]['result']);
            $this->assertSame('rejected', $results[$loser]['result']);
            $this->assertArrayHasKey('changes', $results[$loser]['errors']);
            $untouched = $winner === 0 ? 2 : 0;
            $this->assertSame($before[$untouched], $tracks[$untouched]->fresh()->getAttributes());
            $expectedIds = [$tracks[$winner === 0 ? 0 : 2]->id, $tracks[1]->id];
            sort($expectedIds, SORT_NUMERIC);
            $this->assertSame($expectedIds, $results[$winner]['changed_ids']);
            $this->assertSame([], $results[$winner]['unchanged_ids']);
            foreach ([$winner === 0 ? 0 : 2, 1] as $index) {
                $retained = $tracks[$index]->fresh();
                $this->assertSame('Winner '.$winner, $retained->mood);
                $this->assertSame(['Retained '.($index + 1)], $retained->tags);
                $this->assertSame(2, $retained->metadata_version);
                $old = $before[$index];
                $new = $retained->getAttributes();
                foreach (['mood', 'metadata_version', 'updated_at'] as $field) {
                    unset($old[$field], $new[$field]);
                }
                $this->assertSame($old, $new);
            }
            $audits = AuditEvent::where('action', 'catalog.track.metadata_updated')->orderBy('subject_id')->get();
            $this->assertSame($expectedIds, $audits->pluck('subject_id')->all());
            $this->assertSame([$actors[$winner]->id, $actors[$winner]->id], $audits->pluck('actor_id')->all());
            foreach ($audits as $audit) {
                $this->assertSame(['mood'], $audit->context['changed_fields']);
                $this->assertSame(2, $audit->context['metadata_version']);
            }
        });
    }

    public static function authorityOrdering(): array
    {
        return ['batch locks first' => [0], 'withdrawal locks first' => [1]];
    }

    #[DataProvider('authorityOrdering')]
    public function test_authority_withdrawal_and_batch_serialize_on_the_exact_persisted_actor_row(int $first): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->drafts($actor, 1)[0];
        $review = app(BulkUpdateTrackMetadata::class)->review([$track->id], $this->changes('Authorized edit'), $actor);
        $before = $track->fresh()->getAttributes();
        $inputs = [['mode' => 'authority', 'operation' => 'apply', 'actor_id' => $actor->id, 'review' => $review],
            ['mode' => 'authority', 'operation' => 'withdraw', 'actor_id' => $actor->id]];
        $this->race($inputs, function ($directory, $processes, $connections) use ($actor, $track, $before, $first): void {
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
                $this->assertSame('Authorized edit', $track->fresh()->mood);
                $this->assertSame(2, $track->fresh()->metadata_version);
                $audit = AuditEvent::where('action', 'catalog.track.metadata_updated')->sole();
                $this->assertSame($track->id, $audit->subject_id);
                $this->assertSame($actor->id, $audit->actor_id);
                $this->assertSame(['mood'], $audit->context['changed_fields']);
            } else {
                $this->assertSame('denied', $results[0]['result']);
                $this->assertSame($before, $track->fresh()->getAttributes());
                $this->assertSame(0, AuditEvent::where('action', 'catalog.track.metadata_updated')->count());
            }
        });
    }

    public static function revocations(): array
    {
        return ['staff role' => ['is_admin', false], 'verified email' => ['email_verified_at', null],
            'MFA enrollment' => ['app_authentication_secret', null]];
    }

    #[DataProvider('revocations')]
    public function test_review_and_apply_reject_authority_revocation_hidden_by_an_old_repeatable_read_snapshot(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        Filament::setCurrentPanel($panel);
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $track = $this->drafts($actor, 1)[0];
        $command = app(BulkUpdateTrackMetadata::class);
        $review = $command->review([$track->id], $this->changes('Denied edit'), $actor);
        $before = $this->evidence();
        config(['database.connections.bulk_metadata_revocation' => config('database.connections.'.DB::getDefaultConnection())]);
        $other = DB::connection('bulk_metadata_revocation');
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $other->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $this->assertSame(0, DB::transactionLevel(), 'Authority race requires committed fixtures.');
        DB::beginTransaction();
        try {
            $oldActor = User::findOrFail($actor->id);
            $this->assertTrue($oldActor->is_admin);
            $this->assertNotNull($oldActor->email_verified_at);
            $this->assertNotNull($oldActor->getAppAuthenticationSecret());
            $other->transaction(function () use ($other, $actor, $field, $value): void {
                $this->assertNotNull($other->table('users')->where('id', $actor->id)->lockForUpdate()->first());
                $this->assertSame(1, $other->table('users')->where('id', $actor->id)->update([$field => $value]));
            });
            $this->assertSame($oldActor->getRawOriginal($field), User::findOrFail($actor->id)->getRawOriginal($field), 'Ordinary reads must demonstrably retain the old eligible RR view.');
            foreach ([fn () => $command->review([$track->id], $this->changes('Denied edit'), $oldActor), fn () => $command->apply($review, $oldActor)] as $operation) {
                try {
                    $operation();
                    $this->fail('Old Repeatable Read authority authorized a bulk metadata operation.');
                } catch (AuthorizationException) {
                }
                $this->assertSame(1, DB::transactionLevel());
            }
        } finally {
            DB::rollBack();
            DB::purge('bulk_metadata_revocation');
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame($value, User::findOrFail($actor->id)->{$field});
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['tracks', 'audit_events']);
    }

    private function race(array $inputs, callable $assertions): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'Independent workers require committed fixtures.');
        $directory = storage_path('framework/testing/bulk-metadata-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/bulk-track-metadata-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_BULK_METADATA_RACE_DIRECTORY' => $directory, 'VASEY_BULK_METADATA_RACE_WORKER' => (string) $worker,
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
            $this->assertSame(0, $process->getExitCode(), 'Bulk metadata worker failed: '.$process->getOutput().$process->getErrorOutput());
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
                $this->assertTrue($process->isRunning(), 'Worker exited before observed lock/barrier: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Bulk metadata workers never reached the required observed lock/barrier.');
    }
}
