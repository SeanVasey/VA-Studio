<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingValues;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use App\Jobs\RetrieveMembershipInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Stripe\Event;
use Stripe\WebhookSignature;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/** A valid signature is not proof of payment: intake records a deduplicated hint and never observes or awards. */
class BillingWebhookIntakeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const SECRET = 'whsec_SYNTHETICREHEARSAL';

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        Queue::fake();
    }

    public function test_bad_signature_or_stale_timestamp_records_nothing(): void
    {
        F::binding();
        $payload = $this->event('evt_SYNTHETIC1', 'invoice.paid');
        foreach (['t='.time().',v1='.str_repeat('0', 64), WebhookSignature::generateSignatureHeader($payload, 'whsec_OTHERSYNTHETIC'),
            WebhookSignature::generateSignatureHeader($payload, self::SECRET, time() - BillingWebhookIntake::TOLERANCE_SECONDS - 60), ''] as $signature) {
            try {
                (new BillingWebhookIntake)->receive($payload, $signature);
                $this->fail('An unverifiable delivery must refuse.');
            } catch (BillingException $error) {
                $this->assertSame('signature', $error->reason);
            }
        }
        try {
            (new BillingWebhookIntake)->receive($payload.' ', WebhookSignature::generateSignatureHeader($payload, self::SECRET));
            $this->fail('A changed body must refuse.');
        } catch (BillingException $error) {
            $this->assertSame('signature', $error->reason);
        }
        $this->assertSame(0, DB::table('production_membership_billing_events')->count());
        Queue::assertNothingPushed();
    }

    public function test_paid_event_records_one_hint_schedules_retrieval_and_awards_nothing(): void
    {
        $binding = F::binding();
        $result = $this->receive($this->event('evt_SYNTHETIC1', 'invoice.paid'));
        $this->assertFalse($result['duplicate']);
        $this->assertSame(['binding_id' => $binding['id'], 'invoice_ref' => F::INVOICE], $result['scheduled']);
        $this->assertSame('retrieval_hint', $result['event']['disposition']);
        $this->assertSame(BillingValues::hash('invoice', F::ACCOUNT, 'test', F::INVOICE), $result['event']['invoice_ref_hash']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, fn ($job) => $job->bindingId === $binding['id'] && $job->invoiceRef() === F::INVOICE);
        $this->assertNothingObservedOrAwarded();
    }

    public function test_replayed_event_id_is_deduplicated_without_a_second_row_or_retrieval(): void
    {
        $binding = F::binding();
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(F::PERIOD_START + 3600));
        try {
            $payload = $this->event('evt_SYNTHETIC1', 'invoice.paid');
            $first = $this->receive($payload);
            // The scheduled retrieval ran and observed the invoice after the hint, so a redelivery has nothing left to recover.
            CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(F::PERIOD_START + 3610));
            (new RetrieveMembershipInvoice($binding['id'], F::INVOICE))->handle(new BillingReconciliation(new RehearsalBillingGateway(F::graph())));
            CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(F::PERIOD_START + 3620));
            $replay = $this->receive($payload);
            $this->assertTrue($replay['duplicate']);
            $this->assertNull($replay['scheduled']);
            $this->assertSame($first['event']['id'], $replay['event']['id']);
            // A re-signed body with the same event id is still the same provider event.
            $this->assertTrue($this->receive($this->event('evt_SYNTHETIC1', 'invoice.payment_failed'))['duplicate']);
            $this->assertSame(1, DB::table('production_membership_billing_events')->count());
            Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_out_of_order_events_are_hints_only_and_each_schedules_a_current_retrieval(): void
    {
        F::binding();
        $paid = $this->receive($this->event('evt_SYNTHETIC2', 'invoice.paid', 1791338400));
        $older = $this->receive($this->event('evt_SYNTHETIC1', 'invoice.payment_failed', 1791334800, ['status' => 'open']));
        $this->assertSame(['retrieval_hint', 'retrieval_hint'], [$paid['event']['disposition'], $older['event']['disposition']]);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 2);
        $this->assertNothingObservedOrAwarded();
    }

    public function test_scope_type_and_binding_dispositions(): void
    {
        F::binding();
        foreach ([['livemode' => true], ['account' => 'acct_CONNECTEDSYNTHETIC']] as $change) {
            try {
                $this->receive($this->event('evt_SYNTHETICSCOPE', 'invoice.paid', null, [], $change));
                $this->fail('A live or Connect event is outside the configured own test account.');
            } catch (BillingException $error) {
                $this->assertSame('event_scope', $error->reason);
            }
        }
        $refund = $this->receive($this->event('evt_SYNTHETIC3', 'charge.refunded', null, ['id' => F::CHARGE, 'object' => 'charge']));
        $this->assertSame(['no_invoice_hint', null, null], [$refund['event']['disposition'], $refund['event']['invoice_ref_hash'], $refund['scheduled']]);
        $other = $this->receive($this->event('evt_SYNTHETIC4', 'customer.updated', null, ['id' => F::CUSTOMER, 'object' => 'customer']));
        $this->assertSame('ignored_type', $other['event']['disposition']);
        $unbound = $this->receive($this->event('evt_SYNTHETIC5', 'invoice.paid', null, ['parent' => ['type' => 'subscription_details',
            'subscription_details' => ['subscription' => 'sub_UNBOUNDSYNTHETIC']]]));
        $this->assertSame(['retrieval_hint', null], [$unbound['event']['disposition'], $unbound['scheduled']]);
        Queue::assertNothingPushed();
        $this->assertNothingObservedOrAwarded();
    }

    /** Codex P2 (intake-3): the parser assumes the pinned object shape, so any other (or no) event API version fails closed before a row. */
    public function test_events_from_any_api_version_other_than_the_pin_are_refused_before_a_row_or_dispatch(): void
    {
        F::binding();
        $accepted = $this->receive($this->event('evt_SYNTHETICV0', 'invoice.paid'));
        $this->assertFalse($accepted['duplicate']);
        $this->assertSame(1, DB::table('production_membership_billing_events')->count());
        $this->assertSame(BillingProviderPin::API_VERSION, BillingValues::decrypt($accepted['event']['payload_ciphertext'])['api_version']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
        $older = json_decode($this->event('evt_SYNTHETICV1', 'invoice.paid'), true, flags: JSON_THROW_ON_ERROR);
        $older['api_version'] = '2025-01-27.acacia';
        unset($older['data']['object']['parent']);
        $older['data']['object']['subscription'] = F::SUBSCRIPTION;
        $missing = json_decode($this->event('evt_SYNTHETICV2', 'invoice.paid'), true, flags: JSON_THROW_ON_ERROR);
        unset($missing['api_version']);
        $invalid = json_decode($this->event('evt_SYNTHETICV3', 'invoice.paid'), true, flags: JSON_THROW_ON_ERROR);
        $invalid['api_version'] = [BillingProviderPin::API_VERSION];
        $near = json_decode($this->event('evt_SYNTHETICV4', 'invoice.paid'), true, flags: JSON_THROW_ON_ERROR);
        $near['api_version'] = BillingProviderPin::API_VERSION.' ';
        foreach ([$older, $missing, $invalid, $near] as $event) {
            try {
                $this->receive(json_encode($event, JSON_THROW_ON_ERROR));
                $this->fail('An event from an unpinned API version must refuse.');
            } catch (BillingException $error) {
                $this->assertSame('api_version', $error->reason);
            }
        }
        $this->assertSame(1, DB::table('production_membership_billing_events')->count());
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
    }

    private function receive(string $payload): array
    {
        return (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, self::SECRET));
    }

    /** A synthetic v1 snapshot event built through the SDK's Event class; data.object is the SDK-built invoice. */
    private function event(string $id, string $type, ?int $created = null, array $object = [], array $event = []): string
    {
        $values = [...['id' => $id, 'object' => 'event', 'api_version' => BillingProviderPin::API_VERSION, 'created' => $created ?? F::PERIOD_START,
            'livemode' => false, 'pending_webhooks' => 1, 'request' => ['id' => null, 'idempotency_key' => null], 'type' => $type,
            'data' => ['object' => [...F::graph()['invoice'], ...$object]]], ...$event];

        return json_encode(F::sdk(Event::class, $values), JSON_THROW_ON_ERROR);
    }

    private function assertNothingObservedOrAwarded(): void
    {
        foreach (['production_membership_billing_observations', 'production_membership_billing_invoices', 'production_membership_paid_periods',
            'production_membership_credit_events'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
