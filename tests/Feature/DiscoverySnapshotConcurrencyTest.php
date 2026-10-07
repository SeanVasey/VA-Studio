<?php

namespace Tests\Feature;

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Discovery\CurrentEligibleTrackSnapshot;
use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use App\Domain\Catalog\Discovery\EligibleTrackSnapshot;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Rights\Models\RightsDeclaration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ExclusiveSelectionFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class DiscoverySnapshotConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Discovery epoch lock races require native MySQL.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('d', 32))]);
        $this->travelTo(now()->startOfSecond());
    }

    public static function writers(): array
    {
        return ['unpublish' => ['unpublish'], 'rights' => ['rights'], 'offer' => ['offer'], 'license' => ['license'], 'media' => ['media'], 'inventory' => ['inventory']];
    }

    #[DataProvider('writers')]
    public function test_committed_writers_before_final_fence_refuse_prechange_capture(string $writer): void
    {
        ExclusiveSelectionFixtures::configure();
        $f = $writer === 'inventory' ? ExclusiveSelectionFixtures::active() : QuoteFixtures::selection();
        $quote = $writer === 'inventory' ? ExclusiveSelectionFixtures::quote($f) : null;
        // Published media replacement is forbidden; an independent draft's genuine
        // processing still invalidates the conservative global generation.
        $mediaTrack = $writer === 'media' ? Track::create(['title' => 'Synthetic independent draft', 'slug' => 'independent-draft', 'status' => 'draft']) : null;
        $this->runRace($f, 'before-fence', function () use ($f, $writer, $quote, $mediaTrack): void {
            match ($writer) {
                'unpublish' => app(PublishTrack::class)->unpublish($f['track'], $f['actor']),
                'rights' => RightsDeclaration::create(['track_id' => $f['track']->id, 'status' => 'pending', 'provenance_reference' => 'Synthetic hold', 'sample_disclosure' => 'Synthetic']),
                'offer' => app(DeactivateOffer::class)->handle($f['offer'], $f['actor']),
                'license' => LicenseFixtures::published($f['actor']),
                'media' => MediaFixtures::readyTrackMedia($mediaTrack, $f['actor']),
                'inventory' => app(ReserveQuoteInventory::class)->hold($quote->public_id, InventoryFixtures::OWNER),
            };
        });
    }

    public function test_writer_waits_on_actual_final_epoch_record_and_retained_capture_then_refuses_consumption(): void
    {
        $this->runRace(QuoteFixtures::selection(), 'after-fence', null);
    }

    private function runRace(array $f, string $phase, ?callable $beforeRelease): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/discovery-race-'.Str::uuid());
        $files = new Filesystem;
        $files->makeDirectory($directory, 0700, true);
        $processes = [];
        $epoch = (new DiscoveryEpoch)->current(DB::connection()->getPdo());
        try {
            $reader = $this->worker($directory, 'reader', $phase, $f['track']->id);
            $reader->start();
            $processes[] = $reader;
            $this->await(fn () => is_file($directory.'/reader-ready'), $processes);
            $ready = json_decode(file_get_contents($directory.'/reader-ready'), true, 8, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $ready['depth']);
            $writer = null;
            $wait = null;
            if ($beforeRelease !== null) {
                $beforeRelease();
                $this->assertGreaterThan($epoch, (new DiscoveryEpoch)->current(DB::connection()->getPdo()));
            } else {
                $writer = $this->worker($directory, 'writer', $phase, $f['track']->id);
                $writer->start();
                $processes[] = $writer;
                $this->await(fn () => is_file($directory.'/writer-started'), $processes);
                $started = json_decode(file_get_contents($directory.'/writer-started'), true, 8, JSON_THROW_ON_ERROR);
                $this->assertSame(0, $started['depth']);
                $this->assertNotSame($ready['connection'], $started['connection']);
                $this->await(function () use ($ready, $started, &$wait): bool {
                    $wait = DB::selectOne("SELECT l.LOCK_STATUS, l.LOCK_TYPE, l.INDEX_NAME, l.OBJECT_NAME, l.LOCK_DATA FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE=w.ENGINE WHERE r.PROCESSLIST_ID=? AND b.PROCESSLIST_ID=? AND l.OBJECT_SCHEMA=? AND l.OBJECT_NAME=? AND l.INDEX_NAME='PRIMARY' AND l.LOCK_STATUS='WAITING' AND l.LOCK_TYPE='RECORD' AND l.LOCK_DATA='1' LIMIT 1",
                        [$started['connection'], $ready['connection'], DB::getDatabaseName(), DiscoveryEpoch::TABLE]);

                    return $wait !== null;
                }, $processes);
                $this->assertSame('WAITING', $wait->LOCK_STATUS);
                $this->assertSame(DiscoveryEpoch::TABLE, $wait->OBJECT_NAME);
            }
            touch($directory.'/release');
            $reader->wait();
            $this->assertSame(0, $reader->getExitCode(), $reader->getOutput().$reader->getErrorOutput());
            $result = json_decode($reader->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            $this->assertSame(0, $result['depth']);
            $this->assertSame($phase === 'before-fence' ? 'refused' : 'captured', $result['status']);
            if ($writer !== null) {
                $writer->wait();
                $this->assertSame(0, $writer->getExitCode(), $writer->getOutput().$writer->getErrorOutput());
                $written = json_decode($writer->getOutput(), true, 8, JSON_THROW_ON_ERROR);
                $this->assertSame('written', $written['status']);
                $this->assertSame(0, $written['depth']);
                $this->assertGreaterThan($epoch, (new DiscoveryEpoch)->current(DB::connection()->getPdo()));
                try {
                    app(CurrentEligibleTrackSnapshot::class)->currentPaths(new EligibleTrackSnapshot($result['paths'], null, $result['evidence']));
                    $this->fail('Postcapture committed writer left retained eligibility current.');
                } catch (LogicException) {
                    $this->assertSame(0, DB::transactionLevel());
                }
            }
            if (($output = getenv('VASEY_DISCOVERY_WITNESS')) !== false) {
                file_put_contents($output, json_encode(['phase' => $phase, 'ready' => $ready, 'wait' => $wait, 'result' => array_diff_key($result, ['evidence' => true])], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $files->deleteDirectory($directory);
        }
    }

    private function worker(string $directory, string $role, string $phase, int $trackId): Process
    {
        $db = DB::connection()->getConfig();
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'], 'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'];

        return new Process([PHP_BINARY, '-d', 'memory_limit=512M', base_path('tests/Support/discovery-snapshot-worker.php')], base_path(), $env,
            json_encode(['directory' => $directory, 'role' => $role, 'phase' => $phase, 'track_id' => $trackId,
                'media' => config('media'), 'commerce' => config('commerce'), 'media_root' => Storage::disk('local')->path(''), 'now' => now()->toISOString()], JSON_THROW_ON_ERROR), 30);
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
                $this->assertTrue($process->isRunning(), $process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Missing real discovery synchronization witness.');
    }
}
