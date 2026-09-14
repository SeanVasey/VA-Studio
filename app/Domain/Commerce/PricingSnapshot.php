<?php

namespace App\Domain\Commerce;

use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\QuotePricing;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Dispatch by the retained schema; never reinterpret old evidence as a new algorithm. */
final class PricingSnapshot
{
    public const ALGORITHM = PricingSnapshotV1::ALGORITHM;

    public function build(Quote $quote, string $id, CarbonImmutable $issuedAt, ?array $policy): array
    {
        return app(PricingSnapshotV1::class)->build($quote, $id, $issuedAt, $policy);
    }

    public function buildWithPromotion(Quote $quote, string $id, CarbonImmutable $issuedAt, ?array $policy, array $promotion): array
    {
        return app(PricingSnapshotV2::class)->build($quote, $id, $issuedAt, $policy, $promotion);
    }

    public function verify(QuotePricing $pricing, Quote $quote): array
    {
        return $this->reader($pricing)->verify($pricing, $quote);
    }

    public function present(QuotePricing $pricing): array
    {
        return $this->reader($pricing)->present($pricing);
    }

    private function reader(QuotePricing $pricing): PricingSnapshotV1|PricingSnapshotV2
    {
        return match ($pricing->snapshot['schema_version'] ?? null) {
            1 => app(PricingSnapshotV1::class),
            2 => app(PricingSnapshotV2::class),
            default => throw new InvalidArgumentException('Unsupported pricing evidence version.'),
        };
    }
}
