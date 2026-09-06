<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Rights\Models\RightsDeclaration;
use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Committed fixtures and independent PHP/MySQL connections; no enclosing test transaction. */
class QuoteConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent-process quote locking is verified on MySQL, not SQLite.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    public function test_simultaneous_same_key_and_selection_create_one_quote_and_return_the_same_result(): void
    {
        $fixture = QuoteFixtures::selection();
        $owner = bin2hex(random_bytes(32));
        $key = (string) Str::uuid();

        $results = $this->race($owner, $key, [$fixture['items'], $fixture['items']]);

        $this->assertSame(['created', 'created'], array_column($results, 'result'));
        $this->assertSame($results[0]['quote_id'], $results[1]['quote_id']);
        $this->assertSame($results[0]['snapshot_hash'], $results[1]['snapshot_hash']);
        $this->assertDatabaseCount('quotes', 1);
        $this->assertDatabaseCount('quote_lines', 1);
        $this->assertSame(1, DB::table('quote_owners')->where('owner_key', $owner)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.quote.created')->count());
    }

    public function test_simultaneous_different_selections_with_one_key_create_one_quote_and_one_conflict(): void
    {
        $fixture = QuoteFixtures::selection();
        // Both selections are independently valid, so only the reused key can reject the loser.
        $secondOffer = app(SaveOfferDraft::class)->handle(null, [
            ...$fixture['offer']->only(['track_id', 'license_version_id', 'currency', 'deliverable_asset_ids']),
            'price_minor' => 6999,
        ], $fixture['actor']);
        $secondRevision = app(PublishOffer::class)->handle($secondOffer, $fixture['actor']);
        $secondItems = [[
            'trackId' => $fixture['track']->id,
            'offerId' => $secondOffer->id,
            'licenseVersionId' => $secondRevision->license_version_id,
            'offerRevisionId' => $secondRevision->id,
        ]];
        $owner = bin2hex(random_bytes(32));
        $key = (string) Str::uuid();

        $results = $this->race($owner, $key, [$fixture['items'], $secondItems]);

        $outcomes = array_column($results, 'result');
        sort($outcomes);
        $this->assertSame(['conflict', 'created'], $outcomes);
        $winner = $results[0]['result'] === 'created' ? 0 : 1;
        $this->assertSame('IDEMPOTENCY_CONFLICT', $results[1 - $winner]['error_code']);
        $this->assertSame(409, $results[1 - $winner]['status']);
        $this->assertDatabaseCount('quotes', 1);
        $this->assertDatabaseCount('quote_lines', 1);
        $quote = Quote::query()->sole();
        $this->assertSame($results[$winner]['quote_id'], $quote->public_id);
        $expectedRevision = $winner === 0 ? $fixture['items'][0]['offerRevisionId'] : $secondRevision->id;
        $this->assertDatabaseHas('quote_lines', ['quote_id' => $quote->id, 'offer_revision_id' => $expectedRevision]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.quote.created')->count());
    }

    public function test_readiness_sees_rights_change_committed_while_quote_waits_for_track_lock(): void
    {
        $fixture = QuoteFixtures::selection();
        $this->assertSame(0, DB::transactionLevel(), 'The original catalog fixture must be committed.');
        DB::beginTransaction();
        try {
            Track::query()->lockForUpdate()->findOrFail($fixture['track']->id);
            $results = $this->race(
                bin2hex(random_bytes(32)),
                (string) Str::uuid(),
                [$fixture['items']],
                'track',
                function (array $connectionIds) use ($fixture): void {
                    $this->assertWorkerWaitsForParentTrackLock($connectionIds[0], $fixture['track']->id);
                    RightsDeclaration::create([
                        'track_id' => $fixture['track']->id,
                        'provenance_reference' => 'TEST-ONLY-CONCURRENT-RIGHTS-HOLD',
                        'sample_disclosure' => 'Synthetic concurrent review hold',
                        'status' => 'pending',
                    ]);
                    DB::commit();
                },
            );
            $this->assertSame('conflict', $results[0]['result']);
            $this->assertSame('SELECTION_CHANGED', $results[0]['error_code']);
            $this->assertSame(409, $results[0]['status']);
            $this->assertSame('pending', $fixture['track']->rightsDeclarations()->latest('id')->firstOrFail()->status);
            $this->assertDatabaseCount('quotes', 0);
            $this->assertDatabaseCount('quote_lines', 0);
            $this->assertSame(0, DB::table('audit_events')->where('action', 'commerce.quote.created')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    private function assertWorkerWaitsForParentTrackLock(int $workerConnectionId, int $trackId): void
    {
        $parentConnectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
        $database = DB::connection()->getConfig();
        $observerName = 'quote_race_observer_'.Str::uuid();
        config(['database.connections.'.$observerName => [...$database, 'url' => null]]);
        try {
            // Observe from an independent autocommit connection. Performance Schema lock tables
            // expose live lock relationships; an INNODB_TRX state alone does not identify the blocker.
            // CI's test account can read these tables; no application permission is changed here.
            // https://dev.mysql.com/doc/refman/8.4/en/innodb-information-schema-internal-data.html
            $observer = DB::connection($observerName);
            $observerConnectionId = (int) $observer->selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
            $this->assertNotContains($observerConnectionId, [$parentConnectionId, $workerConnectionId]);
            $this->assertSame(0, $observer->transactionLevel());
            $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS lock_status
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread
    ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread
    ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested
    ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID
    AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB'
    AND requesting_thread.PROCESSLIST_ID = ?
    AND blocking_thread.PROCESSLIST_ID = ?
    AND requested.OBJECT_SCHEMA = ?
    AND requested.OBJECT_NAME = 'tracks'
    AND requested.INDEX_NAME = 'PRIMARY'
    AND requested.LOCK_TYPE = 'RECORD'
    AND requested.LOCK_STATUS = 'WAITING'
    AND requested.LOCK_DATA = ?
LIMIT 1
SQL;
            $deadline = microtime(true) + 10;
            do {
                $waitingLock = $observer->selectOne($sql, [$workerConnectionId, $parentConnectionId, $database['database'], (string) $trackId]);
                if ($waitingLock) {
                    break;
                }
                usleep(100000);
            } while (microtime(true) < $deadline);
            $this->assertSame('WAITING', $waitingLock?->lock_status, 'The quote worker must actually wait for the parent transaction on this exact tracks row.');
        } finally {
            DB::purge($observerName);
            app('config')->offsetUnset('database.connections.'.$observerName);
        }
    }

    /** @return list<array<string, mixed>> */
    private function race(string $owner, string $key, array $selections, string $phase = 'owner', ?Closure $afterRelease = null): array
    {
        $this->assertSame($phase === 'track' ? 1 : 0, DB::transactionLevel(), 'Only the deliberate administrative track lock may remain uncommitted.');
        $directory = storage_path('framework/testing/quote-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        try {
            foreach ($selections as $worker => $items) {
                $process = new Process(
                    [PHP_BINARY, base_path('tests/Support/quote-race-worker.php')],
                    base_path(),
                    $this->workerEnvironment($directory, $worker, $phase),
                    json_encode(['owner' => $owner, 'key' => $key, 'items' => $items], JSON_THROW_ON_ERROR),
                    25,
                );
                $process->start();
                $processes[] = $process;
            }

            // Workers pause inside CreateQuote immediately before the selected SQL operation.
            // Release only once every independent connection reaches that exact transaction point.
            $deadline = microtime(true) + 15;
            $readyPaths = array_map(fn (int $worker) => $directory.'/ready-'.$worker, array_keys($processes));
            do {
                clearstatcache();
                if (count(array_filter($readyPaths, 'is_file')) === count($processes)) {
                    break;
                }
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Quote worker exited before the race barrier: '.$process->getOutput());
                    }
                    $process->checkTimeout();
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $connectionIds = [];
            foreach ($readyPaths as $path) {
                $this->assertFileExists($path, 'Quote worker never reached the database barrier.');
                $connectionIds[] = (int) file_get_contents($path);
            }
            $parentConnectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
            $this->assertSame(count($processes) + 1, count(array_unique([...$connectionIds, $parentConnectionId])), 'Every worker and the parent must use independent MySQL connections.');
            touch($directory.'/release');
            $afterRelease?->__invoke($connectionIds);

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), 'Quote worker failed: '.$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            $this->assertSame(count($processes) + 1, count(array_unique([...array_column($results, 'pid'), getmypid()])));

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $filesystem->deleteDirectory($directory);
        }
    }

    /** Credentials travel only through the child environment, never command arguments or output. */
    private function workerEnvironment(string $directory, int $worker, string $phase): array
    {
        $database = DB::connection()->getConfig();

        return [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
            'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
            'DB_CHARSET' => (string) $database['charset'],
            'DB_COLLATION' => (string) $database['collation'],
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'VASEY_QUOTE_RACE_DIRECTORY' => $directory,
            'VASEY_QUOTE_RACE_WORKER' => (string) $worker,
            'VASEY_QUOTE_RACE_PHASE' => $phase,
            'VASEY_QUOTE_RACE_MEDIA_ROOT' => Storage::disk('local')->path(''),
        ];
    }
}
