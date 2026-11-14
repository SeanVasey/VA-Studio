<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutObservation;
use App\Domain\Commerce\Models\PaymentObservation;
use App\Domain\Commerce\Models\StripeReceiptWork;
use App\Domain\Commerce\Models\StripeWebhookReceipt;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\PaymentFixtures as F;
use Tests\Support\StripeWebhookFixtures;
use Tests\TestCase;

/**
 * M-07 drill proofs (docs/ops/stripe-test-drills.md) that the existing suite did not already pin.
 * Each drill drives the signed webhook route and the runner's payment stages
 * (receipts → reconcile → finalize) against the synthetic Stripe gateway, never the network.
 * The snapshot each operator step records comes from vasey:test-commerce-evidence.
 * SQLite does not prove MySQL concurrency; the native race lives in TestPaymentConcurrencyTest.
 */
class StripeTestDrillTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        FinalizationFixtures::configure();
        Queue::fake();
        $this->gateway = F::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
    }

    public function test_declined_card_is_pending_with_an_honest_retry_state_and_the_same_session_retry_confirms_once(): void
    {
        $f = $this->open();
        $before = F::unchangedBusinessEvidence();
        // Card 4000 0000 0000 0002 at Stripe's hosted page: the session stays open and unpaid, and the
        // PaymentIntent created for the attempt waits for a new payment method. No checkout.session event is sent.
        $this->gateway->session['payment_intent'] = F::PAYMENT;
        $this->gateway->payment = ['status' => 'requires_payment_method', 'amount_received' => 0,
            'last_payment_error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'generic_decline',
                'message' => 'Your card was declined.', 'payment_method' => ['card' => ['last4' => '0002', 'brand' => 'visa']]],
        ] + F::payment($this->gateway->session);

        $declined = $this->sweep();
        $this->assertSame(['receipts' => [], 'reconcile' => [$f['intent']->public_id.' pending'], 'finalize' => []], $declined);
        $this->assertDatabaseCount('verified_payments', 0);
        $this->assertDatabaseCount('order_finalizations', 0);
        $this->assertDatabaseCount('license_grants', 0);
        $this->assertSame($before, F::unchangedBusinessEvidence(), 'A decline consumes and releases nothing.');
        $pending = PaymentObservation::sole();
        $this->assertSame(['pending', F::PAYMENT, null], [$pending->outcome, $pending->provider_payment_intent_id, $pending->stripe_webhook_receipt_id]);
        // Retained evidence keeps whitelisted payment fields only, never the provider's decline or card details.
        $retained = app(CheckoutEvidence::class)->decrypt($pending->evidence_ciphertext, $pending->evidence_hash, $pending->canonicalization_version);
        $this->assertSame('requires_payment_method', $retained['payment']['status']);
        $this->assertArrayNotHasKey('last_payment_error', $retained['payment']);
        foreach (['generic_decline', 'card_declined', '0002', 'Your card was declined'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($retained, JSON_THROW_ON_ERROR));
        }

        // The buyer sees an open, unverified checkout whose retry link is the same Stripe session.
        $view = app(HostedCheckout::class)->status($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame(['open', 'not_verified', 'not_started', 'https://checkout.stripe.com/c/pay/'.CheckoutFixtures::SESSION],
            [$view['status'], $view['paymentStatus'], $view['fulfillmentStatus'], $view['url']]);
        $snapshot = $this->evidence($f['order']->public_id);
        $this->assertSame([false, ['pending' => 1], 'open', 'pending', 0],
            [$snapshot['payment']['verified'], $snapshot['payment']['observation_outcomes'], $snapshot['checkout']['latest_status'],
                $snapshot['reservation']['state'], $snapshot['grants']['count']]);

        // Card 4242 4242 4242 4242 in the same session: the same PaymentIntent succeeds and the next sweep confirms once.
        $this->pay();
        $retry = $this->sweep();
        $this->assertSame([$f['intent']->public_id.' awaiting_finalization'], $retry['reconcile']);
        $this->assertSame([$f['order']->public_id.' paid'], $retry['finalize']);
        $this->assertSame($pending->getAttributes(), PaymentObservation::findOrFail($pending->id)->getAttributes());
        $this->assertSame(F::PAYMENT, VerifiedPayment::sole()->provider_payment_intent_id);

        // Stripe's completed event for the retried payment is processed after reconciliation: no second payment or grant.
        $this->deliver('checkout.session.completed', 'evt_DrillDeclineRetry')->assertOk();
        $late = $this->sweep();
        $this->assertSame([StripeWebhookReceipt::sole()->id.' awaiting_finalization'], $late['receipts']);
        $this->assertSame([[], []], [$late['reconcile'], $late['finalize']]);
        $this->assertDatabaseCount('verified_payments', 1);
        $this->assertDatabaseCount('order_finalizations', 1);
        $this->assertDatabaseCount('license_grants', 1);
    }

    public function test_failed_async_payment_event_is_never_success_and_the_order_stays_unpaid(): void
    {
        $f = $this->open();
        $before = F::unchangedBusinessEvidence();
        // Card-only checkout should never emit async events; the subscribed failure event still cannot confirm.
        $this->gateway->session = ['status' => 'complete', 'url' => null, 'payment_intent' => F::PAYMENT] + $this->gateway->session;
        $this->gateway->payment = ['status' => 'requires_payment_method', 'amount_received' => 0] + F::payment($this->gateway->session);

        $this->deliver('checkout.session.async_payment_failed', 'evt_DrillAsyncFailed')->assertOk()->assertExactJson(['received' => true]);
        $lines = $this->sweep();
        $receipt = StripeWebhookReceipt::sole();
        $this->assertSame([$receipt->id.' pending'], $lines['receipts']);
        $this->assertSame([$f['intent']->public_id.' pending'], $lines['reconcile']);
        $this->assertSame([], $lines['finalize']);
        // Unknown is not failure: durable work retries under its bounded policy instead of inferring an outcome.
        $this->assertSame(['retry', 'pending'], [StripeReceiptWork::sole()->state, StripeReceiptWork::sole()->outcome]);
        $this->assertDatabaseCount('verified_payments', 0);
        $this->assertDatabaseCount('license_grants', 0);
        $this->assertSame($before, F::unchangedBusinessEvidence());
        $view = app(HostedCheckout::class)->status($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame(['not_verified', 'not_started', null], [$view['paymentStatus'], $view['finalizationStatus'], $view['url']]);
    }

    public function test_expired_session_keeps_its_reservation_and_a_later_completed_event_for_it_stays_expired(): void
    {
        $f = $this->open();
        $before = F::unchangedBusinessEvidence();
        $this->expire();
        $this->deliver('checkout.session.expired', 'evt_DrillExpired')->assertOk();
        $expired = StripeWebhookReceipt::sole();
        $this->assertSame([$expired->id.' expired'], $this->sweep()['receipts']);

        // A later completed event for the same session whose snapshot claims payment: the event is a locator only,
        // and the authoritative session is still expired.
        $claim = F::event(['status' => 'complete', 'payment_status' => 'paid', 'url' => null,
            'payment_intent' => 'pi_DrillSnapshotClaim'] + $this->gateway->session, 'evt_DrillLateCompleted');
        $this->deliverEvent($claim)->assertOk();
        $late = StripeWebhookReceipt::where('event_id', 'evt_DrillLateCompleted')->sole();
        $lines = $this->sweep();
        $this->assertSame([$late->id.' expired'], $lines['receipts']);
        $this->assertSame([$f['intent']->public_id.' expired'], $lines['reconcile']);
        $this->assertSame([], $lines['finalize']);
        $this->assertSame([], array_values(array_filter($this->gateway->calls, fn ($call) => ($call['id'] ?? null) === 'pi_DrillSnapshotClaim')));
        $this->assertSame(['expired'], PaymentObservation::distinct()->pluck('outcome')->all());
        $this->assertDatabaseCount('verified_payments', 0);
        $this->assertDatabaseCount('license_grants', 0);
        // The staging profile has no unpaid release: the reservation stays pending as retained evidence.
        $this->assertSame($before, F::unchangedBusinessEvidence());

        $view = app(HostedCheckout::class)->status($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame(['expired', 'not_verified', null], [$view['status'], $view['paymentStatus'], $view['url']]);
        $snapshot = $this->evidence('evt_DrillLateCompleted');
        $this->assertSame($f['order']->public_id, $snapshot['order']['id']);
        $this->assertSame([['evt_DrillExpired', 'checkout.session.expired', 'processed', 'expired'],
            ['evt_DrillLateCompleted', 'checkout.session.completed', 'processed', 'expired']],
            array_map(fn ($row) => [$row['event_id'], $row['event_type'], $row['work_state'], $row['work_outcome']], $snapshot['receipts']));
        $this->assertSame(['pending', false, 'expired', false, null], [$snapshot['reservation']['state'], $snapshot['reservation']['consumed'],
            $snapshot['checkout']['latest_status'], $snapshot['payment']['verified'], $snapshot['unpaid_release']]);
    }

    public function test_a_declined_attempt_left_to_expire_is_a_terminal_unpaid_outcome_without_a_grant(): void
    {
        $f = $this->open();
        $before = F::unchangedBusinessEvidence();
        // Drill 1 abandoned after the decline, then drill 2: the session expires and its PaymentIntent is canceled.
        $this->gateway->session['payment_intent'] = F::PAYMENT;
        $this->gateway->payment = ['status' => 'requires_payment_method', 'amount_received' => 0] + F::payment($this->gateway->session);
        $this->assertSame([$f['intent']->public_id.' pending'], $this->sweep()['reconcile']);
        $this->expire();
        $this->gateway->payment['status'] = 'canceled';

        $this->deliver('checkout.session.expired', 'evt_DrillDeclinedThenExpired')->assertOk();
        $lines = $this->sweep();
        $receipt = StripeWebhookReceipt::sole();
        // The canceled PaymentIntent outranks the session's expiry in the outcome name; both are terminal and unpaid.
        $this->assertSame([$receipt->id.' canceled'], $lines['receipts']);
        $this->assertSame(['processed', 'canceled'], [StripeReceiptWork::sole()->state, StripeReceiptWork::sole()->outcome]);
        $this->assertSame([], $lines['finalize']);
        $this->assertDatabaseCount('verified_payments', 0);
        $this->assertDatabaseCount('license_grants', 0);
        $this->assertSame($before, F::unchangedBusinessEvidence());
        $view = app(HostedCheckout::class)->status($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame(['expired', 'not_verified', null], [$view['status'], $view['paymentStatus'], $view['url']]);
    }

    public function test_provider_payment_reported_after_a_recorded_expiry_is_quarantined_without_a_grant(): void
    {
        $f = $this->open();
        $before = F::unchangedBusinessEvidence();
        $this->expire();
        $this->deliver('checkout.session.expired', 'evt_DrillExpiredFirst')->assertOk();
        $this->sweep();
        $terminal = CheckoutObservation::orderByDesc('id')->first()->getAttributes();

        // Stripe cannot complete an expired session. A contradicting provider read is evidence of a changed
        // object, so it is quarantined for investigation instead of confirming payment or regressing the session.
        $this->pay();
        $this->deliver('checkout.session.completed', 'evt_DrillContradiction')->assertOk();
        $receipt = StripeWebhookReceipt::where('event_id', 'evt_DrillContradiction')->sole();
        $lines = $this->sweep();
        $this->assertSame([$receipt->id.' payment_changed'], $lines['receipts']);
        $this->assertSame([$f['intent']->public_id.' payment_changed'], $lines['reconcile']);
        $work = StripeReceiptWork::where('stripe_webhook_receipt_id', $receipt->id)->sole();
        $this->assertSame(['quarantined', 'payment_changed'], [$work->state, $work->outcome]);
        $this->assertSame($terminal, CheckoutObservation::orderByDesc('id')->first()->getAttributes());
        $this->assertSame(0, PaymentObservation::where('outcome', 'confirmed')->count());
        $this->assertDatabaseCount('verified_payments', 0);
        $this->assertDatabaseCount('license_grants', 0);
        $this->assertSame($before, F::unchangedBusinessEvidence());
    }

    public function test_dashboard_resend_after_the_order_completed_is_one_receipt_and_explicit_replay_changes_no_grant(): void
    {
        $f = $this->open();
        $this->pay();
        $event = F::event($this->gateway->session, 'evt_DrillResent');
        $this->deliverEvent($event)->assertOk();
        $first = $this->sweep();
        $receipt = StripeWebhookReceipt::sole();
        $this->assertSame([$receipt->id.' awaiting_finalization'], $first['receipts']);
        $this->assertSame([$f['order']->public_id.' paid'], $first['finalize']);
        $original = $receipt->getAttributes();
        $retained = FinalizationFixtures::retained();
        $snapshot = $this->evidence('evt_DrillResent');

        // Dashboard "Resend" (or `stripe events resend`) minutes later: the same event, a fresh signature
        // timestamp and Stripe's changed delivery counter. One receipt; the runner finds no new work.
        $this->travel(7)->minutes();
        $event['pending_webhooks'] = 0;
        $this->deliverEvent($event)->assertOk()->assertExactJson(['received' => true]);
        $this->assertSame($original, StripeWebhookReceipt::sole()->getAttributes());
        $this->assertSame(['receipts' => [], 'reconcile' => [], 'finalize' => []], $this->sweep());

        // An operator's explicit, audited replay re-verifies the same receipt and converges on the same payment.
        $this->assertSame(0, Artisan::call('vasey:process-stripe-receipts', ['receipt' => (string) $receipt->id, '--replay' => true]));
        $this->assertSame($receipt->id.' awaiting_finalization', trim(Artisan::output()));
        $this->assertSame(1, AuditEvent::where('action', 'commerce.payment.receipt_replayed')->count());
        $this->assertSame($retained, FinalizationFixtures::retained(), 'No second payment, finalization, grant or entitlement.');
        $this->assertSame([$receipt->id, $receipt->id], PaymentObservation::orderBy('id')->pluck('stripe_webhook_receipt_id')->all());

        $after = $this->evidence('evt_DrillResent');
        $this->assertSame($snapshot['totals'], $after['totals']);
        $this->assertSame(['receipts' => 1, 'verified_payments' => 1, 'license_grants' => 1], $after['totals']);
        $this->assertSame(['webhook_receipt', 'evt_DrillResent', 1], [$after['payment']['source'],
            $after['payment']['confirming_event_id'], $after['grants']['count']]);
        $this->assertSame([['evt_DrillResent', 'processed', 'awaiting_finalization', 1]],
            array_map(fn ($row) => [$row['event_id'], $row['work_state'], $row['work_outcome'], $row['attempts']], $after['receipts']));
    }

    public function test_reconcile_without_webhook_keeps_reconciliation_evidence_when_the_webhook_arrives_later(): void
    {
        $f = $this->open();
        $this->pay();
        // The Dashboard endpoint is disabled: nothing is delivered, and the reconcile stage finds the paid session.
        $lines = $this->sweep();
        $this->assertSame(['receipts' => [], 'reconcile' => [$f['intent']->public_id.' awaiting_finalization'],
            'finalize' => [$f['order']->public_id.' paid']], $lines);
        $this->assertDatabaseCount('stripe_webhook_receipts', 0);
        $payment = VerifiedPayment::sole();
        $evidence = app(CheckoutEvidence::class)->decrypt($payment->evidence_ciphertext, $payment->evidence_hash, $payment->canonicalization_version);
        $this->assertSame(['reconciliation', null, 'confirmed'], [$evidence['source'], $evidence['receipt_id'], $evidence['outcome']]);
        $this->assertNull(PaymentObservation::sole()->stripe_webhook_receipt_id);
        $this->assertSame(1, AuditEvent::where('action', 'commerce.payment.test_verified')->count());
        $confirmation = $payment->getAttributes();
        $retained = FinalizationFixtures::retained();
        $snapshot = $this->evidence($f['intent']->public_id);
        $this->assertSame(['reconciliation', null, []], [$snapshot['payment']['source'], $snapshot['payment']['confirming_event_id'], $snapshot['receipts']]);

        // The endpoint is re-enabled and the event created while it was disabled is resent: processed as a duplicate.
        $this->deliver('checkout.session.completed', 'evt_DrillAfterReconcile')->assertOk();
        $receipt = StripeWebhookReceipt::sole();
        $this->assertSame(['receipts' => [$receipt->id.' awaiting_finalization'], 'reconcile' => [], 'finalize' => []], $this->sweep());
        $this->assertSame($confirmation, VerifiedPayment::sole()->getAttributes());
        $this->assertSame($retained, FinalizationFixtures::retained());
        $this->assertSame(1, AuditEvent::where('action', 'commerce.payment.test_verified')->count());
        $this->assertSame([null, $receipt->id], PaymentObservation::orderBy('id')->pluck('stripe_webhook_receipt_id')->all());

        $after = $this->evidence(CheckoutFixtures::SESSION);
        $this->assertSame(['reconciliation', null, 1, 1], [$after['payment']['source'], $after['payment']['confirming_event_id'],
            $after['grants']['count'], $after['totals']['verified_payments']]);
        $this->assertSame([['source' => 'reconciliation', 'event_id' => null], ['source' => 'receipt', 'event_id' => 'evt_DrillAfterReconcile']],
            array_map(fn ($row) => ['source' => $row['source'], 'event_id' => $row['event_id']], $after['payment']['observations']));
    }

    /** Prepared order plus an open hosted session, exactly as "Continue to Stripe test checkout" leaves it. */
    private function open(): array
    {
        $f = CheckoutFixtures::prepared();
        app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame('open', $this->gateway->session['status']);

        return $f + ['intent' => CheckoutIntent::where('order_id', $f['order']->id)->sole()];
    }

    /** What Stripe's hosted page records after card 4242 4242 4242 4242. */
    private function pay(): void
    {
        $this->gateway->session = ['status' => 'complete', 'payment_status' => 'paid', 'url' => null, 'payment_intent' => F::PAYMENT] + $this->gateway->session;
        $this->gateway->payment = F::payment($this->gateway->session);
    }

    /** `stripe post /v1/checkout/sessions/<id>/expire`, or the session's own expiry. */
    private function expire(): void
    {
        $this->gateway->session = ['status' => 'expired', 'url' => null] + $this->gateway->session;
    }

    private function deliver(string $type, string $id): TestResponse
    {
        return $this->deliverEvent(F::event($this->gateway->session, $id, $type));
    }

    /** Each delivery, including a resend, is signed again with the current timestamp. */
    private function deliverEvent(array $event): TestResponse
    {
        $body = StripeWebhookFixtures::body($event);

        return $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => StripeWebhookFixtures::signature($body)], $body);
    }

    /** The payment stages of scripts/ops/run-test-commerce-pipeline.sh, in order; returns each stage's item lines. */
    private function sweep(): array
    {
        $lines = [];
        foreach (['receipts' => 'vasey:process-stripe-receipts', 'reconcile' => 'vasey:reconcile-test-payments',
            'finalize' => 'vasey:finalize-test-payments'] as $stage => $command) {
            $this->assertSame(0, Artisan::call($command, ['--limit' => '25']), $stage);
            $lines[$stage] = array_values(array_filter(explode("\n", trim(Artisan::output())),
                fn (string $line): bool => $line !== '' && ! str_starts_with($line, 'NEXT_AFTER=')));
        }

        return $lines;
    }

    private function evidence(string $reference): array
    {
        $this->assertSame(0, Artisan::call('vasey:test-commerce-evidence', ['reference' => $reference, '--json' => true]));

        return json_decode(Artisan::output(), true, 16, JSON_THROW_ON_ERROR);
    }
}
