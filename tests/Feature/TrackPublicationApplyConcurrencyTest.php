<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Domain\Media\QueueMediaProcessing;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ExclusiveSelectionFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class TrackPublicationApplyConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Reviewed publication apply requires independent MySQL actor, track and scope lock observations; SQLite is not concurrency evidence.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public static function writers(): array
    {
        $cases = [];
        foreach (['metadata', 'offer', 'scope', 'finalize', 'media', 'authority'] as $writer) {
            foreach ([0, 1] as $first) {
                $cases[$writer.' / '.($first === 0 ? 'publication first' : 'writer first')] = [$writer, $first];
            }
        }

        return $cases;
    }

    #[DataProvider('writers')]
    public function test_reviewed_publication_and_participating_writers_serialize_current_evidence(string $writer, int $first): void
    {
        $this->freezeSecond();
        ExclusiveSelectionFixtures::configure();
        if ($writer === 'finalize') {
            FinalizationFixtures::configure();
            Queue::fake();
            $gateway = PaymentFixtures::gateway();
            $this->app->instance(StripeCheckoutGateway::class, $gateway);
            $this->app->instance(StripePaymentGateway::class, $gateway);
            $fixture = FinalizationFixtures::confirmed($gateway, true);
        } else {
            $fixture = $writer === 'scope' ? ExclusiveSelectionFixtures::active() : QuoteFixtures::selection();
        }
        $actor = $fixture['actor'];
        $other = LicenseFixtures::admin();
        $track = app(PublishTrack::class)->unpublish($fixture['track'], $actor);
        if ($writer === 'offer') {
            // Draft changes do not alter frozen publication evidence. Race the actual publication
            // transaction, not a preceding draft-save transaction that releases its track fence.
            app(SaveOfferDraft::class)->handle($fixture['offer'], ['price_minor' => $fixture['offer']->price_minor + 1], $other);
        }
        $run = null;
        if ($writer === 'media') {
            $source = MediaFixtures::source($track, 'artwork');
            $run = app(QueueMediaProcessing::class)->handle($source, $other);
        }
        $review = app(PublishTrack::class)->reviewManifest($track, $actor);
        $common = ['track_id' => $track->id, 'media_root' => Storage::disk('local')->path(''), 'now' => now()->toISOString(),
            'scope_id' => $fixture['scope']->id ?? null, 'offer_id' => $fixture['offer']->id,
            'metadata_version' => $track->metadata_version, 'config' => [
                'commerce' => config('commerce'), 'media' => config('media'), 'payments' => config('payments'),
            ]];
        $table = match ($writer) {
            'scope', 'finalize' => 'rights_scopes', 'authority' => 'users', default => 'tracks'
        };
        $id = match ($table) {
            'rights_scopes' => $fixture['scope']->id, 'users' => $actor->id, default => $track->id
        };
        $inputs = [$common + ['operation' => 'apply', 'actor_id' => $actor->id, 'review' => $review],
            $common + ['operation' => $writer, 'actor_id' => $writer === 'authority' ? $actor->id : $other->id,
                'payment_id' => $fixture['payment']->id ?? null, 'run_id' => $run?->id]];
        $inputs[$first]['pause_table'] = $table;
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $writer, $table, $id, $review, $track, $actor, $run): void {
            $second = 1 - $first;
            touch($directory.'/start-'.$first);
            $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
            touch($directory.'/start-'.$second);
            $this->observeWait($connections[$second], $connections[$first], $table, $id, $processes);
            touch($directory.'/commit');
            $results = $this->results($processes, $connections);
            $this->assertSame($first === 0 ? 'published' : ($writer === 'authority' ? 'denied' : 'rejected'), $results[0]['result'], json_encode($results));
            $expectedWriter = $writer === 'finalize' ? 'paid' : ($writer === 'media' && $first === 0 ? 'media-failed' : 'saved');
            $this->assertSame($expectedWriter, $results[1]['result'], json_encode($results));
            if ($writer === 'media' && $first === 0) {
                $this->assertSame('track_published', $results[1]['code']);
                $failed = MediaProcessingRun::findOrFail($run->id);
                $this->assertSame('failed', $failed->status);
                $this->assertSame([], $failed->output_asset_ids ?? []);
            }
            $current = $track->fresh();
            $this->assertSame($first === 0 ? 'published' : 'draft', $current->status);
            $this->assertSame($review['publication_version'] + ($first === 0 ? 1 : 0), $current->publication_version);
            $audits = DB::table('audit_events')->where('action', 'catalog.track.published')->where('subject_id', $track->id)->get();
            $this->assertCount($first === 0 ? 2 : 1, $audits);
            if ($first === 0) {
                $this->assertSame($review['manifest_hash'], json_decode($audits->last()->context, true)['manifest_hash']);
            }
            if ($writer === 'authority') {
                $this->assertFalse(User::findOrFail($actor->id)->is_admin);
            }
            if ($writer === 'finalize') {
                $this->assertSame('paid', OrderFinalization::sole()->outcome);
                $this->assertDatabaseCount('license_grants', 1);
                $this->assertDatabaseCount('exclusive_sales', 1);
                // The finalizer actually inserts revision/license FK references while publication
                // is waiting with a shared revision lock; an exclusive revision lock would cycle.
                $this->assertContains('offer_revisions:shared', $results[0]['locks']);
                $this->assertContains('rights_scopes:exclusive', $results[0]['locks']);
            }
        });
    }

    public function test_one_actor_cannot_apply_the_same_review_twice_concurrently(): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $track = app(PublishTrack::class)->unpublish($fixture['track'], $actor);
        $review = app(PublishTrack::class)->reviewManifest($track, $actor);
        $input = ['operation' => 'apply', 'actor_id' => $actor->id, 'track_id' => $track->id, 'review' => $review,
            'media_root' => Storage::disk('local')->path(''), 'now' => now()->toISOString(),
            'config' => ['commerce' => config('commerce'), 'media' => config('media'), 'payments' => config('payments')]];
        $this->race([$input + ['pause_table' => 'users'], $input], function ($directory, $processes, $connections) use ($actor): void {
            touch($directory.'/start-0');
            $this->await(fn () => is_file($directory.'/locked-0'), $processes);
            touch($directory.'/start-1');
            $this->observeWait($connections[1], $connections[0], 'users', $actor->id, $processes);
            touch($directory.'/commit');
            $this->assertSame(['published', 'rejected'], array_column($this->results($processes, $connections), 'result'));
        });
        $this->assertSame($review['publication_version'] + 1, $track->fresh()->publication_version);
    }

    private function race(array $inputs, callable $assertions): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'Independent workers require committed fixtures.');
        $directory = storage_path('framework/testing/track-publication-apply-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/track-publication-apply-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_TRACK_PUBLICATION_APPLY_RACE_DIRECTORY' => $directory, 'VASEY_TRACK_PUBLICATION_APPLY_RACE_WORKER' => (string) $worker,
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
            $this->assertSame(0, $process->getExitCode(), 'Track publication apply worker failed: '.$process->getOutput().$process->getErrorOutput());
            $this->assertTrue(json_validate($process->getOutput()), 'Worker returned non-JSON: '.$process->getOutput().$process->getErrorOutput());
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
        $this->fail('Track publication apply workers never reached the required observed lock/barrier.');
    }
}
