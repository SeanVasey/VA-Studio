<?php

return [
    'test_access_enabled' => env('VASEY_TEST_DELIVERY_ACCESS_ENABLED', false),
    'test_access_policy' => env('VASEY_TEST_DELIVERY_ACCESS_POLICY'),
    // test-owner-delivery-v2 only (FP-077). Resume window after a transfer's first byte, by file size; operational
    // limits, not license terms. Each is 60..604800 seconds and never shorter than the tier below it.
    'test_resume_window_seconds' => [
        'below_512_mib' => env('VASEY_TEST_DELIVERY_RESUME_SECONDS_BELOW_512_MIB', 3600),
        'from_512_mib' => env('VASEY_TEST_DELIVERY_RESUME_SECONDS_FROM_512_MIB', 21600),
        'from_4_gib' => env('VASEY_TEST_DELIVERY_RESUME_SECONDS_FROM_4_GIB', 86400),
    ],
    // Started-but-incomplete v2 authorizations per grant per rolling 24 hours (1..1000); beyond it issuance is refused.
    'test_incomplete_authorizations_per_grant_24h' => env('VASEY_TEST_DELIVERY_INCOMPLETE_PER_GRANT_24H', 10),
    // Optional automatic enabling of test delivery (D-19 amendment, 2026-11-12): local, testing and staging only, default off,
    // admitted only with the exact TestDeliveryAutoEnablePolicy::CONTRACT. Staff blocks and enables stay final.
    'test_auto_enable_enabled' => env('VASEY_TEST_DELIVERY_AUTO_ENABLED', false),
    'test_auto_enable_policy' => env('VASEY_TEST_DELIVERY_AUTO_POLICY'),
    'test_activation_enabled' => env('VASEY_TEST_FULFILLMENT_ACTIVATION_ENABLED', false),
    'test_activation_policy' => env('VASEY_TEST_FULFILLMENT_ACTIVATION_POLICY'),
];
