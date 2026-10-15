<?php

namespace App\Domain\Grants\PaidTax;

use RuntimeException;

final class PaidTaxException extends RuntimeException
{
    public function __construct(public readonly int $status = 503)
    {
        parent::__construct('Paid grant request unavailable.');
    }

    public static function require(bool $condition, int $status = 503): void
    {
        if (! $condition) {
            throw new self($status);
        }
    }
}
