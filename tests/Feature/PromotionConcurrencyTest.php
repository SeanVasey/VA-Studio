<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\PromotionCampaign;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PromotionUsage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\PromotionFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class PromotionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Independent-process promotion capacity requires MySQL.'); }
    }

    public static function races(): array
    {
        $names = ['last_use', 'same_quote', 'conflicting_policy', 'expired_hold', 'pending_hold', 'same_attempt', 'different_attempt', 'clock_skew'];

        return array_combine($names, array_map(static fn ($name) => [$name], $names));
    }

    #[DataProvider('races')]
    public function test_independent_requests_serialize_capacity_retries_and_attempts(string $scenario): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond());
        config(['commerce.test_pricing_policy' => null]);
        $owner = bin2hex(random_bytes(32));
        $policy = PromotionFixtures::policy(['max_uses' => 1]);
        PromotionFixtures::configure([$policy]);
        $make = fn () => app(CreateQuote::class)->handle($owner, (string) Str::uuid(), QuoteFixtures::selection()['items']);
        $first = $make();
        $attempts = in_array($scenario, ['same_attempt', 'different_attempt', 'clock_skew'], true);
        if ($attempts || in_array($scenario, ['expired_hold', 'pending_hold'], true)) {
            $prior = app(PriceQuote::class)->createWithPromotion($first->public_id, $owner, 'SYNTHETIC');
            if ($scenario === 'pending_hold') {
                app(PromotionUsage::class)->beginAttempt($first->public_id, $owner, (string) Str::uuid());
            }
            if (in_array($scenario, ['expired_hold', 'pending_hold', 'clock_skew'], true)) { $this->travelTo($prior->expires_at); }
            if ($scenario === 'pending_hold') { $first = $make(); }
        }
        // Different quotes use distinct tracks/offers so their earlier catalog locks
        // cannot serialize the campaign test before the intended shared guard.
        $sameQuote = in_array($scenario, ['same_quote', 'same_attempt', 'different_attempt'], true);
        $sentinels = 0;
        if (in_array($scenario, ['last_use', 'conflicting_policy', 'pending_hold'], true)) {
            // Split the missing quote_id lookup gaps before concurrent pricing inserts.
            // Otherwise one worker can wait on quote_pricings while its peer waits
            // at the later campaign barrier, creating a test-only circular wait.
            $sentinel = app(CreateQuote::class)->handle($owner, (string) Str::uuid(), $first->request);
            app(PriceQuote::class)->create($sentinel->public_id, $owner);
            $sentinels = 1;
        }
        $second = $sameQuote ? $first : $make();
        if ($scenario === 'clock_skew') { app(PriceQuote::class)->createWithPromotion($second->public_id, $owner, 'SYNTHETIC'); }
        $attempt = (string) Str::uuid();
        $inputs = [];
        foreach ([$first, $second] as $index => $quote) {
            $configured = $policy;
            if ($scenario === 'conflicting_policy' && $index === 1) { $configured['discount']['amount_minor']++; }
            $inputs[] = ['quote' => $quote->public_id, 'owner' => $owner, 'policy' => $configured,
                'now' => $scenario === 'clock_skew' && $index === 0 ? $prior->expires_at->subSecond()->toIso8601ZuluString() : now()->toIso8601ZuluString(),
                'action' => $attempts ? 'attempt' : 'price',
                'attempt' => in_array($scenario, ['different_attempt', 'clock_skew'], true) && $index === 1 ? (string) Str::uuid() : $attempt,
                'barrier' => $sameQuote || $scenario === 'expired_hold' ? 'quotes' : 'promotion_campaigns'];
        }
        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed before independent workers start.');
        $directory = storage_path('framework/testing/promotion-race-'.Str::uuid());
        $filesystem = new Filesystem; $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        try {
            foreach ($inputs as $index => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/promotion-race-worker.php')], base_path(),
                    $this->environment($directory, $index), json_encode($input, JSON_THROW_ON_ERROR), 30);
                $process->start(); $processes[] = $process;
            }
            $paths = [$directory.'/ready-0', $directory.'/ready-1'];
            $deadline = microtime(true) + 18;
            do {
                clearstatcache();
                if (count(array_filter($paths, 'is_file')) === 2) { break; }
                foreach ($processes as $process) {
                    if (! $process->isRunning()) { $this->fail('Promotion worker exited before barrier: '.$process->getOutput()); }
                    $process->checkTimeout();
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            foreach ($paths as $path) { $this->assertFileExists($path, 'Worker did not reach the intended shared lock.'); }
            $ids = array_map(static fn ($path) => (int) file_get_contents($path), $paths);
            $ids[] = (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
            $this->assertCount(3, array_unique($ids));
            touch($directory.'/release');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            }
            $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            $outcomes = array_column($results, 'result'); sort($outcomes);
            $expected = match ($scenario) {
                'same_quote', 'same_attempt' => ['ok', 'ok'],
                'pending_hold' => ['rejected', 'rejected'],
                default => ['ok', 'rejected'],
            };
            $this->assertSame($expected, $outcomes);
            $error = match ($scenario) {
                'conflicting_policy' => 'PROMOTION_CHANGED', 'expired_hold' => 'QUOTE_EXPIRED',
                'different_attempt' => 'PROMOTION_ATTEMPT_CONFLICT', default => 'PROMOTION_LIMIT_REACHED',
            };
            foreach ($results as $result) {
                if ($result['result'] === 'rejected') { $this->assertSame($error, $result['code']); }
            }
            if (in_array($scenario, ['same_quote', 'same_attempt'], true)) {
                $this->assertSame($results[0]['effect_id'], $results[1]['effect_id']);
                $this->assertSame($results[0]['fingerprint'], $results[1]['fingerprint']);
            }
            $uses = in_array($scenario, ['expired_hold', 'clock_skew'], true) ? 2 : 1;
            $this->assertDatabaseCount('promotion_campaigns', 1);
            $this->assertDatabaseCount('promotion_uses', $uses);
            $this->assertDatabaseCount('quote_pricings', $uses + $sentinels);
            $this->assertSame($uses, DB::table('audit_events')->where('action', 'commerce.promotion.held')->count());
            $this->assertSame($uses + $sentinels, DB::table('audit_events')->where('action', 'commerce.quote.priced')->count());
            $pending = $attempts || $scenario === 'pending_hold' ? 1 : 0;
            $this->assertSame($pending, PromotionUse::where('state', 'pending')->count());
            $this->assertSame($pending, DB::table('audit_events')->where('action', 'commerce.promotion.pending')->count());
            $active = PromotionUse::where('promotion_campaign_id', PromotionCampaign::sole()->id)
                ->where(fn ($query) => $query->where('state', '<>', 'held')->orWhere('expires_at', '>', now()))->count();
            $this->assertSame(1, $active);
        } finally {
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(1); } }
            $filesystem->deleteDirectory($directory);
        }
    }

    private function environment(string $directory, int $worker): array
    {
        $database = DB::connection()->getConfig();

        return [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'],
            'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''), 'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VASEY_PROMOTION_RACE_DIRECTORY' => $directory, 'VASEY_PROMOTION_RACE_WORKER' => (string) $worker,
            'VASEY_PROMOTION_RACE_MEDIA_ROOT' => Storage::disk('local')->path(''),
        ];
    }
}
