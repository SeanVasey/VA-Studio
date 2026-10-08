<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\Models\CheckoutObservation;
use App\Domain\Commerce\Models\PaymentObservation;
use App\Domain\Commerce\Models\StripeReceiptWork;
use App\Domain\Commerce\Models\StripeWebhookReceipt;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\PaymentWork;
use App\Domain\Commerce\Payments\PaymentEvidence;
use App\Domain\Commerce\Payments\PaymentVerificationException;
use App\Domain\Commerce\Payments\DispatchStripeReceipt;
use App\Domain\Commerce\Payments\ProcessStripeReceipt;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripeEventFingerprint;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use App\Domain\Commerce\QuoteException;
use App\Jobs\ProcessStripeReceiptJob;
use Tests\Support\FinalizationDatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CheckoutFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures as F;
use Tests\Support\StripeWebhookFixtures;
use Tests\TestCase;

/** Actual domain/inbox calls with synthetic authoritative responses; no Stripe network traffic. */
class TestPaymentProcessingTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond()); F::configure(); Queue::fake();
        $this->gateway = F::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
    }

    public function test_authoritative_session_and_payment_are_verified_once_without_finalizing_order_resources(): void
    {
        $f = F::started($this->gateway, true, true); $before = F::unchangedBusinessEvidence();
        $receipt = F::receipt($this->gateway->session); $receiptBefore = $receipt->refresh()->getAttributes();
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertDatabaseCount('verified_payments', 1); $this->assertDatabaseCount('payment_observations', 1);
        $verified = VerifiedPayment::sole(); $work = StripeReceiptWork::sole();
        $this->assertSame($f['order']->id, $verified->order_id);
        $this->assertSame($f['intent']->id, $verified->checkout_intent_id);
        $this->assertSame($f['intent']->order_attempt_id, $verified->order_attempt_id);
        $this->assertSame(CheckoutSession::sole()->id, $verified->checkout_session_id);
        $this->assertSame(F::PAYMENT, $verified->provider_payment_intent_id);
        $this->assertSame($this->gateway->session['amount_total'], $verified->amount_minor);
        $this->assertSame('USD', $verified->currency); $this->assertSame('test', $verified->mode);
        $this->assertSame('processed', $work->state); $this->assertSame('awaiting_finalization', $work->outcome);
        $this->assertSame(1, $work->attempts); $this->assertNull($work->claim_token); $this->assertNull($work->lease_expires_at);
        $this->assertSame('confirmed', PaymentObservation::sole()->outcome);
        $this->assertSame($receipt->id, PaymentObservation::sole()->stripe_webhook_receipt_id);
        $this->assertSame($before, F::unchangedBusinessEvidence()); $this->assertSame($receiptBefore, $receipt->fresh()->getAttributes());
        $this->assertSame('prepared', app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER)['status']);
        foreach ($this->gateway->calls as $call) { $this->assertSame(0, $call['transaction_level']); }
        $calls = $this->gateway->calls; $confirmation = $verified->getAttributes();
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame($calls, $this->gateway->calls); $this->assertSame($confirmation, $verified->fresh()->getAttributes());
        $this->assertDatabaseCount('payment_observations', 1); $this->assertSame($before, F::unchangedBusinessEvidence());
    }

    public function test_distinct_receipts_and_stale_provider_observations_preserve_one_successful_confirmation(): void
    {
        F::started($this->gateway); $before = F::unchangedBusinessEvidence();
        $first = F::receipt($this->gateway->session);
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($first->id));
        $confirmation = VerifiedPayment::sole()->getAttributes();
        $second = F::receipt($this->gateway->session, 'evt_SecondPayment', 'checkout.session.async_payment_succeeded');
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($second->id));
        $this->gateway->payment['status'] = 'processing'; $this->gateway->payment['amount_received'] = 0;
        $this->gateway->session['payment_status'] = 'unpaid';
        $third = F::receipt($this->gateway->session, 'evt_StalePayment');
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($third->id));
        $this->assertDatabaseCount('verified_payments', 1);
        $this->assertSame($confirmation, VerifiedPayment::sole()->getAttributes());
        $this->assertDatabaseCount('stripe_webhook_receipts', 3); $this->assertDatabaseCount('stripe_receipt_work', 3);
        $this->assertSame($before, F::unchangedBusinessEvidence());
    }

    public function test_old_signed_receipt_is_decoded_from_its_retained_bytes_without_revalidating_delivery_age(): void
    {
        F::started($this->gateway); $receipt = F::receipt($this->gateway->session);
        $this->travelTo(now()->addDays(3));
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertDatabaseCount('verified_payments', 1);
    }

    public static function invalidProviderEvidence(): array
    {
        return array_map(fn ($name) => [$name], ['account', 'session_id', 'session_live', 'session_currency', 'session_total',
            'session_tax', 'session_shipping', 'session_discount', 'session_metadata', 'session_reference', 'session_lines',
            'payment_id', 'payment_live', 'payment_currency', 'payment_amount', 'payment_partial', 'payment_capturable',
            'payment_metadata', 'payment_method', 'payment_account', 'payment_context', 'future_usage', 'money_string']);
    }

    #[DataProvider('invalidProviderEvidence')]
    public function test_invalid_authoritative_evidence_is_quarantined_and_grants_nothing(string $scenario): void
    {
        F::started($this->gateway, false, true); $receipt = F::receipt($this->gateway->session);
        $before = F::unchangedBusinessEvidence();
        match ($scenario) {
            'account' => $this->gateway->accountResponse['id'] = 'acct_DIFFERENT',
            'session_id' => $this->gateway->session['id'] = 'cs_test_DIFFERENT',
            'session_live' => $this->gateway->session['livemode'] = true,
            'session_currency' => $this->gateway->session['currency'] = 'eur',
            'session_total' => $this->gateway->session['amount_total']++,
            'session_tax' => $this->gateway->session['total_details']['amount_tax'] = 1,
            'session_shipping' => $this->gateway->session['total_details']['amount_shipping'] = 1,
            'session_discount' => $this->gateway->session['total_details']['amount_discount'] = 1,
            'session_metadata' => $this->gateway->session['metadata']['attempt_id'] = (string) Str::uuid(),
            'session_reference' => $this->gateway->session['client_reference_id'] = (string) Str::uuid(),
            'session_lines' => $this->gateway->session['line_items']['data'][0]['amount_total']++,
            'payment_id' => $this->gateway->payment['id'] = 'pi_DIFFERENT',
            'payment_live' => $this->gateway->payment['livemode'] = true,
            'payment_currency' => $this->gateway->payment['currency'] = 'eur',
            'payment_amount' => $this->gateway->payment['amount']++,
            'payment_partial' => $this->gateway->payment['amount_received']--,
            'payment_capturable' => $this->gateway->payment['amount_capturable'] = 1,
            'payment_metadata' => $this->gateway->payment['metadata']['order_id'] = (string) Str::uuid(),
            'payment_method' => $this->gateway->payment['payment_method_types'] = ['card', 'link'],
            'payment_account' => $this->gateway->payment['on_behalf_of'] = 'acct_DIFFERENT',
            'payment_context' => $this->gateway->payment['transfer_data'] = ['destination' => 'acct_DIFFERENT'],
            'future_usage' => $this->gateway->payment['setup_future_usage'] = 'off_session',
            'money_string' => $this->gateway->payment['amount'] = (string) $this->gateway->payment['amount'],
        };
        $this->assertSame('payment_changed', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame('quarantined', StripeReceiptWork::sole()->state);
        $this->assertSame('payment_changed', StripeReceiptWork::sole()->outcome);
        $this->assertDatabaseCount('verified_payments', 0); $this->assertDatabaseCount('payment_observations', 0);
        $this->assertSame($before, F::unchangedBusinessEvidence());
    }

    public static function nonSuccessfulPayments(): array
    {
        return [['processing', 'pending'], ['requires_action', 'pending'], ['requires_payment_method', 'pending'],
            ['requires_capture', 'authorized'], ['canceled', 'canceled']];
    }

    #[DataProvider('nonSuccessfulPayments')]
    public function test_payment_authorization_or_pending_status_is_not_a_success(string $status, string $outcome): void
    {
        F::started($this->gateway); $this->gateway->payment['status'] = $status;
        $this->gateway->session['payment_status'] = 'unpaid';
        $this->gateway->payment['amount_received'] = 0;
        $this->gateway->payment['amount_capturable'] = $status === 'requires_capture' ? $this->gateway->payment['amount'] : 0;
        if ($status === 'requires_capture') { $this->gateway->payment['capture_method'] = 'manual'; }
        $before = F::unchangedBusinessEvidence(); $receipt = F::receipt($this->gateway->session);
        $this->assertSame($outcome, app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame($outcome, PaymentObservation::sole()->outcome);
        $this->assertSame($outcome === 'canceled' ? 'processed' : 'retry', StripeReceiptWork::sole()->state);
        $this->assertDatabaseCount('verified_payments', 0); $this->assertSame($before, F::unchangedBusinessEvidence());
    }

    public function test_pending_receipt_can_later_confirm_without_overwriting_its_earlier_observation(): void
    {
        F::started($this->gateway); $this->gateway->session['payment_status'] = 'unpaid';
        $this->gateway->payment['status'] = 'processing'; $this->gateway->payment['amount_received'] = 0;
        $receipt = F::receipt($this->gateway->session);
        $this->assertSame('pending', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $pending = PaymentObservation::sole()->getAttributes(); $this->assertDatabaseCount('verified_payments', 0);
        $this->travelTo(StripeReceiptWork::sole()->next_attempt_at);
        $this->gateway->session['payment_status'] = 'paid'; $this->gateway->payment['status'] = 'succeeded';
        $this->gateway->payment['amount_received'] = $this->gateway->payment['amount'];
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame($pending, PaymentObservation::findOrFail($pending['id'])->getAttributes());
        $this->assertDatabaseCount('payment_observations', 2); $this->assertDatabaseCount('verified_payments', 1);
    }

    public function test_paid_session_conflicting_with_pending_payment_retries_without_claiming_confirmation(): void
    {
        F::started($this->gateway); $this->gateway->payment['status'] = 'processing'; $this->gateway->payment['amount_received'] = 0;
        $receipt = F::receipt($this->gateway->session);
        $this->assertSame('retry', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame('retry', StripeReceiptWork::sole()->state);
        $this->assertDatabaseCount('payment_observations', 0); $this->assertDatabaseCount('verified_payments', 0);
    }

    public function test_a_new_provider_payment_cannot_replace_an_existing_confirmation_for_the_order(): void
    {
        F::started($this->gateway); $first = F::receipt($this->gateway->session);
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($first->id));
        $original = VerifiedPayment::sole()->getAttributes();
        $this->gateway->session['payment_intent'] = 'pi_DIFFERENT'; $this->gateway->payment['id'] = 'pi_DIFFERENT';
        $second = F::receipt($this->gateway->session, 'evt_DIFFERENTPAYMENT');
        $this->assertSame('payment_changed', app(ProcessStripeReceipt::class)->handle($second->id));
        $this->assertSame('quarantined', StripeReceiptWork::where('stripe_webhook_receipt_id', $second->id)->sole()->state);
        $this->assertSame($original, VerifiedPayment::sole()->getAttributes()); $this->assertDatabaseCount('verified_payments', 1);
    }

    public function test_a_payment_already_confirmed_for_another_order_cannot_be_reused(): void
    {
        $serial = 0;
        $this->gateway->onCreate = function (array $params) use (&$serial): array {
            return CheckoutFixtures::session($params, 'cs_test_MULTI'.(++$serial));
        };
        F::started($this->gateway); $first = F::receipt($this->gateway->session);
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($first->id));
        $original = VerifiedPayment::sole()->getAttributes();
        F::started($this->gateway); $second = F::receipt($this->gateway->session, 'evt_OTHERORDER');
        $this->assertSame('payment_changed', app(ProcessStripeReceipt::class)->handle($second->id));
        $this->assertSame('quarantined', StripeReceiptWork::where('stripe_webhook_receipt_id', $second->id)->sole()->state);
        $this->assertSame($original, VerifiedPayment::sole()->getAttributes());
        $this->assertDatabaseCount('verified_payments', 1); $this->assertDatabaseCount('orders', 2);
    }

    public function test_expired_or_unpaid_session_never_infers_success_from_the_event_name(): void
    {
        F::started($this->gateway); $this->gateway->session['status'] = 'expired';
        $this->gateway->session['payment_status'] = 'unpaid'; $this->gateway->session['payment_intent'] = null;
        $receipt = F::receipt($this->gateway->session); $before = F::unchangedBusinessEvidence();
        $this->assertSame('expired', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame('expired', PaymentObservation::sole()->outcome);
        $this->assertDatabaseCount('verified_payments', 0); $this->assertSame($before, F::unchangedBusinessEvidence());
    }

    public function test_event_snapshot_payment_claims_do_not_replace_authoritative_retrieval(): void
    {
        F::started($this->gateway); $event = F::event($this->gateway->session);
        $event['data']['object']['amount_total'] = 1; $event['data']['object']['currency'] = 'eur';
        $event['data']['object']['payment_intent'] = 'pi_UNTRUSTEDSNAPSHOT';
        $receipt = F::receive($event);
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame(F::PAYMENT, VerifiedPayment::sole()->provider_payment_intent_id);
        $this->assertSame($this->gateway->payment['amount'], VerifiedPayment::sole()->amount_minor);
        $retrieved = array_values(array_filter($this->gateway->calls, fn ($call) => $call['operation'] === 'payment_intent'));
        $this->assertSame([F::PAYMENT], array_column($retrieved, 'id'));
    }

    public function test_unknown_snapshot_type_is_retained_as_unsupported_without_provider_calls(): void
    {
        $event = StripeWebhookFixtures::event(); $event['type'] = 'customer.created';
        $event['data']['object'] = ['object' => 'customer', 'id' => 'cus_SYNTHETIC', 'email' => 'private@example.invalid'];
        $receipt = F::receive($event);
        $this->assertSame('unsupported', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame('unsupported', StripeReceiptWork::sole()->state);
        $this->assertCount(0, $this->gateway->calls); $this->assertDatabaseCount('verified_payments', 0);
        $this->assertDatabaseCount('payment_observations', 0); $this->assertDatabaseCount('stripe_webhook_receipts', 1);
    }

    public static function corruptReceipts(): array
    {
        return array_map(fn ($name) => [$name], ['ciphertext', 'payload_hash', 'fingerprint', 'fingerprint_version',
            'event_id', 'event_type', 'object_id', 'object_type', 'api_version', 'provider_created_at']);
    }

    #[DataProvider('corruptReceipts')]
    public function test_retained_receipt_inconsistency_is_quarantined_before_provider_io(string $scenario): void
    {
        F::started($this->gateway); $event = F::event($this->gateway->session);
        $body = StripeWebhookFixtures::body($event);
        $attributes = ['account_id' => CheckoutFixtures::ACCOUNT, 'livemode' => false, 'event_id' => $event['id'],
            'event_type' => $event['type'], 'object_id' => $event['data']['object']['id'], 'object_type' => 'checkout.session',
            'api_version' => $event['api_version'], 'provider_created_at' => $event['created'], 'signature_timestamp' => time(),
            'payload_sha256' => hash('sha256', $body), 'event_fingerprint' => StripeEventFingerprint::hash(json_decode($body)),
            'fingerprint_version' => StripeEventFingerprint::VERSION, 'payload_ciphertext' => Crypt::encryptString($body), 'received_at' => now()];
        match ($scenario) {
            'ciphertext' => $attributes['payload_ciphertext'] = 'not-authenticated-ciphertext',
            'payload_hash' => $attributes['payload_sha256'] = str_repeat('0', 64),
            'fingerprint' => $attributes['event_fingerprint'] = str_repeat('0', 64),
            'fingerprint_version' => $attributes['fingerprint_version'] = 'unknown-version',
            'event_id' => $attributes['event_id'] = 'evt_DIFFERENT',
            'event_type' => $attributes['event_type'] = 'checkout.session.expired',
            'object_id' => $attributes['object_id'] = 'cs_test_DIFFERENT',
            'object_type' => $attributes['object_type'] = 'payment_intent',
            'api_version' => $attributes['api_version'] = '2026-07-29.dahlia',
            'provider_created_at' => $attributes['provider_created_at']++,
        };
        $receipt = StripeWebhookReceipt::create($attributes); $calls = $this->gateway->calls;
        $this->assertSame('receipt_changed', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame('quarantined', StripeReceiptWork::sole()->state);
        $this->assertSame($calls, $this->gateway->calls); $this->assertDatabaseCount('verified_payments', 0);
        $this->assertDatabaseCount('payment_observations', 0);
    }

    public function test_lost_initial_session_binding_is_recovered_by_full_authoritative_session_evidence(): void
    {
        $f = CheckoutFixtures::prepared(); $accepted = null;
        $this->gateway->onCreate = function (array $params) use (&$accepted): array {
            $accepted = CheckoutFixtures::session($params); throw new RuntimeException('Synthetic lost response.');
        };
        try { app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER); $this->fail('Expected uncertain provider response.'); }
        catch (QuoteException $error) { $this->assertSame('CHECKOUT_UNAVAILABLE', $error->errorCode); }
        $this->assertDatabaseCount('checkout_sessions', 0); $intent = CheckoutIntent::sole();
        $accepted['status'] = 'complete'; $accepted['payment_status'] = 'paid'; $accepted['url'] = null; $accepted['payment_intent'] = F::PAYMENT;
        $this->gateway->session = $accepted; $this->gateway->payment = F::payment($accepted);
        $receipt = F::receipt($accepted); $this->travelTo($intent->retry_before->addHour());
        $before = F::unchangedBusinessEvidence();
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertDatabaseCount('checkout_intents', 1); $this->assertDatabaseCount('checkout_sessions', 1);
        $this->assertSame(CheckoutFixtures::SESSION, CheckoutSession::sole()->provider_session_id);
        $this->assertDatabaseCount('verified_payments', 1); $this->assertSame($before, F::unchangedBusinessEvidence());
        $this->assertCount(1, array_filter($this->gateway->calls, fn ($call) => $call['operation'] === 'create'));
    }

    public function test_known_session_reconciliation_covers_a_missing_webhook_after_checkout_policy_withdrawal(): void
    {
        $f = F::started($this->gateway); $before = F::unchangedBusinessEvidence();
        config(['commerce.test_checkout_policy' => null, 'payments.stripe.checkout_enabled' => false]);
        $this->travelTo(now()->addHours(3));
        $this->assertSame('awaiting_finalization', app(VerifyTestPayment::class)->reconcile($f['intent']));
        $this->assertDatabaseCount('verified_payments', 1); $this->assertDatabaseCount('stripe_webhook_receipts', 0);
        $this->assertNull(PaymentObservation::sole()->stripe_webhook_receipt_id);
        $this->assertSame($before, F::unchangedBusinessEvidence());
    }

    public function test_provider_timeout_retains_retryable_work_without_raw_errors_or_customer_details(): void
    {
        F::started($this->gateway); $receipt = F::receipt($this->gateway->session); $before = F::unchangedBusinessEvidence(); Log::spy();
        $this->gateway->onPayment = fn () => throw new RuntimeException('provider-secret-marker customer-private@example.invalid https://private.invalid');
        $this->assertSame('retry', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $work = StripeReceiptWork::sole(); $this->assertSame('retry', $work->state); $this->assertSame(1, $work->attempts);
        $this->assertTrue($work->next_attempt_at->equalTo(now()->addSeconds(30)));
        $calls = $this->gateway->calls;
        $this->assertSame('retry', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame($calls, $this->gateway->calls); $this->assertSame(1, $work->fresh()->attempts);
        $this->travelTo($work->next_attempt_at); $this->gateway->onPayment = null;
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame(2, $work->fresh()->attempts); $this->assertDatabaseCount('verified_payments', 1);
        $safe = json_encode([StripeReceiptWork::sole()->toArray(), PaymentObservation::sole()->toArray(), VerifiedPayment::sole()->toArray(),
            DB::table('audit_events')->get()], JSON_THROW_ON_ERROR);
        foreach (['provider-secret-marker', 'customer-private@example.invalid', 'https://private.invalid', ...array_values(OrderFixtures::buyer())] as $private) {
            $this->assertStringNotContainsString($private, $safe);
        }
        $this->assertSame($before, F::unchangedBusinessEvidence());
    }

    public function test_retry_budget_is_finite_and_explicit_replay_recovers_the_same_receipt(): void
    {
        F::started($this->gateway); $receipt = F::receipt($this->gateway->session);
        $this->gateway->onPayment = fn () => throw new RuntimeException('Synthetic timeout.');
        foreach (range(1, 8) as $attempt) {
            $outcome = app(ProcessStripeReceipt::class)->handle($receipt->id);
            $work = StripeReceiptWork::sole(); $this->assertSame($attempt, $work->attempts);
            if ($attempt < 8) {
                $this->assertSame('retry', $outcome); $this->assertSame('retry', $work->state);
                $this->assertTrue($work->next_attempt_at->equalTo(now()->addSeconds(min(3600, 30 * (2 ** ($attempt - 1))))));
                $this->travelTo($work->next_attempt_at);
            } else { $this->assertSame('retry_exhausted', $outcome); $this->assertSame('quarantined', $work->state); }
        }
        $calls = $this->gateway->calls;
        $this->assertSame('retry_exhausted', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame($calls, $this->gateway->calls); $this->gateway->onPayment = null;
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id, true));
        $this->assertDatabaseCount('stripe_receipt_work', 1); $this->assertDatabaseCount('verified_payments', 1);
    }

    public function test_crash_on_last_attempt_is_quarantined_after_lease_expiry_without_starving_later_receipts(): void
    {
        F::started($this->gateway); $receipt = F::receipt($this->gateway->session);
        $claim = app(PaymentWork::class)->claim($receipt->id); $claim->forceFill(['attempts' => 8])->save();
        $this->travelTo($claim->lease_expires_at->addSecond());
        $later = F::receipt($this->gateway->session, 'evt_AFTERCRASH');
        $this->assertSame(0, Artisan::call('vasey:process-stripe-receipts', ['--limit' => '2']));
        $exhausted = StripeReceiptWork::where('stripe_webhook_receipt_id', $receipt->id)->sole();
        $this->assertSame('quarantined', $exhausted->state); $this->assertSame('retry_exhausted', $exhausted->outcome);
        $this->assertSame(8, $exhausted->attempts); $this->assertNull($exhausted->claim_token); $this->assertNull($exhausted->lease_expires_at);
        $this->assertSame('processed', StripeReceiptWork::where('stripe_webhook_receipt_id', $later->id)->sole()->state);
        $this->assertDatabaseCount('verified_payments', 1);
    }

    public function test_payment_succeeding_between_session_and_payment_reads_retries_then_converges(): void
    {
        F::started($this->gateway); $this->gateway->session['status'] = 'open'; $this->gateway->session['payment_status'] = 'unpaid';
        $this->gateway->session['url'] = 'https://checkout.stripe.com/c/pay/'.CheckoutFixtures::SESSION;
        $receipt = F::receipt($this->gateway->session);
        $this->assertSame('retry', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame('retry', StripeReceiptWork::sole()->state); $this->assertDatabaseCount('verified_payments', 0);
        $this->assertDatabaseCount('payment_observations', 0);
        $this->travelTo(StripeReceiptWork::sole()->next_attempt_at);
        $this->gateway->session['status'] = 'complete'; $this->gateway->session['payment_status'] = 'paid'; $this->gateway->session['url'] = null;
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertDatabaseCount('verified_payments', 1);
    }

    public function test_expired_lease_can_be_reclaimed_but_the_old_token_has_no_commit_authority(): void
    {
        F::started($this->gateway); $receipt = F::receipt($this->gateway->session);
        $work = app(PaymentWork::class); $first = $work->claim($receipt->id); $this->assertNotNull($first);
        $this->assertSame('busy', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame(1, StripeReceiptWork::sole()->attempts);
        $this->travelTo($first->lease_expires_at->addSecond()); $second = $work->claim($receipt->id);
        $this->assertNotNull($second); $this->assertNotSame($first->claim_token, $second->claim_token);
        $this->assertNull(DB::transaction(fn () => $work->owns($first)));
        $this->assertNotNull(DB::transaction(fn () => $work->owns($second)));
        $this->travelTo($second->lease_expires_at->addSecond());
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame(3, StripeReceiptWork::sole()->attempts); $this->assertDatabaseCount('verified_payments', 1);
    }

    public static function blockedEnvironments(): array { return [['disabled'], ['string_true'], ['production'], ['preview'], ['live'], ['staging_live'], ['account']]; }

    #[DataProvider('blockedEnvironments')]
    public function test_processing_requires_explicit_local_test_configuration_before_any_provider_request(string $scenario): void
    {
        F::started($this->gateway); $receipt = F::receipt($this->gateway->session); $calls = $this->gateway->calls;
        match ($scenario) {
            'disabled' => config(['payments.stripe.processing_enabled' => false]),
            'string_true' => config(['payments.stripe.processing_enabled' => 'true']),
            'production', 'preview' => $this->app->instance('env', $scenario),
            'staging_live' => [$this->app->instance('env', 'staging'), config(['payments.stripe.mode' => 'live'])],
            'live' => config(['payments.stripe.mode' => 'live']),
            'account' => config(['payments.stripe.account_id' => null]),
        };
        try {
            $this->assertSame('unavailable', app(ProcessStripeReceipt::class)->handle($receipt->id));
            $this->assertSame($calls, $this->gateway->calls); $this->assertDatabaseCount('verified_payments', 0);
            $this->assertDatabaseCount('payment_observations', 0);
        } finally { if (in_array($scenario, ['production', 'preview', 'staging_live'], true)) { $this->app->instance('env', 'testing'); } }
    }

    public function test_retained_payment_evidence_whitelists_provider_fields_and_hides_private_ciphertext(): void
    {
        F::started($this->gateway); $this->gateway->payment['client_secret'] = 'provider-secret-marker';
        $this->gateway->payment['receipt_email'] = 'customer-private@example.invalid';
        $this->gateway->session['customer_details'] = ['email' => 'customer-private@example.invalid'];
        $receipt = F::receipt($this->gateway->session);
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        foreach ([PaymentObservation::sole(), VerifiedPayment::sole()] as $model) {
            $this->assertSame(hash('sha256', $model->evidence_ciphertext), $model->evidence_hash);
            $plain = Crypt::decryptString($model->evidence_ciphertext);
            foreach (['provider-secret-marker', 'customer-private@example.invalid', 'receipt_email', 'client_secret', 'customer_details'] as $private) {
                $this->assertStringNotContainsString($private, $plain);
            }
            $this->assertStringNotContainsString('evidence_ciphertext', $model->toJson());
            $this->assertStringNotContainsString('evidence_hash', $model->toJson());
        }
    }

    public function test_authenticated_confirmation_ciphertext_still_requires_semantically_valid_payment_evidence(): void
    {
        $f = F::started($this->gateway); $receipt = F::receipt($this->gateway->session);
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $original = VerifiedPayment::sole(); $attributes = $original->getAttributes();
        $request = app(CheckoutEvidence::class)->verifyIntent($f['intent'], $f['order']);
        $session = CheckoutSession::sole();
        foreach (['total', 'received', 'session'] as $scenario) {
            $candidate = clone $original;
            $evidence = json_decode(Crypt::decryptString($candidate->evidence_ciphertext), true, 32, JSON_THROW_ON_ERROR);
            if ($scenario === 'total') { $candidate->amount_minor++; $evidence['amount_minor']++; }
            elseif ($scenario === 'received') { $evidence['payment']['amount_received'] = 0; }
            else { $evidence['session']['session_id'] = 'cs_test_DIFFERENT'; }
            [$ciphertext, $hash] = app(CheckoutEvidence::class)->encrypt($evidence);
            $candidate->forceFill(['evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash]);
            try {
                app(PaymentEvidence::class)->verifyConfirmation($candidate, $request, $session);
                $this->fail('Semantically altered confirmation was trusted for '.$scenario.'.');
            } catch (PaymentVerificationException $error) { $this->assertSame('payment_changed', $error->reason); }
        }
        $this->assertSame($attributes, $original->fresh()->getAttributes());
    }

    public function test_default_scan_skips_ineligible_early_work_before_applying_limit_and_recovers_missing_dispatch(): void
    {
        $event = StripeWebhookFixtures::event(); $event['type'] = 'customer.created'; $event['data']['object'] = ['object' => 'customer', 'id' => 'cus_SYNTHETIC'];
        $event['id'] = 'evt_EarlyUnsupported'; $early = F::receive($event);
        $this->assertSame('unsupported', app(ProcessStripeReceipt::class)->handle($early->id));
        $event['id'] = 'evt_ActiveLease'; $active = F::receive($event);
        $this->assertNotNull(app(PaymentWork::class)->claim($active->id));
        $event['id'] = 'evt_FutureRetry'; $future = F::receive($event);
        $claim = app(PaymentWork::class)->claim($future->id);
        $this->assertSame('retry', app(PaymentWork::class)->outcome($claim, 'retry', 'retry'));
        F::started($this->gateway); $receipt = F::receipt($this->gateway->session);
        $this->assertSame(0, Artisan::call('vasey:process-stripe-receipts', ['--limit' => '1']));
        $this->assertSame('processed', StripeReceiptWork::where('stripe_webhook_receipt_id', $receipt->id)->sole()->state);
        $this->assertDatabaseCount('verified_payments', 1);
        $this->assertStringNotContainsString('evt_EarlyUnsupported', Artisan::output());
    }

    public function test_default_scan_recovers_a_crashed_workers_expired_claim_without_replacing_the_receipt(): void
    {
        F::started($this->gateway); $receipt = F::receipt($this->gateway->session);
        $claim = app(PaymentWork::class)->claim($receipt->id); $this->assertNotNull($claim);
        $this->assertDatabaseCount('verified_payments', 0); $this->travelTo($claim->lease_expires_at->addSecond());
        $this->assertSame(0, Artisan::call('vasey:process-stripe-receipts', ['--limit' => '1']));
        $this->assertSame('processed', StripeReceiptWork::sole()->state); $this->assertSame(2, StripeReceiptWork::sole()->attempts);
        $this->assertDatabaseCount('stripe_webhook_receipts', 1); $this->assertDatabaseCount('stripe_receipt_work', 1);
        $this->assertDatabaseCount('verified_payments', 1);
    }

    public function test_processing_command_prints_only_safe_outcomes_and_replay_requires_an_explicit_receipt(): void
    {
        F::started($this->gateway); $receipt = F::receipt($this->gateway->session);
        $this->gateway->onPayment = fn () => throw new RuntimeException('provider-secret-marker customer-private@example.invalid');
        Artisan::call('vasey:process-stripe-receipts', ['receipt' => (string) $receipt->id]);
        $output = Artisan::output(); $this->assertStringContainsString('retry', $output);
        foreach (['provider-secret-marker', 'customer-private@example.invalid', CheckoutFixtures::SESSION, F::PAYMENT, ...array_values(OrderFixtures::buyer())] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        $this->assertSame(1, Artisan::call('vasey:process-stripe-receipts', ['--replay' => true]));
    }

    public function test_missing_webhook_operator_reconciliation_uses_only_known_owned_intents(): void
    {
        $f = F::started($this->gateway);
        $this->assertSame(0, Artisan::call('vasey:reconcile-test-payments', ['intent' => $f['intent']->public_id]));
        $output = Artisan::output();
        $this->assertStringContainsString('awaiting_finalization', $output);
        $this->assertDatabaseCount('verified_payments', 1); $this->assertDatabaseCount('stripe_webhook_receipts', 0);
        $this->assertStringNotContainsString(F::PAYMENT, $output);
        $this->assertStringNotContainsString(CheckoutFixtures::SESSION, $output);
    }

    public function test_reconciliation_cursor_can_pass_a_permanent_provider_failure_and_reach_later_work(): void
    {
        $serial = 0;
        $this->gateway->onCreate = function (array $params) use (&$serial): array {
            return CheckoutFixtures::session($params, 'cs_test_CURSOR'.(++$serial));
        };
        $first = F::started($this->gateway); $firstSession = $this->gateway->session;
        $second = F::started($this->gateway); $secondSession = $this->gateway->session;
        $this->gateway->onRetrieve = function (string $id) use ($firstSession, $secondSession): array {
            if ($id === $firstSession['id']) { throw new RuntimeException('Synthetic first-page provider failure.'); }

            return $secondSession;
        };
        $this->assertSame(0, Artisan::call('vasey:reconcile-test-payments', ['--limit' => '1']));
        $output = Artisan::output();
        $this->assertStringContainsString($first['intent']->public_id.' retry', $output);
        $this->assertStringContainsString('NEXT_AFTER='.$first['intent']->public_id, $output);
        $this->assertDatabaseCount('verified_payments', 0);
        $this->assertSame(0, Artisan::call('vasey:reconcile-test-payments', ['--limit' => '1', '--after' => $first['intent']->public_id]));
        $this->assertStringContainsString($second['intent']->public_id.' awaiting_finalization', Artisan::output());
        $this->assertDatabaseCount('verified_payments', 1); $this->assertSame($second['order']->id, VerifiedPayment::sole()->order_id);
        $this->assertSame(1, Artisan::call('vasey:reconcile-test-payments', ['--after' => (string) Str::uuid()]));
        $this->assertSame(1, Artisan::call('vasey:reconcile-test-payments', ['intent' => $second['intent']->public_id, '--after' => $first['intent']->public_id]));
    }

    public function test_processing_inside_an_outer_transaction_never_calls_the_provider(): void
    {
        F::started($this->gateway); $receipt = F::receipt($this->gateway->session); $calls = $this->gateway->calls;
        DB::transaction(function () use ($receipt): void {
            $this->assertSame('unavailable', app(ProcessStripeReceipt::class)->handle($receipt->id));
        });
        $this->assertSame($calls, $this->gateway->calls); $this->assertDatabaseCount('verified_payments', 0);
    }

    public function test_lease_expiry_at_final_work_update_rolls_back_every_payment_effect(): void
    {
        F::started($this->gateway); $receipt = F::receipt($this->gateway->session);
        $checkoutCount = CheckoutObservation::count(); $before = F::unchangedBusinessEvidence(); $expired = false;
        VerifiedPayment::created(function () use (&$expired): void {
            if (! $expired) { $expired = true; $this->travelTo(now()->addSeconds(121)); }
        });
        $this->assertSame('stale', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertTrue($expired); $this->assertDatabaseCount('verified_payments', 0); $this->assertDatabaseCount('payment_observations', 0);
        $this->assertSame($checkoutCount, CheckoutObservation::count()); $this->assertSame($before, F::unchangedBusinessEvidence());
        $this->assertSame('processing', StripeReceiptWork::sole()->state);
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame(2, StripeReceiptWork::sole()->attempts); $this->assertDatabaseCount('verified_payments', 1);
    }

    public function test_failure_after_confirmation_insert_rolls_back_confirmation_and_is_recoverable(): void
    {
        F::started($this->gateway); $receipt = F::receipt($this->gateway->session);
        $checkoutCount = CheckoutObservation::count(); $before = F::unchangedBusinessEvidence(); $failed = false;
        StripeReceiptWork::updating(function (StripeReceiptWork $work) use (&$failed): void {
            if (! $failed && $work->state === 'processed') { $failed = true; throw new RuntimeException('Synthetic work completion failure.'); }
        });
        $this->assertSame('retry', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertTrue($failed); $this->assertDatabaseCount('verified_payments', 0); $this->assertDatabaseCount('payment_observations', 0);
        $this->assertSame($checkoutCount, CheckoutObservation::count()); $this->assertSame($before, F::unchangedBusinessEvidence());
        $this->assertSame('retry', StripeReceiptWork::sole()->state);
        $this->travelTo(StripeReceiptWork::sole()->next_attempt_at);
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertDatabaseCount('verified_payments', 1); $this->assertDatabaseCount('payment_observations', 1);
    }

    public function test_queue_failure_still_acknowledges_durable_receipt_and_scanner_recovers_it(): void
    {
        config(['queue.default' => 'database']); F::started($this->gateway); $calls = $this->gateway->calls;
        Bus::shouldReceive('dispatch')->once()->with(\Mockery::on(fn ($job) => $job instanceof ProcessStripeReceiptJob))
            ->andThrow(new RuntimeException('queue-private-marker redis://private.invalid'));
        $body = StripeWebhookFixtures::body(F::event($this->gateway->session));
        $response = $this->postPaymentWebhook($body)->assertOk()->assertExactJson(['received' => true]);
        $response->assertDontSee('queue-private-marker', false)->assertDontSee('private.invalid', false);
        $this->assertSame($calls, $this->gateway->calls); $this->assertDatabaseCount('stripe_webhook_receipts', 1);
        $this->assertDatabaseCount('stripe_receipt_work', 0); $this->assertDatabaseCount('verified_payments', 0);
        $this->assertSame(0, Artisan::call('vasey:process-stripe-receipts', ['--limit' => '1']));
        $this->assertDatabaseCount('verified_payments', 1); $this->assertSame('processed', StripeReceiptWork::sole()->state);
    }

    public function test_dispatch_waits_for_transaction_commit_and_is_discarded_on_rollback(): void
    {
        config(['queue.default' => 'database']); F::started($this->gateway); $receipt = F::receipt($this->gateway->session);
        Queue::fake();
        DB::transaction(function () use ($receipt): void {
            app(DispatchStripeReceipt::class)->handle($receipt->id); Queue::assertNothingPushed();
        });
        Queue::assertPushed(ProcessStripeReceiptJob::class, 1); Queue::fake();
        try {
            DB::transaction(function () use ($receipt): void {
                app(DispatchStripeReceipt::class)->handle($receipt->id); throw new RuntimeException('Synthetic rollback.');
            });
        } catch (RuntimeException $error) { $this->assertSame('Synthetic rollback.', $error->getMessage()); }
        Queue::assertNothingPushed(); $this->assertDatabaseCount('verified_payments', 0);
    }

    private function postPaymentWebhook(string $body): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => StripeWebhookFixtures::signature($body)], $body);
    }

    public function test_receipt_only_dispatches_async_work_and_the_job_payload_has_no_private_data(): void
    {
        config(['queue.default' => 'database']); F::started($this->gateway); $calls = $this->gateway->calls;
        $body = StripeWebhookFixtures::body(F::event($this->gateway->session));
        $this->postPaymentWebhook($body)->assertOk()->assertExactJson(['received' => true]);
        $receipt = StripeWebhookReceipt::sole();
        Queue::assertPushed(ProcessStripeReceiptJob::class, fn ($job) => $job->receiptId === $receipt->id && $job->queue === 'payments');
        $this->assertSame($calls, $this->gateway->calls); $this->assertDatabaseCount('verified_payments', 0);
        $job = new ProcessStripeReceiptJob($receipt->id); $serialized = serialize($job);
        foreach ([CheckoutFixtures::SESSION, F::PAYMENT, config('payments.stripe.secret_key'), ...array_values(OrderFixtures::buyer())] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
    }

    public function test_sync_receipt_path_never_runs_payment_provider_io_before_acknowledgment(): void
    {
        config(['queue.default' => 'sync']); F::started($this->gateway); Queue::fake(); $calls = $this->gateway->calls;
        $body = StripeWebhookFixtures::body(F::event($this->gateway->session));
        $this->postPaymentWebhook($body)->assertOk()->assertExactJson(['received' => true]);
        Queue::assertNothingPushed(); $this->assertSame($calls, $this->gateway->calls);
        $this->assertDatabaseCount('stripe_webhook_receipts', 1); $this->assertDatabaseCount('verified_payments', 0);
    }
}
