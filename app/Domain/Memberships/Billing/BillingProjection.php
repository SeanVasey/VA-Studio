<?php

namespace App\Domain\Memberships\Billing;

/**
 * Allowlisted projections of the locked SDK models' toArray() output. Only fields the settlement
 * evaluator reads are retained; secrets (client_secret, confirmation_secret), customer contact
 * details, URLs and payment-method details are dropped. Expanded references collapse to their id.
 */
final class BillingProjection
{
    private const FIELDS = [
        'account' => ['id', 'object'],
        'invoice' => ['id', 'object', 'livemode', 'status', 'customer', 'currency', 'parent', 'lines', 'total', 'subtotal', 'amount_due',
            'amount_paid', 'amount_remaining', 'amount_overpaid', 'amount_paid_off_stripe', 'discounts', 'total_discount_amounts',
            'total_pretax_credit_amounts', 'total_taxes', 'starting_balance', 'ending_balance', 'pre_payment_credit_notes_amount',
            'post_payment_credit_notes_amount', 'on_behalf_of', 'application', 'billing_reason', 'collection_method'],
        'line_item' => ['id', 'object', 'livemode', 'amount', 'subtotal', 'currency', 'discounts', 'discount_amounts', 'pretax_credit_amounts',
            'taxes', 'parent', 'pricing', 'period', 'quantity', 'invoice'],
        'invoice_payment' => ['id', 'object', 'livemode', 'invoice', 'status', 'amount_paid', 'amount_requested', 'currency', 'payment', 'is_default'],
        'payment_intent' => ['id', 'object', 'livemode', 'status', 'amount', 'amount_received', 'currency', 'customer', 'latest_charge',
            'on_behalf_of', 'transfer_data', 'application', 'application_fee_amount'],
        'charge' => ['id', 'object', 'livemode', 'status', 'paid', 'captured', 'refunded', 'amount', 'amount_captured', 'amount_refunded',
            'disputed', 'currency', 'customer', 'payment_intent', 'balance_transaction', 'on_behalf_of', 'transfer_data', 'application',
            'application_fee_amount'],
        'balance_transaction' => ['id', 'object', 'amount', 'currency', 'exchange_rate', 'status', 'type', 'source'],
        'subscription' => ['id', 'object', 'livemode', 'customer', 'status', 'currency', 'items'],
        'subscription_item' => ['id', 'object', 'price'],
    ];

    private const REFERENCES = ['customer', 'invoice', 'latest_charge', 'payment_intent', 'balance_transaction', 'source', 'price', 'on_behalf_of', 'application'];

    public static function project(string $object, array $value): array
    {
        BillingException::require(isset(self::FIELDS[$object]) && ($value['object'] ?? null) === $object, 'provider_inconsistent');
        $result = [];
        foreach (self::FIELDS[$object] as $field) {
            $result[$field] = in_array($field, self::REFERENCES, true) && is_array($value[$field] ?? null)
                ? BillingValues::ref($value[$field]) : ($value[$field] ?? null);
        }
        if ($object === 'invoice') {
            $parent = is_array($value['parent'] ?? null) ? $value['parent'] : [];
            $result['parent'] = ['type' => $parent['type'] ?? null, 'subscription_details' => is_array($parent['subscription_details'] ?? null)
                ? ['subscription' => BillingValues::ref($parent['subscription_details']['subscription'] ?? null)] : null];
            $result['lines'] = self::collection($value['lines'] ?? null, 'line_item');
        } elseif ($object === 'line_item') {
            $parent = is_array($value['parent'] ?? null) ? $value['parent'] : [];
            $item = is_array($parent['subscription_item_details'] ?? null) ? $parent['subscription_item_details'] : null;
            $result['parent'] = ['type' => $parent['type'] ?? null, 'subscription_item_details' => $item === null ? null
                : ['subscription' => BillingValues::ref($item['subscription'] ?? null), 'proration' => $item['proration'] ?? null,
                    'subscription_item' => BillingValues::ref($item['subscription_item'] ?? null)]];
            $pricing = is_array($value['pricing'] ?? null) ? $value['pricing'] : [];
            $result['pricing'] = ['type' => $pricing['type'] ?? null, 'price_details' => is_array($pricing['price_details'] ?? null)
                ? ['price' => BillingValues::ref($pricing['price_details']['price'] ?? null)] : null];
            $period = is_array($value['period'] ?? null) ? $value['period'] : [];
            $result['period'] = ['start' => $period['start'] ?? null, 'end' => $period['end'] ?? null];
        } elseif ($object === 'invoice_payment') {
            $payment = is_array($value['payment'] ?? null) ? $value['payment'] : [];
            $result['payment'] = ['type' => $payment['type'] ?? null, 'payment_intent' => BillingValues::ref($payment['payment_intent'] ?? null),
                'charge' => BillingValues::ref($payment['charge'] ?? null)];
        } elseif ($object === 'subscription') {
            $result['items'] = self::collection($value['items'] ?? null, 'subscription_item');
        }

        return $result;
    }

    /** A list projection; completeness (has_more = false) is judged by the gateway and the evaluator. */
    public static function collection(mixed $value, string $object): array
    {
        BillingException::require(is_array($value) && ($value['object'] ?? null) === 'list' && is_bool($value['has_more'] ?? null)
            && is_array($value['data'] ?? null) && array_is_list($value['data']), 'provider_inconsistent');

        return ['object' => 'list', 'has_more' => $value['has_more'],
            'data' => array_map(fn (mixed $entry): array => self::project($object, is_array($entry) ? $entry : []), $value['data'])];
    }
}
