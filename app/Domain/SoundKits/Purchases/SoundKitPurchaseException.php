<?php

namespace App\Domain\SoundKits\Purchases;

use RuntimeException;

/** A refusal of the kit test purchase lane, with a stable public code and HTTP status. Never carries private detail. */
final class SoundKitPurchaseException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct(match ($errorCode) {
            'NOT_FOUND' => 'Not found.',
            'INVALID_REQUEST' => 'Enter the licensee name and try again.',
            'LICENSEE_NAME_INVALID' => 'Enter the licensee\'s legal name in Latin characters (up to 255 bytes, no braces).',
            'IDEMPOTENCY_CONFLICT' => 'This request key has already been used for a different purchase.',
            'KIT_UNAVAILABLE' => 'This kit is not available to buy right now.',
            'CHECKOUT_EXPIRED' => 'This order has expired. Start a new purchase.',
            'CHECKOUT_UNAVAILABLE' => 'Test checkout is unavailable right now. No payment has been taken.',
            'CHECKOUT_CHANGED' => 'The checkout could not be verified. No rights were granted.',
            'DELIVERY_UNAVAILABLE' => 'This download is not available.',
            default => 'This request could not be completed.',
        });
    }
}
