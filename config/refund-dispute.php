<?php

/*
 * A5 refund and dispute lifecycle on the Stripe-test commerce chain. Default-off.
 *
 * The four policy values mirror MachinePolicyV1 `refunds_and_disputes`. They have no defaults: Sean has not
 * approved a refund or dispute policy, and every write refuses while any value is unset or unknown.
 */
return [
    'enabled' => env('VASEY_TEST_REFUND_DISPUTE_ENABLED', false),
    'policy' => [
        'refund_authority' => env('VASEY_TEST_REFUND_DISPUTE_REFUND_AUTHORITY'),
        'partial_refunds' => env('VASEY_TEST_REFUND_DISPUTE_PARTIAL_REFUNDS'),
        'dispute_access' => env('VASEY_TEST_REFUND_DISPUTE_DISPUTE_ACCESS'),
        'exclusive_reopening' => env('VASEY_TEST_REFUND_DISPUTE_EXCLUSIVE_REOPENING'),
    ],
];
