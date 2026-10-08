<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingVerdict;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use App\Jobs\RetrieveMembershipInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\Event;
use Stripe\WebhookSignature;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/**
 * Review R-2 (Codex P2): a hint committed before its dispatch was lost is recovered by the provider's redelivery. A duplicate
 * delivery re-dispatches the retrieval only while the hint has no observation appended after it was received.
 */
class BillingWebhookRedeliveryTest extends TestCase
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

    public function test_duplicate_after_a_successful_observation_dispatches_nothing(): void
    {
        $binding = F::binding();
        Queue::fake();
        $payload = $this->event('evt_SYNTHETIC1');
        $this->receive($payload);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
        $this->runRetrieval($binding['id'], 10);
        $this->at(20);
        $duplicate = $this->receive($payload);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertNull($duplicate['scheduled']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
        $this->assertSame(1, DB::table('production_membership_billing_observations')->count());
    }

    public function test_duplicate_after_a_lost_dispatch_dispatches_once_and_leaves_the_event_row_unchanged(): void
    {
        $binding = F::binding();
        $payload = $this->event('evt_SYNTHETIC1');
        $this->loseFirstDispatch($payload);
        $stored = (array) DB::table('production_membership_billing_events')->first();
        $this->at(30);
        Queue::fake();
        $duplicate = $this->receive($payload);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame(['binding_id' => $binding['id'], 'invoice_ref' => F::INVOICE], $duplicate['scheduled']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
        Queue::assertPushed(RetrieveMembershipInvoice::class, fn ($job) => $job->bindingId === $binding['id'] && $job->invoiceRef === F::INVOICE);
        $this->assertSame([$stored], array_map(fn ($row) => (array) $row, DB::table('production_membership_billing_events')->get()->all()));
        $this->assertSame(0, DB::table('production_membership_billing_observations')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    public function test_two_duplicates_after_a_lost_dispatch_still_result_in_one_observation(): void
    {
        $binding = F::binding();
        $payload = $this->event('evt_SYNTHETIC1');
        $this->loseFirstDispatch($payload);
        $this->at(30);
        Queue::fake();
        $this->assertNotNull($this->receive($payload)['scheduled']);
        $this->runRetrieval($binding['id'], 40);
        $this->at(50);
        $this->assertNull($this->receive($payload)['scheduled']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
        $this->assertSame([1, 1], [DB::table('production_membership_billing_invoices')->count(), DB::table('production_membership_billing_observations')->count()]);
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    public function test_an_observation_older_than_the_hint_does_not_cover_it(): void
    {
        $binding = F::binding();
        $this->runRetrieval($binding['id'], 0);
        $this->at(60);
        $payload = $this->event('evt_SYNTHETIC1');
        $this->loseFirstDispatch($payload);
        $this->at(70);
        Queue::fake();
        $this->assertNotNull($this->receive($payload)['scheduled']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
    }

    public function test_duplicates_before_the_retrieval_runs_each_schedule_and_only_append_valid_chained_observations(): void
    {
        $binding = F::binding();
        $payload = $this->event('evt_SYNTHETIC1');
        $this->loseFirstDispatch($payload);
        $this->at(30);
        Queue::fake();
        $this->receive($payload);
        $this->receive($payload);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 2);
        $this->runRetrieval($binding['id'], 40);
        $this->runRetrieval($binding['id'], 50);
        $this->assertSame(1, DB::table('production_membership_billing_invoices')->count());
        $invoiceId = DB::table('production_membership_billing_invoices')->value('id');
        $this->assertSame([1, 2], array_column((new BillingLedger)->observations($invoiceId), 'sequence'));
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    public function test_duplicates_of_events_that_name_no_retrievable_invoice_dispatch_nothing(): void
    {
        F::binding();
        Queue::fake();
        $charge = $this->event('evt_SYNTHETIC3', 'charge.refunded', ['id' => F::CHARGE, 'object' => 'charge']);
        $ignored = $this->event('evt_SYNTHETIC4', 'customer.updated', ['id' => F::CUSTOMER, 'object' => 'customer']);
        $unbound = $this->event('evt_SYNTHETIC5', 'invoice.paid', ['parent' => ['type' => 'subscription_details',
            'subscription_details' => ['subscription' => 'sub_UNBOUNDSYNTHETIC']]]);
        foreach ([$charge, $ignored, $unbound] as $payload) {
            $this->receive($payload);
            $this->at(30);
            $duplicate = $this->receive($payload);
            $this->assertTrue($duplicate['duplicate']);
            $this->assertNull($duplicate['scheduled']);
        }
        Queue::assertNothingPushed();
    }

    /**
     * Codex P2 (unknown-outcomes, Finding C): only a definitive observation covers a hint. An `unknown` outcome (timeout, ambiguous
     * response) and a `provider_incomplete` refusal retrieved no usable state, so a redelivery must dispatch again.
     *
     * @return array<string, array{0: string, 1: bool}> observation kind, whether it covers the hint
     */
    public static function observationKinds(): array
    {
        return [
            'unknown_unavailable' => ['unavailable', false],
            'unknown_inconsistent' => ['inconsistent', false],
            'refused_provider_incomplete' => ['incomplete', false],
            'settled' => ['settled', true],
            'refused_currency' => ['refused', true],
            'not_settled_invoice_open' => ['open', true],
            'reversed_refunded' => ['refunded', true],
        ];
    }

    #[DataProvider('observationKinds')]
    public function test_only_a_definitive_observation_after_the_hint_covers_it(string $kind, bool $covers): void
    {
        $binding = F::binding();
        $this->runRetrieval($binding['id'], 0); // The binding owns the identity, as an unknown can only be recorded under one.
        $this->at(60);
        $payload = $this->event('evt_SYNTHETICKIND');
        $this->loseFirstDispatch($payload);
        $this->observe($binding['id'], $kind, 70);
        $this->at(80);
        Queue::fake();
        $duplicate = $this->receive($payload);
        if ($covers) {
            $this->assertNull($duplicate['scheduled']);
            Queue::assertNothingPushed();
        } else {
            $this->assertSame(['binding_id' => $binding['id'], 'invoice_ref' => F::INVOICE], $duplicate['scheduled']);
            Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
        }
    }

    public function test_a_definitive_observation_after_an_unknown_one_covers_the_hint(): void
    {
        $binding = F::binding();
        $this->runRetrieval($binding['id'], 0);
        $this->at(60);
        $payload = $this->event('evt_SYNTHETICUNKNOWN');
        $this->loseFirstDispatch($payload);
        $this->observe($binding['id'], 'unavailable', 70);
        $this->at(75);
        Queue::fake();
        $this->assertNotNull($this->receive($payload)['scheduled']);
        $this->runRetrieval($binding['id'], 80);
        $this->at(90);
        $this->assertNull($this->receive($payload)['scheduled']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
    }

    /** Codex P2 (intake-2, line 56): an InvoicePayment carries no invoice parent, so the binding comes from the invoice's existing identity. */
    public function test_invoice_payment_paid_dispatches_for_the_binding_that_owns_the_invoice_identity(): void
    {
        $binding = F::binding();
        $this->runRetrieval($binding['id'], 0);
        $this->at(60);
        Queue::fake();
        $result = $this->receive($this->paymentEvent('evt_SYNTHETICPAY1'));
        $this->assertFalse($result['duplicate']);
        $this->assertSame('retrieval_hint', $result['event']['disposition']);
        $this->assertSame(['binding_id' => $binding['id'], 'invoice_ref' => F::INVOICE], $result['scheduled']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, fn ($job) => $job->bindingId === $binding['id'] && $job->invoiceRef === F::INVOICE);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
    }

    public function test_invoice_payment_paid_for_an_unknown_invoice_keeps_the_hint_and_dispatches_nothing(): void
    {
        F::binding();
        Queue::fake();
        $result = $this->receive($this->paymentEvent('evt_SYNTHETICPAY2'));
        $this->assertSame(['retrieval_hint', null], [$result['event']['disposition'], $result['scheduled']]);
        $this->assertNotNull($result['event']['invoice_ref_hash']);
        $this->assertSame(1, DB::table('production_membership_billing_events')->count());
        $this->assertSame(0, DB::table('production_membership_billing_invoices')->count());
        $this->at(30);
        $this->assertNull($this->receive($this->paymentEvent('evt_SYNTHETICPAY2'))['scheduled']);
        Queue::assertNothingPushed();
    }

    public function test_a_lost_invoice_payment_dispatch_is_recovered_by_redelivery_through_the_same_identity(): void
    {
        $binding = F::binding();
        $this->runRetrieval($binding['id'], 0);
        $this->at(60);
        $payload = $this->paymentEvent('evt_SYNTHETICPAY3');
        $this->loseFirstDispatch($payload);
        $this->at(70);
        Queue::fake();
        $this->assertSame(['binding_id' => $binding['id'], 'invoice_ref' => F::INVOICE], $this->receive($payload)['scheduled']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
    }

    /** Codex P2 (intake-2, line 86): the loser of a concurrent first delivery runs the same recovery against the winner's stored event. */
    public function test_concurrent_loser_recovers_a_winner_whose_dispatch_was_lost(): void
    {
        $binding = F::binding();
        $payload = $this->event('evt_SYNTHETICRACE1');
        $this->raceWinner($payload, lost: true);
        $this->at(30);
        $loser = $this->receive($payload);
        $this->assertTrue($loser['duplicate']);
        $this->assertSame(['binding_id' => $binding['id'], 'invoice_ref' => F::INVOICE], $loser['scheduled']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
        $this->assertSame([1, 0], [DB::table('production_membership_billing_events')->count(), DB::table('production_membership_billing_observations')->count()]);
    }

    public function test_concurrent_loser_dispatches_nothing_when_the_winner_was_already_observed(): void
    {
        $binding = F::binding();
        $payload = $this->event('evt_SYNTHETICRACE2');
        $this->raceWinner($payload, lost: false, bindingId: $binding['id']);
        $loser = $this->receive($payload);
        $this->assertTrue($loser['duplicate']);
        $this->assertNull($loser['scheduled']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
        $this->assertSame([1, 1], [DB::table('production_membership_billing_events')->count(), DB::table('production_membership_billing_observations')->count()]);
    }

    /**
     * Makes the next event insert lose: just before intake opens its insert transaction (after its own lookup found nothing), a
     * winning delivery of the same event id commits. A lost winner dispatch throws on the sync queue and is swallowed; a kept one
     * is retrieved at once. Either way the queue is faked afterwards so only the loser's dispatch is counted (the kept winner's
     * single push is the fake's first).
     */
    private function raceWinner(string $payload, bool $lost, ?string $bindingId = null): void
    {
        $armed = true;
        DB::connection()->beforeStartingTransaction(function () use (&$armed, $payload, $lost, $bindingId): void {
            if (! $armed) {
                return;
            }
            $armed = false;
            if ($lost) {
                try {
                    $this->receive($payload);
                } catch (BindingResolutionException) {
                    // The winner's post-commit dispatch failed.
                }
                Queue::fake();

                return;
            }
            Queue::fake();
            $this->receive($payload);
            $this->runRetrieval($bindingId, 10);
            $this->at(20);
        });
    }

    /** The first delivery commits the hint, then the dispatch fails: the sync queue runs the job, which has no bound gateway. */
    private function loseFirstDispatch(string $payload): void
    {
        try {
            $this->receive($payload);
            $this->fail('The unbound gateway must make the first dispatch fail after the hint committed.');
        } catch (BindingResolutionException) {
            // Expected: this lane binds no gateway.
        }
        $this->assertSame(1, DB::table('production_membership_billing_events')->count());
    }

    private function runRetrieval(string $bindingId, int $offset, array $graph = []): void
    {
        $this->at($offset);
        (new RetrieveMembershipInvoice($bindingId, F::INVOICE))->handle(new BillingReconciliation(new RehearsalBillingGateway(F::graph($graph))));
    }

    /** Appends one observation of the named kind under the identity the binding already owns. */
    private function observe(string $bindingId, string $kind, int $offset): void
    {
        match ($kind) {
            'settled' => $this->runRetrieval($bindingId, $offset),
            'refused' => $this->runRetrieval($bindingId, $offset, ['invoice' => ['currency' => 'usd']]),
            'open' => $this->runRetrieval($bindingId, $offset, ['invoice' => ['status' => 'open']]),
            'refunded' => $this->runRetrieval($bindingId, $offset, ['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]]),
            'unavailable', 'inconsistent', 'incomplete' => $this->appendVerdict($offset, match ($kind) {
                'unavailable' => BillingVerdict::unknown('provider_unavailable', ['invoice_ref' => F::INVOICE]),
                'inconsistent' => BillingVerdict::unknown('provider_inconsistent', ['invoice_ref' => F::INVOICE]),
                'incomplete' => new BillingVerdict('refused', 'provider_incomplete', ['invoice_ref' => F::INVOICE]),
            }),
        };
    }

    private function appendVerdict(int $offset, BillingVerdict $verdict): void
    {
        $this->at($offset);
        $identity = (array) DB::table('production_membership_billing_invoices')->first();
        (new BillingLedger)->append($identity, $verdict, self::T0 + $offset, IdentityPolicy::REHEARSAL);
    }

    private function at(int $offset): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(self::T0 + $offset));
    }

    private function receive(string $payload): array
    {
        return (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, self::SECRET));
    }

    private function paymentEvent(string $id): string
    {
        $values = ['id' => $id, 'object' => 'event', 'api_version' => BillingProviderPin::API_VERSION, 'created' => F::PERIOD_START,
            'livemode' => false, 'pending_webhooks' => 1, 'request' => ['id' => null, 'idempotency_key' => null], 'type' => 'invoice_payment.paid',
            'data' => ['object' => F::graph()['payments'][0]]];

        return json_encode(F::sdk(Event::class, $values), JSON_THROW_ON_ERROR);
    }

    private function event(string $id, string $type = 'invoice.paid', array $object = []): string
    {
        $values = ['id' => $id, 'object' => 'event', 'api_version' => BillingProviderPin::API_VERSION, 'created' => F::PERIOD_START,
            'livemode' => false, 'pending_webhooks' => 1, 'request' => ['id' => null, 'idempotency_key' => null], 'type' => $type,
            'data' => ['object' => [...F::graph()['invoice'], ...$object]]];

        return json_encode(F::sdk(Event::class, $values), JSON_THROW_ON_ERROR);
    }
}
