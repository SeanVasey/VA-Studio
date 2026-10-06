<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaUploadSession;
use App\Domain\Media\ResumableMediaUploads;
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
use Tests\TestCase;

class ResumableMediaUploadsConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent resumable upload serialization requires MySQL; SQLite is not concurrency evidence.');
        }
    }

    public static function competingOperations(): array
    {
        return ['duplicate chunk' => ['append', 'append'], 'duplicate completion' => ['complete', 'complete'],
            'cancellation wins' => ['cancel', 'complete'], 'completion wins' => ['complete', 'cancel']];
    }

    public static function mfaOperations(): array
    {
        return ['start' => ['start'], 'inspect' => ['inspect'], 'append' => ['append'], 'complete' => ['complete'], 'cancel' => ['cancel']];
    }

    #[DataProvider('mfaOperations')]
    public function test_required_mfa_withdrawn_during_native_actor_wait_refuses_upload_operation(string $operation): void
    {
        $this->fakePrivateMediaStorage();
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret($panel->getMultiFactorAuthenticationProviders()['app']->generateSecret());
        $track = Track::create(['title' => 'SYNTHETIC MFA withdrawal', 'slug' => 'mfa-withdrawal']);
        $bytes = MediaFixtures::png();
        $service = app(ResumableMediaUploads::class);
        $session = $service->start($track, 'artwork', strlen($bytes), hash('sha256', $bytes), 'synthetic.png', $actor);
        $service->append($session['id'], 0, UploadedFile::fake()->createWithContent('chunk.bin', $bytes), $actor);
        $before = MediaUploadSession::sole()->getAttributes();
        $files = Storage::disk('local')->allFiles();
        $audits = AuditEvent::count();
        $directory = storage_path('framework/testing/resumable-mfa-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        file_put_contents($directory.'/chunk.bin', $bytes);
        $processes = [];
        try {
            DB::beginTransaction();
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            $blocker = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $processes['withdrawal'] = $this->worker($directory, ['name' => 'withdrawal', 'pause' => false,
                'operation' => $operation, 'require_mfa' => true, 'actor_id' => $actor->id,
                'session_id' => $session['id'], 'track_id' => $track->id]);
            $this->await(fn () => is_file($directory.'/ready-withdrawal'), $processes);
            $ready = json_decode(file_get_contents($directory.'/ready-withdrawal'), true, 16, JSON_THROW_ON_ERROR);
            $this->assertNotSame($blocker, $ready['connection_id']);
            $this->assertNotSame(getmypid(), $ready['pid']);
            touch($directory.'/start-withdrawal');
            // The child passed the same required-MFA admission as HTTP, then
            // waits on this exact native actor record while removal commits.
            $this->await(fn () => $this->actorWait($ready['connection_id'], $blocker, $actor->id), $processes);
            DB::table('users')->where('id', $actor->id)->update(['app_authentication_secret' => null]);
            DB::commit();
            $process = $processes['withdrawal'];
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            $this->assertTrue($result['mfa_precheck']);
            $this->assertSame('unauthorized', $result['result']);
            $this->assertSame(0, $result['transaction_level']);
            $this->assertNull($actor->fresh()->getAppAuthenticationSecret());
            $this->assertSame($before, MediaUploadSession::sole()->getAttributes());
            $this->assertSame($files, Storage::disk('local')->allFiles());
            $this->assertSame($audits, AuditEvent::count());
            $this->assertDatabaseCount('media_upload_sessions', 1);
            $this->assertDatabaseCount('media_assets', 0);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    #[DataProvider('competingOperations')]
    public function test_native_actor_wait_serializes_duplicate_and_competing_upload_operations(string $first, string $second): void
    {
        $this->fakePrivateMediaStorage();
        $actor = LicenseFixtures::admin();
        $track = Track::create(['title' => 'SYNTHETIC concurrent upload', 'slug' => 'concurrent-upload']);
        $bytes = MediaFixtures::png();
        $service = app(ResumableMediaUploads::class);
        $session = $service->start($track, 'artwork', strlen($bytes), hash('sha256', $bytes), 'synthetic.png', $actor);
        if ($first !== 'append') {
            $service->append($session['id'], 0, UploadedFile::fake()->createWithContent('chunk.bin', $bytes), $actor);
        }
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/resumable-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        file_put_contents($directory.'/chunk.bin', $bytes);
        $processes = [];
        try {
            $processes['first'] = $this->worker($directory, ['name' => 'first', 'pause' => true, 'operation' => $first,
                'actor_id' => $actor->id, 'session_id' => $session['id']]);
            $processes['second'] = $this->worker($directory, ['name' => 'second', 'pause' => false, 'operation' => $second,
                'actor_id' => $actor->id, 'session_id' => $session['id']]);
            $this->await(fn () => is_file($directory.'/ready-first') && is_file($directory.'/ready-second'), $processes);
            $ready = [];
            foreach (array_keys($processes) as $name) {
                $ready[$name] = json_decode(file_get_contents($directory.'/ready-'.$name), true, 16, JSON_THROW_ON_ERROR);
            }
            $this->assertCount(3, array_unique([(int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
                $ready['first']['connection_id'], $ready['second']['connection_id']]));
            $this->assertCount(3, array_unique([getmypid(), $ready['first']['pid'], $ready['second']['pid']]));
            touch($directory.'/start-first');
            $this->await(fn () => is_file($directory.'/first-locked-actor'), $processes);
            touch($directory.'/start-second');
            $this->await(fn () => $this->actorWait($ready['second']['connection_id'], $ready['first']['connection_id'], $actor->id), $processes);
            touch($directory.'/release-first');
            $results = [];
            foreach ($processes as $name => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[$name] = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
                $this->assertSame($ready[$name]['connection_id'], $results[$name]['connection_id']);
                $this->assertSame(0, $results[$name]['transaction_level']);
            }
            $this->assertSame('saved', $results['first']['result']);
            $this->assertSame($first === $second ? 'saved' : 'rejected', $results['second']['result']);
            if ($first === 'append') {
                $this->assertSame(strlen($bytes), MediaUploadSession::where('public_id', $session['id'])->firstOrFail()->received_bytes);
                $this->assertCount(1, Storage::disk('local')->allFiles('resumable'));
                $this->assertDatabaseCount('media_assets', 0);
            } elseif ($first === 'cancel') {
                $this->assertSame('cancelled', MediaUploadSession::where('public_id', $session['id'])->firstOrFail()->status);
                $this->assertDatabaseCount('media_assets', 0);
                $this->assertSame([], Storage::disk('local')->allFiles('resumable'));
            } else {
                $asset = MediaAsset::sole();
                $this->assertSame($asset->id, $results['first']['asset_id']);
                if ($second === 'complete') {
                    $this->assertSame($asset->id, $results['second']['asset_id']);
                }
                $this->assertSame($bytes, Storage::disk('local')->get($asset->storage_path));
                $this->assertSame([], Storage::disk('local')->allFiles('resumable'));
                $this->assertSame(1, AuditEvent::where('action', 'media.upload.quarantined')->count());
                $this->assertSame(1, AuditEvent::where('action', 'media.upload.session_completed')->count());
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    private function worker(string $directory, array $input): Process
    {
        $database = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/resumable-media-upload-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
            'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ], json_encode($input + ['directory' => $directory, 'media_root' => Storage::disk('local')->path('')], JSON_THROW_ON_ERROR), 40);
        $process->start();

        return $process;
    }

    private function actorWait(int $requester, int $blocker, int $actor): bool
    {
        $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS lock_status
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = 'users' AND requested.INDEX_NAME = 'PRIMARY'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = ?
LIMIT 1
SQL;

        return DB::selectOne($sql, [$requester, $blocker, DB::connection()->getConfig('database'), (string) $actor])?->lock_status === 'WAITING';
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
        $this->fail('Resumable workers did not reach the exact native actor wait.');
    }
}
