<?php

namespace App\Domain\SoundKits\Products;

/**
 * Visibility of the public sound-kit family (/sound-kits, its sitemap entries and navigation item). Only the exact boolean true
 * enables it; a string such as "false" or "1" in a cached config does not. Staff editing is not gated by this flag. The flag
 * grants no purchase: {@see self::purchasable()} is a separate, constant answer until a kit checkout exists.
 */
final class SoundKitStorefrontPolicy
{
    public function enabled(): bool
    {
        return config('sound-kits.storefront_enabled') === true;
    }

    /**
     * Whether a kit can be bought. Always false: the existing quote -> order -> verified payment -> grant -> delivery chain is
     * bound to track offers and cannot carry a kit line without a payment-domain change (docs/sound-kit-storefront.md). The page
     * shows an honest notice instead of a purchase control while this is false.
     */
    public function purchasable(): bool
    {
        return false;
    }
}
