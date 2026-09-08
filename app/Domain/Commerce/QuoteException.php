<?php

namespace App\Domain\Commerce;

use RuntimeException;

final class QuoteException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct(match ($errorCode) {
            'INVALID_QUOTE_REQUEST' => 'Choose valid catalog selections and supply an idempotency key.',
            'IDEMPOTENCY_CONFLICT' => 'This request key has already been used for different selections.',
            'QUOTE_EXPIRED' => 'This review has expired. Request a new review.',
            'QUOTE_NOT_FOUND' => 'This review could not be found.',
            default => 'A selection has changed or is unavailable. Choose it again.',
        });
    }
}
