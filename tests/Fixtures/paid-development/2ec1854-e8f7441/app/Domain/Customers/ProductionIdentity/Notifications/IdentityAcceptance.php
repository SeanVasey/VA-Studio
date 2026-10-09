<?php

namespace App\Domain\Customers\ProductionIdentity\Notifications;

use App\Domain\Customers\ProductionIdentity\IdentityException;

/** Positive SMTP submission is not a delivery or mailbox-verification assertion. */
final readonly class IdentityAcceptance
{
    public function __construct(public string $receiptHash)
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $receiptHash) || $receiptHash === str_repeat('0', 64)) {
            throw new IdentityException;
        }
    }
}
