<?php

namespace App\Domain\Customers\Listening;

/** Operator-reviewed stopped upgrade; this declaration does not make an old reader V2-capable. */
final class ListeningRollout
{
    public static function capture(): array
    {
        $configuration = config('customer-listening');
        $reference = is_array($configuration) ? ($configuration['v2_rollout_review_reference'] ?? null) : null;
        $enabled = is_array($configuration) && count($configuration) === 2
            && ($configuration['v2_promotion_enabled'] ?? null) === true
            && is_string($reference) && mb_check_encoding($reference, 'UTF-8') && trim($reference) === $reference
            && mb_strlen($reference) >= 1 && mb_strlen($reference) <= 200 && strlen($reference) <= 800
            && preg_match('/[\p{Cc}\p{Cf}]/u', $reference) === 0 && preg_match('/[^\p{Z}\s]/u', $reference) === 1;

        return ['configuration' => $configuration, 'promotionEnabled' => $enabled];
    }

    public static function requireCurrent(array $captured): void
    {
        if (config('customer-listening') !== $captured['configuration']) {
            throw new ListeningException(503);
        }
    }
}
