<?php

return [
    // Public sound-kit storefront pages (/sound-kits, its sitemap entries and navigation item), BL-53 (FP-007). A code literal on
    // purpose, not an environment key: the pages stay off until a reviewed change flips it, so no deployment environment can open
    // them by accident. Turning it on lists published kits only; it does NOT create a purchase path. Kit checkout, entitlement and
    // delivery are not built (docs/sound-kit-storefront.md), so do not enable it before that work lands.
    // Read only through App\Domain\SoundKits\Products\SoundKitStorefrontPolicy.
    'storefront_enabled' => false,
];
