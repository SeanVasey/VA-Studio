<?php

declare(strict_types=1);

namespace App\Domain\Migration\History;

/** Supplied historical status only; zero arithmetic never establishes a refund. */
final class CompetingExclusiveSale
{
    public static function remains(string $status, int $amountMinor, int $refundedMinor): bool
    {
        return in_array($status, ['paid', 'partially_refunded', 'unknown'], true)
            && ($amountMinor === 0 || $refundedMinor < $amountMinor);
    }
}
