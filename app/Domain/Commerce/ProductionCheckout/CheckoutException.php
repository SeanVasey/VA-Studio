<?php

namespace App\Domain\Commerce\ProductionCheckout;

final class CheckoutException extends \RuntimeException
{
    public function __construct(public readonly string $reason = 'changed', public readonly int $status = 409)
    {
        parent::__construct('PRODUCTION_CHECKOUT_UNAVAILABLE');
    }

    public static function require(bool $condition, string $reason = 'changed', int $status = 409): void
    {
        if (! $condition) {
            throw new self($reason, $status);
        }
    }
}
