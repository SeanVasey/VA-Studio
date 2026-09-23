<?php

namespace App\Domain\Commerce;

use App\Domain\Commerce\Inventory\ExclusiveSelectionPolicy;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\QuotePricing;
use App\Support\CanonicalJson;
use App\Support\Money\MinorUnits;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Exclusive/mixed test quotes use a new algorithm; V1/V2 are retained unchanged. */
final class PricingSnapshotV3
{
    public const ALGORITHM = 'vasey-quote-pricing-v3';

    public function build(Quote $quote, string $id, CarbonImmutable $issuedAt, ?array $policy, ?array $promotion = null): array
    {
        if (($quote->snapshot['schema_version'] ?? null) !== 2 ||
            ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $id) ||
            $quote->currency !== 'USD' || $issuedAt->lessThan($quote->created_at) || $issuedAt->greaterThanOrEqualTo($quote->expires_at)) {
            throw new InvalidArgumentException('Invalid exclusive pricing identity or lifetime.');
        }
        app(ExclusiveSelectionPolicy::class)->validate($quote->snapshot['selection_policy']);
        $expires = $quote->expires_at;
        $dates = app(PricingPolicy::class);
        if ($policy !== null) {
            $policy = $dates->validate($policy);
            $dates->activeAt($policy, $issuedAt);
            $expires = $expires->min($dates->timestamp($policy['effective_until']));
        }
        $source = $quote->snapshot['lines'];
        if (! array_is_list($source) || count($source) < 1 || count($source) > 10) { throw new InvalidArgumentException('Invalid pricing selection.'); }
        $lines = []; $hasExclusive = false;
        foreach ($source as $line) {
            $offer = $line['offer_snapshot']; $commercial = $offer['commercial'];
            $base = MinorUnits::amount($commercial['price_minor']); $type = $commercial['type'];
            if ($commercial['currency'] !== 'USD' || ! in_array($type, ['non-exclusive', 'exclusive'], true) ||
                $offer['license']['type'] !== $type || $base < 1 || $base > 2147483647 ||
                ($type === 'non-exclusive' && $offer['schema_version'] !== 1)) {
                throw new InvalidArgumentException('Unsupported priced offer.');
            }
            if ($type === 'exclusive') {
                $hasExclusive = true; $activation = $line['exclusive_activation'];
                if ($offer['schema_version'] !== 2 || $offer['purpose'] !== 'test_exclusive_preparation' ||
                    CanonicalJson::hash($activation['snapshot']) !== $activation['snapshot_hash'] ||
                    $activation['snapshot']['offer_revision_id'] !== $line['offer_revision_id'] ||
                    $activation['snapshot']['offer_snapshot_hash'] !== $line['offer_snapshot_hash'] ||
                    CanonicalJson::hash($offer) !== $line['offer_snapshot_hash'] ||
                    CanonicalJson::hash($activation['snapshot']['inventory']) !== CanonicalJson::hash($offer['inventory']) ||
                    CanonicalJson::hash($activation['snapshot']['policy']) !== CanonicalJson::hash($quote->snapshot['selection_policy'])) {
                    throw new InvalidArgumentException('Invalid exclusive activation evidence.');
                }
            }
            $lines[] = ['offer_revision_id' => $line['offer_revision_id'], 'commercial_type' => $type, 'quantity' => 1,
                'base_minor' => $base, 'discount_minor' => 0, 'tax_basis_minor' => $base,
                'disclosure_hash' => app(QuoteLicenseDisclosure::class)->fromLine($quote, $line)['disclosureHash']];
        }
        if (! $hasExclusive || MinorUnits::sum(array_column($lines, 'base_minor')) !== $quote->subtotal_minor) {
            throw new InvalidArgumentException('Invalid exclusive quote subtotal or version.');
        }
        $trace = null;
        if ($promotion !== null) {
            $promotions = app(PromotionPolicy::class); $promotion = $promotions->validate($promotion);
            $dates->activeAt($promotion, $issuedAt);
            $expires = $expires->min($dates->timestamp($promotion['effective_until']));
            // V1 campaign semantics explicitly mean NON-exclusive. Filter before its
            // eligibility basis, minimum spend, percentage and allocation calculations.
            $eligible = $promotion['eligibility']['mode'] === 'all_non_exclusive'
                ? array_values(array_filter($lines, fn ($line) => $line['commercial_type'] === 'non-exclusive')) : $lines;
            $trace = $promotions->calculate($promotion, $eligible);
        }
        $discounts = array_column($trace['allocations'] ?? [], 'discount_minor', 'offer_revision_id');
        $fixed = ($policy['tax']['mode'] ?? null) === 'fixed_test';
        foreach ($lines as &$line) {
            $line['discount_minor'] = $discounts[$line['offer_revision_id']] ?? 0;
            $line['tax_basis_minor'] = $line['base_minor'] - $line['discount_minor'];
            $line['rounding'] = $fixed ? MinorUnits::fraction($line['tax_basis_minor'], $policy['tax']['rate_bps']) : null;
            $line['tax_minor'] = $line['rounding']['rounded_minor'] ?? null;
            $line['total_minor'] = $fixed ? MinorUnits::sum([$line['tax_basis_minor'], $line['tax_minor']]) : null;
        }
        unset($line);
        $snapshot = ['schema_version' => 3, 'algorithm' => self::ALGORITHM, 'pricing_id' => $id,
            'quote_id' => $quote->public_id, 'quote_snapshot_hash' => $quote->snapshot_hash,
            'issued_at' => $issuedAt->utc()->toIso8601ZuluString(), 'expires_at' => $expires->utc()->toIso8601ZuluString(),
            'currency' => 'USD', 'discount_policy' => $promotion === null ? 'none' : 'single_promotion_explicit_exclusive_v1',
            'subtotal_minor' => $quote->subtotal_minor, 'discount_minor' => MinorUnits::sum(array_column($lines, 'discount_minor')),
            'tax_basis_minor' => MinorUnits::sum(array_column($lines, 'tax_basis_minor')),
            'tax_minor' => $fixed ? MinorUnits::sum(array_column($lines, 'tax_minor')) : null,
            'total_minor' => $fixed ? MinorUnits::sum(array_column($lines, 'total_minor')) : null,
            'tax_status' => $policy === null ? 'unresolved' : ($fixed ? 'fixed_test' : 'provider_pending'),
            'tax_policy' => $policy, 'policy_hash' => $policy === null ? null : CanonicalJson::hash($policy),
            'selection_policy_hash' => CanonicalJson::hash($quote->snapshot['selection_policy']),
            'inventory_policy_hash' => CanonicalJson::hash($quote->snapshot['inventory_policy']),
            'payable' => false, 'lines' => $lines];
        if ($promotion !== null) {
            $snapshot += ['promotion' => $promotion, 'promotion_hash' => CanonicalJson::hash($promotion), 'discount_trace' => $trace];
        }

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
        $expected = $this->build($quote, $pricing->public_id, $pricing->created_at, $snapshot['tax_policy'], $snapshot['promotion'] ?? null);
        if (! hash_equals($pricing->snapshot_hash, CanonicalJson::hash($expected)) ||
            $pricing->expires_at->utc()->toIso8601ZuluString() !== $expected['expires_at']) {
            throw new InvalidArgumentException('Exclusive pricing cannot be reproduced.');
        }

        return $expected;
    }

    public function present(QuotePricing $pricing): array
    {
        $data = app(PricingSnapshotV1::class)->present($pricing);
        unset($data['pricingHash']);
        $data['pricingSchema'] = 3; $data['testOnly'] = true;
        if (isset($pricing->snapshot['promotion'])) {
            $promotion = $pricing->snapshot['promotion'];
            $data['promotion'] = ['key' => $promotion['key'], 'version' => $promotion['version'], 'code' => $promotion['code'],
                'hash' => $pricing->snapshot['promotion_hash']];
        }

        return $data + ['pricingHash' => CanonicalJson::hash($data)];
    }
}
