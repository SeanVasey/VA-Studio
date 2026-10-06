<?php

namespace App\Domain\Commerce\UnpaidRelease;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Payments\PaymentEvidence;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Support\CanonicalJson;
use RuntimeException;

/** Terminal facts from bounded GETs, never an atomic provider snapshot or a timeout inference. */
final class UnpaidReleaseEvidence
{
    public function inspect(string $sessionId, array $request): array
    {
        UnpaidReleasePolicy::outsideTransactions();
        $gateway = app(StripePaymentGateway::class);
        $account = $gateway->account();
        if (($account['object'] ?? null) !== 'account' || ($account['id'] ?? null) !== $request['account_id']) {
            throw new RuntimeException('Test unpaid evidence changed.');
        }
        $before = $gateway->retrieve($sessionId);
        $paymentId = $before['payment_intent'] ?? null;
        if ($paymentId !== null && ! PaymentEvidence::paymentId($paymentId)) {
            throw new RuntimeException('Test unpaid evidence changed.');
        }
        $payment = $paymentId === null ? null : $gateway->paymentIntent($paymentId);
        $after = $gateway->retrieve($sessionId);
        if (($before['id'] ?? null) !== $sessionId || ($after['id'] ?? null) !== $sessionId) {
            throw new RuntimeException('Test unpaid evidence changed.');
        }
        $this->validate(['before' => $before, 'payment' => $payment, 'after' => $after], $request);
        // Minimize before retaining: no customer/contact fields, URLs, SDK bodies or client secrets.
        $safe = ['before' => $this->session($before), 'payment' => $payment === null ? null : array_intersect_key($payment,
            array_flip(['id', 'object', 'livemode', 'currency', 'amount', 'amount_received', 'amount_capturable',
                'metadata', 'status', 'capture_method', 'payment_method_types'])), 'after' => $this->session($after)];
        $this->validate($safe, $request);

        return $safe;
    }

    public function validate(array $observation, array $request): void
    {
        if (count($observation) !== 3 || ! is_array($observation['before'] ?? null)
            || ! is_array($observation['after'] ?? null) || ! array_key_exists('payment', $observation)
            || ($observation['payment'] !== null && ! is_array($observation['payment']))) {
            throw new RuntimeException('Test unpaid evidence changed.');
        }
        $sessions = [];
        foreach (['before', 'after'] as $key) {
            $raw = $observation[$key];
            $sessions[] = app(CheckoutEvidence::class)->session($raw, $request);
            if (($raw['status'] ?? null) !== 'expired' || ($raw['payment_status'] ?? null) !== 'unpaid') {
                throw new UnpaidOutcomeException;
            }
            foreach (['payment_intent', 'after_expiration', 'recovered_from'] as $field) {
                if (! array_key_exists($field, $raw)) {
                    throw new RuntimeException('Test unpaid evidence changed.');
                }
            }
            if ($raw['after_expiration'] !== null || $raw['recovered_from'] !== null) {
                throw new UnpaidOutcomeException;
            }
            app(PaymentEvidence::class)->capture($raw, $observation['payment'], $request, 'reconciliation', null);
        }
        if (CanonicalJson::encode($sessions[0]) !== CanonicalJson::encode($sessions[1])) {
            throw new RuntimeException('Test unpaid evidence changed.');
        }
        $payment = $observation['payment'];
        if ($payment !== null && (($payment['status'] ?? null) !== 'canceled'
            || ($payment['amount_received'] ?? null) !== 0 || ($payment['amount_capturable'] ?? null) !== 0)) {
            throw new UnpaidOutcomeException;
        }
    }

    private function session(array $session): array
    {
        $safe = array_intersect_key($session, array_flip(['id', 'object', 'livemode', 'mode', 'currency',
            'client_reference_id', 'metadata', 'amount_subtotal', 'amount_total', 'expires_at', 'payment_method_types',
            'status', 'payment_status', 'payment_intent', 'url', 'after_expiration', 'recovered_from']));
        $safe['total_details'] = array_intersect_key($session['total_details'], array_flip(['amount_discount', 'amount_tax', 'amount_shipping']));
        $safe['automatic_tax'] = ['enabled' => $session['automatic_tax']['enabled']];
        $safe['line_items'] = ['has_more' => false, 'data' => array_map(fn ($line) => array_intersect_key($line,
            array_flip(['quantity', 'currency', 'amount_subtotal', 'amount_total', 'amount_discount', 'amount_tax']))
            + ['price' => array_intersect_key($line['price'], array_flip(['unit_amount', 'currency']))], $session['line_items']['data'])];

        return $safe;
    }
}
