<?php

namespace App\Domain\Grants\Paid;

use RuntimeException;

final class PaidGrantException extends RuntimeException
{
    /** $reason is an internal, non-leaking code for staff and tests; the message and the HTTP status stay generic. */
    public function __construct(public readonly int $status = 503, public readonly ?string $reason = null)
    {
        parent::__construct('Paid grant request unavailable.');
    }

    public static function require(bool $condition, int $status = 503, ?string $reason = null): void
    {
        if (! $condition) {
            throw new self($status, $reason);
        }
    }
}
