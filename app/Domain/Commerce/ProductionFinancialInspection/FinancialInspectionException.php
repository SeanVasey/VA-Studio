<?php

namespace App\Domain\Commerce\ProductionFinancialInspection;

use RuntimeException;

final class FinancialInspectionException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('PAID_FINANCIAL_CANDIDATE_UNAVAILABLE');
    }

    public static function require(bool $condition, string $reason = 'attention'): void
    {
        if (! $condition) {
            throw new self($reason);
        }
    }
}
