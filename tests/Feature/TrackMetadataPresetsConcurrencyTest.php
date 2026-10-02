<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\TrackMetadataPreset;
use App\Domain\Catalog\TrackMetadataPresets;
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

class TrackMetadataPresetsConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent metadata preset row locking and Repeatable Read authority checks require MySQL; SQLite is not concurrency evidence.');
        }
    }

    private function preset(User $actor): TrackMetadataPreset
    {
        return app(TrackMetadataPresets::class)->handle(null, ['name' => 'Synthetic contention defaults', 'mood' => 'Original mood', 'tags' => ['Original tag']], $actor);
    }

    public function test_overlapping_edits_wait_on_the_exact_preset_row_and_only_one_revision_is_saved(): void
    {
        $actors = [LicenseFixtures::admin(), LicenseFixtures::admin()];
        $preset = $this->preset($actors[0]);
        $inputs = array_map(fn ($worker) => ['operation' => 'save', 'actor_id' => $actors[$worker]->id, 'preset_id' => $preset->id,
            'version' => 1, 'mood' => 'Winning mood '.$worker], [0, 1]);
        $this->race($inputs, function ($directory, $processes, $connections) use ($preset, $actors): void {
            touch($directory.'/start-0');
            touch($directory.'/start-1');
            $this->await(fn () => is_file($directory.'/locked-0') || is_file($directory.'/locked-1'), $processes);
            $winner = is_file($directory.'/locked-0') ? 0 : 1;
            $loser = 1 - $winner;
            $this->observeWait($connections[$loser], $connections[$winner], $preset->id, $processes);
            touch($directory.'/commit');
            $results = $this->results($processes, $connections);
            $this->assertSame('saved', $results[$winner]['result']);
            $this->assertSame('rejected', $results[$loser]['result']);
            $this->assertArrayHasKey('name', $results[$loser]['errors']);
            $this->assertStringContainsString('changed since you opened', $results[$loser]['errors']['name'][0]);
            $retained = $preset->fresh();
            $this->assertSame(2, $retained->version);
            $this->assertSame('Winning mood '.$winner, $retained->metadata['mood']);
            $this->assertSame(['Original tag'], $retained->metadata['tags']);
            $audit = AuditEvent::where('action', 'catalog.track_metadata_preset.updated')->sole();
            $this->assertSame($actors[$winner]->id, $audit->actor_id);
            $this->assertSame($preset->id, $audit->subject_id);
            $this->assertSame(['mood'], $audit->context['changed_fields']);
            $this->assertSame(2, $audit->context['version']);
            $this->assertDatabaseCount('tracks', 0);
        });
    }

    public static function archiveLockOrders(): array
    {
        return ['snapshot locks first' => [0], 'archive locks first' => [1]];
    }

    #[DataProvider('archiveLockOrders')]
    public function test_snapshot_and_archive_serialize_on_the_exact_preset_row(int $first): void
    {
        $actors = [LicenseFixtures::admin(), LicenseFixtures::admin()];
        $preset = $this->preset($actors[0]);
        $expected = app(TrackMetadataPresets::class)->snapshot($preset->id, $actors[0], 1);
        $inputs = [['operation' => 'snapshot', 'actor_id' => $actors[0]->id, 'preset_id' => $preset->id, 'version' => 1],
            ['operation' => 'archive', 'actor_id' => $actors[1]->id, 'preset_id' => $preset->id, 'version' => 1]];
        $this->race($inputs, function ($directory, $processes, $connections) use ($preset, $expected, $actors, $first): void {
            $second = 1 - $first;
            touch($directory.'/start-'.$first);
            $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
            touch($directory.'/start-'.$second);
            $this->observeWait($connections[$second], $connections[$first], $preset->id, $processes);
            touch($directory.'/commit');
            $results = $this->results($processes, $connections);
            $this->assertSame('archived', $results[1]['result']);
            $this->assertSame(2, $preset->fresh()->version);
            $this->assertNotNull($preset->fresh()->archived_at);
            if ($first === 0) {
                $this->assertSame('copied', $results[0]['result']);
                $this->assertSame($expected, $results[0]['snapshot']);
            } else {
                $this->assertSame('rejected', $results[0]['result']);
                $this->assertArrayHasKey('name', $results[0]['errors']);
                $this->assertArrayNotHasKey('snapshot', $results[0]);
            }
            $audit = AuditEvent::where('action', 'catalog.track_metadata_preset.archived')->sole();
            $this->assertSame($actors[1]->id, $audit->actor_id);
            $this->assertSame($preset->id, $audit->subject_id);
            $this->assertSame(['archived_at'], $audit->context['changed_fields']);
            $this->assertSame(2, $audit->context['version']);
            $this->assertSame(0, AuditEvent::where('action', 'catalog.track_metadata_preset.updated')->count());
            $this->assertDatabaseCount('tracks', 0);
        });
    }

    public static function revocations(): array
    {
        return ['staff role' => ['is_admin', false], 'verified email' => ['email_verified_at', null],
            'MFA enrollment' => ['app_authentication_secret', null]];
    }

    #[DataProvider('revocations')]
    public function test_every_domain_operation_rejects_revocation_hidden_by_an_old_repeatable_read_snapshot(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        Filament::setCurrentPanel($panel);
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $command = app(TrackMetadataPresets::class);
        $preset = $this->preset($actor);
        $metadata = $command->snapshot($preset->id, $actor)['metadata'];
        $before = $this->evidence();
        config(['database.connections.preset_authority_revocation' => config('database.connections.'.DB::getDefaultConnection())]);
        $other = DB::connection('preset_authority_revocation');
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $other->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $this->assertSame(0, DB::transactionLevel(), 'Revocation fixtures must already be committed.');
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
            // Demonstrate that an ordinary query still reads the eligible old snapshot.
            $this->assertSame($oldActor->getRawOriginal($field), User::findOrFail($actor->id)->getRawOriginal($field));
            foreach ([
                fn () => $command->active($oldActor), fn () => $command->snapshot($preset->id, $oldActor),
                fn () => $command->handle(null, ['name' => 'Denied create'], $oldActor),
                fn () => $command->handle($preset, ['version' => 1, 'mood' => 'Denied edit'], $oldActor),
                fn () => $command->archive($preset, 1, $oldActor),
                fn () => $command->createDraft(['title' => 'Denied draft', 'slug' => 'denied-draft'] + $metadata, $oldActor),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('An old Repeatable Read snapshot restored revoked preset authority.');
                } catch (AuthorizationException) {
                }
                $this->assertSame(1, DB::transactionLevel(), 'Denied nested domain operation must not close the caller transaction.');
            }
        } finally {
            DB::rollBack();
            DB::purge('preset_authority_revocation');
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame($value, User::findOrFail($actor->id)->{$field});
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['track_metadata_presets', 'tracks', 'audit_events']);
    }

    private function race(array $inputs, callable $assertions): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'Independent workers require committed fixtures.');
        $directory = storage_path('framework/testing/metadata-presets-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/track-metadata-presets-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_PRESET_RACE_DIRECTORY' => $directory, 'VASEY_PRESET_RACE_WORKER' => (string) $worker,
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

    private function observeWait(int $requester, int $blocker, int $id, array $processes): void
    {
        $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS lock_status
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = 'track_metadata_presets' AND requested.INDEX_NAME = 'PRIMARY'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = ?
LIMIT 1
SQL;
        $this->await(fn () => DB::selectOne($sql, [$requester, $blocker, DB::connection()->getDatabaseName(), (string) $id])?->lock_status === 'WAITING', $processes);
    }

    private function results(array $processes, array $connections): array
    {
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), 'Metadata-preset worker failed: '.$process->getOutput().$process->getErrorOutput());
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
        $this->fail('Metadata-preset workers never reached the required observed lock/barrier.');
    }
}
