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
use App\Domain\Grants\Paid\PaidGrantDeadline;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantRendererProcess;
use App\Domain\Grants\Paid\PaidGrants;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
 * Codex P2 (comment 4223207353): each claimed line gets its own 300 s budget, started right after its claim, matching that
 * claim's database lease, for its render, asset verification, record frame and failure frame. The call-wide 300 s budget
 * still gates new claims at the loop top. Render time is spent on the virtual paid-namespace monotonic clock
 * (Tests\Support\PaidGrantMonotonicClock) by a container double of the pinned renderer that delegates to the real one; no
 * test sleeps. Synthetic rehearsal fixtures only; no live payment or legal facts are certified.
 */
final class PaidGrantPreparationBudgetTest extends TestCase
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

    public function test_three_lines_rendering_120_s_each_are_all_prepared_with_one_attempt_each(): void
    {
        $this->proveClockSeam();
        $f = $this->order(3);
        $renderer = $this->slowRenderer(120);

        // Line 3 is claimed at about 240 s and finishes at about 360 s inside its own lease. The call-wide budget then
        // refuses at the loop top (410) before anything else is claimed, with every line prepared.
        $this->assertSame(410, $this->refusedStatus(fn () => $this->prepare($f)));
        $this->assertSame(3, $renderer->renders);
        $this->assertSame(['complete', 'complete', 'complete'], $this->states());
        $this->assertSame([1, 1, 1], $this->attempts());
        $this->assertDatabaseCount('paid_originals', 3);
        $this->assertDatabaseCount('paid_fulfillments', 0);

        // The retry renders nothing and fulfils; no attempt was spent on the late line.
        $complete = $this->prepare($f);
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame(3, $renderer->renders);
        $this->assertSame([1, 1, 1], array_column($complete['lines'], 'attempts'));
        $this->assertSame([1, 1, 1], $this->attempts());
        $this->assertDatabaseCount('paid_fulfillments', 1);
        // Each line's asset hash ran under that line's own current budget, in the render loop and in completion.
        $this->assertAssetDeadlines(6);
    }

    public function test_a_late_line_that_fails_is_marked_failed_under_its_own_budget(): void
    {
        $f = $this->order(3);
        // Line 3 is claimed at about 240 s, then its render fails at about 360 s, past the call-wide budget.
        $this->slowRenderer(120, failOn: 3);

        $this->assertSame(RuntimeException::class, $this->refusedStatus(fn () => $this->prepare($f)));
        // Its still-owned claim can be marked failed, so a retry need not wait out the lease.
        $this->assertSame(['complete', 'complete', 'failed'], $this->states());
        $this->assertSame([1, 1, 1], $this->attempts());
        $this->assertDatabaseCount('paid_originals', 2);
        $this->assertDatabaseCount('paid_fulfillments', 0);

        app()->forgetInstance(PaidGrantRendererProcess::class);
        $complete = $this->prepare($f);
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame([1, 1, 2], $this->attempts());
        $this->assertAssetDeadlines(2);
    }

    public function test_a_single_line_exceeding_its_own_300_s_is_still_refused_and_stays_claimed_until_its_lease_ends(): void
    {
        $f = $this->order(1);
        $renderer = $this->slowRenderer(301);

        $this->assertSame(410, $this->refusedStatus(fn () => $this->prepare($f)));
        $this->assertSame(1, $renderer->renders);
        // The claim consumed its attempt. Its own budget has lapsed, so the failure frame cannot run either: the line stays
        // claimed until its database lease ends, and nothing is prepared or fulfilled.
        $this->assertSame(['claimed'], $this->states());
        $this->assertSame([1], $this->attempts());
        $this->assertDatabaseCount('paid_originals', 0);
        $this->assertDatabaseCount('paid_fulfillments', 0);

        // A refresh inside the lease sees the line busy and claims nothing new.
        $busy = $this->prepare($f);
        $this->assertFalse($busy['fulfilled']);
        $this->assertSame('claimed', $busy['lines'][0]['documentStatus']);
        $this->assertSame([1], $this->attempts());
        $this->assertSame(1, $renderer->renders);
        // The line's own budget lapsed before its asset hash, so no asset was hashed.
        $this->assertAssetDeadlines(0);
        $this->assertSame(0, $this->assets->verified);
    }

    private function assertAssetDeadlines(int $minimumChecks, int $lapsed = 0): void
    {
        $this->assertSame([], $this->assets->violations, 'Paid code handed the asset hash a deadline outside the expected bound.');
        $this->assertSame($lapsed, $this->assets->lapsed, 'Asset hashes refused for a deadline already lapsed on the paid clock.');
        $this->assertGreaterThanOrEqual($minimumChecks, $this->assets->verified);
    }

    /** The virtual clock must reach paid code, or every scenario above would pass for the wrong reason. */
    private function proveClockSeam(): void
    {
        PaidGrantMonotonicClock::advance(1000);
        try {
            $this->assertGreaterThan(\hrtime(true) + 900_000_000_000, PaidGrantDeadline::start(1)->value(), 'The paid-namespace clock seam is not live.');
        } finally {
            PaidGrantMonotonicClock::reset();
        }
    }

    /** Each render takes `$seconds` virtual seconds through the real, pinned renderer; the `$failOn`-th render then fails. */
    private function slowRenderer(int $seconds, ?int $failOn = null): object
    {
        $renderer = new class(new PaidGrantRendererProcess, $seconds, $failOn) implements ContractRenderer
        {
            public int $renders = 0;

            public function __construct(private readonly PaidGrantRendererProcess $process, private readonly int $seconds, private readonly ?int $failOn) {}

            public function render(array $input, array $profile): RenderedContract
            {
                $this->renders++;
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

    private function prepare(array $f): array
    {
        return (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
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
