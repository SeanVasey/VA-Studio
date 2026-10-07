<?php

namespace App\Domain\Customers\ProductionFeatures\Listening;

use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureConfiguration;

/** Operator-reviewed stopped upgrade; this declaration does not make an old reader V2-capable. */
final class ProductionListeningRollout
{
    public static function capture(ProductionFeatureConfiguration $source): array
    {
        $configuration = $source->snapshot('production-customer-listening');
        $reference = is_array($configuration) ? ($configuration['v2_rollout_review_reference'] ?? null) : null;
        $enabled = is_array($configuration) && count($configuration) === 2
            && ($configuration['v2_promotion_enabled'] ?? null) === true
            && is_string($reference) && mb_check_encoding($reference, 'UTF-8') && trim($reference) === $reference
            && mb_strlen($reference) >= 1 && mb_strlen($reference) <= 200 && strlen($reference) <= 800
            && preg_match('/[\p{Cc}\p{Cf}]/u', $reference) === 0 && preg_match('/[^\p{Z}\s]/u', $reference) === 1;

        return ['configuration' => $configuration, 'promotionEnabled' => $enabled];
    }

    public static function requireCurrent(array $captured, ProductionFeatureConfiguration $source): void
    {
        if ($source->snapshot('production-customer-listening') !== $captured['configuration']) {
            throw new ListeningException(503);
        }
    }
}
