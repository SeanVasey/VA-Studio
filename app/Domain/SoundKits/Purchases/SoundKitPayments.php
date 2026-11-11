<?php

namespace App\Domain\SoundKits\Purchases;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Models\StripeReceiptWork;
use App\Domain\Commerce\Payments\PaymentEvidence;
use App\Domain\Commerce\Payments\PaymentProcessingPolicy;
use App\Domain\Commerce\Payments\PaymentVerificationException;
use App\Domain\Commerce\Payments\PaymentWork;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\QuoteException;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\Purchases\Models\SoundKitCheckoutSession;
use App\Domain\SoundKits\Purchases\Models\SoundKitGrant;
use App\Domain\SoundKits\Purchases\Models\SoundKitOrder;
use App\Domain\SoundKits\Purchases\Models\SoundKitPayment;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Verifies a kit order's Stripe TEST payment and grants the kit (BL-13). It is the track chain's verification, reused: the webhook
 * receipt inbox and its fenced work rows ({@see PaymentWork}), GET-only provider reads under {@see PaymentProcessingPolicy}, and the
 * same field-by-field session and PaymentIntent checks ({@see CheckoutEvidence::session()}, {@see PaymentEvidence::capture()}),
 * applied to the order's frozen request. Event payloads are locators only; no amount or status from an event is trusted.
 *
 * Durable verified payment comes first: the payment row commits (its trigger requires the order's exact price and bound session),
 * then the grant is written in its own transaction from that committed row. One payment and one grant per order (unique keys), so
 * a replayed or duplicated webhook, a reconcile and a webhook racing each other all converge on the same single grant.
 */
final class SoundKitPayments
{
    /** True when this Stripe session belongs to a kit order (routing for the shared receipt processor). */
    public function owns(string $sessionId): bool
    {
        return SoundKitCheckoutSession::query()->where('provider_session_id', $sessionId)->exists();
    }

    /** Receipt processing: inspect (GET only), then one fenced commit. Mirrors VerifyTestPayment for track orders. */
    public function process(string $sessionId, StripeReceiptWork $claim): string
    {
        return $this->commit($this->inspect($sessionId), $claim);
    }

    /** Trusted recovery for a payment whose webhook never arrived (owner or console). */
    public function reconcile(SoundKitOrder $order): string
    {
        try {
            app(SoundKitPurchasePolicy::class)->require();
            $session = SoundKitCheckoutSession::query()->where('sound_kit_order_id', $order->id)->first();
            if (! $session) {
                return 'unmatched';
            }
            if (SoundKitPayment::query()->where('sound_kit_order_id', $order->id)->exists()) {
                $this->finalize($order);

                return 'granted';
            }

            return $this->commit($this->inspect($session->provider_session_id, $order), null);
        } catch (PaymentVerificationException $error) {
            return $error->reason;
        } catch (UniqueConstraintViolationException) {
            return 'payment_changed';
        } catch (SoundKitPurchaseException $error) {
            return $error->errorCode === 'NOT_FOUND' ? 'unavailable' : 'payment_changed';
        } catch (Throwable) {
            return 'retry';
        }
    }

    public function inspect(string $sessionId, ?SoundKitOrder $expected = null): array
    {
        $account = app(PaymentProcessingPolicy::class)->account();
        if (! app(SoundKitPurchasePolicy::class)->admitted()) {
            throw new PaymentVerificationException('unavailable');
        }
        app(PaymentProcessingPolicy::class)->outsideTransactions();
        if (preg_match('/\Acs_test_[A-Za-z0-9]{1,120}\z/', $sessionId) !== 1) {
            throw new PaymentVerificationException('payment_changed');
        }
        $gateway = app(StripePaymentGateway::class);
        try {
            $actual = $gateway->account();
            if (($actual['id'] ?? null) !== $account || ($actual['object'] ?? null) !== 'account') {
                throw new PaymentVerificationException('payment_changed');
            }
            $raw = $gateway->retrieve($sessionId);
        } catch (PaymentVerificationException $error) {
            throw $error;
        } catch (Throwable) {
            throw new PaymentVerificationException('retry');
        }
        if (! is_array($raw) || ($raw['id'] ?? null) !== $sessionId) {
            throw new PaymentVerificationException('payment_changed');
        }
        $locator = $raw['metadata']['kit_order_id'] ?? null;
        if (! is_string($locator) || ! Str::isUuid($locator)) {
            throw new PaymentVerificationException('unmatched');
        }
        $order = SoundKitOrder::query()->where('public_id', $locator)->where('account_id', $account)->first();
        $binding = SoundKitCheckoutSession::query()->where('provider_session_id', $sessionId)->where('account_id', $account)->first();
        if (! $order || ! $binding) {
            throw new PaymentVerificationException('unmatched');
        }
        if ($binding->sound_kit_order_id !== $order->id || ($expected && $expected->id !== $order->id)) {
            throw new PaymentVerificationException('payment_changed');
        }
        try {
            $request = app(SoundKitOrders::class)->verified($order)->checkout_request;
            app(CheckoutEvidence::class)->session($raw, $request);
        } catch (SoundKitPurchaseException|QuoteException) {
            throw new PaymentVerificationException('payment_changed');
        }
        $paymentId = $raw['payment_intent'] ?? null;
        $payment = null;
        if ($paymentId !== null) {
            if (! PaymentEvidence::paymentId($paymentId)) {
                throw new PaymentVerificationException('payment_changed');
            }
            try {
                $payment = $gateway->paymentIntent($paymentId);
            } catch (Throwable) {
                throw new PaymentVerificationException('retry');
            }
        }
        try {
            $evidence = app(PaymentEvidence::class)->capture($raw, $payment, $request, 'reconciliation', null);
        } catch (QuoteException) {
            throw new PaymentVerificationException('payment_changed');
        }

        return ['order_id' => $order->id, 'session_id' => $sessionId, 'evidence' => $evidence,
            'observed_at' => now()->toImmutable()->utc()->startOfSecond()];
    }

    /** One fenced commit. An expired receipt worker writes nothing; an existing payment must be the same provider payment. */
    public function commit(array $inspection, ?StripeReceiptWork $claim): string
    {
        app(PaymentProcessingPolicy::class)->outsideTransactions();
        $outcome = DB::transaction(function () use ($inspection, $claim): string {
            $order = SoundKitOrder::query()->whereKey($inspection['order_id'])->lockForUpdate()->firstOrFail();
            $work = $claim ? app(PaymentWork::class)->owns($claim) : null;
            if ($claim && ! $work) {
                return 'stale';
            }
            $evidence = $inspection['evidence'];
            $evidence['source'] = $work ? 'receipt' : 'reconciliation';
            $evidence['receipt_id'] = $work?->stripe_webhook_receipt_id;
            $existing = SoundKitPayment::query()->where('sound_kit_order_id', $order->id)->lockForUpdate()->first();
            if ($existing && ($existing->provider_session_id !== $inspection['session_id']
                || (isset($evidence['payment']['id']) && $existing->provider_payment_intent_id !== $evidence['payment']['id']))) {
                throw new PaymentVerificationException('payment_changed');
            }
            if (! $existing && $evidence['outcome'] === 'confirmed') {
                if (($evidence['amount_minor'] ?? null) !== $order->price_minor || ($evidence['currency'] ?? null) !== $order->currency) {
                    throw new PaymentVerificationException('payment_changed');
                }
                $existing = SoundKitPayment::create(['public_id' => (string) Str::uuid(), 'sound_kit_order_id' => $order->id,
                    'account_id' => $order->account_id, 'provider_session_id' => $inspection['session_id'],
                    'provider_payment_intent_id' => $evidence['payment']['id'], 'stripe_webhook_receipt_id' => $work?->stripe_webhook_receipt_id,
                    'source' => $evidence['source'], 'amount_minor' => $evidence['amount_minor'], 'currency' => 'USD',
                    'evidence' => $evidence, 'evidence_hash' => CanonicalJson::hash($evidence), 'verified_at' => $inspection['observed_at']]);
                AuditEvent::recordAttributed('sound_kit.test_payment.verified', $existing, ['order_public_id' => $order->public_id,
                    'source' => $evidence['source'], 'test_only' => true], null);
            }
            $outcome = $existing ? 'awaiting_grant' : $evidence['outcome'];
            if ($work) {
                app(PaymentWork::class)->finish($work, in_array($outcome, ['pending', 'authorized'], true) ? 'retry' : 'processed', $outcome);
            }

            return $outcome;
        }, 5);
        if ($outcome !== 'awaiting_grant') {
            return $outcome;
        }
        $this->finalize(SoundKitOrder::query()->findOrFail($inspection['order_id']));

        return 'granted';
    }

    /** The grant, written once from the committed verified payment. Replays return the existing grant and write nothing. */
    public function finalize(SoundKitOrder $order): SoundKitGrant
    {
        return DB::transaction(function () use ($order): SoundKitGrant {
            $locked = SoundKitOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = SoundKitGrant::query()->where('sound_kit_order_id', $locked->id)->first();
            if ($existing) {
                return $existing;
            }
            $payment = SoundKitPayment::query()->where('sound_kit_order_id', $locked->id)->firstOrFail();
            $snapshot = app(SoundKitOrders::class)->verified($locked)->snapshot;
            if ($payment->amount_minor !== $locked->price_minor || $payment->currency !== $locked->currency
                || ! hash_equals($payment->evidence_hash, CanonicalJson::hash($payment->evidence))) {
                throw new PaymentVerificationException('payment_changed');
            }
            $revision = SoundKitRevision::query()->where('public_id', $snapshot['kit_revision']['id'])->firstOrFail();
            if ($revision->archive_sha256 !== $snapshot['archive']['sha256'] || $revision->archive_size_bytes !== $snapshot['archive']['size_bytes']) {
                throw new PaymentVerificationException('payment_changed');
            }
            $grant = SoundKitGrant::create(['public_id' => (string) Str::uuid(), 'sound_kit_order_id' => $locked->id,
                'sound_kit_payment_id' => $payment->id, 'sound_kit_product_id' => $locked->sound_kit_product_id,
                'sound_kit_revision_id' => $revision->id, 'license_version_id' => $snapshot['license']['version_id'],
                'snapshot_hash' => $locked->snapshot_hash, 'granted_at' => now()->toImmutable()->utc()->startOfSecond()]);
            AuditEvent::recordAttributed('sound_kit.test_grant.created', $grant, ['order_public_id' => $locked->public_id, 'test_only' => true], null);

            return $grant;
        }, 5);
    }
}
