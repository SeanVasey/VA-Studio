<?php

return [
    'stripe' => [
        'finalization_enabled' => env('STRIPE_TEST_FINALIZATION_ENABLED', false),
        'finalization_policy' => env('STRIPE_TEST_FINALIZATION_POLICY'),
        'processing_enabled' => env('STRIPE_TEST_PAYMENT_PROCESSING_ENABLED', false),
        'checkout_enabled' => env('STRIPE_TEST_CHECKOUT_ENABLED', false),
        'secret_key' => env('STRIPE_TEST_SECRET_KEY'),
        // This receiver is deliberately limited to test events in non-production environments.
        'webhook_enabled' => env('STRIPE_WEBHOOK_ENABLED', false),
        'mode' => env('STRIPE_MODE', 'test'),
        'account_id' => env('STRIPE_ACCOUNT_ID'),
        // The secret for an own-account snapshot destination, not an API key.
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
];
