<?php

namespace App\Domain\Merch\Fulfillment;

use RuntimeException;

/** A refusal with a machine code and an HTTP-like status; the code never carries customer data. */
final class MerchFulfillmentException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 409)
    {
        parent::__construct($errorCode);
    }
}
