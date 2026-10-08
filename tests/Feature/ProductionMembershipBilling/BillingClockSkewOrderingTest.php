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
 * Codex P1 on PR #54 (review L2-3). Overlapping retrievals and webhook hints are ordered by a database-issued position, never by
 * an application clock. Each case injects clock skew with Carbon's test clock: the clock a worker or the intake host reads is
 * deliberately wrong, while the real order of events is fixed by the order the test runs them in. A cross-host wall-clock rule
 * misorders every case below; a database-issued order cannot see the skew at all.
 */
class BillingClockSkewOrderingTest extends TestCase
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

    public function test_an_older_retrieval_on_a_clock_ahead_worker_is_refused_and_the_newer_reversal_stays_the_tail(): void
    {
        $binding = F::binding();
        $ledger = new BillingLedger;
        $first = $this->retrieve($binding['id'], 0);
        $this->assertSame(['settled', 1], [$first['outcome'], $first['sequence']]);

        // The stale retrieval begins first, on a worker whose clock reads 15 s ahead; the fresh one begins after its reads.
        $outcome = $this->staleAroundFresh($binding['id'], 25, 20, 30);

        // Overlapping reads by position (Codex P1 on PR #54, :42): refused for retry, whatever the clocks read.
        $this->assertSame('concurrent_retrieval', $outcome);
        $chain = $ledger->observations($first['invoice_id']);
        $this->assertSame(['settled', 'reversed'], array_column($chain, 'outcome'));
        $this->assertNull($ledger->currentSettled($first['invoice_id'], self::T0 + 31), 'A snapshot read before the refund is never current evidence.');
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    public function test_an_older_first_retrieval_on_a_clock_ahead_worker_cannot_claim_the_tail_after_a_newer_one_created_the_identity(): void
    {
        $binding = F::binding();

        $outcome = $this->staleAroundFresh($binding['id'], 25, 20, 30);

        // Overlapping reads by position (Codex P1 on PR #54, :42): refused for retry, whatever the clocks read.
        $this->assertSame('concurrent_retrieval', $outcome);
        $invoiceId = (string) DB::table('production_membership_billing_invoices')->value('id');
        $this->assertSame(['reversed'], array_column((new BillingLedger)->observations($invoiceId), 'outcome'));
        $this->assertNull((new BillingLedger)->currentSettled($invoiceId, self::T0 + 31));
    }

    public function test_a_newer_retrieval_on_a_clock_behind_worker_is_admitted_after_an_older_one(): void
    {
        $binding = F::binding();
        $first = $this->retrieve($binding['id'], 20);

        // Begins after the first retrieval finished, on a worker whose clock reads 10 s behind it at the start.
        $this->at(10);
        $refunded = new InterleavingBillingGateway(new RehearsalBillingGateway(F::graph(['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]])),
            fn () => $this->at(25));
        $newer = (new BillingReconciliation($refunded))->retrieve($binding['id'], F::INVOICE);

        $this->assertSame(['reversed', 2], [$newer['outcome'], $newer['sequence']]);
        $this->assertGreaterThan($first['retrieval_position'], $newer['retrieval_position']);
        $this->assertLessThan($first['retrieval_started_at'], $newer['retrieval_started_at'], 'The worker clock was behind; it decided nothing.');
        $this->assertNull((new BillingLedger)->currentSettled($first['invoice_id'], self::T0 + 26));
    }

    public function test_overlapping_retrievals_with_identical_clock_readings_are_still_ordered_and_the_stale_one_is_refused(): void
    {
        $binding = F::binding();
        $first = $this->retrieve($binding['id'], 0);

        // Both retrievals read the very same microsecond at their start; only the database can tell which began first.
        $outcome = $this->staleAroundFresh($binding['id'], 10, 10, 10);

        // Overlapping reads by position (Codex P1 on PR #54, :42): refused for retry, whatever the clocks read.
        $this->assertSame('concurrent_retrieval', $outcome);
        $chain = (new BillingLedger)->observations($first['invoice_id']);
        $this->assertSame(['settled', 'reversed'], array_column($chain, 'outcome'));
        $this->assertNull((new BillingLedger)->currentSettled($first['invoice_id'], self::T0 + 11));
    }

    public function test_a_retrieval_that_began_before_a_hint_does_not_cover_it_even_when_the_worker_clock_is_ahead_of_the_intake_clock(): void
    {
        $binding = F::binding();
        $this->retrieve($binding['id'], 0);
        $payload = $this->event('evt_SYNTHETICSKEWAHEAD');

        $this->at(100); // The worker's clock reads 100 at the start of its provider reads.
        $open = new InterleavingBillingGateway(new RehearsalBillingGateway(F::graph(['invoice' => ['status' => 'open']])), function () use ($payload): void {
            $this->at(50); // The hint arrives during those reads on an intake host whose clock reads 50, and its dispatch is lost.
            $this->lose($payload);
            $this->at(110);
        });
        $straddling = (new BillingReconciliation($open))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['not_settled', 2], [$straddling['outcome'], $straddling['sequence']]);
        $this->assertGreaterThan(DB::table('production_membership_billing_events')->value('received_at'), $straddling['retrieval_started_at'],
            'By the skewed clocks the retrieval began after the hint; it did not.');

        $this->at(120);
        Queue::fake();
        $duplicate = $this->receive($payload);
        $this->assertSame(['binding_id' => $binding['id'], 'invoice_ref' => F::INVOICE], $duplicate['scheduled'],
            'A retrieval that began before the hint must not cover it.');
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
    }

    public function test_a_retrieval_that_began_after_a_hint_covers_it_even_when_the_worker_clock_is_behind_the_intake_clock(): void
    {
        $binding = F::binding();
        $this->retrieve($binding['id'], 0);
        $payload = $this->event('evt_SYNTHETICSKEWBEHIND');
        $this->at(50);
        $this->lose($payload);

        // Begins after the hint was received, on a worker whose clock reads 30 s behind the intake host.
        $covering = $this->retrieve($binding['id'], 20);
        $this->assertLessThan(DB::table('production_membership_billing_events')->value('received_at'), $covering['retrieval_started_at']);

        $this->at(60);
        Queue::fake();
        $this->assertNull($this->receive($payload)['scheduled'], 'A retrieval that began after the hint covers it, whatever the clocks read.');
        Queue::assertNothingPushed();
    }

    public function test_a_retrieval_that_began_after_a_hint_in_the_same_clock_instant_covers_it(): void
    {
        $binding = F::binding();
        $this->retrieve($binding['id'], 0);
        $payload = $this->event('evt_SYNTHETICSAMEINSTANT');
        $this->at(50);
        $this->lose($payload);
        $this->retrieve($binding['id'], 50);

        $this->at(60);
        Queue::fake();
        $this->assertNull($this->receive($payload)['scheduled']);
        Queue::assertNothingPushed();
    }

    public function test_the_position_is_committed_before_any_provider_read_and_no_transaction_is_open_during_provider_io(): void
    {
        $binding = F::binding();
        $seen = [];
        $gateway = new InterleavingBillingGateway(new RehearsalBillingGateway(F::graph()), function () use (&$seen): void {
            $seen = [DB::transactionLevel(), DB::table('production_membership_billing_positions')->where('kind', 'retrieval')->count(),
                DB::table('production_membership_billing_observations')->count()];
        });
        $observation = (new BillingReconciliation($gateway))->retrieve($binding['id'], F::INVOICE);

        $this->assertSame([0, 1, 0], $seen, 'The retrieval position exists before the provider reads finish; no ledger transaction spans them.');
        $this->assertSame((int) DB::table('production_membership_billing_positions')->where('kind', 'retrieval')->value('id'), $observation['retrieval_position']);
    }

    /**
     * A stale retrieval of a settled graph begins at clock reading `$staleClock`; between its last provider read and its append, a
     * fresh retrieval of a refunded graph runs to completion at clock reading `$freshClock`. Returns 'appended' or the refusal reason.
     */
    private function staleAroundFresh(string $bindingId, int $staleClock, int $freshClock, int $staleAppendClock): string
    {
        $this->at($staleClock);
        $stale = new InterleavingBillingGateway(new RehearsalBillingGateway(F::graph()), function () use ($bindingId, $freshClock, $staleAppendClock): void {
            $this->retrieve($bindingId, $freshClock, ['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]]);
            $this->at($staleAppendClock);
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

    /** Commits a hint whose dispatch then fails (this lane binds no gateway), as a queue outage would. */
    private function lose(string $payload): void
    {
        try {
            $this->receive($payload);
            $this->fail('The unbound gateway must make the hint dispatch fail after the hint committed.');
        } catch (BindingResolutionException) {
            // Expected.
        }
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
