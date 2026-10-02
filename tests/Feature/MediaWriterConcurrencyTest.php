<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\ReadTrackPublicationManifest;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Domain\Media\Models\StemsRecording;
use App\Domain\Media\QueueMediaProcessing;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MediaFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class MediaWriterConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent media actor/resource fences and old Repeatable Read snapshots require MySQL; SQLite is not concurrency evidence.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public static function ordering(): array
    {
        $cases = [];
        foreach (['queue', 'intake', 'bind', 'complete', 'failure'] as $writer) {
            foreach ([0, 1] as $first) {
                $cases[$writer.' / '.($first === 0 ? 'media first' : 'publication first')] = [$writer, $first];
            }
        }

        return $cases;
    }

    #[DataProvider('ordering')]
    public function test_media_and_publication_writers_serialize_without_a_late_actor_fk_cycle(string $writer, int $first): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $track = app(PublishTrack::class)->unpublish($fixture['track'], $actor);
        $manifest = app(ReadTrackPublicationManifest::class)->handle($track->id, $actor);
        $source = MediaFixtures::source($track, 'artwork', $writer === 'failure' ? 'synthetic invalid artwork' : null);
        $input = ['writer' => $writer, 'source_id' => $source->id];
        if (in_array($writer, ['complete', 'failure'], true)) {
            $run = app(QueueMediaProcessing::class)->handle($source, $actor);
            $input['run_id'] = $run->id;
        }
        if ($writer === 'bind') {
            $source = MediaFixtures::source($track, 'stems_zip', StemsFixtures::zip([['name' => 'Tone.wav']]));
            $run = app(QueueMediaProcessing::class)->handle($source, $actor);
            app(MediaProcessor::class)->handle($run->id);
            $input['stems_id'] = $run->outputs()->sole()->id;
            $input['data'] = ['master_asset_id' => $fixture['media']['master_wav']->id,
                'preview_asset_id' => $fixture['media']['preview_tagged']->id,
                'verification_reference' => 'SYNTHETIC-RACE', 'same_recording_confirmed' => true];
        }
        $review = app(PublishTrack::class)->review($track, $actor, 'publish');
        $retained = DB::table('offer_revisions')->orderBy('id')->get()->toJson();
        $oldMedia = $fixture['media']['artwork']->fresh()->getAttributes();
        $audits = AuditEvent::count();
        $common = ['actor_id' => $actor->id, 'media_root' => Storage::disk('local')->path(''), 'track_id' => $track->id];
        $inputs = [$common + ['operation' => 'media'] + $input,
            $common + ['operation' => 'publish', 'review' => $review]];
        $inputs[$first] += ['pause_table' => $first === 1 ? 'users' : ($writer === 'failure' ? 'media_processing_runs' : 'tracks'),
            'pause_id' => $first === 1 ? $actor->id : ($writer === 'failure' ? $run->id : $track->id),
            'pause_nth' => $writer === 'failure' && $first === 0 ? 2 : 1];
        $this->race($inputs, function ($directory, $processes, $connections) use ($writer, $first, $actor, $input): void {
            $second = 1 - $first;
            touch($directory.'/start-'.$first);
            $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
            touch($directory.'/start-'.$second);
            $this->observeWait($connections[$second], $connections[$first], 'users', $actor->id, $processes);
            touch($directory.'/release-'.$first);
            $results = $this->results($processes, $connections);
            $this->assertSame('published', $results[1]['result'], json_encode($results[1], JSON_THROW_ON_ERROR));
            $this->assertSame('users', $results[1]['locks'][0]['table']);
            $locks = array_column($results[0]['locks'], 'table');
            $users = array_search('users', $locks, true);
            $this->assertNotFalse($users);
            // A worker's independent short claim may precede its attribution fence.
            $resource = array_search($writer === 'failure' ? 'media_processing_runs' : 'tracks', $locks, true);
            if ($writer === 'failure') {
                $resource = array_keys($locks, 'media_processing_runs', true)[1];
            }
            $this->assertLessThan($resource, $users);
            if ($writer === 'failure' || ($writer === 'complete' && $first === 1)) {
                $this->assertSame('media-failed', $results[0]['result']);
                $this->assertSame('failed', MediaProcessingRun::findOrFail($input['run_id'])->status);
                $this->assertSame([], MediaProcessingRun::findOrFail($input['run_id'])->output_asset_ids ?? []);
            } elseif ($first === 1 && in_array($writer, ['queue', 'bind'], true)) {
                $this->assertSame('blocked', $results[0]['result']);
            } else {
                $this->assertSame('media-saved', $results[0]['result'], json_encode($results[0], JSON_THROW_ON_ERROR));
                $saved = match ($writer) {
                    'queue', 'complete' => MediaProcessingRun::findOrFail($results[0]['row']['id']),
                    'intake' => MediaAsset::findOrFail($results[0]['row']['id']),
                    'bind' => StemsRecording::findOrFail($results[0]['row']['id']),
                };
                $this->assertSame($results[0]['row'], $saved->getAttributes());
            }
        });
        $this->assertSame('published', $track->fresh()->status);
        $this->assertSame($review['publication_version'] + 1, $track->fresh()->publication_version);
        $this->assertSame($retained, DB::table('offer_revisions')->orderBy('id')->get()->toJson());
        $this->assertSame($oldMedia, $fixture['media']['artwork']->fresh()->getAttributes());
        $expectedMediaAudit = $writer === 'failure' || $writer === 'intake' || $first === 0 || $writer === 'complete';
        $this->assertSame($audits + ($expectedMediaAudit ? 2 : 1), AuditEvent::count());
        $this->assertNotSame($manifest->hash(), app(ReadTrackPublicationManifest::class)->handle($track->id, $actor)->hash());
    }

    private function race(array $inputs, callable $coordinate): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/media-writer-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/media-writer-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_MEDIA_WRITER_DIRECTORY' => $directory, 'VASEY_MEDIA_WRITER_WORKER' => (string) $worker,
                ], json_encode($input, JSON_THROW_ON_ERROR), 40);
                $process->start();
                $processes[] = $process;
            }
            $this->await(fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), $processes);
            $ready = array_map(fn ($worker) => json_decode(file_get_contents($directory.'/ready-'.$worker), true, 16, JSON_THROW_ON_ERROR), [0, 1]);
            $connections = array_column($ready, 'connection_id');
            $this->assertCount(3, array_unique([...$connections, (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]));
            $this->assertCount(3, array_unique([...array_column($ready, 'pid'), getmypid()]));
            foreach ($ready as $index => $row) {
                $this->assertTrue($row['retained_admin']);
                $this->assertTrue($row['retained_verified_email']);
                if ($inputs[$index]['require_mfa'] ?? false) {
                    $this->assertTrue($row['retained_enrollment']);
                }
            }
            $coordinate($directory, $processes, $connections);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $filesystem->deleteDirectory($directory);
        }
    }

    private function results(array $processes, array $connections): array
    {
        $results = [];
        foreach ($processes as $index => $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), 'Rights writer process failed: '.$process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame($connections[$index], $result['connection_id']);
            $this->assertSame(0, $result['transaction_level']);
            $results[] = $result;
        }

        return $results;
    }

    private function observeWait(int $requester, int $blocker, string $table, int $id, array $processes): void
    {
        $this->await(fn () => $this->waiting($requester, $blocker, $table, $id), $processes);
    }

    private function waiting(int $requester, int $blocker, string $table, int $id): bool
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

        return DB::selectOne($sql, [$requester, $blocker, DB::connection()->getConfig('database'), $table, (string) $id])?->lock_status === 'WAITING';
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
                $this->assertTrue($process->isRunning(), 'A rights worker exited before the required barrier: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Rights writers did not reach the required exact row wait/barrier.');
    }
}
