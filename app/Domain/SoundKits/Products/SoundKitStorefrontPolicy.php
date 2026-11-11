<?php

namespace App\Domain\SoundKits\Products;

use App\Domain\SoundKits\Purchases\SoundKitPurchasePolicy;

/**
 * Visibility of the public sound-kit family (/sound-kits, its sitemap entries and navigation item). Only the exact boolean true
 * enables it; a string such as "false" or "1" in a cached config does not. Staff editing is not gated by this flag.
 *
 * Besides the production flag (a code literal, false), the pages open wherever the Stripe-test kit purchase lane is admitted
 * ({@see SoundKitPurchasePolicy}: local, testing or staging with `VASEY_SOUND_KIT_TEST_CHECKOUT_ENABLED=true`), so a kit can be
 * test-bought on staging. Production never admits that lane.
 */
final class SoundKitStorefrontPolicy
{
    public function enabled(): bool
    {
        return config('sound-kits.storefront_enabled') === true || $this->purchasable();
    }

    /**
     * Whether a kit can be bought here: only through the Stripe TEST purchase lane (BL-13), so never in production. While false the
     * page shows an honest notice instead of a purchase control.
     */
    public function purchasable(): bool
    {
        return app(SoundKitPurchasePolicy::class)->admitted();
    }
}
