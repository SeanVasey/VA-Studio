<?php

namespace App\Domain\Commerce\Payments;

use SensitiveParameter;

interface StripeCheckoutGateway
{
    /** Return the own account identified by the configured test credential. */
    public function account(): array;

    /** Create or recover the same session using the exact retained request and key. */
    public function create(#[SensitiveParameter] array $params, #[SensitiveParameter] string $idempotencyKey): array;

    /** Retrieve authoritative session evidence; this does not confirm a payment or grant rights. */
    public function retrieve(string $sessionId): array;
}
