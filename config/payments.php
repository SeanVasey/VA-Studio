<?php

return [
    'stripe' => [
        // This receiver is deliberately limited to test events in non-production environments.
        'webhook_enabled' => env('STRIPE_WEBHOOK_ENABLED', false),
        'mode' => env('STRIPE_MODE', 'test'),
        'account_id' => env('STRIPE_ACCOUNT_ID'),
        // The secret for an own-account snapshot destination, not an API key.
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
];
