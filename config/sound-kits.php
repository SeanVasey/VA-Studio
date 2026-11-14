<?php

return [
    // Public sound-kit storefront pages (/sound-kits, its sitemap entries and navigation item), BL-53 (FP-007). A code literal on
    // purpose, not an environment key: production pages stay off until a reviewed change flips it. While it is false the pages
    // open only where the Stripe-test kit purchase lane below is admitted (local, testing or staging), never in production.
    // Read only through App\Domain\SoundKits\Products\SoundKitStorefrontPolicy.
    'storefront_enabled' => false,

    // Stripe TEST-mode kit purchase, grant, contract and delivery (BL-13, docs/sound-kit-storefront.md "Kit checkout"). Admitted
    // only where TestEnvironment::admitsTestCommerce() (local, testing, staging) and only for the exact boolean true; production
    // can never admit it. Read only through App\Domain\SoundKits\Purchases\SoundKitPurchasePolicy.
    'test_checkout_enabled' => env('VASEY_SOUND_KIT_TEST_CHECKOUT_ENABLED', false),
];
