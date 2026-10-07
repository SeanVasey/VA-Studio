<?php

namespace App\Domain\Grants\Free;

use RuntimeException;

final class FreeGrantException extends RuntimeException
{
    public function __construct(public readonly int $status = 503)
    {
        parent::__construct('Free grant preparation is unavailable.');
    }

    public static function require(bool $condition, int $status = 503): void
    {
        if (! $condition) {
            throw new self($status);
        }
    }
}
