<?php

namespace Tests\Support;

use App\Domain\Commerce\Models\QuotePricing;

/** Nonbinding synthetic calculation policies. Never used by application setup or seeders. */
final class PricingFixtures
{
    public static function policy(string $mode = 'fixed_test'): array
    {
        return [
            'schema_version' => 1, 'scope' => 'test', 'key' => 'synthetic-tax-only', 'version' => 1,
            'currency' => 'USD', 'provider' => 'stripe', 'account' => 'acct_SYNTHETICONLY',
            'effective_from' => '2020-01-01T00:00:00Z', 'effective_until' => '2099-01-01T00:00:00Z',
            'tax' => $mode === 'fixed_test'
                ? ['mode' => $mode, 'behavior' => 'exclusive', 'rounding' => 'line_half_up', 'rate_bps' => 750]
                : ['mode' => $mode, 'behavior' => 'exclusive', 'max_rate_bps' => 1000],
        ];
    }

    public static function configure(?array $policy): void
    {
        config(['commerce.test_pricing_policy' => $policy === null ? null : json_encode($policy, JSON_THROW_ON_ERROR)]);
    }

    public static function observation(QuotePricing $pricing, ?int $providerTaxPerLine = null): array
    {
        $snapshot = $pricing->snapshot;
        $lines = array_map(static function (array $line) use ($providerTaxPerLine): array {
            $tax = $providerTaxPerLine ?? $line['tax_minor'];

            return ['offer_revision_id' => $line['offer_revision_id'], 'quantity' => 1, 'base_minor' => $line['base_minor'],
                'discount_minor' => 0, 'tax_basis_minor' => $line['base_minor'], 'tax_minor' => $tax, 'total_minor' => $line['base_minor'] + $tax];
        }, $snapshot['lines']);

        return [
            'schema_version' => 1, 'pricing_id' => $pricing->public_id, 'quote_id' => $snapshot['quote_id'], 'policy_hash' => $snapshot['policy_hash'],
            'provider' => 'stripe', 'account' => 'acct_SYNTHETICONLY', 'livemode' => false, 'currency' => 'USD',
            'subtotal_minor' => $snapshot['subtotal_minor'], 'discount_minor' => 0,
            'tax_minor' => array_sum(array_column($lines, 'tax_minor')), 'total_minor' => array_sum(array_column($lines, 'total_minor')),
            'tax_calculation_id' => $providerTaxPerLine === null ? null : 'taxcalc_SYNTHETIC', 'lines' => $lines,
        ];
    }

    public static function taxResult(array $observation): array
    {
        return array_intersect_key($observation, array_flip(['schema_version', 'pricing_id', 'quote_id', 'policy_hash', 'provider', 'account', 'livemode', 'currency', 'lines'])) +
            ['id' => $observation['tax_calculation_id'], 'status' => 'complete', 'behavior' => 'exclusive'];
    }
}
