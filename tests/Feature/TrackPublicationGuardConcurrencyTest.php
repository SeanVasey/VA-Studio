<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishTrack;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class TrackPublicationGuardConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent reviewed track publication row/authority locks and Repeatable Read snapshot refusal require MySQL; SQLite is not concurrency evidence.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public static function intents(): array
    {
        return ['publish' => ['publish'], 'unpublish' => ['unpublish']];
    }

    private function fixture(string $intent): array
    {
        $fixture = QuoteFixtures::selection();
        if ($intent === 'publish') {
            $fixture['track'] = app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        }

        return $fixture;
    }

    private function input(string $mode, string $intent, User $actor, array $review): array
    {
        return ['mode' => $mode, 'operation' => 'apply', 'intent' => $intent, 'actor_id' => $actor->id,
            'review' => $review, 'media_root' => Storage::disk('local')->path('')];
    }

    #[DataProvider('intents')]
    public function test_distinct_actors_serialize_on_the_exact_track_and_only_one_reviewed_revision_is_committed(string $intent): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture($intent);
        $actors = [$actor, LicenseFixtures::admin()];
        $command = app(PublishTrack::class);
        $reviews = array_map(fn ($actor) => $command->review($track, $actor, $intent), $actors);
        $inputs = array_map(fn ($index) => $this->input('overlap', $intent, $actors[$index], $reviews[$index]), [0, 1]);
        $before = $track->fresh()->getAttributes();
        $auditCount = AuditEvent::count();
        $this->race($inputs, function ($directory, $processes, $connections) use ($intent, $track, $actors, $reviews, $before, $auditCount): void {
            touch($directory.'/start');
            $this->await(fn () => is_file($directory.'/locked-0') || is_file($directory.'/locked-1'), $processes);
            $winner = is_file($directory.'/locked-0') ? 0 : 1;
            $loser = 1 - $winner;
            $this->observeWait($connections[$loser], $connections[$winner], 'tracks', $track->id, $processes);
            touch($directory.'/commit');
            $results = $this->results($processes, $connections);
            $this->assertSame('saved', $results[$winner]['result']);
            $this->assertSame('rejected', $results[$loser]['result']);
            $this->assertArrayHasKey('publication', $results[$loser]['errors']);
            $this->assertStringContainsString('changed after publication review', $results[$loser]['errors']['publication'][0]);
            $current = $track->fresh();
            $this->assertSame($intent === 'publish' ? 'published' : 'draft', $current->status);
            $this->assertSame($reviews[$winner]['publication_version'] + 1, $current->publication_version);
            $this->assertSame($before['metadata_version'], $current->metadata_version);
            $after = $current->getAttributes();
            foreach (['status', 'publication_version', 'published_at', 'updated_at'] as $field) {
                unset($before[$field], $after[$field]);
            }
            $this->assertSame($before, $after);
            $this->assertSame($auditCount + 1, AuditEvent::count());
            $audit = AuditEvent::latest('id')->firstOrFail();
            $this->assertSame($actors[$winner]->id, $audit->actor_id);
            $this->assertSame($track->id, $audit->subject_id);
            $this->assertSame('catalog.track.'.($intent === 'publish' ? 'published' : 'unpublished'), $audit->action);
            $this->assertSame(CanonicalJson::encode(['schema_version' => 1, 'metadata_version' => $reviews[$winner]['metadata_version'],
                'previous_publication_version' => $reviews[$winner]['publication_version'], 'publication_version' => $current->publication_version]), CanonicalJson::encode($audit->context));
        });
    }

    public static function authorityOrdering(): array
    {
        return ['publish first' => ['publish', 0], 'publish withdrawal first' => ['publish', 1],
            'unpublish first' => ['unpublish', 0], 'unpublish withdrawal first' => ['unpublish', 1]];
    }

    #[DataProvider('authorityOrdering')]
    public function test_authority_withdrawal_and_reviewed_publication_serialize_on_the_exact_actor_row(string $intent, int $first): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture($intent);
        $review = app(PublishTrack::class)->review($track, $actor, $intent);
        $before = $track->fresh()->getAttributes();
        $auditCount = AuditEvent::count();
        $inputs = [$this->input('authority', $intent, $actor, $review),
            ['mode' => 'authority', 'operation' => 'withdraw', 'actor_id' => $actor->id, 'media_root' => Storage::disk('local')->path('')]];
        $this->race($inputs, function ($directory, $processes, $connections) use ($intent, $actor, $track, $before, $first, $auditCount): void {
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
                $this->assertSame($intent === 'publish' ? 'published' : 'draft', $track->fresh()->status);
                $this->assertSame($before['publication_version'] + 1, $track->fresh()->publication_version);
                $this->assertSame($auditCount + 1, AuditEvent::count());
                $audit = AuditEvent::latest('id')->firstOrFail();
                $this->assertSame($track->id, $audit->subject_id);
                $this->assertSame($actor->id, $audit->actor_id);
            } else {
                $this->assertSame('denied', $results[0]['result']);
                $this->assertSame($before, $track->fresh()->getAttributes());
                $this->assertSame($auditCount, AuditEvent::count());
            }
        });
    }

    public static function revocations(): array
    {
        $cases = [];
        foreach (['publish', 'unpublish'] as $intent) {
            foreach (['staff role' => ['is_admin', false], 'verified email' => ['email_verified_at', null],
                'MFA enrollment' => ['app_authentication_secret', null]] as $name => [$field, $value]) {
                $cases[$intent.' '.$name] = [$intent, $field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('revocations')]
    public function test_old_repeatable_read_snapshots_are_refused_then_current_authority_is_rechecked_standalone(string $intent, string $field, mixed $value): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture($intent);
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $command = app(PublishTrack::class);
        $review = $command->review($track, $actor, $intent);
        $before = $this->evidence();
        config(['database.connections.track_publication_revocation' => config('database.connections.'.DB::getDefaultConnection())]);
        $other = DB::connection('track_publication_revocation');
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $other->selectOne('SELECT CONNECTION_ID() AS id')->id);
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
            $this->assertSame($oldActor->getRawOriginal($field), User::findOrFail($actor->id)->getRawOriginal($field), 'Ordinary reads must retain the demonstrably stale eligible RR view.');
            foreach ([fn () => $command->review($track, $oldActor, $intent), fn () => $intent === 'publish' ? $command->publishReviewed($review, $oldActor) : $command->unpublishReviewed($review, $oldActor)] as $operation) {
                try {
                    $operation();
                    $this->fail('Reviewed publication inherited a stale caller RR snapshot.');
                } catch (LogicException $error) {
                    $this->assertSame('Reviewed track publication requires a standalone transaction.', $error->getMessage());
                }
                $this->assertSame(1, DB::transactionLevel());
            }
        } finally {
            DB::rollBack();
            DB::purge('track_publication_revocation');
        }
        try {
            foreach ([fn () => $command->review($track, $oldActor, $intent), fn () => $intent === 'publish' ? $command->publishReviewed($review, $oldActor) : $command->unpublishReviewed($review, $oldActor)] as $operation) {
                try {
                    $operation();
                    $this->fail('Current withdrawn authority authorized publication after leaving stale RR.');
                } catch (AuthorizationException) {
                }
                $this->assertSame(0, DB::transactionLevel());
            }
            $this->assertSame($before, $this->evidence());
            $this->assertSame($value, User::findOrFail($actor->id)->{$field});
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    #[DataProvider('intents')]
    public function test_metadata_edit_committed_first_invalidates_a_waiting_review_on_the_exact_track_row(string $intent): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture($intent);
        $editor = LicenseFixtures::admin();
        $review = app(PublishTrack::class)->review($track, $actor, $intent);
        $before = $track->fresh()->getAttributes();
        $this->assertNull($before['tags']);
        $auditCount = AuditEvent::count();
        $inputs = [$this->input('metadata', $intent, $actor, $review), ['mode' => 'metadata', 'operation' => 'edit',
            'actor_id' => $editor->id, 'track_id' => $track->id, 'metadata_version' => $track->metadata_version, 'media_root' => Storage::disk('local')->path('')]];
        $this->race($inputs, function ($directory, $processes, $connections) use ($track, $before, $auditCount, $editor): void {
            touch($directory.'/start-1');
            $this->await(fn () => is_file($directory.'/locked-1'), $processes);
            touch($directory.'/start-0');
            $this->observeWait($connections[0], $connections[1], 'tracks', $track->id, $processes);
            touch($directory.'/commit');
            $results = $this->results($processes, $connections);
            $this->assertSame('edited', $results[1]['result']);
            $this->assertSame('rejected', $results[0]['result']);
            $this->assertArrayHasKey('publication', $results[0]['errors']);
            $current = $track->fresh();
            $this->assertSame('Winning metadata edit', $current->mood);
            $this->assertSame($before['metadata_version'] + 1, $current->metadata_version);
            $this->assertSame($before['publication_version'], $current->publication_version);
            $this->assertSame($before['status'], $current->status);
            $this->assertSame([], $current->tags);
            $this->assertSame('[]', $current->getRawOriginal('tags'));
            // The ordinary metadata command canonicalizes omitted nullable tags while saving the winning mood.
            $before['tags'] = '[]';
            $after = $current->getAttributes();
            foreach (['mood', 'metadata_version', 'updated_at'] as $field) {
                unset($before[$field], $after[$field]);
            }
            $this->assertSame($before, $after);
            $this->assertSame($auditCount + 1, AuditEvent::count());
            $audit = AuditEvent::latest('id')->firstOrFail();
            $this->assertSame($editor->id, $audit->actor_id);
            $this->assertSame('catalog.track.metadata_updated', $audit->action);
        });
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['tracks', 'audit_events']);
    }

    private function race(array $inputs, callable $assertions): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'Independent workers require committed fixtures.');
        $directory = storage_path('framework/testing/track-publication-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/track-publication-guard-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_TRACK_PUBLICATION_RACE_DIRECTORY' => $directory, 'VASEY_TRACK_PUBLICATION_RACE_WORKER' => (string) $worker,
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
            $this->assertSame(0, $process->getExitCode(), 'Track publication worker failed: '.$process->getOutput().$process->getErrorOutput());
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
        $this->fail('Track publication workers never reached the required observed lock/barrier.');
    }
}
