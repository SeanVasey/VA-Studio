<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\OwnAccountStripeGateway;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;

/**
 * Exact Stripe Checkout `automatic_tax` mapping. Tax is never computed here: every tax amount is read from an
 * authoritative provider session and only compared for internal provider consistency and against the approved
 * machine-policy ceiling. No client total, redirect or webhook assertion is accepted.
 */
final class TaxCheckoutEvidence
{
    /** What the buyer previews and then assents to before any provider exists: terms and pre-tax prices only. */
    public static function commitment(array $buyer, array $candidate, TaxExecutionContext $context, array $selection, array $machine): array
    {
        return ['schema_version' => 1, 'purpose' => 'production_tax_checkout_preview', 'buyer_origin_id' => $buyer['origin_id'],
            'buyer_provenance' => $buyer['provenance'], 'candidate' => $candidate, 'execution_context' => $context->binding(),
            'selection_hash' => $selection['selection_hash'], 'seller' => $machine['choices']['seller_identity'],
            'assent' => $machine['choices']['assent'], 'amounts' => self::preTax($selection['selection']['advertised_subtotal_minor'])];
    }

    public static function preTax(int $subtotal): array
    {
        return ['currency' => 'USD', 'subtotal_minor' => $subtotal, 'tax' => 'calculated_by_provider_at_hosted_checkout',
            'total' => 'reviewed_by_buyer_at_hosted_checkout'];
    }

    public static function order(TaxCheckoutRecords $tax, array $record): array
    {
        $body = Evidence::open($record, 'production_tax_checkout_order');
        $context = TaxExecutionContext::retained($body['machine'], $body['execution_context']);
        $subtotal = 0;
        foreach ($body['lines'] as $i => $line) {
            CheckoutException::require($line['position'] === $i + 1 && $line['currency'] === 'USD' && is_int($line['amount_minor'])
                && $line['amount_minor'] === $line['selection']['price_minor'] && $line['selection']['position'] === $line['position']);
            $subtotal += $line['amount_minor'];
        }
        $commitment = self::commitment($body['buyer'], $body['candidate'], $context, $body['selection'], $body['machine']);
        CheckoutException::require($record['buyer_origin_id'] === $body['buyer']['origin_id'] && $record['candidate_id'] === $body['candidate']['candidate_id']
            && $record['request_hash'] === $body['request_hash'] && $body['request_hash'] === CanonicalJson::hash($body['request'])
            && $record['request_key'] === $body['request']['key_digest'] && $body['request']['buyer_origin_id'] === $body['buyer']['origin_id']
            && $body['request']['preview_hash'] === $body['preview_hash'] && $body['preview_hash'] === CanonicalJson::hash($commitment)
            && $body['selection_hash'] === $body['selection']['selection_hash'] && $record['currency'] === 'USD'
            && $record['subtotal_minor'] === $subtotal && $subtotal === $body['selection']['selection']['advertised_subtotal_minor']
            && $record['line_count'] === count($body['lines']) && count($body['lines']) === count($body['selection']['selection']['lines'])
            && CanonicalJson::encode($body['amounts']) === CanonicalJson::encode(self::preTax($subtotal))
            && $body['assent']['accepted'] === true && $body['assent']['preview_hash'] === $body['preview_hash']
            && $body['assent']['accepted_at'] === $record['created_at'] && $body['expires_at'] === CarbonImmutable::parse($record['created_at'])
                ->addSeconds($context->reservationSeconds)->format('Y-m-d\TH:i:s\Z'));
        Evidence::same(['kind' => 'unscoped_nonexclusive', 'selection_hash' => $body['selection_hash']], $body['inventory']);
        Evidence::same([...$body['machine']['choices']['assent'], 'accepted' => true, 'preview_hash' => $body['preview_hash'], 'accepted_at' => $record['created_at']], $body['assent']);

        return ['row' => $record, 'body' => $body, 'context' => $context];
    }

    public static function request(array $order, string $requestId, string $createdAt): array
    {
        $context = $order['context'];
        $at = CarbonImmutable::parse($createdAt);
        $metadata = ['schema_version' => '2', 'producer' => TaxCheckoutPolicy::PRODUCER, 'order_id' => $order['row']['public_id'],
            'order_hash' => $order['row']['payload_hash'], 'request_id' => $requestId,
            'buyer_origin_id' => $order['body']['buyer']['origin_id'], 'funds_mode' => $context->fundsMode];
        $items = [];
        foreach ($order['body']['lines'] as $line) {
            $snapshot = $line['selection']['offer_snapshot'];
            $label = mb_strcut($snapshot['product']['title'].' — '.$snapshot['license']['name'], 0, 240, 'UTF-8');
            $items[] = ['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => $line['amount_minor'], 'tax_behavior' => $context->taxBehavior,
                'product_data' => ['name' => $label, 'metadata' => ['line_id' => $line['public_id'], 'line_hash' => CanonicalJson::hash($line)]]]];
        }
        $return = $context->returnOrigin.'/production/tax-checkout/orders/'.$order['row']['public_id'].'/return';
        // Supported provider-calculated tax: Checkout `automatic_tax`. There is deliberately no
        // `payment_intent_data.tax` key: the pinned schema exposes no such linkage.
        $params = ['mode' => 'payment', 'payment_method_types' => ['card'], 'client_reference_id' => $order['row']['public_id'], 'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata, 'capture_method' => $context->captureMethod], 'line_items' => $items,
            'automatic_tax' => ['enabled' => true], 'adaptive_pricing' => ['enabled' => false], 'allow_promotion_codes' => false,
            'customer_creation' => 'if_required', 'success_url' => $return, 'cancel_url' => $return,
            'expires_at' => $at->addSeconds($context->providerLifetimeSeconds)->timestamp, 'expand' => ['line_items.data.price.product']];

        return ['schema_version' => 1, 'purpose' => 'production_tax_checkout_provider_request', 'public_id' => $requestId, 'created_at' => $createdAt,
            'mapping' => 'provider_calculated_automatic_tax_usd_v2', 'order_public_id' => $order['row']['public_id'],
            'order_payload_hash' => $order['row']['payload_hash'], 'execution_context' => $context->binding(),
            'amounts' => self::preTax($order['row']['subtotal_minor']), 'idempotency_key' => TaxCheckoutSchema::IDEMPOTENCY_PREFIX.$requestId,
            'retry_before' => $at->addSeconds($context->retrySeconds)->format('Y-m-d\TH:i:s\Z'),
            'provider_expires_at' => $at->addSeconds($context->providerLifetimeSeconds)->format('Y-m-d\TH:i:s\Z'), 'params' => $params];
    }

    public static function binding(array $request, string $publicId, string $createdAt, string $sessionId): array
    {
        return ['schema_version' => 1, 'purpose' => 'production_tax_checkout_session_binding', 'public_id' => $publicId, 'created_at' => $createdAt,
            'request_public_id' => $request['row']['public_id'], 'request_hash' => $request['row']['payload_hash'],
            'provider_session_id' => $sessionId, 'execution_context' => $request['body']['execution_context']];
    }

    /** Request with its at-most-one provider binding and at-most-one buyer-reviewed paid session. */
    public static function intent(TaxCheckoutRecords $tax, array $order, array $record): array
    {
        $body = Evidence::open($record, 'production_tax_checkout_provider_request');
        Evidence::same(self::request($order, $record['public_id'], $record['created_at']), $body);
        $context = $order['context'];
        CheckoutException::require($record['order_id'] === $order['row']['id'] && $record['account_id'] === $context->accountId
            && $record['funds_mode'] === $context->fundsMode && $record['idempotency_key'] === $body['idempotency_key']
            && $record['currency'] === 'USD' && $record['subtotal_minor'] === $order['row']['subtotal_minor']
            && $record['tax_behavior'] === $context->taxBehavior && $record['maximum_rate_bps'] === $context->maximumRateBps
            && $record['provider_expires_at'] === $body['provider_expires_at'] && $record['created_at'] >= $order['row']['created_at']
            && $record['provider_expires_at'] <= $order['body']['expires_at']);
        $request = ['row' => $record, 'body' => $body];
        $bindings = $tax->selector('binding', 'request_id = ?', [$record['id']]);
        CheckoutException::require(count($bindings) <= 1);
        $binding = $bindings[0] ?? null;
        if ($binding !== null) {
            Evidence::same(self::binding($request, $binding['public_id'], $binding['created_at'], $binding['provider_session_id']),
                Evidence::open($binding, 'production_tax_checkout_session_binding'));
            CheckoutException::require($binding['account_id'] === $context->accountId && $binding['funds_mode'] === $context->fundsMode
                && $binding['created_at'] >= $record['created_at'] && OwnAccountStripeGateway::sessionId($binding['provider_session_id'], $context->fundsMode));
        }
        $reviews = $tax->selector('reviewed', 'request_id = ?', [$record['id']]);
        CheckoutException::require(count($reviews) <= 1);
        $reviewed = $reviews[0] ?? null;
        $reviewedBody = null;
        if ($reviewed !== null) {
            CheckoutException::require($binding !== null);
            $reviewedBody = Evidence::open($reviewed, 'production_tax_checkout_reviewed_session');
            $financial = self::financial($reviewedBody['provider_session'], $reviewedBody['provider_payment'], $body);
            Evidence::same(self::reviewedBody($order, $request, $binding, $financial, $reviewed['public_id'], $reviewed['created_at'],
                $reviewedBody['observed_at'], $reviewedBody['transport']), $reviewedBody);
            $amounts = $reviewedBody['amounts'];
            CheckoutException::require($financial['outcome'] === 'confirmed' && $reviewed['binding_id'] === $binding['id'] && $reviewed['order_id'] === $order['row']['id']
                && $reviewed['account_id'] === $context->accountId && $reviewed['funds_mode'] === $context->fundsMode
                && $reviewed['provider_session_id'] === $binding['provider_session_id'] && $reviewed['provider_payment_id'] === $financial['payment']['id']
                && $reviewed['currency'] === 'USD' && $reviewed['tax_behavior'] === $context->taxBehavior
                && $reviewed['amount_subtotal_minor'] === $amounts['subtotal_minor'] && $reviewed['amount_tax_minor'] === $amounts['tax_minor']
                && $reviewed['amount_total_minor'] === $amounts['total_minor'] && $reviewed['observed_at'] === $reviewedBody['observed_at']
                && $reviewed['created_at'] >= $binding['created_at']);
        }

        return ['row' => $record, 'body' => $body, 'binding' => $binding, 'reviewed' => $reviewed, 'reviewed_body' => $reviewedBody,
            'raw' => ['request' => $record, 'binding' => $bindings, 'reviewed' => $reviews]];
    }

    public static function reviewedBody(array $order, array $request, array $binding, array $financial, string $publicId, string $createdAt,
        string $observedAt, string $transport): array
    {
        $session = $financial['session'];

        return ['schema_version' => 1, 'purpose' => 'production_tax_checkout_reviewed_session', 'public_id' => $publicId, 'created_at' => $createdAt,
            'order_public_id' => $order['row']['public_id'], 'order_hash' => $order['row']['payload_hash'],
            'request_public_id' => $request['row']['public_id'], 'request_hash' => $request['row']['payload_hash'],
            'binding_public_id' => $binding['public_id'], 'binding_hash' => $binding['payload_hash'],
            'provider_session_id' => $session['session_id'], 'observed_at' => $observedAt,
            'outcome' => $observedAt < $order['body']['expires_at'] ? 'on_time' : 'paid_exception',
            'provider_evidence_origin' => $order['context']->provenance, 'transport' => $transport,
            'amounts' => ['currency' => 'USD', 'tax_behavior' => $session['tax_behavior'], 'subtotal_minor' => $session['amount_subtotal'],
                'tax_minor' => $session['amount_tax'], 'total_minor' => $session['amount_total']],
            'lines' => $session['lines'], 'automatic_tax' => $session['automatic_tax'],
            'provider_session' => $financial['retained_session'], 'provider_payment' => $financial['payment']];
    }

    /** Validates an authoritative session against the retained request; returns only safe facts. */
    public static function session(array $session, array $request): array
    {
        $params = $request['params'];
        $context = $request['execution_context'];
        $mode = $context['funds_mode'];
        $behavior = $context['tax']['behavior'];
        $ceiling = $context['tax']['maximum_rate_bps'];
        $subtotal = $request['amounts']['subtotal_minor'];
        $tax = $session['total_details']['amount_tax'] ?? null;
        $automatic = $session['automatic_tax'] ?? null;
        CheckoutException::require(($session['object'] ?? null) === 'checkout.session' && OwnAccountStripeGateway::sessionId($session['id'] ?? null, $mode)
            && ($session['livemode'] ?? null) === ($mode === 'live') && ($session['account'] ?? null) === null && ($session['context'] ?? null) === null
            && ($session['mode'] ?? null) === 'payment' && ($session['currency'] ?? null) === 'usd' && ($session['client_reference_id'] ?? null) === $request['order_public_id']
            && ($session['amount_subtotal'] ?? null) === $subtotal && is_int($tax) && $tax >= 0 && is_int($session['amount_total'] ?? null)
            && ($session['expires_at'] ?? null) === $params['expires_at'] && ($session['total_details']['amount_discount'] ?? null) === 0
            && in_array($session['total_details']['amount_shipping'] ?? null, [0, null], true)
            && is_array($automatic) && ($automatic['enabled'] ?? null) === true
            && in_array($automatic['liability'] ?? null, [null, ['type' => 'self']], true)
            && in_array($automatic['status'] ?? null, ['complete', 'failed', 'requires_location_inputs', null], true)
            && (($automatic['provider'] ?? null) === null || (is_string($automatic['provider']) && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $automatic['provider']) === 1))
            && ($session['payment_method_types'] ?? null) === ['card']
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
        $lineTax = 0;
        $lineTotal = 0;
        $lines = [];
        foreach ($session['line_items']['data'] as $line) {
            $id = $line['price']['product']['metadata']['line_id'] ?? null;
            CheckoutException::require(is_string($id) && isset($expected[$id]) && ! isset($seen[$id]));
            $target = $expected[$id];
            $amount = $target['unit_amount'];
            $taxed = $line['amount_tax'] ?? null;
            CheckoutException::require(($line['quantity'] ?? null) === 1 && ($line['currency'] ?? null) === 'usd' && ($line['price']['currency'] ?? null) === 'usd'
                && ($line['price']['unit_amount'] ?? null) === $amount && ($line['price']['tax_behavior'] ?? null) === $behavior
                && ($line['amount_subtotal'] ?? null) === $amount && ($line['amount_discount'] ?? null) === 0 && is_int($taxed) && $taxed >= 0
                && ($line['amount_total'] ?? null) === ($behavior === 'exclusive' ? $amount + $taxed : $amount)
                && ($behavior === 'exclusive' || $taxed <= $amount)
                && ($line['price']['product']['name'] ?? null) === $target['product_data']['name']);
            Evidence::same($target['product_data']['metadata'], $line['price']['product']['metadata']);
            $seen[$id] = true;
            $lineTax += $taxed;
            $lineTotal += $line['amount_total'];
            $lines[$id] = ['line_id' => $id, 'subtotal_minor' => $amount, 'tax_minor' => $taxed, 'total_minor' => $line['amount_total']];
        }
        // Internal provider consistency only: the session totals must be the sum of the provider's own line figures.
        CheckoutException::require($lineTax === $tax && $lineTotal === $session['amount_total']
            && $session['amount_total'] === ($behavior === 'exclusive' ? $subtotal + $tax : $subtotal), 'provider_inconsistent');
        // The approved machine-policy ceiling bounds what may be retained; the amount itself is never adjusted.
        $net = $behavior === 'exclusive' ? $subtotal : $subtotal - $tax;
        CheckoutException::require($net >= 0 && $tax * 10000 <= $net * $ceiling, 'tax_ceiling');
        if ($session['status'] === 'complete' || $session['payment_status'] === 'paid') {
            CheckoutException::require($automatic['status'] === 'complete', 'provider_inconsistent');
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
        $ordered = [];
        foreach ($params['line_items'] as $line) {
            $ordered[] = $lines[$line['price_data']['product_data']['metadata']['line_id']];
        }

        return ['session_id' => $session['id'], 'status' => $session['status'], 'payment_status' => $session['payment_status'], 'payment_intent_id' => $payment,
            'currency' => 'USD', 'tax_behavior' => $behavior, 'amount_subtotal' => $subtotal, 'amount_tax' => $tax, 'amount_total' => $session['amount_total'],
            'automatic_tax' => ['enabled' => true, 'status' => $automatic['status'] ?? null, 'provider' => $automatic['provider'] ?? null],
            'lines' => $ordered, 'expires_at' => $params['expires_at'], 'url' => $url];
    }

    public static function financial(array $session, ?array $payment, array $request): array
    {
        $safe = self::session($session, $request);
        $locator = $safe['payment_intent_id'];
        $outcome = $safe['status'] === 'expired' ? 'expired' : 'pending';
        $safePayment = null;
        $total = $safe['amount_total'];
        if ($locator !== null) {
            $context = $request['execution_context'];
            CheckoutException::require($payment !== null && ($payment['object'] ?? null) === 'payment_intent' && ($payment['id'] ?? null) === $locator
                && ($payment['livemode'] ?? null) === ($context['funds_mode'] === 'live') && ($payment['currency'] ?? null) === 'usd'
                && ($payment['amount'] ?? null) === $total && is_int($payment['amount_received'] ?? null) && $payment['amount_received'] >= 0 && $payment['amount_received'] <= $total
                && is_int($payment['amount_capturable'] ?? null) && $payment['amount_capturable'] >= 0 && $payment['amount_capturable'] <= $total
                && ($payment['capture_method'] ?? null) === $context['capture_method'] && ($payment['payment_method_types'] ?? null) === ['card']
                && in_array($payment['status'] ?? null, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing', 'requires_capture', 'canceled', 'succeeded'], true));
            Evidence::same($request['params']['payment_intent_data']['metadata'], $payment['metadata'] ?? null);
            foreach (['account', 'context', 'application', 'application_fee_amount', 'on_behalf_of', 'transfer_data', 'transfer_group', 'setup_future_usage'] as $field) {
                CheckoutException::require(($payment[$field] ?? null) === null);
            }
            if ($payment['status'] === 'succeeded') {
                CheckoutException::require($payment['amount_received'] === $total && $payment['amount_capturable'] === 0
                    && $safe['status'] === 'complete' && $safe['payment_status'] === 'paid' && $safe['automatic_tax']['status'] === 'complete', 'provider_inconsistent');
                $outcome = 'confirmed';
            } else {
                CheckoutException::require($safe['payment_status'] !== 'paid', 'provider_inconsistent');
                $outcome = $payment['status'] === 'requires_capture' ? 'authorized' : ($payment['status'] === 'canceled' ? 'canceled' : 'pending');
            }
            $safePayment = array_intersect_key($payment, array_flip(['id', 'object', 'livemode', 'currency', 'amount', 'amount_received', 'amount_capturable',
                'capture_method', 'payment_method_types', 'status', 'metadata']));
        } else {
            CheckoutException::require($payment === null && $safe['payment_status'] !== 'paid', 'provider_inconsistent');
        }

        return ['session' => $safe, 'payment' => $safePayment, 'outcome' => $outcome, 'retained_session' => self::retainSession($session)];
    }

    /** Exactly the fields `session()` reads. No customer details, address, email or tax identifiers are retained. */
    public static function retainSession(array $session): array
    {
        $safe = array_intersect_key($session, array_flip(['id', 'object', 'livemode', 'mode', 'currency', 'client_reference_id', 'metadata',
            'amount_subtotal', 'amount_total', 'expires_at', 'payment_method_types', 'status', 'payment_status', 'payment_intent', 'url']));
        $safe['total_details'] = array_intersect_key($session['total_details'], array_flip(['amount_discount', 'amount_tax', 'amount_shipping']));
        $safe['automatic_tax'] = array_intersect_key($session['automatic_tax'], array_flip(['enabled', 'liability', 'status', 'provider']));
        $safe['line_items'] = ['object' => 'list', 'has_more' => false, 'data' => []];
        foreach ($session['line_items']['data'] as $line) {
            $entry = array_intersect_key($line, array_flip(['quantity', 'currency', 'amount_subtotal', 'amount_total', 'amount_tax', 'amount_discount']));
            $entry['price'] = ['currency' => $line['price']['currency'], 'unit_amount' => $line['price']['unit_amount'], 'tax_behavior' => $line['price']['tax_behavior'],
                'product' => ['name' => $line['price']['product']['name'], 'metadata' => $line['price']['product']['metadata']]];
            $safe['line_items']['data'][] = $entry;
        }

        return $safe;
    }
}
