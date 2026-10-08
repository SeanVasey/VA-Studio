<?php

namespace App\Domain\Grants\ProductionFree;

/** One opaque refusal type. The reason is a fixed internal code, never customer or source data. */
final class ProductionFreeGrantException extends \RuntimeException
{
    public function __construct(public readonly string $reason = 'unavailable')
    {
        parent::__construct('Production free grant unavailable.');
    }

    public static function require(bool $condition, string $reason = 'unavailable'): void
    {
        if (! $condition) {
            throw new self($reason);
        }
    }
}
