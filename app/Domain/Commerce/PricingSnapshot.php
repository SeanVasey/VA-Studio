<?php

namespace App\Domain\Commerce;

use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\QuotePricing;
use App\Support\CanonicalJson;
use App\Support\Money\MinorUnits;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class PricingSnapshot
{
    public const ALGORITHM = 'vasey-quote-pricing-v1';

    public function build(Quote $quote, string $id, CarbonImmutable $issuedAt, ?array $policy): array
    {
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $id) ||
            $quote->currency !== 'USD' || $issuedAt->lessThan($quote->created_at) || $issuedAt->greaterThanOrEqualTo($quote->expires_at)) {
            throw new InvalidArgumentException('Invalid pricing identity, currency or lifetime.');
        }
        $expiresAt = $quote->expires_at;
        if ($policy !== null) {
            $rules = app(PricingPolicy::class);
            $policy = $rules->validate($policy);
            $rules->activeAt($policy, $issuedAt);
            $expiresAt = $expiresAt->min($rules->timestamp($policy['effective_until']));
        }
        $fixed = ($policy['tax']['mode'] ?? null) === 'fixed_test';
        $source = $quote->snapshot['lines'];
        if (! array_is_list($source) || count($source) < 1 || count($source) > 10) {
            throw new InvalidArgumentException('Invalid pricing selection.');
        }
        $lines = [];
        foreach ($source as $line) {
            $commercial = $line['offer_snapshot']['commercial'];
            $base = MinorUnits::amount($commercial['price_minor']);
            if ($commercial['currency'] !== 'USD' || $commercial['type'] !== 'non-exclusive' || $base < 1 || $base > 2147483647) {
                throw new InvalidArgumentException('Unsupported priced offer.');
            }
            $rounding = $fixed ? MinorUnits::fraction($base, $policy['tax']['rate_bps']) : null;
            $tax = $rounding['rounded_minor'] ?? null;
            $lines[] = [
                'offer_revision_id' => $line['offer_revision_id'], 'quantity' => 1,
                'base_minor' => $base, 'discount_minor' => 0, 'tax_basis_minor' => $base,
                'tax_minor' => $tax, 'total_minor' => $tax === null ? null : MinorUnits::sum([$base, $tax]),
                'rounding' => $rounding,
                'disclosure_hash' => app(QuoteLicenseDisclosure::class)->fromLine($quote, $line)['disclosureHash'],
            ];
        }
        $subtotal = MinorUnits::sum(array_column($lines, 'base_minor'));
        if ($subtotal !== $quote->subtotal_minor) {
            throw new InvalidArgumentException('The quote subtotal does not match its exact offers.');
        }
        $tax = $fixed ? MinorUnits::sum(array_column($lines, 'tax_minor')) : null;

        return [
            'schema_version' => 1, 'algorithm' => self::ALGORITHM, 'pricing_id' => $id,
            'quote_id' => $quote->public_id, 'quote_snapshot_hash' => $quote->snapshot_hash,
            'issued_at' => $issuedAt->utc()->toIso8601ZuluString(), 'expires_at' => $expiresAt->utc()->toIso8601ZuluString(),
            'currency' => 'USD', 'discount_policy' => 'none', 'subtotal_minor' => $subtotal,
            'discount_minor' => 0, 'tax_basis_minor' => $subtotal, 'tax_minor' => $tax,
            'total_minor' => $tax === null ? null : MinorUnits::sum([$subtotal, $tax]),
            'tax_status' => $policy === null ? 'unresolved' : ($fixed ? 'fixed_test' : 'provider_pending'),
            'tax_policy' => $policy, 'policy_hash' => $policy === null ? null : CanonicalJson::hash($policy),
            'payable' => false, 'lines' => $lines,
        ];
    }

    /** Verify retained evidence without applying today's availability or policy to historical amounts. */
    public function verify(QuotePricing $pricing, Quote $quote): array
    {
        $snapshot = $pricing->snapshot;
        if ($pricing->quote_id !== $quote->id || $quote->canonicalization_version !== CanonicalJson::VERSION ||
            ! hash_equals($quote->snapshot_hash, CanonicalJson::hash($quote->snapshot)) ||
            $pricing->canonicalization_version !== CanonicalJson::VERSION ||
            ! hash_equals($pricing->snapshot_hash, CanonicalJson::hash($snapshot))) {
            throw new InvalidArgumentException('Pricing evidence does not match its quote.');
        }
        $expected = $this->build($quote, $pricing->public_id, $pricing->created_at, $snapshot['tax_policy'] ?? null);
        if (! hash_equals($pricing->snapshot_hash, CanonicalJson::hash($expected)) ||
            $pricing->expires_at->utc()->toIso8601ZuluString() !== $expected['expires_at']) {
            throw new InvalidArgumentException('Pricing evidence cannot be reproduced.');
        }

        return $expected;
    }

    /** Call only after PriceQuote has checked ownership, current evidence and policy. */
    public function present(QuotePricing $pricing): array
    {
        $snapshot = $pricing->snapshot;
        $policy = $snapshot['tax_policy'];
        $data = [
            'pricingSchema' => 1, 'id' => $pricing->public_id, 'quoteId' => $snapshot['quote_id'],
            'expiresAt' => $pricing->expires_at->utc()->toISOString(), 'currency' => $snapshot['currency'],
            'subtotalMinor' => $snapshot['subtotal_minor'], 'discountMinor' => $snapshot['discount_minor'],
            'taxBasisMinor' => $snapshot['tax_basis_minor'], 'taxMinor' => $snapshot['tax_minor'], 'totalMinor' => $snapshot['total_minor'],
            'taxStatus' => $snapshot['tax_status'], 'payable' => false, 'testOnly' => $policy !== null,
            'policy' => $policy === null ? null : ['key' => $policy['key'], 'version' => $policy['version'], 'hash' => $snapshot['policy_hash']],
            'items' => array_map(static fn (array $line): array => [
                'offerRevisionId' => (string) $line['offer_revision_id'], 'quantity' => $line['quantity'],
                'baseMinor' => $line['base_minor'], 'discountMinor' => $line['discount_minor'], 'taxBasisMinor' => $line['tax_basis_minor'],
                'taxMinor' => $line['tax_minor'], 'totalMinor' => $line['total_minor'], 'disclosureHash' => $line['disclosure_hash'],
            ], $snapshot['lines']),
        ];

        return $data + ['pricingHash' => CanonicalJson::hash($data)];
    }
}
