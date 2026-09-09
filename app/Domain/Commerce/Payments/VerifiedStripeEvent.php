<?php

namespace App\Domain\Commerce\Payments;

use SensitiveParameter;

/** Internal verified receipt input; never a payment authorization or an HTTP projection. */
final readonly class VerifiedStripeEvent
{
    public function __construct(
        public string $accountId,
        public string $eventId,
        public string $eventType,
        public ?string $objectId,
        public string $objectType,
        public ?string $apiVersion,
        public int $created,
        public int $signatureTimestamp,
        public string $fingerprint,
        #[SensitiveParameter] public string $rawBody,
    ) {}
}
