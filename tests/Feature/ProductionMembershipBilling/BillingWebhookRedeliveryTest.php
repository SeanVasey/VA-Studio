<?php

namespace Tests\Feature\ProductionMembershipBilling;

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

    private function runRetrieval(string $bindingId, int $offset): void
    {
        $this->at($offset);
        (new RetrieveMembershipInvoice($bindingId, F::INVOICE))->handle(new BillingReconciliation(new RehearsalBillingGateway(F::graph())));
    }

    private function at(int $offset): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(self::T0 + $offset));
    }

    private function receive(string $payload): array
    {
        return (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, self::SECRET));
    }

    private function event(string $id, string $type = 'invoice.paid', array $object = []): string
    {
        $values = ['id' => $id, 'object' => 'event', 'api_version' => BillingProviderPin::API_VERSION, 'created' => F::PERIOD_START,
            'livemode' => false, 'pending_webhooks' => 1, 'request' => ['id' => null, 'idempotency_key' => null], 'type' => $type,
            'data' => ['object' => [...F::graph()['invoice'], ...$object]]];

        return json_encode(F::sdk(Event::class, $values), JSON_THROW_ON_ERROR);
    }
}
