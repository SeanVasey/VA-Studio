<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;

/** Exact hosted mapping for qualified zero-tax orders; no client total, redirect or webhook assertion is accepted. */
final class HostedEvidence
{
    public static function requireGateway(ProviderGateway $gateway, ExecutionContextV1 $context): string
    {
        $provenance = $gateway->provenance($context);
        CheckoutException::require($context->fundsMode === 'test' ? $provenance === 'synthetic_rehearsal'
            : $gateway instanceof OwnAccountStripeGateway && $provenance === 'own_account_sdk', 'provider', 503);

        return $provenance;
    }

    public static function request(array $order, string $intentId, string $createdAt): array
    {
        $context = $order['context'];
        $at = CarbonImmutable::parse($createdAt);
        $metadata = ['schema_version' => '1', 'producer' => 'production_checkout_v1', 'order_id' => $order['row']['public_id'],
            'order_hash' => $order['row']['payload_hash'], 'attempt_id' => $order['attempt']['public_id'], 'intent_id' => $intentId,
            'buyer_origin_id' => $order['body']['buyer']['origin_id'], 'funds_mode' => $context->fundsMode];
        $items = [];
        foreach ($order['body']['lines'] as $line) {
            CheckoutException::require($line['currency'] === 'USD' && $line['tax_minor'] === 0 && $line['total_minor'] === $line['amount_minor']);
            $snapshot = $line['selection']['offer_snapshot'];
            $label = mb_strcut($snapshot['product']['title'].' — '.$snapshot['license']['name'], 0, 240, 'UTF-8');
            $items[] = ['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => $line['amount_minor'],
                'product_data' => ['name' => $label,
                    'metadata' => ['line_id' => $line['public_id'], 'line_hash' => CanonicalJson::hash($line)]]]];
        }
        $return = $context->returnOrigin.'/production/checkout/orders/'.$order['row']['public_id'].'/return';
        $params = ['mode' => 'payment', 'payment_method_types' => ['card'], 'client_reference_id' => $order['row']['public_id'], 'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata, 'capture_method' => $context->captureMethod], 'line_items' => $items,
            'automatic_tax' => ['enabled' => false], 'adaptive_pricing' => ['enabled' => false], 'allow_promotion_codes' => false,
            'customer_creation' => 'if_required', 'success_url' => $return, 'cancel_url' => $return,
            'expires_at' => $at->addSeconds($context->providerLifetimeSeconds)->timestamp, 'expand' => ['line_items.data.price.product']];

        return ['schema_version' => 1, 'purpose' => 'production_checkout_provider_intent', 'public_id' => $intentId, 'created_at' => $createdAt,
            'mapping' => 'qualified_exemption_exact_usd_v1', 'order_public_id' => $order['row']['public_id'], 'order_payload_hash' => $order['row']['payload_hash'],
            'attempt_id' => $order['attempt']['public_id'], 'execution_context' => $context->binding(), 'amounts' => $order['body']['amounts'],
            'idempotency_key' => 'va-production-checkout-v1-'.$intentId, 'params' => $params];
    }

    public static function intent(Records $rows, array $order, array $record): array
    {
        $body = Evidence::open($record, 'production_checkout_provider_intent');
        Evidence::same(self::request($order, $record['public_id'], $record['created_at']), $body);
        $context = $order['context'];
        $created = CarbonImmutable::parse($record['created_at']);
        CheckoutException::require($record['order_id'] === $order['row']['id'] && $record['attempt_id'] === $order['attempt']['id']
            && $record['account_id'] === $context->accountId && $record['funds_mode'] === $context->fundsMode
            && $record['idempotency_key'] === $body['idempotency_key']
            && $record['retry_before'] === $created->addSeconds($context->retrySeconds)->format('Y-m-d\TH:i:s\Z')
            && $record['provider_expires_at'] === $created->addSeconds($context->providerLifetimeSeconds)->format('Y-m-d\TH:i:s\Z')
            && $record['created_at'] >= $order['row']['created_at'] && $record['provider_expires_at'] <= $order['attempt']['expires_at']);
        $sessions = $rows->selector('session', 'intent_id = ?', [$record['id']]);
        CheckoutException::require(count($sessions) <= 1);
        $session = $sessions[0] ?? null;
        if ($session !== null) {
            $binding = Evidence::open($session, 'production_checkout_session_binding');
            Evidence::same(['schema_version' => 1, 'purpose' => 'production_checkout_session_binding', 'public_id' => $session['public_id'],
                'created_at' => $session['created_at'], 'intent_public_id' => $record['public_id'], 'intent_hash' => $record['payload_hash'],
                'provider_session_id' => $session['provider_session_id'], 'execution_context' => $context->binding()], $binding);
            CheckoutException::require($session['account_id'] === $context->accountId && $session['funds_mode'] === $context->fundsMode
                && OwnAccountStripeGateway::sessionId($session['provider_session_id'], $context->fundsMode));
        }
        $observations = $rows->selector('observation', 'intent_id = ?', [$record['id']], 129);
        CheckoutException::require(count($observations) <= 128);
        $last = null;
        $lastKnown = null;
        foreach ($observations as $i => $observation) {
            $observed = Evidence::open($observation, 'production_checkout_observation');
            CheckoutException::require($observation['sequence'] === $i + 1 && $observed['sequence'] === $i + 1 && $observed['intent_id'] === $record['public_id']
                && $observed['intent_hash'] === $record['payload_hash'] && $observed['kind'] === $observation['kind']);
            if ($observed['kind'] === 'uncertain') {
                Evidence::same(['code' => 'provider_unavailable'], $observed['detail']);
            } else {
                CheckoutException::require($session !== null && $observed['detail']['session']['id'] === $session['provider_session_id']
                    && $observed['provider_evidence_origin'] === ($context->fundsMode === 'live' ? 'own_account_sdk' : 'synthetic_rehearsal'));
                $financial = self::financial($observed['detail']['session'], $observed['detail']['payment'], $body);
                CheckoutException::require($observed['kind'] === self::observationKind($financial));
                if ($lastKnown !== null) {
                    $previousStatus = $lastKnown['detail']['session']['status'];
                    CheckoutException::require(! in_array($previousStatus, ['complete', 'expired'], true)
                        || $observed['detail']['session']['status'] === $previousStatus, 'terminal_regression');
                }
                $lastKnown = $observed;
            }
            $last = $observed;
        }
        $payments = $rows->selector('payment', 'intent_id = ?', [$record['id']]);
        CheckoutException::require(count($payments) <= 1);
        $payment = $payments[0] ?? null;
        if ($payment !== null) {
            CheckoutException::require($session !== null);
            $confirmed = Evidence::open($payment, 'production_checkout_confirmed_payment');
            $financial = self::financial($confirmed['provider_session'], $confirmed['provider_payment'], $body);
            CheckoutException::require($financial['outcome'] === 'confirmed' && $payment['order_id'] === $order['row']['id']
                && $payment['session_id'] === $session['id'] && $payment['account_id'] === $context->accountId && $payment['funds_mode'] === $context->fundsMode
                && $payment['provider_payment_id'] === $financial['payment']['id'] && $confirmed['session_id'] === $session['provider_session_id']
                && $confirmed['intent_id'] === $record['public_id'] && $confirmed['intent_hash'] === $record['payload_hash']
                && $confirmed['order_id'] === $order['row']['public_id'] && $confirmed['order_hash'] === $order['row']['payload_hash']
                && $confirmed['observed_at'] === $payment['observed_at'] && $confirmed['amount_minor'] === $order['body']['amounts']['total_minor']
                && $payment['amount_minor'] === $confirmed['amount_minor'] && $payment['outcome'] === $confirmed['outcome']
                && $payment['outcome'] === ($payment['observed_at'] < $order['attempt']['expires_at'] ? 'on_time' : 'paid_exception')
                && $confirmed['provider_evidence_origin'] === ($context->fundsMode === 'live' ? 'own_account_sdk' : 'synthetic_rehearsal'));
        }

        return ['row' => $record, 'body' => $body, 'session' => $session, 'observations' => $observations, 'last' => $last, 'last_known' => $lastKnown, 'payment' => $payment,
            'raw' => ['intent' => $record, 'session' => $sessions, 'observations' => $observations, 'payment' => $payments]];
    }

    public static function session(array $session, array $request): array
    {
        $params = $request['params'];
        $mode = $request['execution_context']['funds_mode'];
        $total = $request['amounts']['total_minor'];
        CheckoutException::require(($session['object'] ?? null) === 'checkout.session' && OwnAccountStripeGateway::sessionId($session['id'] ?? null, $mode)
            && ($session['livemode'] ?? null) === ($mode === 'live') && ($session['account'] ?? null) === null && ($session['context'] ?? null) === null
            && ($session['mode'] ?? null) === 'payment' && ($session['currency'] ?? null) === 'usd' && ($session['client_reference_id'] ?? null) === $request['order_public_id']
            && ($session['amount_subtotal'] ?? null) === $total && ($session['amount_total'] ?? null) === $total
            && ($session['expires_at'] ?? null) === $params['expires_at'] && ($session['total_details']['amount_discount'] ?? null) === 0
            && ($session['total_details']['amount_tax'] ?? null) === 0 && ($session['total_details']['amount_shipping'] ?? null) === 0
            && ($session['automatic_tax']['enabled'] ?? null) === false && ($session['payment_method_types'] ?? null) === ['card']
            && in_array($session['status'] ?? null, ['open', 'complete', 'expired'], true)
            && in_array($session['payment_status'] ?? null, ['paid', 'unpaid'], true)
            && ($session['line_items']['object'] ?? null) === 'list' && ($session['line_items']['has_more'] ?? null) === false
            && is_array($session['line_items']['data'] ?? null) && array_is_list($session['line_items']['data'])
            && count($session['line_items']['data']) === count($params['line_items']));
        Evidence::same($params['metadata'], $session['metadata'] ?? null);
        $expected = [];
        foreach ($params['line_items'] as $line) {
            $expected[$line['price_data']['product_data']['metadata']['line_id']] = $line['price_data'];
        }
        $seen = [];
        foreach ($session['line_items']['data'] as $line) {
            $id = $line['price']['product']['metadata']['line_id'] ?? null;
            CheckoutException::require(is_string($id) && isset($expected[$id]) && ! isset($seen[$id]));
            $target = $expected[$id];
            $amount = $target['unit_amount'];
            CheckoutException::require(($line['quantity'] ?? null) === 1 && ($line['currency'] ?? null) === 'usd' && ($line['price']['currency'] ?? null) === 'usd'
                && ($line['price']['unit_amount'] ?? null) === $amount && ($line['amount_subtotal'] ?? null) === $amount && ($line['amount_total'] ?? null) === $amount
                && ($line['amount_tax'] ?? null) === 0 && ($line['amount_discount'] ?? null) === 0
                && ($line['price']['product']['name'] ?? null) === $target['product_data']['name']);
            Evidence::same($target['product_data']['metadata'], $line['price']['product']['metadata']);
            $seen[$id] = true;
        }
        $payment = $session['payment_intent'] ?? null;
        CheckoutException::require($payment === null || (is_string($payment) && preg_match('/\Api_[A-Za-z0-9]{1,120}\z/D', $payment) === 1));
        $url = $session['url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        CheckoutException::require($session['status'] === 'open'
            ? is_string($url) && strlen($url) <= 4096 && ! preg_match('/[\x00-\x20\x7f\\\\]/', $url) && is_array($parts)
                && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'checkout.stripe.com'
                && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']) && ($parts['path'] ?? null) === '/c/pay/'.$session['id']
            : $url === null);

        return ['session_id' => $session['id'], 'status' => $session['status'], 'payment_status' => $session['payment_status'], 'payment_intent_id' => $payment,
            'amount_minor' => $total, 'currency' => 'USD', 'expires_at' => $params['expires_at'], 'url' => $url];
    }

    public static function financial(array $session, ?array $payment, array $request): array
    {
        $safeSession = self::session($session, $request);
        $locator = $safeSession['payment_intent_id'];
        $outcome = $safeSession['status'] === 'expired' ? 'expired' : 'pending';
        $safePayment = null;
        if ($locator !== null) {
            $total = $request['amounts']['total_minor'];
            CheckoutException::require($payment !== null && ($payment['object'] ?? null) === 'payment_intent' && ($payment['id'] ?? null) === $locator
                && ($payment['livemode'] ?? null) === ($request['execution_context']['funds_mode'] === 'live') && ($payment['currency'] ?? null) === 'usd'
                && ($payment['amount'] ?? null) === $total && is_int($payment['amount_received'] ?? null) && $payment['amount_received'] >= 0 && $payment['amount_received'] <= $total
                && is_int($payment['amount_capturable'] ?? null) && $payment['amount_capturable'] >= 0 && $payment['amount_capturable'] <= $total
                && ($payment['capture_method'] ?? null) === $request['execution_context']['capture_method'] && ($payment['payment_method_types'] ?? null) === ['card']
                && in_array($payment['status'] ?? null, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing', 'requires_capture', 'canceled', 'succeeded'], true));
            Evidence::same($request['params']['payment_intent_data']['metadata'], $payment['metadata'] ?? null);
            foreach (['account', 'context', 'application', 'application_fee_amount', 'on_behalf_of', 'transfer_data', 'transfer_group', 'setup_future_usage'] as $field) {
                CheckoutException::require(($payment[$field] ?? null) === null);
            }
            if ($payment['status'] === 'succeeded') {
                CheckoutException::require($payment['amount_received'] === $total && $payment['amount_capturable'] === 0
                    && $safeSession['status'] === 'complete' && $safeSession['payment_status'] === 'paid', 'provider_inconsistent');
                $outcome = 'confirmed';
            } else {
                CheckoutException::require($safeSession['payment_status'] !== 'paid', 'provider_inconsistent');
                $outcome = $payment['status'] === 'requires_capture' ? 'authorized' : ($payment['status'] === 'canceled' ? 'canceled' : 'pending');
            }
            $safePayment = array_intersect_key($payment, array_flip(['id', 'object', 'livemode', 'currency', 'amount', 'amount_received', 'amount_capturable',
                'capture_method', 'payment_method_types', 'status', 'metadata']));
        } else {
            CheckoutException::require($payment === null && $safeSession['payment_status'] !== 'paid', 'provider_inconsistent');
        }

        return ['session' => $safeSession, 'payment' => $safePayment, 'outcome' => $outcome];
    }

    public static function proveRetained(Records $rows, array $expected): void
    {
        Evidence::same($expected['intent'], $rows->current->one(CheckoutSchema::TABLES['intent'], $expected['intent']['id']));
        foreach (['session', 'observation' => 'observations', 'payment'] as $key => $value) {
            $kind = is_int($key) ? $value : $key;
            Evidence::same($expected[$value], $rows->selector($kind, 'intent_id = ?', [$expected['intent']['id']], $kind === 'observation' ? 129 : 2));
        }
    }

    public static function observationKind(array $financial): string
    {
        return match ($financial['outcome']) {
            'confirmed' => 'payment_confirmed', 'authorized' => 'authorized', 'canceled' => 'canceled',
            default => 'session_'.$financial['session']['status'],
        };
    }

    /** Retain only the exact fields needed to rerun financial verification; exclude provider customer details. */
    public static function retainSession(array $session): array
    {
        $safe = array_intersect_key($session, array_flip(['id', 'object', 'livemode', 'mode', 'currency', 'client_reference_id', 'metadata',
            'amount_subtotal', 'amount_total', 'expires_at', 'total_details', 'automatic_tax', 'payment_method_types', 'status', 'payment_status', 'payment_intent', 'url']));
        $safe['total_details'] = array_intersect_key($session['total_details'], array_flip(['amount_discount', 'amount_tax', 'amount_shipping']));
        $safe['automatic_tax'] = ['enabled' => $session['automatic_tax']['enabled']];
        $safe['line_items'] = ['object' => 'list', 'has_more' => false, 'data' => []];
        foreach ($session['line_items']['data'] as $line) {
            $entry = array_intersect_key($line, array_flip(['quantity', 'currency', 'amount_subtotal', 'amount_total', 'amount_tax', 'amount_discount']));
            $entry['price'] = ['currency' => $line['price']['currency'], 'unit_amount' => $line['price']['unit_amount'],
                'product' => ['name' => $line['price']['product']['name'], 'metadata' => $line['price']['product']['metadata']]];
            $safe['line_items']['data'][] = $entry;
        }

        return $safe;
    }
}
