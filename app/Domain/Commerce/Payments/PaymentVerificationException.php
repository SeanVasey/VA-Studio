<?php

namespace App\Domain\Commerce\Payments;

use RuntimeException;

final class PaymentVerificationException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Test payment verification unavailable.');
    }
}
