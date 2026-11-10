<?php

namespace App\Domain\Merch\Fulfillment\Printful;

/** One shipping service Printful quoted. `rate` is Printful's decimal string, converted to minor units by the caller. */
final class PrintfulShippingRate
{
    public function __construct(
        public readonly string $shipping,
        public readonly string $name,
        public readonly string $rate,
        public readonly string $currency,
    ) {}
}
