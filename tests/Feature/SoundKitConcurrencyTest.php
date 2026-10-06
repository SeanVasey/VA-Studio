<?php

namespace Tests\Feature;

use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitIntake;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class SoundKitConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Sound-kit independent-process record waits require native MySQL.');
        }
    }

    private function fixture(bool $mfa = false): array
    {
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $actor = LicenseFixtures::admin();
        if ($mfa) {
            $panel = Filament::getPanel('admin');
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
            $actor->saveAppAuthenticationSecret($panel->getMultiFactorAuthenticationProviders()['app']->generateSecret());
        }
        $draft = app(SoundKitDrafts::class)->save(null, ['title' => 'Synthetic native kit', 'provenance' => 'Native test generator'], $actor);
        $directory = $this->directory();
        file_put_contents($directory.'/upload.zip', StemsFixtures::zip([['name' => 'Kick.wav']]));
        $common = ['actor_id' => $actor->id, 'draft_id' => $draft->id, 'version' => 1, 'upload_path' => $directory.'/upload.zip',
            'private_root' => Storage::disk('local')->path(''), 'require_mfa' => $mfa];

        return [$actor, $draft, $directory, $common];
    }

    public static function duplicates(): array
    {
        return ['same upload' => ['upload'], 'same processing' => ['process']];
    }

    #[DataProvider('duplicates')]
    public function test_native_actor_wait_serializes_duplicate_upload_or_processing(string $operation): void
    {
        [$actor, $draft, $directory, $common] = $this->fixture();
        if ($operation === 'process') {
            $revision = app(SoundKitIntake::class)->handle($draft->id, new UploadedFile($common['upload_path'], 'kit.zip', null, null, true), 1, $actor);
            $common['revision_id'] = $revision->id;
        }
        $processes = [];
        try {
            foreach (['first', 'second'] as $name) {
                $processes[$name] = $this->worker($directory, $common + ['name' => $name, 'operation' => $operation, 'pause' => $name === 'first']);
            }
            $ready = $this->ready($directory, $processes);
            touch($directory.'/start-first');
            $this->await(fn () => is_file($directory.'/locked-first'), $processes);
            touch($directory.'/start-second');
            $this->await(fn () => $this->recordWait($ready['second']['connection_id'], $ready['first']['connection_id'], 'users', $actor->id), $processes);
            touch($directory.'/release-first');
            $results = $this->results($processes);
            $this->assertSame('saved', $results['first']['result']);
            $this->assertSame('saved', $results['second']['result']);
            $this->assertSame($results['first']['id'], $results['second']['id']);
            $this->assertSame(1, SoundKitRevision::count());
            $this->assertSame(2, $draft->fresh()->version);
            $this->assertSame(1, AuditEvent::where('action', 'sound_kit.revision.received')->count());
            if ($operation === 'process') {
                $this->assertSame('ready', SoundKitRevision::sole()->status);
                $this->assertSame(1, SoundKitRevision::sole()->attempts);
                $this->assertSame(1, AuditEvent::where('action', 'sound_kit.revision.verified')->count());
            }
        } finally {
            $this->cleanup($directory, $processes);
        }
    }

    public static function operations(): array
    {
        return array_combine(['create', 'edit', 'snapshot', 'upload', 'retry', 'process'], array_map(fn ($op) => [$op], ['create', 'edit', 'snapshot', 'upload', 'retry', 'process']));
    }

    #[DataProvider('operations')]
    public function test_mfa_withdrawal_committed_during_actor_wait_refuses_kit_operation(string $operation): void
    {
        [$actor, $draft, $directory, $common] = $this->fixture(true);
        $revision = app(SoundKitIntake::class)->handle($draft->id, new UploadedFile($common['upload_path'], 'kit.zip', null, null, true), 1, $actor);
        $common['revision_id'] = $revision->id;
        $common['version'] = 2;
        $before = $revision->getAttributes();
        $draftBefore = $draft->fresh()->getAttributes();
        $processes = [];
        try {
            DB::beginTransaction();
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            $blocker = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $processes['withdrawal'] = $this->worker($directory, $common + ['name' => 'withdrawal', 'operation' => $operation]);
            $ready = $this->ready($directory, $processes);
            touch($directory.'/start-withdrawal');
            $this->await(fn () => $this->recordWait($ready['withdrawal']['connection_id'], $blocker, 'users', $actor->id), $processes);
            DB::table('users')->where('id', $actor->id)->update(['app_authentication_secret' => null]);
            DB::commit();
            $result = $this->results($processes)['withdrawal'];
            $this->assertTrue($result['mfa_precheck']);
            $this->assertSame($operation === 'process' ? 'saved' : 'unauthorized', $result['result']);
            $this->assertSame($before, $revision->fresh()->getAttributes());
            $this->assertSame($draftBefore, $draft->fresh()->getAttributes());
            $this->assertSame(1, SoundKitDraft::count());
            $this->assertSame(1, SoundKitRevision::count());
            $this->assertSame(0, AuditEvent::where('action', 'sound_kit.revision.verified')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanup($directory, $processes);
        }
    }

    public function test_mfa_withdrawal_during_final_actor_wait_prevents_verified_commit_after_real_scanning(): void
    {
        [$actor, $draft, $directory, $common] = $this->fixture(true);
        $revision = app(SoundKitIntake::class)->handle($draft->id, new UploadedFile($common['upload_path'], 'kit.zip', null, null, true), 1, $actor);
        $common['revision_id'] = $revision->id;
        $processes = [];
        try {
            $processes['finalize'] = $this->worker($directory, $common + ['name' => 'finalize', 'operation' => 'process', 'pause_scan' => true]);
            $ready = $this->ready($directory, $processes);
            touch($directory.'/start-finalize');
            $this->await(fn () => is_file($directory.'/scanned-finalize'), $processes);
            DB::beginTransaction();
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            $blocker = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            touch($directory.'/release-scan');
            $this->await(fn () => $this->recordWait($ready['finalize']['connection_id'], $blocker, 'users', $actor->id), $processes);
            DB::table('users')->where('id', $actor->id)->update(['app_authentication_secret' => null]);
            DB::commit();
            $this->assertSame('quarantined', $this->results($processes)['finalize']['status']);
            $this->assertSame('authority_changed', $revision->fresh()->failure_code);
            $this->assertNull($revision->fresh()->archive_path);
            $this->assertSame(0, AuditEvent::where('action', 'sound_kit.revision.verified')->count());
            $this->assertCount(1, array_filter(Storage::disk('local')->allFiles('sound-kits'), fn ($file) => str_ends_with($file, '/samples.zip')));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanup($directory, $processes);
        }
    }

    private function directory(): string
    {
        $directory = storage_path('framework/testing/sound-kit-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);

        return $directory;
    }

    private function worker(string $directory, array $input): Process
    {
        $db = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/sound-kit-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'DB_CHARSET' => (string) $db['charset'], 'DB_COLLATION' => (string) $db['collation'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ], json_encode($input + ['directory' => $directory], JSON_THROW_ON_ERROR), 40);
        $process->start();

        return $process;
    }

    private function ready(string $directory, array $processes): array
    {
        $ready = [];
        foreach ($processes as $name => $process) {
            $this->await(fn () => is_file($directory.'/ready-'.$name), $processes);
            $ready[$name] = json_decode(file_get_contents($directory.'/ready-'.$name), true, 16, JSON_THROW_ON_ERROR);
            $this->assertNotSame(getmypid(), $ready[$name]['pid']);
            $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $ready[$name]['connection_id']);
        }

        return $ready;
    }

    private function results(array $processes): array
    {
        $results = [];
        foreach ($processes as $name => $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $results[$name] = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame(0, $results[$name]['transaction_level']);
        }

        return $results;
    }

    private function recordWait(int $requester, int $blocker, string $table, int $id): bool
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

        return DB::selectOne($sql, [$requester, $blocker, DB::getDatabaseName(), $table, (string) $id])?->lock_status === 'WAITING';
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
        $this->fail('Kit workers did not reach the exact native record wait.');
    }

    private function cleanup(string $directory, array $processes): void
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        (new Filesystem)->deleteDirectory($directory);
    }
}
