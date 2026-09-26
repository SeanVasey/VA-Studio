<?php

namespace App\Domain\Commerce\Checkout;

use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\QuoteException;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Versioned provider mapping; no payment confirmation or rights decision. */
final class CheckoutEvidence
{
    public function request(Order $order, array $orderEvidence, string $intentId, array $policy, string $account, CarbonImmutable $at): array
    {
        app(CheckoutPolicy::class)->validate($policy);
        $pricing = $orderEvidence['pricing']['snapshot'];
        if ($pricing['currency'] !== 'USD' || $pricing['tax_status'] !== 'fixed_test' || $pricing['tax_minor'] !== 0 ||
            ($pricing['tax_policy']['tax']['rate_bps'] ?? null) !== 0 ||
            ($pricing['tax_policy']['provider'] ?? null) !== 'stripe' || ($pricing['tax_policy']['account'] ?? null) !== $account ||
            ! is_int($pricing['total_minor']) || $pricing['total_minor'] < 50 || $pricing['total_minor'] > 99999999) {
            throw new QuoteException('CHECKOUT_UNSUPPORTED', 409);
        }
        $items = [];
        foreach ($pricing['lines'] as $position => $line) {
            if ($line['tax_minor'] !== 0 || $line['total_minor'] !== $line['tax_basis_minor'] || $line['quantity'] !== 1) {
                throw new QuoteException('CHECKOUT_UNSUPPORTED', 409);
            }
            $items[] = ['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => $line['tax_basis_minor'],
                'product_data' => ['name' => 'VASEY.AUDIO test license '.($position + 1)]]];
        }
        $metadata = ['order_id' => $order->public_id, 'attempt_id' => $orderEvidence['attempt_id'], 'intent_id' => $intentId, 'schema_version' => '1'];
        $return = $policy['return_origin'].'/orders/'.$order->public_id.'/checkout/return';
        $params = ['mode' => 'payment', 'payment_method_types' => ['card'], 'client_reference_id' => $order->public_id,
            'metadata' => $metadata, 'payment_intent_data' => ['metadata' => $metadata], 'line_items' => $items,
            'automatic_tax' => ['enabled' => false], 'adaptive_pricing' => ['enabled' => false], 'allow_promotion_codes' => false,
            'customer_creation' => 'if_required', 'success_url' => $return, 'cancel_url' => $return,
            'expires_at' => $at->addSeconds($policy['provider_lifetime_seconds'])->timestamp, 'expand' => ['line_items']];

        return ['schema_version' => 1, 'purpose' => 'stripe_test_checkout', 'mapping' => 'post_discount_zero_test_tax_v1',
            'api_version' => CheckoutPolicy::API_VERSION, 'order_id' => $order->public_id,
            'order_payload_hash' => $order->payload_hash, 'attempt_id' => $orderEvidence['attempt_id'], 'intent_id' => $intentId,
            'account_id' => $account, 'mode' => 'test', 'policy' => $policy, 'params' => $params];
    }

    public function verifyIntent(CheckoutIntent $intent, Order $order, ?array $verifiedOrderEvidence = null): array
    {
        $payload = $this->decrypt($intent->request_ciphertext, $intent->request_hash, $intent->canonicalization_version);
        // The finalization reader already verified the original order; this avoids recursive graph reads.
        $evidence = $verifiedOrderEvidence ?? app(ReadOrder::class)->verify($order);
        $expected = $this->request($order, $evidence, $intent->public_id, $payload['policy'] ?? [], $intent->account_id, $intent->created_at);
        if ($intent->order_id !== $order->id || $intent->order_attempt_id !== $order->attempt()->sole()->id || $intent->mode !== 'test' ||
            ! $intent->initiate_before->equalTo(CarbonImmutable::parse($evidence['attempt']['expires_at'])) ||
            ! $intent->created_at->lessThan($intent->initiate_before) || $intent->created_at->lessThan($order->created_at) ||
            ! $intent->retry_before->equalTo($intent->created_at->addSeconds(900)) ||
            ! $intent->provider_expires_at->equalTo($intent->created_at->addSeconds(3600)) ||
            $intent->idempotency_key !== 'vasey-checkout-v1-'.$intent->public_id || CanonicalJson::encode($expected) !== CanonicalJson::encode($payload)) {
            throw new QuoteException('CHECKOUT_CHANGED', 409);
        }

        return $expected;
    }

    public function encrypt(array $payload): array
    {
        $canonical = CanonicalJson::encode($payload);
        if (strlen($canonical) > 262144) { throw new QuoteException('CHECKOUT_CHANGED', 409); }
        $ciphertext = Crypt::encryptString($canonical);

        return [$ciphertext, hash('sha256', $ciphertext)];
    }

    public function decrypt(string $ciphertext, string $hash, string $version): array
    {
        try {
            if ($version !== CanonicalJson::VERSION || ! hash_equals($hash, hash('sha256', $ciphertext))) { throw new \UnexpectedValueException; }
            $canonical = Crypt::decryptString($ciphertext);
            if (strlen($canonical) > 262144) { throw new \UnexpectedValueException; }
            $payload = json_decode($canonical, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || CanonicalJson::encode($payload) !== $canonical) { throw new \UnexpectedValueException; }

            return $payload;
        } catch (Throwable) { throw new QuoteException('CHECKOUT_CHANGED', 409); }
    }

    public function session(array $session, array $request): array
    {
        $params = $request['params'];
        $total = array_sum(array_column(array_column($params['line_items'], 'price_data'), 'unit_amount'));
        $id = $session['id'] ?? null; $status = $session['status'] ?? null;
        if (! is_string($id) || ! preg_match('/\Acs_test_[A-Za-z0-9]{1,120}\z/', $id) ||
            ($session['object'] ?? null) !== 'checkout.session' || ($session['livemode'] ?? null) !== false ||
            ($session['account'] ?? null) !== null || ($session['context'] ?? null) !== null ||
            ($session['mode'] ?? null) !== 'payment' || ($session['currency'] ?? null) !== 'usd' ||
            ($session['client_reference_id'] ?? null) !== $request['order_id'] ||
            CanonicalJson::encode($session['metadata'] ?? null) !== CanonicalJson::encode($params['metadata']) ||
            ($session['amount_subtotal'] ?? null) !== $total || ($session['amount_total'] ?? null) !== $total ||
            ($session['expires_at'] ?? null) !== $params['expires_at'] ||
            ($session['total_details']['amount_discount'] ?? null) !== 0 || ($session['total_details']['amount_tax'] ?? null) !== 0 ||
            ($session['total_details']['amount_shipping'] ?? null) !== 0 || ($session['automatic_tax']['enabled'] ?? null) !== false ||
            ($session['payment_method_types'] ?? null) !== ['card'] ||
            ! in_array($status, ['open', 'complete', 'expired'], true) ||
            ! in_array($session['payment_status'] ?? null, ['paid', 'unpaid', 'no_payment_required'], true) ||
            ($session['line_items']['has_more'] ?? null) !== false || ! is_array($session['line_items']['data'] ?? null) ||
            count($session['line_items']['data']) !== count($params['line_items'])) {
            throw new QuoteException('CHECKOUT_CHANGED', 409);
        }
        $expected = array_column(array_column($params['line_items'], 'price_data'), 'unit_amount'); $actual = [];
        foreach ($session['line_items']['data'] as $line) {
            $amount = $line['price']['unit_amount'] ?? null;
            if (! is_int($amount) || ($line['quantity'] ?? null) !== 1 || ($line['currency'] ?? null) !== 'usd' ||
                ($line['price']['currency'] ?? null) !== 'usd' || ($line['amount_subtotal'] ?? null) !== $amount ||
                ($line['amount_total'] ?? null) !== $amount || ($line['amount_discount'] ?? null) !== 0 || ($line['amount_tax'] ?? null) !== 0) {
                throw new QuoteException('CHECKOUT_CHANGED', 409);
            }
            $actual[] = $amount;
        }
        sort($actual); sort($expected);
        if ($actual !== $expected) { throw new QuoteException('CHECKOUT_CHANGED', 409); }
        $url = $session['url'] ?? null;
        if (($status === 'open' && ! $this->url($url, $id)) || ($status !== 'open' && $url !== null)) {
            throw new QuoteException('CHECKOUT_CHANGED', 409);
        }
        $payment = $session['payment_intent'] ?? null;
        if (is_array($payment)) { $payment = $payment['id'] ?? false; }
        if ($payment !== null && (! is_string($payment) || ! preg_match('/\Api_[A-Za-z0-9]{1,120}\z/', $payment))) {
            throw new QuoteException('CHECKOUT_CHANGED', 409);
        }
        // Whitelist retained fields. Provider customer details and raw error bodies are never copied.
        return ['schema_version' => 1, 'intent_id' => $request['intent_id'], 'account_id' => $request['account_id'],
            'mode' => 'test', 'session_id' => $id, 'status' => $status, 'provider_payment_status' => $session['payment_status'],
            'payment_intent_id' => $payment, 'amount_minor' => $total, 'currency' => 'USD', 'expires_at' => $params['expires_at'],
            'url' => $url, 'line_amounts' => $actual, 'metadata' => $params['metadata']];
    }

    public function url(mixed $url, ?string $sessionId = null): bool
    {
        if (! is_string($url) || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return false; }
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'checkout.stripe.com' &&
            ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']) &&
            str_starts_with($parts['path'] ?? '', '/c/pay/cs_test_') &&
            ($sessionId === null || ($parts['path'] ?? null) === '/c/pay/'.$sessionId);
    }
}
