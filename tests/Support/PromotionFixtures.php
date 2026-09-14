<?php

namespace Tests\Support;

use App\Domain\Commerce\Models\QuotePricing;

/** Synthetic, nonbinding fixtures; no setup, migration or seeder calls this class. */
final class PromotionFixtures
{
    public static function policy(array $changes = []): array
    {
        return array_replace([
            'schema_version' => 1, 'scope' => 'test', 'key' => 'synthetic-promotion', 'version' => 1,
            'code' => 'SYNTHETIC', 'currency' => 'USD', 'effective_from' => '2020-01-01T00:00:00Z', 'effective_until' => '2099-01-01T00:00:00Z',
            'eligibility' => ['mode' => 'all_non_exclusive'], 'minimum_subtotal_minor' => 0,
            'discount' => ['type' => 'fixed', 'amount_minor' => 500], 'stacking' => 'none', 'allocation' => 'largest_remainder_v1',
            'max_uses' => 3, 'release' => 'unstarted_at_expiry',
        ], $changes);
    }

    public static function configure(array $policies): void
    {
        config(['commerce.test_promotions' => json_encode($policies, JSON_THROW_ON_ERROR)]);
    }

    public static function observation(QuotePricing $pricing, ?int $providerTax = null): array
    {
        $value = PricingFixtures::observation($pricing, $providerTax);
        $value['discount_minor'] = $pricing->snapshot['discount_minor'];
        foreach ($value['lines'] as $index => &$line) {
            $expected = $pricing->snapshot['lines'][$index];
            $line['discount_minor'] = $expected['discount_minor'];
            $line['tax_basis_minor'] = $expected['tax_basis_minor'];
            $line['total_minor'] = $line['tax_basis_minor'] + $line['tax_minor'];
        }
        unset($line);
        $value['total_minor'] = array_sum(array_column($value['lines'], 'total_minor'));

        return $value;
    }
}
