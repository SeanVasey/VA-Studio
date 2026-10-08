<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use App\Jobs\RetrieveMembershipInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Stripe\Event;
use Stripe\WebhookSignature;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InterleavingBillingGateway;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/**
 * Review R-6 (Addendum 1) and A1-3. Two retrievals of one invoice append in commit order, not provider-read order, so a snapshot
 * read before a refund could be appended after the `reversed` observation and become the current tail. A retrieval records when
 * its provider reads began and when they ended (two database-issued positions); the append admits a retrieval only if its reads
 * began after the tail's reads ended, and refuses an overlapping one as `concurrent_retrieval` for retry. The overlap is
 * simulated in one process by running the second retrieval between the first one's last provider read and its append, which is
 * where the real race lives. The two-process native regression is BillingNativeStaleRetrievalRaceTest.
 *
 * The same start, not the append time, decides whether a webhook hint is covered (A1-3). "Start" is the retrieval's
 * database-issued position, not a clock (Codex P1 on PR #54; skewed clocks are covered by BillingClockSkewOrderingTest).
 */
class BillingOverlappingRetrievalTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const SECRET = 'whsec_SYNTHETICREHEARSAL';

    private const T0 = F::PERIOD_START + 3600;

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        $this->at(0);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_stale_settled_snapshot_appended_after_a_newer_reversal_is_refused_and_never_becomes_current(): void
    {
        $binding = F::binding();
        $ledger = new BillingLedger;
        $first = $this->retrieve($binding['id'], 0);
        $this->assertSame(['settled', 1], [$first['outcome'], $first['sequence']]);

        $outcome = $this->staleSettledAroundAFreshRefund($binding['id'], 10, 20, 30);

        $chain = $ledger->observations($first['invoice_id']);
        $this->assertNull($ledger->currentSettled($first['invoice_id'], self::T0 + 31), 'A snapshot read before the refund is not current evidence.');
        // The fresh retrieval ran between the stale one's last provider read and its end position, so the two reads overlap by
        // position and the database cannot order them: refused for retry, never appended (Codex P1 on PR #54, :42). A read that
        // wholly preceded the tail's is `superseded_retrieval` (BillingRetrievalIntervalOrderingTest).
        $this->assertSame(['settled', 'reversed'], array_column($chain, 'outcome'));
        $this->assertSame('concurrent_retrieval', $outcome);
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
        // The retry reads afresh after the tail's read ended and is admitted; the ledger converges on the later state.
        $retry = $this->retrieve($binding['id'], 40, ['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]]);
        $this->assertSame(['reversed', 3], [$retry['outcome'], $retry['sequence']]);
        $this->assertNull($ledger->currentSettled($first['invoice_id'], self::T0 + 41));
    }

    public function test_a_stale_first_retrieval_cannot_claim_the_tail_after_a_fresh_one_created_the_identity(): void
    {
        $binding = F::binding();
        $outcome = $this->staleSettledAroundAFreshRefund($binding['id'], 10, 20, 30);

        $this->assertSame(1, DB::table('production_membership_billing_invoices')->count());
        $invoiceId = (string) DB::table('production_membership_billing_invoices')->value('id');
        $chain = (new BillingLedger)->observations($invoiceId);
        $this->assertSame(['reversed'], array_column($chain, 'outcome'));
        // The fresh retrieval ran between the stale one's last provider read and its end position, so the two reads overlap by
        // position and the database cannot order them: refused for retry, never appended (Codex P1 on PR #54, :42). A read that
        // wholly preceded the tail's is `superseded_retrieval` (BillingRetrievalIntervalOrderingTest).
        $this->assertNull((new BillingLedger)->currentSettled($invoiceId, self::T0 + 31));
        $this->assertSame('concurrent_retrieval', $outcome);
    }

    public function test_a_retrieval_that_began_first_and_finished_first_is_still_appended_in_order(): void
    {
        $binding = F::binding();
        $first = $this->retrieve($binding['id'], 0);
        $second = $this->retrieve($binding['id'], 10, ['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]]);
        $third = $this->retrieve($binding['id'], 10);
        $this->assertSame([1, 2, 3], [$first['sequence'], $second['sequence'], $third['sequence']]);
        $this->assertSame(['settled', 'reversed', 'settled'], [$first['outcome'], $second['outcome'], $third['outcome']]);
        $starts = array_column((new BillingLedger)->observations($first['invoice_id']), 'retrieval_started_at');
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts);
    }

    /** A1-3: the hint is covered only by a retrieval whose provider reads began after the hint, not by one that merely finished after it. */
    public function test_a_retrieval_that_began_before_the_hint_does_not_cover_it_and_one_that_began_after_does(): void
    {
        $binding = F::binding();
        $this->retrieve($binding['id'], 0);
        $payload = $this->event('evt_SYNTHETICSTALE');

        $this->at(10);
        $open = new InterleavingBillingGateway(new RehearsalBillingGateway(F::graph(['invoice' => ['status' => 'open']])), function () use ($payload): void {
            // The hint arrives while the provider reads are in progress, and its own dispatch is lost.
            $this->at(20);
            try {
                $this->receive($payload);
                $this->fail('The unbound gateway must make the hint dispatch fail after the hint committed.');
            } catch (BindingResolutionException) {
                // Expected: this lane binds no gateway.
            }
            $this->at(30); // The reads finish, and the observation is appended, after the hint.
        });
        $straddling = (new BillingReconciliation($open))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['not_settled', 2], [$straddling['outcome'], $straddling['sequence']]);
        $this->assertGreaterThan(DB::table('production_membership_billing_events')->value('received_at'), $straddling['created_at']);

        $this->at(40);
        Queue::fake();
        $duplicate = $this->receive($payload);
        $this->assertSame(['binding_id' => $binding['id'], 'invoice_ref' => F::INVOICE], $duplicate['scheduled'],
            'A retrieval that began before the hint must not cover it.');
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);

        $this->retrieve($binding['id'], 50);
        $this->at(60);
        Queue::fake();
        $this->assertNull($this->receive($payload)['scheduled'], 'A retrieval that began after the hint covers it.');
        Queue::assertNothingPushed();
    }

    /**
     * Runs a retrieval of a settled graph that, between its last provider read and its append, is overtaken by a retrieval of a
     * refunded graph. Returns 'appended' or the refusal reason of the stale one.
     */
    private function staleSettledAroundAFreshRefund(string $bindingId, int $staleStart, int $freshStart, int $staleAppend): string
    {
        $this->at($staleStart);
        $stale = new InterleavingBillingGateway(new RehearsalBillingGateway(F::graph()), function () use ($bindingId, $freshStart, $staleAppend): void {
            $this->retrieve($bindingId, $freshStart, ['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]]);
            $this->at($staleAppend);
        });
        try {
            (new BillingReconciliation($stale))->retrieve($bindingId, F::INVOICE);

            return 'appended';
        } catch (BillingException $error) {
            return $error->reason;
        }
    }

    private function retrieve(string $bindingId, int $offset, array $graph = []): array
    {
        $this->at($offset);

        return (new BillingReconciliation(new RehearsalBillingGateway(F::graph($graph))))->retrieve($bindingId, F::INVOICE);
    }

    private function at(int $offset): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(self::T0 + $offset));
    }

    private function receive(string $payload): array
    {
        return (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, self::SECRET));
    }

    private function event(string $id): string
    {
        $values = ['id' => $id, 'object' => 'event', 'api_version' => BillingProviderPin::API_VERSION, 'created' => F::PERIOD_START,
            'livemode' => false, 'pending_webhooks' => 1, 'request' => ['id' => null, 'idempotency_key' => null], 'type' => 'invoice.paid',
            'data' => ['object' => F::graph()['invoice']]];

        return json_encode(F::sdk(Event::class, $values), JSON_THROW_ON_ERROR);
    }
}
