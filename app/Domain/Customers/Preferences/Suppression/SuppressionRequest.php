<?php

namespace App\Domain\Customers\Preferences\Suppression;

/** Private server-derived recipient/binding; never a customer HTTP DTO or loggable message. */
final readonly class SuppressionRequest
{
    public function __construct(public string $operationId, public string $recipient, public string $recipientHmac,
        public array $binding, public string $bindingHash, public string $requestHash) {}
}
