<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingSettlement;
use App\Domain\Memberships\Billing\BillingVerdict;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use App\Jobs\RetrieveMembershipInvoice;
use Carbon\CarbonImmutable;
use Closure;
use Fiber;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Stripe\Event as StripeEvent;
use Stripe\WebhookSignature;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InterleavingBillingGateway;
use Tests\Support\RehearsalBillingGateway;
use Tests\Support\SuspendingBillingGateway;
use Tests\TestCase;

/**
 * Codex P1 on PR #54 (`BillingReconciliation.php:42`). A position allocated before a provider read does not order the reads: a
 * retrieval can take an early position and stall before reading, then read state newer than a retrieval that took a later
 * position. Each retrieval is therefore bracketed by two database-issued positions, a start committed before its first provider
 * read and an end committed after its last, and an observation is admitted only when its start is above the tail's end (its read
 * began after the tail's read finished). An overlapping read is refused as `concurrent_retrieval` (the job retries it with a
 * fresh interval); a read that wholly precedes the tail's is refused as `superseded_retrieval` (a normal end).
 *
 * The ledger cases drive the exact position sequence of the finding; the Fiber cases drive the real BillingReconciliation through
 * the same interleavings in one process. The two-process native regression is BillingNativeIntervalRetrievalRaceTest.
 */
class BillingRetrievalIntervalOrderingTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const SECRET = 'whsec_SYNTHETICREHEARSAL';

    private const T0 = F::PERIOD_START + 3600;

    private const REFUND = ['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]];

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

    /** The finding's sequence: A start 1, B start 2, B end 3, A end 4. A (the reversal) appends first; B's older read is refused. */
    public function test_the_reversal_appended_first_keeps_the_tail_when_the_overlapping_older_settled_read_appends_after_it(): void
    {
        [$ledger, $invoice] = $this->seeded();
        $aStart = $ledger->startRetrieval();
        $bStart = $ledger->startRetrieval();
        $bEnd = $ledger->endRetrieval($bStart);
        $aEnd = $ledger->endRetrieval($aStart);
        $this->assertSame([$aStart + 1, $aStart + 2, $aStart + 3], [$bStart, $bEnd, $aEnd]);

        $reversal = $this->append($ledger, $invoice, $this->reversed(), $aStart, $aEnd);
        $this->assertSame(['reversed', 2, $aStart, $aEnd], [$reversal['outcome'], $reversal['sequence'], $reversal['retrieval_position'], $reversal['retrieval_end_position']]);

        $this->assertSame('concurrent_retrieval', $this->refusal(fn () => $this->append($ledger, $invoice, $this->settled(), $bStart, $bEnd)));
        $this->assertSame(['settled', 'reversed'], array_column($ledger->observations($invoice['id']), 'outcome'));
        $this->assertNull($ledger->currentSettled($invoice['id'], self::T0 + 1), 'The older settled read must never become the tail.');
    }

    /** Same positions, the other append order: B (settled) appends first, A's reversal overlaps it and retries with a fresh interval. */
    public function test_the_reversal_that_overlaps_a_settled_tail_is_refused_for_retry_and_the_retry_reads_and_records_the_reversal(): void
    {
        [$ledger, $invoice] = $this->seeded();
        $aStart = $ledger->startRetrieval();
        $bStart = $ledger->startRetrieval();
        $bEnd = $ledger->endRetrieval($bStart);
        $aEnd = $ledger->endRetrieval($aStart);

        $settled = $this->append($ledger, $invoice, $this->settled(), $bStart, $bEnd);
        $this->assertSame(['settled', 2], [$settled['outcome'], $settled['sequence']]);
        // A's read began (1) before B's read finished (3): the database cannot order the two reads, so A is not a normal end.
        $this->assertSame('concurrent_retrieval', $this->refusal(fn () => $this->append($ledger, $invoice, $this->reversed(), $aStart, $aEnd)));
        $this->assertSame(['settled', 'settled'], array_column($ledger->observations($invoice['id']), 'outcome'));

        $retryStart = $ledger->startRetrieval();
        $retryEnd = $ledger->endRetrieval($retryStart);
        $retry = $this->append($ledger, $invoice, $this->reversed(), $retryStart, $retryEnd);
        $this->assertSame(['reversed', 3], [$retry['outcome'], $retry['sequence']]);
        $this->assertNull($ledger->currentSettled($invoice['id'], self::T0 + 1));
    }

    public function test_a_read_that_began_after_the_tails_read_finished_is_admitted_and_one_that_wholly_preceded_it_is_superseded(): void
    {
        [$ledger, $invoice] = $this->seeded();
        $earlyStart = $ledger->startRetrieval();
        $earlyEnd = $ledger->endRetrieval($earlyStart);
        $lateStart = $ledger->startRetrieval();
        $lateEnd = $ledger->endRetrieval($lateStart);

        $late = $this->append($ledger, $invoice, $this->reversed(), $lateStart, $lateEnd);
        $this->assertSame(['reversed', 2], [$late['outcome'], $late['sequence']]);
        // The early read ended (earlyEnd) before the tail's read began (lateStart): the tail is strictly newer, nothing to retry.
        $this->assertSame('superseded_retrieval', $this->refusal(fn () => $this->append($ledger, $invoice, $this->settled(), $earlyStart, $earlyEnd)));

        $laterStart = $ledger->startRetrieval();
        $laterEnd = $ledger->endRetrieval($laterStart);
        $this->assertSame(3, $this->append($ledger, $invoice, $this->settled(), $laterStart, $laterEnd)['sequence']);
        $this->assertSame(['settled', 'reversed', 'settled'], array_column($ledger->observations($invoice['id']), 'outcome'));
    }

    /** An interval that encloses the tail's, and one that starts exactly at the tail's end, are both overlapping reads. */
    public function test_a_read_enclosing_the_tails_read_or_starting_at_its_end_position_is_concurrent(): void
    {
        [$ledger, $invoice] = $this->seeded();
        $outerStart = $ledger->startRetrieval();
        $innerStart = $ledger->startRetrieval();
        $innerEnd = $ledger->endRetrieval($innerStart);
        $outerEnd = $ledger->endRetrieval($outerStart);
        $this->append($ledger, $invoice, $this->reversed(), $innerStart, $innerEnd);
        $this->assertSame('concurrent_retrieval', $this->refusal(fn () => $this->append($ledger, $invoice, $this->settled(), $outerStart, $outerEnd)));
        // A start position equal to the tail's end cannot be admitted: positions are never shared, and "after" is strict.
        $this->assertSame('concurrent_retrieval', $this->refusal(fn () => $this->append($ledger, $invoice, $this->settled(), $innerEnd, $ledger->endRetrieval($innerEnd))));
        $this->assertSame(['settled', 'reversed'], array_column($ledger->observations($invoice['id']), 'outcome'));
    }

    public function test_the_ledger_refuses_an_end_position_that_is_not_after_its_start_or_was_not_issued_as_an_end(): void
    {
        [$ledger, $invoice] = $this->seeded();
        $start = $ledger->startRetrieval();
        foreach ([fn () => $ledger->endRetrieval(0), fn () => $ledger->endRetrieval(-1)] as $invalid) {
            $this->assertSame('invalid_value', $this->refusal($invalid));
        }
        $end = $ledger->endRetrieval($start);
        $this->assertGreaterThan($start, $end);
        foreach ([[$start, $start], [$end, $start], [$start, 0]] as [$from, $to]) {
            $this->assertSame('invalid_value', $this->refusal(fn () => $this->append($ledger, $invoice, $this->reversed(), $from, $to)));
        }
        $this->assertCount(1, $ledger->observations($invoice['id']));
        DB::beginTransaction();
        try {
            $this->assertSame('transaction_open', $this->refusal(fn () => $ledger->endRetrieval($start)));
        } finally {
            DB::rollBack();
        }
    }

    public function test_a_first_retrieval_has_no_tail_and_records_both_positions_with_the_end_allocated_after_the_last_read(): void
    {
        $binding = F::binding();
        $seen = [];
        $gateway = new InterleavingBillingGateway(new RehearsalBillingGateway(F::graph()), function () use (&$seen): void {
            $seen = [DB::transactionLevel(), DB::table('production_membership_billing_positions')->where('kind', 'retrieval')->count()];
        });
        $observation = (new BillingReconciliation($gateway))->retrieve($binding['id'], F::INVOICE);

        $this->assertSame([0, 1], $seen, 'Only the start position exists while the provider reads run.');
        $positions = DB::table('production_membership_billing_positions')->orderBy('id')->get(['id', 'kind']);
        $this->assertSame([['retrieval', $observation['retrieval_position']], ['retrieval', $observation['retrieval_end_position']]],
            $positions->map(fn ($row): array => [$row->kind, (int) $row->id])->all());
        $this->assertSame(['settled', 1], [$observation['outcome'], $observation['sequence']]);
        $this->assertGreaterThan($observation['retrieval_position'], $observation['retrieval_end_position']);
    }

    public function test_a_refused_first_retrieval_leaves_only_its_two_anonymous_positions(): void
    {
        $binding = F::binding();
        $this->assertSame('provider_unavailable', $this->refusal(fn () => (new BillingReconciliation(new RehearsalBillingGateway(F::graph(), 'invoice')))->retrieve($binding['id'], F::INVOICE)));
        $this->assertSame('binding_refused_customer', $this->refusal(fn () => (new BillingReconciliation(new RehearsalBillingGateway(F::graph(['invoice' => ['customer' => 'cus_FOREIGNSYNTHETIC']]))))->retrieve($binding['id'], F::INVOICE)));

        $this->assertSame([0, 0], [DB::table('production_membership_billing_invoices')->count(), DB::table('production_membership_billing_observations')->count()]);
        $this->assertSame(['retrieval', 'retrieval', 'retrieval', 'retrieval'], DB::table('production_membership_billing_positions')->orderBy('id')->pluck('kind')->all());
        $this->assertSame(['created_at', 'id', 'kind'], collect(array_keys((array) DB::table('production_membership_billing_positions')->first()))->sort()->values()->all(),
            'A position names no invoice, binding or provider reference.');
    }

    /** The finding through the real reconciliation: A stalls before its reads, B reads the settled graph, A reads the reversal. */
    public function test_retrieval_a_stalled_before_reading_appends_the_reversal_and_overlapping_b_cannot_append_its_older_settlement_after_it(): void
    {
        $binding = F::binding();
        $first = $this->retrieve($binding['id'], F::graph());

        $a = $this->fiber($binding['id'], new SuspendingBillingGateway(new RehearsalBillingGateway(F::graph(self::REFUND)), 'before_first_read'));
        $this->assertSame('before_first_read', $a->start());
        $b = $this->fiber($binding['id'], new SuspendingBillingGateway(new RehearsalBillingGateway(F::graph()), 'after_last_read'));
        $this->assertSame('after_last_read', $b->start(), 'B has read the settled graph and is stalled before its append.');
        // The provider has reversed the payment by the time A reads.
        $a->resume();
        $this->assertSame(['reversed', 2], [$a->getReturn()['outcome'], $a->getReturn()['sequence']]);
        $b->resume();

        $this->assertSame('concurrent_retrieval', $b->getReturn());
        $chain = (new BillingLedger)->observations($first['invoice_id']);
        $this->assertSame(['settled', 'reversed'], array_column($chain, 'outcome'));
        $this->assertNull((new BillingLedger)->currentSettled($first['invoice_id'], self::T0 + 1), 'The stale settlement is never current evidence.');
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    /** The other order: B's settled read appends while A is stalled; A's reversal is refused for retry, and the retry records it. */
    public function test_retrieval_a_stalled_before_reading_is_retried_when_b_appended_first_and_the_retry_records_the_reversal(): void
    {
        $binding = F::binding();
        $first = $this->retrieve($binding['id'], F::graph());

        $a = $this->fiber($binding['id'], new SuspendingBillingGateway(new RehearsalBillingGateway(F::graph(self::REFUND)), 'before_first_read'));
        $a->start();
        $settled = $this->retrieve($binding['id'], F::graph());
        $this->assertSame(['settled', 2], [$settled['outcome'], $settled['sequence']]);
        $a->resume();

        $this->assertSame('concurrent_retrieval', $a->getReturn(), 'A read that began before the tail\'s read finished is retried, not ended.');
        $this->assertNotNull((new BillingLedger)->currentSettled($first['invoice_id'], self::T0 + 1));
        $retry = $this->retrieve($binding['id'], F::graph(self::REFUND));
        $this->assertSame(['reversed', 3], [$retry['outcome'], $retry['sequence']]);
        $this->assertNull((new BillingLedger)->currentSettled($first['invoice_id'], self::T0 + 1));
    }

    /** A stale read whose end position committed before a fresh retrieval began is wholly earlier: superseded, a normal end. */
    public function test_a_read_that_finished_before_the_tails_read_began_is_superseded(): void
    {
        $binding = F::binding();
        $first = $this->retrieve($binding['id'], F::graph());
        $stale = new InterleavingBillingGateway(new RehearsalBillingGateway(F::graph()), function () use ($binding): void {
            // The next commit after the last provider read is this retrieval's end position; the fresh retrieval begins after it.
            $this->afterNextCommit(fn () => $this->retrieve($binding['id'], F::graph(self::REFUND)));
        });

        $this->assertSame('superseded_retrieval', $this->refusal(fn () => (new BillingReconciliation($stale))->retrieve($binding['id'], F::INVOICE)));
        $chain = (new BillingLedger)->observations($first['invoice_id']);
        $this->assertSame(['settled', 'reversed'], array_column($chain, 'outcome'));
        // Two positions each for the seed, the stale and the fresh retrieval: the refused stale one leaves its two anonymous rows.
        $this->assertSame(6, DB::table('production_membership_billing_positions')->where('kind', 'retrieval')->count());
    }

    /** Hint coverage stays on the START position: a read whose end is after the hint but whose start is before it covers nothing. */
    public function test_a_hint_received_between_a_retrievals_start_and_end_positions_is_not_covered_by_it(): void
    {
        $binding = F::binding();
        $this->retrieve($binding['id'], F::graph());
        $payload = $this->event('evt_SYNTHETICINTERVALHINT');
        $straddling = (new BillingReconciliation(new InterleavingBillingGateway(new RehearsalBillingGateway(F::graph(['invoice' => ['status' => 'open']])),
            fn () => $this->lose($payload))))->retrieve($binding['id'], F::INVOICE);
        $hintPosition = (int) DB::table('production_membership_billing_events')->value('hint_position');
        $this->assertGreaterThan($straddling['retrieval_position'], $hintPosition);
        $this->assertLessThan($straddling['retrieval_end_position'], $hintPosition);

        Queue::fake();
        $this->assertSame(['binding_id' => $binding['id'], 'invoice_ref' => F::INVOICE], $this->receive($payload)['scheduled'],
            'The end position orders appends only; it never decides coverage.');
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
    }

    /** @return array{0: BillingLedger, 1: array} a ledger and an invoice identity with one settled observation as the tail */
    private function seeded(): array
    {
        $binding = F::binding();
        $first = $this->retrieve($binding['id'], F::graph());
        $this->assertSame(['settled', 1], [$first['outcome'], $first['sequence']]);

        return [new BillingLedger, (array) DB::table('production_membership_billing_invoices')->where('id', $first['invoice_id'])->first()];
    }

    private function append(BillingLedger $ledger, array $invoice, BillingVerdict $verdict, int $start, int $end): array
    {
        return $ledger->append($invoice, $verdict, self::T0, IdentityPolicy::REHEARSAL, $start, $end, CarbonImmutable::now('UTC'));
    }

    private function settled(): BillingVerdict
    {
        return BillingSettlement::evaluate(F::snapshots(F::graph()), F::expectation());
    }

    private function reversed(): BillingVerdict
    {
        $verdict = BillingSettlement::evaluate(F::snapshots(F::graph(self::REFUND)), F::expectation());
        $this->assertSame('reversed', $verdict->outcome);

        return $verdict;
    }

    private function retrieve(string $bindingId, array $graph): array
    {
        return (new BillingReconciliation(new RehearsalBillingGateway($graph)))->retrieve($bindingId, F::INVOICE);
    }

    /** A retrieval run as a Fiber: it returns the observation, or the refusal reason. */
    private function fiber(string $bindingId, SuspendingBillingGateway $gateway): Fiber
    {
        return new Fiber(function () use ($bindingId, $gateway): array|string {
            try {
                return (new BillingReconciliation($gateway))->retrieve($bindingId, F::INVOICE);
            } catch (BillingException $error) {
                return $error->reason;
            }
        });
    }

    private function afterNextCommit(Closure $callback): void
    {
        $armed = true;
        Event::listen(TransactionCommitted::class, function () use (&$armed, $callback): void {
            if ($armed) {
                $armed = false;
                $callback();
            }
        });
    }

    private function refusal(Closure $operation): string
    {
        try {
            $operation();
        } catch (BillingException $error) {
            return $error->reason;
        }
        $this->fail('Expected a billing refusal.');
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

        return json_encode(F::sdk(StripeEvent::class, $values), JSON_THROW_ON_ERROR);
    }
}
