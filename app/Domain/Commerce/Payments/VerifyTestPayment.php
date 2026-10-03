<?php

namespace App\Domain\Commerce\Payments;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Finalization\DispatchTestFinalization;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\PaymentObservation;
use App\Domain\Commerce\Models\StripeReceiptWork;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\QuoteException;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class VerifyTestPayment
{
    /** Trusted console recovery, including a payment whose webhook was never received. */
    public function reconcile(CheckoutIntent $intent): string
    {
        try {
            app(PaymentProcessingPolicy::class)->account();
            app(PaymentProcessingPolicy::class)->outsideTransactions();
            $session = CheckoutSession::where('checkout_intent_id', $intent->id)->first();
            if (! $session) {
                return 'unmatched';
            }

            return $this->commit($this->inspect($session->provider_session_id, $intent), null);
        } catch (PaymentVerificationException $error) {
            return $error->reason;
        } catch (QuoteException|UniqueConstraintViolationException) {
            return 'payment_changed';
        } catch (Throwable) {
            return 'retry';
        }
    }

    /** GET-only, outside all transactions. The event supplies only this Session locator. */
    public function inspect(string $sessionId, ?CheckoutIntent $expected = null): array
    {
        $policy = app(PaymentProcessingPolicy::class);
        $account = $policy->account();
        $policy->outsideTransactions();
        if (! preg_match('/\Acs_test_[A-Za-z0-9]{1,120}\z/', $sessionId)) {
            throw new PaymentVerificationException('payment_changed');
        }
        $gateway = app(StripePaymentGateway::class);
        try {
            $actualAccount = $gateway->account();
            if (($actualAccount['id'] ?? null) !== $account || ($actualAccount['object'] ?? null) !== 'account') {
                throw new PaymentVerificationException('payment_changed');
            }
            $raw = $gateway->retrieve($sessionId);
        } catch (PaymentVerificationException $error) {
            throw $error;
        } catch (Throwable) {
            throw new PaymentVerificationException('retry');
        }
        if (($raw['id'] ?? null) !== $sessionId) {
            throw new PaymentVerificationException('payment_changed');
        }
        $binding = CheckoutSession::where('account_id', $account)->where('mode', 'test')->where('provider_session_id', $sessionId)->first();
        $locator = $raw['metadata']['intent_id'] ?? null;
        if (! OrderRequest::uuid($locator)) {
            throw new PaymentVerificationException('unmatched');
        }
        $intent = CheckoutIntent::where('public_id', $locator)->where('account_id', $account)->where('mode', 'test')->first();
        if (! $intent) {
            throw new PaymentVerificationException('unmatched');
        }
        if (($expected && $intent->id !== $expected->id) || ($binding && $binding->checkout_intent_id !== $intent->id)) {
            throw new PaymentVerificationException('payment_changed');
        }
        $order = Order::findOrFail($intent->order_id);
        $request = app(CheckoutEvidence::class)->verifyIntent($intent, $order);
        $observed = app(CheckoutEvidence::class)->session($raw, $request);
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
        $evidence = app(PaymentEvidence::class)->capture($raw, $payment, $request, 'reconciliation', null);

        return ['intent_id' => $intent->id, 'order_id' => $order->id, 'request' => $request,
            'observed' => $observed, 'evidence' => $evidence, 'observed_at' => now()->toImmutable()->utc()->startOfSecond()];
    }

    /** One fenced commit: no session binding or observation is written by an expired worker. */
    public function commit(array $inspection, ?StripeReceiptWork $claim): string
    {
        app(PaymentProcessingPolicy::class)->outsideTransactions();
        $outcome = DB::transaction(function () use ($inspection, $claim): string {
            $order = Order::whereKey($inspection['order_id'])->lockForUpdate()->firstOrFail();
            $intent = CheckoutIntent::whereKey($inspection['intent_id'])->lockForUpdate()->firstOrFail();
            $work = $claim ? app(PaymentWork::class)->owns($claim) : null;
            if ($claim && ! $work) {
                return 'stale';
            }
            if ($intent->account_id !== app(PaymentProcessingPolicy::class)->account() || $intent->order_id !== $order->id) {
                throw new PaymentVerificationException('payment_changed');
            }
            $checkoutEvidence = app(CheckoutEvidence::class);
            $request = $checkoutEvidence->verifyIntent($intent, $order);
            if (CanonicalJson::encode($request) !== CanonicalJson::encode($inspection['request'])) {
                throw new PaymentVerificationException('payment_changed');
            }
            $at = $inspection['observed_at'];
            $session = app(HostedCheckout::class)->recordObservation($intent, $request, $inspection['observed'], $at);
            $evidence = $inspection['evidence'];
            $evidence['source'] = $work ? 'receipt' : 'reconciliation';
            $evidence['receipt_id'] = $work?->stripe_webhook_receipt_id;
            [$ciphertext, $hash] = $checkoutEvidence->encrypt($evidence);
            $existing = VerifiedPayment::where('checkout_intent_id', $intent->id)->first();
            if ($existing) {
                app(PaymentEvidence::class)->verifyConfirmation($existing, $request, $session);
                if ($existing->order_id !== $order->id || $existing->order_attempt_id !== $intent->order_attempt_id
                    || (isset($evidence['payment']['id']) && $existing->provider_payment_intent_id !== $evidence['payment']['id'])) {
                    throw new PaymentVerificationException('payment_changed');
                }
            }
            PaymentObservation::create(['checkout_intent_id' => $intent->id, 'stripe_webhook_receipt_id' => $work?->stripe_webhook_receipt_id,
                'account_id' => $intent->account_id, 'mode' => 'test', 'provider_payment_intent_id' => $evidence['payment']['id'] ?? null,
                'outcome' => $evidence['outcome'], 'evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash,
                'canonicalization_version' => CanonicalJson::VERSION, 'observed_at' => $at]);
            if ($evidence['outcome'] === 'confirmed' && ! $existing) {
                $existing = VerifiedPayment::create(['order_id' => $order->id, 'order_attempt_id' => $intent->order_attempt_id,
                    'checkout_intent_id' => $intent->id, 'checkout_session_id' => $session->id, 'account_id' => $intent->account_id,
                    'mode' => 'test', 'provider_payment_intent_id' => $evidence['payment']['id'], 'amount_minor' => $evidence['amount_minor'],
                    'currency' => 'USD', 'evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash,
                    'canonicalization_version' => CanonicalJson::VERSION, 'confirmed_at' => $at]);
                AuditEvent::recordAttributed('commerce.payment.test_verified', $existing, ['order_public_id' => $order->public_id,
                    'intent_public_id' => $intent->public_id, 'test_only' => true, 'fulfillment' => 'awaiting_finalization'], null);
            }
            $outcome = $existing ? 'awaiting_finalization' : $evidence['outcome'];
            if ($work) {
                app(PaymentWork::class)->finish($work, in_array($outcome, ['pending', 'authorized'], true) ? 'retry' : 'processed', $outcome);
                $outcome = $work->outcome;
            }

            return $outcome;
        }, 5);
        if ($outcome === 'awaiting_finalization') {
            $paymentId = VerifiedPayment::where('order_id', $inspection['order_id'])->value('id');
            if ($paymentId !== null) {
                app(DispatchTestFinalization::class)->handle((int) $paymentId);
            }
        }

        return $outcome;
    }
}
