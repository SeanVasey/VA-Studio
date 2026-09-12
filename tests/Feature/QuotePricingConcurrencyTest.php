<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\QuotePricing;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\PricingFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class QuotePricingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent-process pricing locking requires MySQL.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    public static function policies(): array
    {
        return ['same policy' => [false], 'different policy' => [true]];
    }

    #[DataProvider('policies')]
    public function test_simultaneous_first_pricing_requests_share_one_immutable_record(bool $different): void
    {
        $owner = bin2hex(random_bytes(32));
        $quote = app(CreateQuote::class)->handle($owner, (string) Str::uuid(), QuoteFixtures::selection()['items']);
        $this->assertSame(0, DB::transactionLevel(), 'Quote fixture must be committed before independent workers start.');
        $policies = [PricingFixtures::policy(), PricingFixtures::policy()];
        if ($different) { $policies[1]['tax']['rate_bps']++; }
        $directory = storage_path('framework/testing/pricing-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        try {
            foreach ($policies as $index => $policy) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/pricing-race-worker.php')], base_path(),
                    $this->workerEnvironment($directory, $index), json_encode(['quote' => $quote->public_id, 'owner' => $owner, 'policy' => $policy], JSON_THROW_ON_ERROR), 25);
                $process->start();
                $processes[] = $process;
            }
            $paths = [$directory.'/ready-0', $directory.'/ready-1'];
            $deadline = microtime(true) + 15;
            do {
                clearstatcache();
                if (count(array_filter($paths, 'is_file')) === 2) { break; }
                foreach ($processes as $process) {
                    $this->assertTrue($process->isRunning(), 'Pricing worker exited before its barrier: '.$process->getOutput());
                    $process->checkTimeout();
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            foreach ($paths as $path) { $this->assertFileExists($path, 'Pricing worker never reached the exact quote lock.'); }
            $connectionIds = array_map(fn (string $path) => (int) file_get_contents($path), $paths);
            $connectionIds[] = (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
            $this->assertCount(3, array_unique($connectionIds));
            touch($directory.'/release');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            }
            $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            $outcomes = array_column($results, 'result');
            sort($outcomes);
            $this->assertSame($different ? ['conflict', 'created'] : ['created', 'created'], $outcomes);
            if ($different) {
                $loser = $results[0]['result'] === 'conflict' ? 0 : 1;
                $this->assertSame('PRICING_CHANGED', $results[$loser]['error_code']);
                $this->assertSame(409, $results[$loser]['status']);
            } else {
                $this->assertSame($results[0]['pricing_id'], $results[1]['pricing_id']);
                $this->assertSame($results[0]['snapshot_hash'], $results[1]['snapshot_hash']);
            }
            $winner = $results[0]['result'] === 'created' ? 0 : 1;
            $this->assertSame($results[$winner]['snapshot_hash'], QuotePricing::sole()->snapshot_hash);
            $this->assertDatabaseCount('quote_pricings', 1);
            $this->assertDatabaseCount('quotes', 1);
            $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.quote.priced')->count());
        } finally {
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(1); } }
            $filesystem->deleteDirectory($directory);
        }
    }

    private function workerEnvironment(string $directory, int $worker): array
    {
        $database = DB::connection()->getConfig();

        return [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'],
            'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''), 'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VASEY_PRICING_RACE_DIRECTORY' => $directory, 'VASEY_PRICING_RACE_WORKER' => (string) $worker,
            'VASEY_PRICING_RACE_MEDIA_ROOT' => Storage::disk('local')->path(''),
        ];
    }
}
