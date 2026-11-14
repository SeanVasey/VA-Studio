<?php

namespace App\Domain\Commerce\ProductionFinancialRetrieval;

use RuntimeException;

final class FinancialRetrievalException extends RuntimeException
{
    public function __construct(public readonly string $reason = 'unavailable')
    {
        parent::__construct('PAID_FINANCIAL_RETRIEVAL_UNAVAILABLE');
    }

    public static function require(bool $condition, string $reason = 'unavailable'): void
    {
        if (! $condition) {
            throw new self($reason);
        }
    }
}
