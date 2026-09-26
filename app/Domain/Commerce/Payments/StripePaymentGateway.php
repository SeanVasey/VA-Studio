<?php

namespace App\Domain\Commerce\Payments;

interface StripePaymentGateway
{
    /** Return the own account identified by the configured test credential. */
    public function account(): array;

    /** Retrieve the session associated with an existing checkout intent. */
    public function retrieve(string $sessionId): array;

    /** Retrieve existing test payment evidence without creating, confirming or capturing money. */
    public function paymentIntent(string $paymentIntentId): array;
}
