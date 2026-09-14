<?php

namespace App\Domain\Commerce;

use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\QuotePricing;
use App\Support\CanonicalJson;
use App\Support\Money\MinorUnits;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class PricingSnapshotV2
{
    public const ALGORITHM = 'vasey-quote-pricing-v2';

    public function build(Quote $quote, string $id, CarbonImmutable $issuedAt, ?array $taxPolicy, array $promotion): array
    {
        $snapshot = app(PricingSnapshotV1::class)->build($quote, $id, $issuedAt, $taxPolicy);
        $rules = app(PromotionPolicy::class);
        $promotion = $rules->validate($promotion);
        $dates = app(PricingPolicy::class);
        $dates->activeAt($promotion, $issuedAt);
        $trace = $rules->calculate($promotion, $snapshot['lines']);
        $discounts = array_column($trace['allocations'], 'discount_minor', 'offer_revision_id');
        $fixedTax = $snapshot['tax_status'] === 'fixed_test';
        foreach ($snapshot['lines'] as &$line) {
            $line['discount_minor'] = $discounts[$line['offer_revision_id']] ?? 0;
            $line['tax_basis_minor'] = $line['base_minor'] - $line['discount_minor'];
            $line['rounding'] = $fixedTax ? MinorUnits::fraction($line['tax_basis_minor'], $taxPolicy['tax']['rate_bps']) : null;
            $line['tax_minor'] = $line['rounding']['rounded_minor'] ?? null;
            $line['total_minor'] = $line['tax_minor'] === null ? null : MinorUnits::sum([$line['tax_basis_minor'], $line['tax_minor']]);
        }
        unset($line);
        $snapshot['schema_version'] = 2;
        $snapshot['algorithm'] = self::ALGORITHM;
        $snapshot['expires_at'] = $dates->timestamp($snapshot['expires_at'])->min($dates->timestamp($promotion['effective_until']))->toIso8601ZuluString();
        $snapshot['discount_policy'] = 'single_promotion_v1';
        $snapshot['promotion'] = $promotion;
        $snapshot['promotion_hash'] = CanonicalJson::hash($promotion);
        $snapshot['discount_trace'] = $trace;
        $snapshot['discount_minor'] = $trace['discount_minor'];
        $snapshot['tax_basis_minor'] = MinorUnits::sum(array_column($snapshot['lines'], 'tax_basis_minor'));
        $snapshot['tax_minor'] = $fixedTax ? MinorUnits::sum(array_column($snapshot['lines'], 'tax_minor')) : null;
        $snapshot['total_minor'] = $fixedTax ? MinorUnits::sum(array_column($snapshot['lines'], 'total_minor')) : null;

        return $snapshot;
    }

    public function verify(QuotePricing $pricing, Quote $quote): array
    {
        $snapshot = $pricing->snapshot;
        if ($pricing->quote_id !== $quote->id || $quote->canonicalization_version !== CanonicalJson::VERSION ||
            ! hash_equals($quote->snapshot_hash, CanonicalJson::hash($quote->snapshot)) ||
            $pricing->canonicalization_version !== CanonicalJson::VERSION ||
            ! hash_equals($pricing->snapshot_hash, CanonicalJson::hash($snapshot))) {
            throw new InvalidArgumentException('Pricing evidence does not match its quote.');
        }
        $expected = $this->build($quote, $pricing->public_id, $pricing->created_at, $snapshot['tax_policy'], $snapshot['promotion']);
        if (! hash_equals($pricing->snapshot_hash, CanonicalJson::hash($expected)) ||
            $pricing->expires_at->utc()->toIso8601ZuluString() !== $expected['expires_at']) {
            throw new InvalidArgumentException('Promotion pricing cannot be reproduced.');
        }

        return $expected;
    }

    /** Owner/current policy and usage checks belong to PriceQuote, before projection. */
    public function present(QuotePricing $pricing): array
    {
        $data = app(PricingSnapshotV1::class)->present($pricing);
        unset($data['pricingHash']);
        $promotion = $pricing->snapshot['promotion'];
        $data['pricingSchema'] = 2;
        $data['testOnly'] = true;
        $data['promotion'] = ['key' => $promotion['key'], 'version' => $promotion['version'], 'code' => $promotion['code'],
            'hash' => $pricing->snapshot['promotion_hash']];

        return $data + ['pricingHash' => CanonicalJson::hash($data)];
    }
}
