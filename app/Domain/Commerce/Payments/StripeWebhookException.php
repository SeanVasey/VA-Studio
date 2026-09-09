<?php

namespace App\Domain\Commerce\Payments;

use RuntimeException;

final class StripeWebhookException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status)
    {
        // Provider exceptions may retain raw payloads/signatures. Never chain them here.
        parent::__construct($errorCode);
    }
}
