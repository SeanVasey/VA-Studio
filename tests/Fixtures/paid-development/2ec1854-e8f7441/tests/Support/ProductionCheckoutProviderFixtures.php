<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use App\Domain\Commerce\ProductionCheckout\HostedEvidence;

/** Synthetic contract inputs only. Never authenticates an actual merchant, tax position, or payment. */
final class ProductionCheckoutProviderFixtures
{
    public static function configure(): void
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Checkout fixtures require testing.');
        }
        config(['production_checkout.funds_mode' => 'test', 'production_checkout.account_id' => 'acct_SYNTHETIC',
            'production_checkout.return_origin' => 'https://review.invalid', 'production_checkout.review_lifetime_seconds' => 600,
            'production_checkout.provider_io_enabled' => true, 'production_checkout.secret_key' => 'sk_'.'test_'.'SYNTHETIC']);
    }

    public static function machine(): array
    {
        $machine = ProductionTrackCapabilitiesFixtures::machine();
        $machine['version'] = 'synthetic-checkout-machine-v1';
        $machine['choices']['tax_calculation'] = ['strategy' => 'declared_exemption', 'behavior' => 'exclusive', 'maximum_rate_bps' => 0, 'rounding' => 'not_applicable'];
        $machine['choices']['reservation_and_exclusives']['late_time_basis'] = 'application_verified_observation_time';
        $machine['choices']['reservation_and_exclusives']['reservation_seconds'] = 7200;
        $machine['choices']['reservation_and_exclusives']['provider_lifetime_seconds'] = 3600;

        return $machine;
    }

    public static function request(): array
    {
        self::configure();
        $context = ExecutionContextV1::current(self::machine());
        $order = ['context' => $context, 'row' => ['public_id' => '00000000-0000-4000-8000-000000000001', 'payload_hash' => str_repeat('a', 64)],
            'attempt' => ['public_id' => '00000000-0000-4000-8000-000000000002'],
            'body' => ['buyer' => ['origin_id' => '00000000-0000-4000-8000-000000000003'],
                'amounts' => ['currency' => 'USD', 'subtotal_minor' => 4999, 'tax_minor' => 0, 'total_minor' => 4999],
                'lines' => [['public_id' => '00000000-0000-4000-8000-000000000004', 'position' => 1, 'currency' => 'USD', 'amount_minor' => 4999, 'tax_minor' => 0, 'total_minor' => 4999,
                    'selection' => ['offer_snapshot' => ['product' => ['title' => 'NONBINDING SYNTHETIC RECORDING'], 'license' => ['name' => 'NONBINDING SYNTHETIC LICENSE']]]]]]];

        return HostedEvidence::request($order, '00000000-0000-4000-8000-000000000005', '2026-10-07T12:00:00Z');
    }

    public static function session(array $params, string $status = 'open', bool $paid = false): array
    {
        $id = 'cs_test_SYNTHETIC';
        $lines = [];
        foreach ($params['line_items'] as $line) {
            $price = $line['price_data'];
            $lines[] = ['quantity' => 1, 'currency' => 'usd', 'amount_subtotal' => $price['unit_amount'], 'amount_total' => $price['unit_amount'],
                'amount_tax' => 0, 'amount_discount' => 0, 'price' => ['currency' => 'usd', 'unit_amount' => $price['unit_amount'],
                    'product' => ['name' => $price['product_data']['name'], 'metadata' => $price['product_data']['metadata']]]];
        }
        $total = array_sum(array_column($lines, 'amount_total'));

        return ['id' => $id, 'object' => 'checkout.session', 'livemode' => false, 'mode' => 'payment', 'currency' => 'usd',
            'client_reference_id' => $params['client_reference_id'], 'metadata' => $params['metadata'], 'amount_subtotal' => $total, 'amount_total' => $total,
            'expires_at' => $params['expires_at'], 'total_details' => ['amount_discount' => 0, 'amount_tax' => 0, 'amount_shipping' => 0],
            'automatic_tax' => ['enabled' => false], 'payment_method_types' => ['card'], 'status' => $status, 'payment_status' => $paid ? 'paid' : 'unpaid',
            'payment_intent' => $paid ? 'pi_SYNTHETIC' : null, 'url' => $status === 'open' ? 'https://checkout.stripe.com/c/pay/'.$id : null,
            'line_items' => ['object' => 'list', 'has_more' => false, 'data' => $lines]];
    }

    public static function payment(array $params): array
    {
        $amount = array_sum(array_column(array_column($params['line_items'], 'price_data'), 'unit_amount'));

        return ['id' => 'pi_SYNTHETIC', 'object' => 'payment_intent', 'livemode' => false, 'currency' => 'usd', 'amount' => $amount, 'amount_received' => $amount,
            'amount_capturable' => 0, 'capture_method' => $params['payment_intent_data']['capture_method'], 'payment_method_types' => ['card'],
            'status' => 'succeeded', 'metadata' => $params['payment_intent_data']['metadata']];
    }
}
