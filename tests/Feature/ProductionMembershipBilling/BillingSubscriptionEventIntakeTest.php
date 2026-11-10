<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingValues;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use App\Jobs\RetrieveMembershipInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Stripe\Event;
use Stripe\Subscription;
use Stripe\WebhookSignature;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/**
 * customer.subscription.updated and .deleted (BL-69) go through the existing hint ledger: signature, own account, livemode=false and
 * pinned API version are enforced, the event id deduplicates, and the event is retained as a no_invoice_hint. It never settles,
 * observes, awards or schedules a retrieval, so order and repetition change nothing; invoice.paid stays the only settling hint.
 */
class BillingSubscriptionEventIntakeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const SECRET = 'whsec_SYNTHETICREHEARSAL';

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        Queue::fake();
    }

    public function test_updated_and_deleted_are_retained_deduplicated_hints_that_settle_and_schedule_nothing(): void
    {
        $binding = F::binding();
        $updated = $this->event('evt_SYNTHETICSUBUPD', 'customer.subscription.updated');
        $deleted = $this->event('evt_SYNTHETICSUBDEL', 'customer.subscription.deleted', ['status' => 'canceled']);
        $first = $this->receive($updated);
        $second = $this->receive($deleted);
        foreach ([$first, $second] as $result) {
            $this->assertFalse($result['duplicate']);
            $this->assertSame(['no_invoice_hint', null, null], [$result['event']['disposition'], $result['event']['invoice_ref_hash'], $result['scheduled']]);
        }
        $this->assertSame(['customer.subscription.updated', 'customer.subscription.deleted'], [$first['event']['type'], $second['event']['type']]);
        // The subscription reference is retained inside the sealed hint payload, and the bound subscription still schedules nothing.
        $this->assertSame(F::SUBSCRIPTION, BillingValues::decrypt($first['event']['payload_ciphertext'])['subscription_ref']);
        $this->assertSame($binding['subscription_ref_hash'], BillingValues::hash('subscription', F::ACCOUNT, 'test', F::SUBSCRIPTION));
        // Replays, including a re-signed different body under the same event id, are the same provider event.
        $this->assertTrue($this->receive($updated)['duplicate']);
        $this->assertTrue($this->receive($deleted)['duplicate']);
        $this->assertTrue($this->receive($this->event('evt_SYNTHETICSUBDEL', 'customer.subscription.updated'))['duplicate']);
        $this->assertSame(2, DB::table('production_membership_billing_events')->count());
        Queue::assertNothingPushed();
        $this->assertNothingObservedOrAwarded();
    }

    public function test_arrival_order_and_a_late_updated_after_deleted_change_nothing(): void
    {
        F::binding();
        // The ledger is append-only, so both orders run in one ledger under distinct event ids and are compared by arrival position.
        $this->receive($this->event('evt_SYNTHETICDELA', 'customer.subscription.deleted', ['status' => 'canceled']));
        $this->receive($this->event('evt_SYNTHETICUPDA', 'customer.subscription.updated', ['status' => 'active'], F::PERIOD_START - 600));
        $this->receive($this->event('evt_SYNTHETICUPDB', 'customer.subscription.updated', ['status' => 'active'], F::PERIOD_START - 600));
        $this->receive($this->event('evt_SYNTHETICDELB', 'customer.subscription.deleted', ['status' => 'canceled']));
        // The older "updated" arriving after the newer "deleted" neither resurrects nor cancels anything: every arrival is the same retained,
        // unscheduled hint whatever came before it.
        $rows = DB::table('production_membership_billing_events')->orderBy('hint_position')->get(['type', 'disposition', 'invoice_ref_hash']);
        $this->assertSame(['customer.subscription.deleted', 'customer.subscription.updated', 'customer.subscription.updated', 'customer.subscription.deleted'], $rows->pluck('type')->all());
        $this->assertSame(['no_invoice_hint'], $rows->pluck('disposition')->unique()->values()->all());
        $this->assertSame([null], $rows->pluck('invoice_ref_hash')->unique()->values()->all());
        Queue::assertNothingPushed();
        $this->assertNothingObservedOrAwarded();
    }

    public function test_wrong_account_livemode_version_signature_and_object_are_refused_before_a_row(): void
    {
        F::binding();
        $cases = [
            'live event' => [$this->event('evt_SYNTHETICLIVE', 'customer.subscription.updated', [], null, ['livemode' => true]), 'event_scope'],
            'connected account' => [$this->event('evt_SYNTHETICACCT', 'customer.subscription.deleted', [], null, ['account' => 'acct_CONNECTEDSYNTHETIC']), 'event_scope'],
            'live embedded object' => [$this->event('evt_SYNTHETICEMB', 'customer.subscription.updated', ['livemode' => true]), 'event_scope'],
            'not a subscription' => [$this->event('evt_SYNTHETICOBJ', 'customer.subscription.updated', ['object' => 'customer']), 'event_scope'],
            'other api version' => [$this->event('evt_SYNTHETICVER', 'customer.subscription.updated', [], null, ['api_version' => '2025-01-27.acacia']), 'api_version'],
        ];
        foreach ($cases as $name => [$payload, $reason]) {
            try {
                $this->receive($payload);
                $this->fail($name.' must refuse.');
            } catch (BillingException $error) {
                $this->assertSame($reason, $error->reason, $name);
            }
        }
        $payload = $this->event('evt_SYNTHETICSIG', 'customer.subscription.updated');
        foreach ([WebhookSignature::generateSignatureHeader($payload, 'whsec_OTHERSYNTHETIC'), ''] as $signature) {
            try {
                (new BillingWebhookIntake)->receive($payload, $signature);
                $this->fail('A bad signature must refuse.');
            } catch (BillingException $error) {
                $this->assertSame('signature', $error->reason);
            }
        }
        $this->assertSame(0, DB::table('production_membership_billing_events')->count());
        Queue::assertNothingPushed();
    }

    public function test_invoice_paid_remains_the_only_hint_that_schedules_a_settling_retrieval(): void
    {
        $binding = F::binding();
        $this->receive($this->event('evt_SYNTHETICSUBUPD', 'customer.subscription.updated'));
        $paid = (new BillingWebhookIntake)->receive($this->invoiceEvent('evt_SYNTHETICPAID'), WebhookSignature::generateSignatureHeader($this->invoiceEvent('evt_SYNTHETICPAID'), self::SECRET));
        $this->assertSame(['retrieval_hint', ['binding_id' => $binding['id'], 'invoice_ref' => F::INVOICE]], [$paid['event']['disposition'], $paid['scheduled']]);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
        $this->assertNothingObservedOrAwarded();
    }

    private function receive(string $payload): array
    {
        return (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, self::SECRET));
    }

    private function event(string $id, string $type, array $object = [], ?int $created = null, array $event = []): string
    {
        $subscription = F::sdk(Subscription::class, [...['id' => F::SUBSCRIPTION, 'object' => 'subscription', 'livemode' => false, 'customer' => F::CUSTOMER,
            'status' => 'active', 'currency' => 'xts'], ...$object]);
        $values = [...['id' => $id, 'object' => 'event', 'api_version' => BillingProviderPin::API_VERSION, 'created' => $created ?? F::PERIOD_START,
            'livemode' => false, 'pending_webhooks' => 1, 'request' => ['id' => null, 'idempotency_key' => null], 'type' => $type,
            'data' => ['object' => $subscription]], ...$event];

        return json_encode(F::sdk(Event::class, $values), JSON_THROW_ON_ERROR);
    }

    private function invoiceEvent(string $id): string
    {
        return json_encode(F::sdk(Event::class, ['id' => $id, 'object' => 'event', 'api_version' => BillingProviderPin::API_VERSION, 'created' => F::PERIOD_START,
            'livemode' => false, 'pending_webhooks' => 1, 'request' => ['id' => null, 'idempotency_key' => null], 'type' => 'invoice.paid',
            'data' => ['object' => F::graph()['invoice']]]), JSON_THROW_ON_ERROR);
    }

    private function assertNothingObservedOrAwarded(): void
    {
        foreach (['production_membership_billing_observations', 'production_membership_billing_invoices', 'production_membership_paid_periods',
            'production_membership_credit_events'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
