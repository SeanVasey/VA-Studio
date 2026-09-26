<?php

namespace App\Domain\Delivery;

use RuntimeException;

final class DeliveryException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Test fulfillment activation is unavailable.');
    }
}
