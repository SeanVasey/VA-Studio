<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingValues;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use App\Jobs\RetrieveMembershipInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Stripe\Event;
use Stripe\WebhookSignature;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/**
 * Addendum 1, A1-2: an operator command lists retained `retrieval_hint` events that no definitive observation covers and, only
 * with --dispatch, queues one retrieval per uncovered invoice. It is gated by the billing policy (default off), registers no
 * schedule, reads no provider and writes no ledger row.
 */
class BillingSweepHintsCommandTest extends TestCase
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

    public function test_it_refuses_while_the_billing_policy_is_off_and_neither_lists_nor_dispatches(): void
    {
        $binding = F::binding();
        $this->lose('evt_SYNTHETICOFF');
        config(['production-membership-billing.enabled' => false]);
        Queue::fake();
        foreach ([[], ['--dispatch' => true]] as $options) {
            $exit = Artisan::call('membership-billing:sweep-hints', $options);
            $output = Artisan::output();
            $this->assertSame(1, $exit);
            $this->assertStringContainsString('disabled', $output);
            $this->assertStringNotContainsString('evt_', $output);
        }
        Queue::assertNothingPushed();
        $this->assertSame($binding['id'], DB::table('production_membership_billing_subscriptions')->value('id'));
    }

    public function test_the_dry_run_lists_uncovered_hints_by_hash_and_dispatches_nothing(): void
    {
        $binding = F::binding();
        $this->lose('evt_SYNTHETICDRY');
        $hash = (string) DB::table('production_membership_billing_events')->value('provider_event_ref_hash');
        Queue::fake();
        $this->at(30);

        $this->assertSame(0, Artisan::call('membership-billing:sweep-hints'));
        $output = Artisan::output();
        $this->assertStringContainsString(substr($hash, 0, 12), $output);
        $this->assertStringContainsString($binding['id'], $output);
        $this->assertStringContainsString('dry run', strtolower($output));
        $this->assertStringNotContainsString(F::INVOICE, $output, 'Provider references are never printed.');
        $this->assertStringNotContainsString('evt_SYNTHETICDRY', $output);
        Queue::assertNothingPushed();
    }

    public function test_dispatch_queues_one_retrieval_per_uncovered_invoice_and_leaves_covered_hints_alone(): void
    {
        $binding = F::binding();
        $covered = 'evt_SYNTHETICCOVERED';
        $this->lose($covered, 'in_SYNTHETIC');
        $this->at(10);
        (new RetrieveMembershipInvoice($binding['id'], F::INVOICE))->handle(new BillingReconciliation(new RehearsalBillingGateway(F::graph())));
        $this->at(20);
        // A second invoice of the same bound subscription whose hint was lost and never retrieved.
        $this->lose('evt_SYNTHETICOTHER', 'in_SYNTHETICOTHER');
        // Two uncovered hints for that one invoice need one retrieval, not two.
        $this->lose('evt_SYNTHETICOTHERAGAIN', 'in_SYNTHETICOTHER', 'invoice.updated');
        // A hint that names a subscription no binding owns cannot be routed.
        $this->lose('evt_SYNTHETICUNBOUND', 'in_SYNTHETICUNBOUND', 'invoice.paid', 'sub_UNBOUNDSYNTHETIC');
        $this->at(30);
        Queue::fake();

        $this->assertSame(0, Artisan::call('membership-billing:sweep-hints', ['--dispatch' => true]));
        $output = Artisan::output();
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
        Queue::assertPushed(RetrieveMembershipInvoice::class, fn (RetrieveMembershipInvoice $job) => $job->bindingId === $binding['id'] && $job->invoiceRef() === 'in_SYNTHETICOTHER');
        $this->assertStringContainsString('dispatched', strtolower($output));
        $coveredHash = BillingValues::hash('event', F::ACCOUNT, 'test', $covered);
        $this->assertStringNotContainsString(substr($coveredHash, 0, 12), $output, 'A covered hint is not listed as work.');
        $this->assertSame(1, DB::table('production_membership_billing_observations')->count(), 'The sweep reads no provider and writes no ledger row.');
        $this->assertSame(4, DB::table('production_membership_billing_events')->count(), 'Retained hints are never modified or deleted.');
    }

    public function test_a_second_sweep_after_the_retrieval_ran_dispatches_nothing(): void
    {
        $binding = F::binding();
        $this->lose('evt_SYNTHETICTWICE');
        $this->at(30);
        Queue::fake();
        Artisan::call('membership-billing:sweep-hints', ['--dispatch' => true]);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);

        (new RetrieveMembershipInvoice($binding['id'], F::INVOICE))->handle(new BillingReconciliation(new RehearsalBillingGateway(F::graph())));
        $this->at(60);
        Queue::fake();
        $this->assertSame(0, Artisan::call('membership-billing:sweep-hints', ['--dispatch' => true]));
        Queue::assertNothingPushed();
    }

    public function test_the_command_is_not_scheduled(): void
    {
        $scheduled = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);
        $this->assertFalse($scheduled->contains(fn (string $command) => str_contains($command, 'membership-billing')));
    }

    /** Commits an event hint whose dispatch fails afterwards (this lane binds no gateway), as a queue outage would. */
    private function lose(string $eventId, string $invoiceRef = F::INVOICE, string $type = 'invoice.paid', string $subscription = F::SUBSCRIPTION): void
    {
        try {
            $this->receive($this->event($eventId, $invoiceRef, $type, $subscription));
        } catch (BindingResolutionException) {
            // Expected.
        }
    }

    private function receive(string $payload): array
    {
        return (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, self::SECRET));
    }

    private function event(string $id, string $invoiceRef, string $type, string $subscription): string
    {
        $invoice = [...F::graph()['invoice'], 'id' => $invoiceRef,
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => $subscription]]];
        $values = ['id' => $id, 'object' => 'event', 'api_version' => BillingProviderPin::API_VERSION, 'created' => F::PERIOD_START,
            'livemode' => false, 'pending_webhooks' => 1, 'request' => ['id' => null, 'idempotency_key' => null], 'type' => $type, 'data' => ['object' => $invoice]];

        return json_encode(F::sdk(Event::class, $values), JSON_THROW_ON_ERROR);
    }

    private function at(int $offset): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(self::T0 + $offset));
    }
}
