<?php

namespace Tests\Feature;

use App\Domain\Catalog\ReadTrackPublicationManifest;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class TrackPublicationManifestConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent publication manifest actor/track lock observations and stale Repeatable Read refusal require MySQL; SQLite is not concurrency evidence.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function input(string $mode, string $operation, int $actorId, int $trackId): array
    {
        return ['mode' => $mode, 'operation' => $operation, 'actor_id' => $actorId, 'track_id' => $trackId,
            'media_root' => Storage::disk('local')->path('')];
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['tracks', 'audit_events']);
    }

    public static function authorityOrdering(): array
    {
        return ['capture locks first' => [0], 'authority withdrawal locks first' => [1]];
    }

    #[DataProvider('authorityOrdering')]
    public function test_capture_and_authority_withdrawal_serialize_on_the_exact_actor_row(int $first): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $track = $fixture['track'];
        $before = $this->evidence();
        $manifest = app(ReadTrackPublicationManifest::class)->handle($track->id, $actor);
        $inputs = [$this->input('authority', 'capture', $actor->id, $track->id), $this->input('authority', 'withdraw', $actor->id, $track->id)];
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $actor, $manifest): void {
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
                $this->assertSame('captured', $results[0]['result']);
                $this->assertSame($manifest->hash(), $results[0]['hash']);
                $this->assertSame($actor->id, $results[0]['actor_id']);
            } else {
                $this->assertSame('denied', $results[0]['result']);
                $this->assertArrayNotHasKey('hash', $results[0]);
            }
        });
        $this->assertSame($before, $this->evidence(), 'Capture must not create an audit or change the track in either authority ordering.');
    }

    public static function revocations(): array
    {
        return ['staff role' => ['is_admin', false], 'verified email' => ['email_verified_at', null],
            'MFA enrollment' => ['app_authentication_secret', null]];
    }

    #[DataProvider('revocations')]
    public function test_old_repeatable_read_authority_snapshot_is_refused_then_standalone_capture_uses_current_authority(string $field, mixed $value): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $track = $fixture['track'];
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $reader = app(ReadTrackPublicationManifest::class);
        $reader->handle($track->id, $actor);
        $before = $this->evidence();
        config(['database.connections.track_manifest_revocation' => config('database.connections.'.DB::getDefaultConnection())]);
        $other = DB::connection('track_manifest_revocation');
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
            $this->assertSame($oldActor->getRawOriginal($field), User::findOrFail($actor->id)->getRawOriginal($field), 'Ordinary reads must demonstrate the stale eligible Repeatable Read view.');
            try {
                $reader->handle($track->id, $oldActor);
                $this->fail('Capture inherited a stale caller transaction.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('standalone', $error->getMessage());
            }
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
            DB::purge('track_manifest_revocation');
        }
        try {
            try {
                $reader->handle($track->id, $oldActor);
                $this->fail('A stale caller model authorized a new standalone capture.');
            } catch (AuthorizationException) {
            }
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($before, $this->evidence());
            $this->assertSame($value, User::findOrFail($actor->id)->{$field});
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public function test_metadata_writer_committed_first_is_observed_after_the_exact_track_row_wait(): void
    {
        $this->trackWriterFirst('edit');
    }

    public function test_publication_writer_committed_first_is_observed_after_the_exact_track_row_wait(): void
    {
        $this->trackWriterFirst('publish');
    }

    private function trackWriterFirst(string $operation): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $writer = LicenseFixtures::admin();
        $track = $fixture['track'];
        $before = $track->fresh()->getAttributes();
        $auditCount = DB::table('audit_events')->count();
        $inputs = [$this->input('track', 'capture', $actor->id, $track->id),
            $this->input('track', $operation, $writer->id, $track->id) + ['metadata_version' => $track->metadata_version]];
        $this->race($inputs, function ($directory, $processes, $connections) use ($operation, $track, $actor, $writer, $before, $auditCount): void {
            touch($directory.'/start-1');
            $this->await(fn () => is_file($directory.'/locked-1'), $processes);
            touch($directory.'/start-0');
            $this->observeWait($connections[0], $connections[1], 'tracks', $track->id, $processes);
            touch($directory.'/commit');
            $results = $this->results($processes, $connections);
            $this->assertSame('saved', $results[1]['result']);
            $this->assertSame('captured', $results[0]['result']);
            $current = $track->fresh();
            $this->assertSame($current->metadata_version, $results[0]['track']['metadata_version']);
            $this->assertSame($current->publication_version, $results[0]['track']['publication_version']);
            $this->assertSame($current->status, $results[0]['track']['status']);
            $this->assertSame($current->mood, $results[0]['track']['mood']);
            $this->assertSame($operation === 'edit' ? $before['metadata_version'] + 1 : $before['metadata_version'], $current->metadata_version);
            $this->assertSame($operation === 'publish' ? $before['publication_version'] + 1 : $before['publication_version'], $current->publication_version);
            $this->assertSame($auditCount + 1, DB::table('audit_events')->count(), 'Only the writer may record an audit.');
            $this->assertSame($writer->id, (int) DB::table('audit_events')->latest('id')->value('actor_id'));
            $this->assertSame(app(ReadTrackPublicationManifest::class)->handle($track->id, $actor)->hash(), $results[0]['hash']);
        });
    }

    private function race(array $inputs, callable $assertions): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'Independent workers require committed fixtures.');
        $directory = storage_path('framework/testing/track-publication-manifest-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/track-publication-manifest-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_TRACK_PUBLICATION_MANIFEST_RACE_DIRECTORY' => $directory, 'VASEY_TRACK_PUBLICATION_MANIFEST_RACE_WORKER' => (string) $worker,
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
            $this->assertSame(0, $process->getExitCode(), 'Track publication manifest worker failed: '.$process->getOutput().$process->getErrorOutput());
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
        $this->fail('Track publication manifest workers never reached the required observed lock/barrier.');
    }
}
