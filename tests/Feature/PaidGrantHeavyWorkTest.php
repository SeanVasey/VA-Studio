<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProductionCheckout;
use App\Domain\Commerce\ProductionCheckout\TaxExemptions;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\RenderedContract;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Grants\Paid\PaidGrantCommands;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantFiles;
use App\Domain\Grants\Paid\PaidGrantRendererProcess;
use App\Domain\Grants\Paid\PaidGrantRows;
use App\Domain\Grants\Paid\PaidGrants;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidGrantClockedAssetFiles;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\PaidGrantMonotonicClock;
use Tests\Support\ProductionCheckoutFixtures;
use Tests\Support\ProductionCheckoutGatewayFixture;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;
use Throwable;

// Loaded with this file, before any paid code runs in the process (see PaidGrantMonotonicClock).
class_exists(PaidGrantMonotonicClock::class);

/**
 * Conditions C12 and C13 (server part). C12: one heavy step (a line's claim through its render, asset hash and record, or a
 * line's completion re-verification) per buyer account at a time, through a non-blocking cache lock; a request that finds
 * it held returns the current projection, marked busy, without claiming, rendering or hashing. C13: an exhausted call
 * budget returns the current projection instead of 410, so the page can continue. Database claims, leases and
 * `batch_once` stay the correctness guarantees. Time is spent on the virtual paid-namespace clock; no test sleeps.
 * Synthetic rehearsal fixtures only; no live payment or legal facts are certified.
 */
final class PaidGrantHeavyWorkTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidGrantDependencyFixtures;
    use ProductionCheckoutJourneyFixture;

    private PaidGrantClockedAssetFiles $assets;

    protected function beforeRefreshingDatabase(): void
    {
        $this->preparePaidDependencies();
    }

    protected function setUp(): void
    {
        parent::setUp();
        PaidGrantMonotonicClock::reset();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.committed_read_receipts_enabled' => true,
            'production_checkout.committed_read_receipt_version' => 'production-checkout-committed-read-v1',
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => 'identity-historical-committed-receipt-v1',
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'paid-grants.rehearsal_enabled' => true,
            'paid-grants.delivery_policy' => ['schema_version' => 1, 'version' => 'explicit-synthetic-delivery-v1', 'purpose' => 'paid-original-delivery',
                'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60]]);
        Queue::fake();
        // Every asset hash must receive a deadline that is current on the paid clock and at most one 300 s bound away.
        $this->assets = new PaidGrantClockedAssetFiles(300);
        app()->instance(DeliveryAssetFiles::class, $this->assets);
    }

    protected function tearDown(): void
    {
        PaidGrantMonotonicClock::reset();
        parent::tearDown();
    }

    protected function smtp(string $mode, int $noticeId): array
    {
        $capture = tempnam(sys_get_temp_dir(), 'va-paid-synthetic-smtp-');
        $process = new Process(['python3', $this->paidDependencyPath('tests/Support/production_identity_smtp_sink.py'), $mode, $capture], timeout: 20);
        $process->start();
        try {
            $process->waitUntil(fn (): bool => preg_match('/\A[0-9]+\n/', $process->getOutput()) === 1);
            app()->instance(IdentityNoticeTransport::class, new LoopbackSmtp((int) trim($process->getOutput())));
            (new WorkIdentityNotice)->process($noticeId);
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            return filesize($capture) > 0 ? json_decode(file_get_contents($capture), true, 8, JSON_THROW_ON_ERROR) : [];
        } finally {
            $process->stop(0);
            unlink($capture);
        }
    }

    public function test_a_request_that_finds_the_buyer_lock_held_returns_the_projection_without_claiming_rendering_or_hashing(): void
    {
        $f = $this->order(2);
        $renderer = $this->slowRenderer(0);
        $files = $this->countedFiles();
        $held = Cache::lock($this->lockKey($f), PaidGrantDocuments::LEASE_SECONDS + 60);
        $this->assertTrue($held->get());

        $busy = null;
        $projection = $this->prepare($f, $busy);
        $this->assertTrue($busy);
        $this->assertFalse($projection['fulfilled']);
        $this->assertSame(['pending', 'pending'], array_column($projection['lines'], 'documentStatus'));
        $this->assertSame([0, 0], $this->attempts(), 'No attempt is spent while another request holds the buyer lock.');
        $this->assertSame(0, $renderer->renders);
        $this->assertSame(0, $files->verified);
        $this->assertSame(0, $this->assets->verified);
        $this->assertDatabaseCount('paid_originals', 0);

        // The holder finishes; the next request does the work.
        $this->assertTrue($held->release());
        $complete = $this->prepare($f, $busy);
        $this->assertFalse($busy);
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame([1, 1], $this->attempts());
        $this->assertSame(2, $renderer->renders);
        $this->assertSame(2, $files->verified);
        $this->assertAssetDeadlines(4);
    }

    public function test_completion_does_not_re_verify_while_the_buyer_lock_is_held(): void
    {
        $f = $this->order(2);
        // 200 s per render: line 2 finishes at about 400 s, so the request returns its projection with both lines prepared.
        $renderer = $this->slowRenderer(200);
        $busy = null;
        $progress = $this->prepare($f, $busy);
        $this->assertFalse($busy);
        $this->assertFalse($progress['fulfilled']);
        $this->assertSame(['complete', 'complete'], $this->states());
        $files = $this->countedFiles();
        $hashed = $this->assets->verified;
        $held = Cache::lock($this->lockKey($f), PaidGrantDocuments::LEASE_SECONDS + 60);
        $this->assertTrue($held->get());

        $waiting = $this->prepare($f, $busy);
        $this->assertTrue($busy);
        $this->assertSame($progress, $waiting);
        $this->assertSame(0, $files->verified);
        $this->assertSame($hashed, $this->assets->verified);
        $this->assertDatabaseCount('paid_fulfillments', 0);

        $held->release();
        $this->assertTrue($this->prepare($f, $busy)['fulfilled']);
        $this->assertFalse($busy);
        $this->assertSame(2, $files->verified);
        $this->assertSame(2, $renderer->renders);
        $this->assertSame([1, 1], $this->attempts());
    }

    public function test_the_lock_is_held_during_each_heavy_step_and_released_after_success(): void
    {
        $f = $this->order(2);
        $key = $this->lockKey($f);
        $observed = ['render' => [], 'verify' => []];
        $this->slowRenderer(0, during: function () use (&$observed, $key): void {
            $observed['render'][] = $this->lockIsHeld($key);
        });
        $this->countedFiles(function () use (&$observed, $key): void {
            $observed['verify'][] = $this->lockIsHeld($key);
        });

        $this->assertTrue($this->prepare($f)['fulfilled']);
        $this->assertSame(['render' => [true, true], 'verify' => [true, true]], $observed);
        $this->assertFalse($this->lockIsHeld($key), 'Released after the last step.');
    }

    public function test_the_lock_is_released_after_an_exception_inside_a_step(): void
    {
        $f = $this->order(1);
        $held = [];
        $this->slowRenderer(0, failOn: 1, during: function () use (&$held, $f): void {
            $held[] = $this->lockIsHeld($this->lockKey($f));
        });
        $this->assertSame(RuntimeException::class, $this->refusedStatus(fn () => $this->prepare($f)));
        $this->assertSame([true], $held);
        $this->assertFalse($this->lockIsHeld($this->lockKey($f)), 'Released after an exception.');
        $this->assertSame(['failed'], $this->states());
    }

    public function test_a_lock_left_by_a_crashed_worker_expires_and_is_reacquired(): void
    {
        $f = $this->order(1);
        $renderer = $this->slowRenderer(0);
        // A worker took the lock and died without releasing it.
        $this->assertTrue(Cache::lock($this->lockKey($f), PaidGrantDocuments::LEASE_SECONDS + 60)->get());
        $busy = null;
        $this->prepare($f, $busy);
        $this->assertTrue($busy);
        $this->assertSame(0, $renderer->renders);

        $this->travel(PaidGrantDocuments::LEASE_SECONDS + 61)->seconds();
        $this->assertTrue($this->prepare($f, $busy)['fulfilled']);
        $this->assertFalse($busy);
        $this->assertSame([1], $this->attempts());
    }

    public function test_a_live_claim_by_another_request_is_reported_busy_and_unchanged(): void
    {
        $f = $this->order(1);
        $renderer = $this->slowRenderer(0);
        $expires = CarbonImmutable::now('UTC')->addSeconds(PaidGrantDocuments::LEASE_SECONDS)->format('Y-m-d H:i:s');
        (new PaidGrantCommands)->run($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'],
            function (array $graph, PaidGrantRows $rows) use ($expires): array {
                $rows->execute('UPDATE '.$rows->table('paid_document_work')." SET state = 'claimed', attempts = 1, claim_id = ?, expires_at = ? WHERE id = ?",
                    [(string) Str::uuid(), $expires, $graph['lines'][0]['work']['id']]);

                return [];
            });
        $busy = null;
        $projection = $this->prepare($f, $busy);
        $this->assertTrue($busy);
        $this->assertSame('claimed', $projection['lines'][0]['documentStatus']);
        $this->assertSame([1], $this->attempts());
        $this->assertSame(0, $renderer->renders);
        $this->assertFalse($this->lockIsHeld($this->lockKey($f)));
    }

    public function test_the_database_cache_store_provides_the_lock_from_the_migrated_table(): void
    {
        $this->assertTrue(Schema::hasTable('cache_locks'));
        config(['cache.default' => 'database']);
        $f = $this->order(1);
        $renderer = $this->slowRenderer(0);
        $held = Cache::lock($this->lockKey($f), PaidGrantDocuments::LEASE_SECONDS + 60);
        $this->assertTrue($held->get());
        $this->assertSame(1, DB::table('cache_locks')->count());
        $busy = null;
        $this->prepare($f, $busy);
        $this->assertTrue($busy);
        $this->assertSame(0, $renderer->renders);
        $held->release();
        $this->assertTrue($this->prepare($f, $busy)['fulfilled']);
        $this->assertFalse($busy);
        $this->assertSame(0, DB::table('cache_locks')->count(), 'Every step released its database lock.');
    }

    private function assertAssetDeadlines(int $minimumChecks, int $lapsed = 0): void
    {
        $this->assertSame([], $this->assets->violations, 'Paid code handed the asset hash a deadline outside the expected bound.');
        $this->assertSame($lapsed, $this->assets->lapsed, 'Asset hashes refused for a deadline already lapsed on the paid clock.');
        $this->assertGreaterThanOrEqual($minimumChecks, $this->assets->verified);
    }

    /**
     * Each render takes `$seconds` virtual seconds through the real, pinned renderer, calling `$during` first; the
     * `$failOn`-th render then fails.
     */
    private function slowRenderer(int $seconds, ?int $failOn = null, ?Closure $during = null): object
    {
        $renderer = new class(new PaidGrantRendererProcess, $seconds, $failOn, $during) implements ContractRenderer
        {
            public int $renders = 0;

            public function __construct(private readonly PaidGrantRendererProcess $process, private readonly int $seconds, private readonly ?int $failOn,
                private readonly ?Closure $during) {}

            public function render(array $input, array $profile): RenderedContract
            {
                $this->renders++;
                if ($this->during !== null) {
                    ($this->during)();
                }
                $rendered = $this->process->render($input, $profile);
                PaidGrantMonotonicClock::advance($this->seconds);
                if ($this->renders === $this->failOn) {
                    throw new RuntimeException('SYNTHETIC LATE RENDER FAILURE');
                }

                return $rendered;
            }
        };
        app()->instance(PaidGrantRendererProcess::class, $renderer);

        return $renderer;
    }

    private function prepare(array $f, ?bool &$busy = null): array
    {
        return (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'], null, $busy);
    }

    private function lockKey(array $f): string
    {
        return 'paid-grant-heavy:'.hash('sha256', 'paid-heavy-v1:'.$f['buyer']['principal']->accountId);
    }

    private function lockIsHeld(string $key): bool
    {
        $probe = Cache::lock($key, 1);
        if ($probe->get()) {
            $probe->release();

            return false;
        }

        return true;
    }

    /** Completion's per-line original check, counted (and observed) without changing the pinned class. */
    private function countedFiles(?Closure $during = null): object
    {
        $files = new class(new PaidGrantFiles, $during)
        {
            public int $verified = 0;

            public function __construct(private readonly PaidGrantFiles $files, private readonly ?Closure $during) {}

            public function verify(array|object $record): string
            {
                $this->verified++;
                if ($this->during !== null) {
                    ($this->during)();
                }

                return $this->files->verify($record);
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->files->{$method}(...$arguments);
            }
        };
        app()->instance(PaidGrantFiles::class, $files);

        return $files;
    }

    /** @return list<string> */
    private function states(): array
    {
        return DB::table('paid_document_work')->orderBy('id')->pluck('state')->all();
    }

    /** @return list<int> */
    private function attempts(): array
    {
        return array_map('intval', DB::table('paid_document_work')->orderBy('id')->pluck('attempts')->all());
    }

    /** A finalized, unprepared paid order of `$lines` lines (1 to 3). */
    private function order(int $lines): array
    {
        $catalog = ProductionCheckoutFixtures::catalog();
        $items = $catalog['items'];
        foreach (array_slice([3499, 2999], 0, $lines - 1) as $price) {
            $items = [...$items, ...QuoteFixtures::selection($price)['items']];
        }
        $buyer = $this->enrollThroughLocalSmtp();
        $access = new ProductionCustomerAccess;
        $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, ProductionCheckoutFixtures::exemptionPolicy($catalog), 'synthetic-two-lines-policy', $catalog['actor']);
        $basis = (new TaxExemptions($access))->qualify($buyer['principal'], $buyer['user'], $authority['public_id'], $items,
            ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:two-lines-buyer', 'source_sha256' => hash('sha256', 'NONBINDING TWO-LINE QUALIFICATION'),
                'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
                'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')], 'synthetic-two-lines-qualification', $catalog['actor']);
        $checkout = new ProductionCheckout($access);
        $review = $checkout->review($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $items, $basis['public_id'], ['legalName' => 'Declared synthetic buyer'], 'synthetic-two-lines-review');
        $order = $checkout->accept($buyer['principal'], $buyer['user'], $review['reviewId'], $review['reviewHash'], true, 'synthetic-two-lines-accept');
        $gateway = new ProductionCheckoutGatewayFixture;
        $hosted = new HostedCheckout($access, $gateway);
        $hosted->initiate($buyer['principal'], $buyer['user'], $order['orderId']);
        $gateway->paid = true;
        $this->assertSame('verified', $hosted->reconcile($buyer['principal'], $buyer['user'], $order['orderId'])['paymentStatus']);
        $batch = (new PaidGrants)->finalize($buyer['principal'], $buyer['user'], $order['orderId']);
        $this->assertCount($lines, $batch['lines']);

        return compact('catalog', 'buyer', 'order', 'batch');
    }

    private function refusedStatus(Closure $call): int|string
    {
        try {
            $call();
        } catch (PaidGrantException $error) {
            return $error->status;
        } catch (Throwable $error) {
            return $error::class;
        }
        $this->fail('Expected a refusal.');
    }
}
