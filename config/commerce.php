<?php

return [
    // Explicit JSON policy for local/testing only. Missing policy keeps tax unresolved.
    // No rate, exemption, account or effective period is provided by default.
    'test_pricing_policy' => env('VASEY_TEST_PRICING_POLICY'),
    // Explicit test-only campaign list. Setup installs no coupons or commercial choices.
    'test_promotions' => env('VASEY_TEST_PROMOTIONS'),
    // Internal inventory exercises only; no production reservation policy or default TTL.
    'test_inventory_policy' => env('VASEY_TEST_INVENTORY_POLICY'),
    // No exclusive selection or cutoff policy is installed by default.
    'test_exclusive_selection_policy' => env('VASEY_TEST_EXCLUSIVE_SELECTION_POLICY'),
    // Explicit synthetic seller/assent policy; no real merchant or legal terms are supplied.
    'test_order_policy' => env('VASEY_TEST_ORDER_POLICY'),
];
