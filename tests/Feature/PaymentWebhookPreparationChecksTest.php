<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\PaymentObservation;
use App\Domain\Commerce\Models\StripeReceiptWork;
use App\Domain\Commerce\Models\StripeWebhookReceipt;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Payments\ProcessStripeReceipt;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Jobs\ProcessStripeReceiptJob;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaymentFixtures as F;
use Tests\Support\StripeWebhookFixtures;
use Tests\TestCase;

/**
 * Live-payment preparation checklist (production-preparation-1): the receiver-to-verification
 * chain on synthetic events and a synthetic authoritative provider. Signature validity records
 * evidence only; payment state comes from authoritative retrieval. No network, no real keys.
 */
class PaymentWebhookPreparationChecksTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        Queue::fake();
        $this->gateway = F::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
    }

    private function deliver(string $body, ?string $signature = null): TestResponse
    {
        return $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature ?? StripeWebhookFixtures::signature($body)], $body);
    }

    private function process(StripeWebhookReceipt $receipt): string
    {
        return app(ProcessStripeReceipt::class)->handle($receipt->id);
    }

    private function receiptFor(string $eventId): StripeWebhookReceipt
    {
        return StripeWebhookReceipt::where('event_id', $eventId)->sole();
    }

    public function test_valid_signature_records_evidence_only_and_payment_requires_authoritative_retrieval(): void
    {
        F::started($this->gateway);
        $calls = $this->gateway->calls;
        $body = StripeWebhookFixtures::body(F::event($this->gateway->session));
        $this->deliver($body)->assertOk()->assertExactJson(['received' => true]);

        $receipt = StripeWebhookReceipt::sole();
        $this->assertSame($body, Crypt::decryptString($receipt->payload_ciphertext));
        $this->assertSame(hash('sha256', $body), $receipt->payload_sha256);
        // A verified signature alone is not proof of payment: nothing is granted or retrieved yet.
        $this->assertDatabaseCount('verified_payments', 0);
        $this->assertDatabaseCount('payment_observations', 0);
        $this->assertSame($calls, $this->gateway->calls);

        $this->assertSame('awaiting_finalization', $this->process($receipt));
        $this->assertDatabaseCount('verified_payments', 1);
        $operations = array_column(array_slice($this->gateway->calls, count($calls)), 'operation');
        $this->assertContains('retrieve', $operations);
        $this->assertContains('payment_intent', $operations);
    }

    public static function invalidSignatures(): array
    {
        return array_combine($cases = ['wrong_secret', 'body_tampered_after_signing', 'stale_timestamp', 'future_timestamp',
            'missing_header', 'no_v1_scheme', 'two_timestamps'], array_map(fn (string $case): array => [$case], $cases));
    }

    #[DataProvider('invalidSignatures')]
    public function test_invalid_signature_creates_no_receipt_work_or_provider_io_even_with_processing_enabled(string $case): void
    {
        F::started($this->gateway);
        $calls = $this->gateway->calls;
        $body = StripeWebhookFixtures::body(F::event($this->gateway->session));
        $signature = match ($case) {
            'wrong_secret' => StripeWebhookFixtures::signature($body, secret: 'whsec_SyntheticOtherEndpoint2026'),
            'stale_timestamp' => StripeWebhookFixtures::signature($body, time() - 301),
            'future_timestamp' => StripeWebhookFixtures::signature($body, time() + 301),
            'missing_header' => '',
            'no_v1_scheme' => str_replace(',v1=', ',v0=', StripeWebhookFixtures::signature($body)),
            'two_timestamps' => StripeWebhookFixtures::signature($body).',t='.time(),
            default => StripeWebhookFixtures::signature($body),
        };
        if ($case === 'body_tampered_after_signing') {
            $body = str_replace('"livemode": false', '"livemode":  false', $body);
        }
        $this->deliver($body, $signature)->assertBadRequest()->assertExactJson(['code' => 'STRIPE_WEBHOOK_INVALID']);
        $this->assertDatabaseCount('stripe_webhook_receipts', 0);
        $this->assertDatabaseCount('stripe_receipt_work', 0);
        $this->assertDatabaseCount('verified_payments', 0);
        $this->assertSame($calls, $this->gateway->calls);
        Queue::assertNotPushed(ProcessStripeReceiptJob::class);
    }

    public function test_replayed_event_id_is_one_receipt_one_work_row_and_one_confirmation(): void
    {
        F::started($this->gateway);
        $event = F::event($this->gateway->session);
        $body = StripeWebhookFixtures::body($event);
        $this->deliver($body)->assertOk();
        $receipt = StripeWebhookReceipt::sole();
        $original = $receipt->getAttributes();
        $this->assertSame('awaiting_finalization', $this->process($receipt));
        $confirmation = VerifiedPayment::sole()->getAttributes();
        $calls = $this->gateway->calls;

        // Same bytes later (a fresh delivery signature), then a reformatted retry with a new delivery counter.
        $this->deliver($body, StripeWebhookFixtures::signature($body, time() + 1))->assertOk();
        $event['pending_webhooks'] = 0;
        $this->deliver(json_encode(array_reverse($event, true), JSON_THROW_ON_ERROR))->assertOk();
        $this->assertSame('awaiting_finalization', $this->process($receipt));

        $this->assertSame($original, StripeWebhookReceipt::sole()->getAttributes());
        $this->assertDatabaseCount('stripe_receipt_work', 1);
        $this->assertDatabaseCount('payment_observations', 1);
        $this->assertSame($confirmation, VerifiedPayment::sole()->getAttributes());
        $this->assertSame($calls, $this->gateway->calls);

        // Same event id with different content is a conflict, never a rewrite.
        $event['data']['object']['amount_total']++;
        $this->deliver(StripeWebhookFixtures::body($event))->assertConflict()->assertExactJson(['code' => 'STRIPE_EVENT_CONFLICT']);
        $this->assertSame($original, StripeWebhookReceipt::sole()->getAttributes());
        $this->assertSame($confirmation, VerifiedPayment::sole()->getAttributes());
    }

    public function test_out_of_order_events_converge_on_authoritative_state_without_revoking_or_duplicating(): void
    {
        F::started($this->gateway);
        $session = $this->gateway->session;
        $later = F::event($session, 'evt_SYNTHETICLATER', 'checkout.session.async_payment_succeeded');
        $earlier = F::event($session, 'evt_SYNTHETICEARLIER');
        $earlier['created'] = $later['created'] - 60;
        $stale = F::event([...$session, 'status' => 'expired', 'payment_status' => 'unpaid', 'payment_intent' => null],
            'evt_SYNTHETICSTALEEXPIRED', 'checkout.session.expired');
        $stale['created'] = $earlier['created'] - 60;

        $this->deliver(StripeWebhookFixtures::body($later))->assertOk();
        $this->assertSame('awaiting_finalization', $this->process($this->receiptFor('evt_SYNTHETICLATER')));
        $confirmation = VerifiedPayment::sole()->getAttributes();

        $this->deliver(StripeWebhookFixtures::body($earlier))->assertOk();
        $this->deliver(StripeWebhookFixtures::body($stale))->assertOk();
        $this->assertSame('awaiting_finalization', $this->process($this->receiptFor('evt_SYNTHETICEARLIER')));
        // The stale snapshot claims expiry; the authoritative provider says paid. The snapshot is a locator only.
        $this->assertSame('awaiting_finalization', $this->process($this->receiptFor('evt_SYNTHETICSTALEEXPIRED')));

        $this->assertDatabaseCount('stripe_webhook_receipts', 3);
        $this->assertDatabaseCount('stripe_receipt_work', 3);
        $this->assertDatabaseCount('verified_payments', 1);
        $this->assertSame($confirmation, VerifiedPayment::sole()->getAttributes());
        $this->assertSame([], PaymentObservation::where('outcome', 'expired')->pluck('id')->all());
    }

    public function test_unknown_provider_outcome_stays_retryable_and_later_converges_once(): void
    {
        F::started($this->gateway);
        $this->deliver(StripeWebhookFixtures::body(F::event($this->gateway->session)))->assertOk();
        $receipt = StripeWebhookReceipt::sole();
        $original = $receipt->getAttributes();

        $this->gateway->onPayment = fn () => throw new RuntimeException('synthetic-timeout private-marker');
        $this->assertSame('retry', $this->process($receipt));
        $work = StripeReceiptWork::sole();
        $this->assertSame(['retry', 1], [$work->state, $work->attempts]);
        $this->assertNotNull($work->next_attempt_at);
        // Unknown is neither failure nor success: no observation, no confirmation, retained evidence.
        $this->assertDatabaseCount('payment_observations', 0);
        $this->assertDatabaseCount('verified_payments', 0);
        $this->assertSame($original, $receipt->fresh()->getAttributes());
        $this->assertStringNotContainsString('private-marker', json_encode($work->fresh()->toArray(), JSON_THROW_ON_ERROR));

        $calls = $this->gateway->calls;
        $this->assertSame('retry', $this->process($receipt));
        $this->assertSame($calls, $this->gateway->calls);

        $this->travelTo($work->next_attempt_at);
        $this->gateway->onPayment = null;
        $this->gateway->session['payment_status'] = 'unpaid';
        $this->gateway->payment['status'] = 'processing';
        $this->gateway->payment['amount_received'] = 0;
        $this->assertSame('pending', $this->process($receipt));
        $pending = PaymentObservation::sole()->getAttributes();
        $this->assertSame('pending', $pending['outcome']);
        $this->assertDatabaseCount('verified_payments', 0);

        $this->travelTo(StripeReceiptWork::sole()->next_attempt_at);
        $this->gateway->session['payment_status'] = 'paid';
        $this->gateway->payment['status'] = 'succeeded';
        $this->gateway->payment['amount_received'] = $this->gateway->payment['amount'];
        $this->assertSame('awaiting_finalization', $this->process($receipt));
        $this->assertSame($pending, PaymentObservation::findOrFail($pending['id'])->getAttributes());
        $this->assertDatabaseCount('payment_observations', 2);
        $this->assertDatabaseCount('verified_payments', 1);
        $this->assertDatabaseCount('stripe_receipt_work', 1);
    }

    public function test_unknown_event_type_is_retained_unsupported_without_provider_io(): void
    {
        $event = StripeWebhookFixtures::event();
        $event['id'] = 'evt_SYNTHETICFUTURE';
        $event['type'] = 'checkout.session.future_state';
        $body = StripeWebhookFixtures::body($event);
        $this->deliver($body)->assertOk();
        $receipt = StripeWebhookReceipt::sole();
        $this->assertSame('unsupported', $this->process($receipt));
        $this->assertSame('unsupported', StripeReceiptWork::sole()->state);
        $this->assertSame($body, Crypt::decryptString($receipt->fresh()->payload_ciphertext));
        $this->assertSame([], $this->gateway->calls);
        $this->assertDatabaseCount('verified_payments', 0);
    }

    public function test_live_mode_events_and_production_environment_are_refused_by_the_current_receiver(): void
    {
        $event = StripeWebhookFixtures::event();
        $event['livemode'] = true;
        $this->deliver(StripeWebhookFixtures::body($event))->assertBadRequest();
        $this->app->instance('env', 'production');
        try {
            $this->deliver(StripeWebhookFixtures::body())->assertServiceUnavailable()->assertExactJson(['code' => 'STRIPE_WEBHOOK_UNAVAILABLE']);
        } finally {
            // The migration trait's teardown must not run under a production environment.
            $this->app->instance('env', 'testing');
        }
        $this->assertDatabaseCount('stripe_webhook_receipts', 0);
    }
}
