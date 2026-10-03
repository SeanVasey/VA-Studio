<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\BindStemsToRecording;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Domain\Media\QueueMediaProcessing;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\RecordingFixtures;
use Tests\TestCase;

class StemsRecordingPreviewConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent processor and recording preview fencing requires MySQL; SQLite is not concurrency evidence.');
        }
    }

    public static function callerTransactions(): array
    {
        return ['root transaction' => [false], 'old caller snapshot' => [true]];
    }

    #[DataProvider('callerTransactions')]
    public function test_processor_completion_rejects_an_obsolete_preview_after_the_track_wait(bool $callerTransaction): void
    {
        $this->fakePrivateMediaStorage();
        ['actor' => $processor, 'track' => $track, 'stems' => $stems, 'data' => $data, 'media' => $media] = RecordingFixtures::draft();
        $binder = LicenseFixtures::admin();
        $this->assertNotSame($processor->id, $binder->id);
        $source = MediaFixtures::source($track, bytes: MediaFixtures::wav(1.2, 880));
        $run = app(QueueMediaProcessing::class)->handle($source, $processor);
        $originalIds = [...array_map(fn (MediaAsset $asset): int => $asset->id, array_values($media)), $stems->id];
        $originals = MediaAsset::whereIn('id', $originalIds)->orderBy('id')->get()->map->getAttributes()->all();
        $this->assertSame(0, DB::transactionLevel(), 'Worker fixtures must be committed.');
        $directory = $this->directory();
        $processes = [];
        try {
            $processes['processor'] = $this->worker('processor', ['run_id' => $run->id, 'pause_at_track' => true], $directory);
            $processes['binding'] = $this->worker('binding', ['track_id' => $track->id, 'stems_id' => $stems->id,
                'actor_id' => $binder->id, 'data' => $data, 'caller_transaction' => $callerTransaction], $directory);
            $ready = $this->ready($directory, $processes);
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertCount(3, array_unique([$parent, $ready['processor']['connection_id'], $ready['binding']['connection_id']]));
            $this->assertCount(3, array_unique([getmypid(), $ready['processor']['pid'], $ready['binding']['pid']]));
            touch($directory.'/start-processor');
            $this->await(fn () => is_file($directory.'/processor-locked-track'), $processes);
            touch($directory.'/start-binding');
            $this->await(fn () => $this->trackWait($ready['binding']['connection_id'], $ready['processor']['connection_id'], $track->id), $processes);
            touch($directory.'/release-processor');
            $results = $this->results($processes, $ready);

            $this->assertSame('processed', $results['processor']['result']);
            $this->assertSame('completed', $results['processor']['status']);
            $replacement = MediaProcessingRun::findOrFail($run->id);
            $this->assertSame('completed', $replacement->status);
            $this->assertSame('processed', $source->fresh()->status);
            $preview = $replacement->outputs()->where('role', 'preview_tagged')->sole();
            $this->assertSame($source->id, $preview->parent_asset_id);
            $this->assertNotSame($data['preview_asset_id'], $preview->id);
            $this->assertSame($preview->id, $track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->latest('id')->value('id'));
            $this->assertSame('rejected', $results['binding']['result'], json_encode($results['binding'], JSON_THROW_ON_ERROR));
            $this->assertStringContainsString('current preview', $results['binding']['errors']['master_asset_id'][0]);
            $this->assertSame($callerTransaction ? 1 : 0, $results['binding']['caller_transaction_level']);
            if ($callerTransaction) {
                $this->assertSame($data['preview_asset_id'], $results['binding']['snapshot_before']);
                $this->assertSame($data['preview_asset_id'], $results['binding']['snapshot_after']);
            }
            $this->assertDatabaseCount('stems_recordings', 0);
            $this->assertSame(0, AuditEvent::where('action', 'media.stems.recording_associated')->count());
            $this->assertSame($originals, MediaAsset::whereIn('id', $originalIds)->orderBy('id')->get()->map->getAttributes()->all());
            foreach ($originals as $original) {
                $this->assertSame($original['sha256'], hash_file('sha256', Storage::disk('local')->path($original['storage_path'])));
            }
        } finally {
            $this->cleanup($processes, $directory);
        }
    }

    public static function forgedSources(): array
    {
        return ['source submitted as stems' => ['stems'], 'source submitted as master' => ['master']];
    }

    #[DataProvider('forgedSources')]
    public function test_preview_fence_does_not_lock_a_quarantined_processing_source(string $submittedAs): void
    {
        $this->fakePrivateMediaStorage();
        ['actor' => $processor, 'track' => $track, 'stems' => $stems, 'data' => $data] = RecordingFixtures::draft();
        $binder = LicenseFixtures::admin();
        $this->assertNotSame($processor->id, $binder->id);
        $source = MediaFixtures::source($track, bytes: MediaFixtures::wav(1.2, 990));
        $run = app(QueueMediaProcessing::class)->handle($source, $processor);
        $directory = $this->directory();
        $processes = [];
        $oldTimeout = (int) DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')->seconds;
        $armed = false;
        $previewRead = false;
        DB::listen(function ($query) use (&$armed, &$previewRead): void {
            if ($armed && preg_match('/\Aselect\b/i', $query->sql) && str_contains($query->sql, 'from `media_assets`')
                && in_array('preview_tagged', $query->bindings, true) && in_array('ready', $query->bindings, true)) {
                $previewRead = true;
            }
        });
        try {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
            DB::beginTransaction();
            User::query()->lockForUpdate()->findOrFail($binder->id);
            Track::query()->lockForUpdate()->findOrFail($track->id);
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $processes['processor'] = $this->worker('processor', ['run_id' => $run->id, 'pause_at_track' => false], $directory);
            $ready = $this->ready($directory, $processes);
            $this->assertNotSame($parent, $ready['processor']['connection_id']);
            touch($directory.'/start-processor');
            $this->await(fn () => $this->trackWait($ready['processor']['connection_id'], $parent, $track->id), $processes);
            $sourceLock = DB::selectOne(<<<'SQL'
SELECT held.LOCK_MODE AS mode
FROM performance_schema.data_locks AS held
JOIN performance_schema.threads AS owner ON owner.THREAD_ID = held.THREAD_ID
WHERE held.ENGINE = 'INNODB' AND owner.PROCESSLIST_ID = ? AND held.OBJECT_SCHEMA = ?
  AND held.OBJECT_NAME = 'media_assets' AND held.INDEX_NAME = 'PRIMARY' AND held.LOCK_DATA = ?
  AND held.LOCK_TYPE = 'RECORD' AND held.LOCK_STATUS = 'GRANTED' AND held.LOCK_MODE LIKE 'X%'
LIMIT 1
SQL, [$ready['processor']['connection_id'], DB::connection()->getConfig('database'), (string) $source->id]);
            $this->assertNotNull($sourceLock, 'Processor must hold the exact source row while waiting on the binder track.');
            $this->assertStringStartsWith('X', $sourceLock->mode);
            $armed = true;
            try {
                app(BindStemsToRecording::class)->handle($submittedAs === 'stems' ? $source : $stems,
                    $submittedAs === 'master' ? ['master_asset_id' => $source->id] + $data : $data, $binder);
                $this->fail('A quarantined processing source was accepted as a deliverable.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('master_asset_id', $exception->errors());
            } finally {
                $armed = false;
            }
            $this->assertTrue($previewRead, 'The preview selection must execute while the processor still owns its source lock.');
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue($this->trackWait($ready['processor']['connection_id'], $parent, $track->id));
            $this->assertDatabaseCount('stems_recordings', 0);
            $this->assertSame(0, AuditEvent::where('action', 'media.stems.recording_associated')->count());
            DB::rollBack();
            $results = $this->results($processes, $ready);
            $this->assertSame('completed', $results['processor']['status']);
            $this->assertSame('completed', $run->fresh()->status);
        } finally {
            $armed = false;
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.$oldTimeout);
            $this->cleanup($processes, $directory);
        }
    }

    private function directory(): string
    {
        $directory = storage_path('framework/testing/recording-preview-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);

        return $directory;
    }

    private function worker(string $operation, array $input, string $directory): Process
    {
        $database = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/stems-recording-preview-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
            'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VASEY_RECORDING_PREVIEW_DIRECTORY' => $directory, 'VASEY_RECORDING_PREVIEW_OPERATION' => $operation,
        ], json_encode($input + ['media_root' => Storage::disk('local')->path('')], JSON_THROW_ON_ERROR), 40);
        $process->start();

        return $process;
    }

    private function ready(string $directory, array $processes): array
    {
        $this->await(fn () => collect(array_keys($processes))->every(fn ($operation) => is_file($directory.'/ready-'.$operation)), $processes);
        $ready = [];
        foreach ($processes as $operation => $process) {
            $ready[$operation] = json_decode(file_get_contents($directory.'/ready-'.$operation), true, 16, JSON_THROW_ON_ERROR);
        }

        return $ready;
    }

    private function results(array $processes, array $ready): array
    {
        $results = [];
        foreach ($processes as $operation => $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $output = $process->getOutput();
            $this->assertJson($output, 'Recording preview worker emitted invalid JSON: '.$output.$process->getErrorOutput());
            $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame($ready[$operation]['connection_id'], $result['connection_id']);
            $this->assertSame($ready[$operation]['pid'], $result['pid']);
            $this->assertSame(0, $result['transaction_level']);
            $results[$operation] = $result;
        }

        return $results;
    }

    private function trackWait(int $requester, int $blocker, int $trackId): bool
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

        return DB::selectOne($sql, [$requester, $blocker, DB::connection()->getConfig('database'), (string) $trackId])?->lock_status === 'WAITING';
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
                $this->assertTrue($process->isRunning(), 'Recording preview worker exited before the required barrier: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Recording preview workers did not reach the exact track wait/barrier.');
    }

    private function cleanup(array $processes, string $directory): void
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        (new Filesystem)->deleteDirectory($directory);
    }
}
