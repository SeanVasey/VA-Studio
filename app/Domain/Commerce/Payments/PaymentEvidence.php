<?php

namespace App\Domain\Commerce\Payments;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Support\CanonicalJson;

/** Financial observations only. A successful collection does not establish fulfillment eligibility. */
final class PaymentEvidence
{
    public function capture(array $session, ?array $payment, array $request, string $source, ?int $receiptId): array
    {
        $sessionEvidence = app(CheckoutEvidence::class)->session($session, $request);
        // Private Checkout URL is irrelevant to financial verification and is not retained here.
        unset($sessionEvidence['url']);
        $total = array_sum(array_column(array_column($request['params']['line_items'], 'price_data'), 'unit_amount'));
        $locator = $session['payment_intent'] ?? null;
        $safe = null; $outcome = $session['status'] === 'expired' ? 'expired' : 'pending';
        if ($locator !== null) {
            if (! self::paymentId($locator) || $payment === null || ($payment['id'] ?? null) !== $locator
                || ($payment['object'] ?? null) !== 'payment_intent' || ($payment['livemode'] ?? null) !== false
                || ($payment['currency'] ?? null) !== 'usd' || ($payment['amount'] ?? null) !== $total
                || ! is_int($payment['amount_received'] ?? null) || $payment['amount_received'] < 0 || $payment['amount_received'] > $total
                || ! is_int($payment['amount_capturable'] ?? null) || $payment['amount_capturable'] < 0 || $payment['amount_capturable'] > $total
                || CanonicalJson::encode($payment['metadata'] ?? null) !== CanonicalJson::encode($request['params']['payment_intent_data']['metadata'])
                || ($payment['payment_method_types'] ?? null) !== ['card']
                || ! in_array($payment['status'] ?? null, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing', 'requires_capture', 'canceled', 'succeeded'], true)
                || ! in_array($payment['capture_method'] ?? null, ['automatic', 'automatic_async', 'manual'], true)
                || (isset($payment['confirmation_method']) && $payment['confirmation_method'] !== 'automatic')) {
                throw new PaymentVerificationException('payment_changed');
            }
            foreach (['account', 'context', 'application', 'application_fee_amount', 'on_behalf_of', 'transfer_data', 'transfer_group', 'setup_future_usage'] as $key) {
                if (($payment[$key] ?? null) !== null) { throw new PaymentVerificationException('payment_changed'); }
            }
            if ($payment['status'] === 'succeeded') {
                if ($payment['amount_received'] !== $total || $payment['amount_capturable'] !== 0) {
                    throw new PaymentVerificationException('payment_changed');
                }
                // The two GETs are not an atomic provider snapshot. Payment may finish between them.
                if ($session['status'] !== 'complete' || $session['payment_status'] !== 'paid') {
                    throw new PaymentVerificationException('retry');
                }
                $outcome = 'confirmed';
            } elseif ($session['payment_status'] === 'paid') {
                // Conflicting reads are never enough to confirm; a later retry can reconcile them.
                throw new PaymentVerificationException('retry');
            } elseif ($payment['status'] === 'requires_capture') { $outcome = 'authorized'; }
            elseif ($payment['status'] === 'canceled') { $outcome = 'canceled'; }
            $safe = array_intersect_key($payment, array_flip(['id', 'object', 'livemode', 'currency', 'amount',
                'amount_received', 'amount_capturable', 'metadata', 'status', 'capture_method', 'payment_method_types']));
        } elseif ($payment !== null || $session['payment_status'] === 'paid') {
            throw new PaymentVerificationException('retry');
        }
        if ($session['payment_status'] === 'no_payment_required') { throw new PaymentVerificationException('payment_changed'); }

        return ['schema_version' => 1, 'purpose' => 'verified_test_payment_observation', 'source' => $source,
            'receipt_id' => $receiptId, 'account_id' => $request['account_id'], 'mode' => 'test',
            'api_version' => $request['api_version'], 'intent_id' => $request['intent_id'], 'order_id' => $request['order_id'],
            'order_payload_hash' => $request['order_payload_hash'], 'attempt_id' => $request['attempt_id'],
            'session' => $sessionEvidence, 'payment' => $safe, 'outcome' => $outcome,
            'currency' => 'USD', 'amount_minor' => $total];
    }

    public static function paymentId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\Api_[A-Za-z0-9]{1,120}\z/', $id) === 1;
    }

    public function verifyConfirmation(VerifiedPayment $payment, array $request, CheckoutSession $session): void
    {
        $payload = app(CheckoutEvidence::class)->decrypt($payment->evidence_ciphertext, $payment->evidence_hash, $payment->canonicalization_version);
        $amounts = array_column(array_column($request['params']['line_items'], 'price_data'), 'unit_amount');
        $total = array_sum($amounts); sort($amounts);
        $expectedSession = ['schema_version' => 1, 'intent_id' => $request['intent_id'], 'account_id' => $request['account_id'],
            'mode' => 'test', 'session_id' => $session->provider_session_id, 'status' => 'complete', 'provider_payment_status' => 'paid',
            'payment_intent_id' => $payment->provider_payment_intent_id, 'amount_minor' => $total, 'currency' => 'USD',
            'expires_at' => $request['params']['expires_at'], 'line_amounts' => $amounts, 'metadata' => $request['params']['metadata']];
        if (($payload['schema_version'] ?? null) !== 1 || ($payload['purpose'] ?? null) !== 'verified_test_payment_observation'
            || ($payload['outcome'] ?? null) !== 'confirmed' || ($payload['intent_id'] ?? null) !== $request['intent_id']
            || ($payload['order_id'] ?? null) !== $request['order_id'] || ($payload['order_payload_hash'] ?? null) !== $request['order_payload_hash']
            || ($payload['attempt_id'] ?? null) !== $request['attempt_id'] || ($payload['account_id'] ?? null) !== $request['account_id']
            || $payment->account_id !== $request['account_id'] || $payment->mode !== 'test' || $payment->checkout_session_id !== $session->id
            || $session->account_id !== $request['account_id'] || $session->mode !== 'test'
            || $payment->checkout_intent_id !== $session->checkout_intent_id
            || ($payload['mode'] ?? null) !== 'test' || ($payload['api_version'] ?? null) !== $request['api_version']
            || ! in_array($payload['source'] ?? null, ['receipt', 'reconciliation'], true)
            || ($payload['source'] === 'receipt' && (! is_int($payload['receipt_id'] ?? null) || $payload['receipt_id'] < 1))
            || ($payload['source'] === 'reconciliation' && ($payload['receipt_id'] ?? null) !== null)
            || CanonicalJson::encode($payload['session'] ?? null) !== CanonicalJson::encode($expectedSession)
            || ($payload['payment']['id'] ?? null) !== $payment->provider_payment_intent_id
            || ($payload['payment']['object'] ?? null) !== 'payment_intent' || ($payload['payment']['livemode'] ?? null) !== false
            || ($payload['payment']['currency'] ?? null) !== 'usd' || ($payload['payment']['amount'] ?? null) !== $total
            || ($payload['payment']['amount_received'] ?? null) !== $total || ($payload['payment']['amount_capturable'] ?? null) !== 0
            || ($payload['payment']['payment_method_types'] ?? null) !== ['card']
            || ! in_array($payload['payment']['capture_method'] ?? null, ['automatic', 'automatic_async', 'manual'], true)
            || CanonicalJson::encode($payload['payment']['metadata'] ?? null) !== CanonicalJson::encode($request['params']['metadata'])
            || ($payload['payment']['status'] ?? null) !== 'succeeded' || ($payload['amount_minor'] ?? null) !== $payment->amount_minor
            || $payment->amount_minor !== $total
            || ($payload['currency'] ?? null) !== 'USD' || $payment->currency !== 'USD') {
            throw new PaymentVerificationException('payment_changed');
        }
    }
}
