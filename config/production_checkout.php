<?php

return [
    'http_enabled' => env('PRODUCTION_CHECKOUT_HTTP_ENABLED', false),
    'fresh_checkout_enabled' => env('PRODUCTION_CHECKOUT_ENABLED', false),
    'reconciliation_enabled' => env('PRODUCTION_CHECKOUT_RECONCILIATION_ENABLED', false),
    'provider_io_enabled' => env('PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED', false),
    'exemption_authoring_enabled' => env('PRODUCTION_CHECKOUT_EXEMPTION_AUTHORING_ENABLED', false),
    'committed_read_receipts_enabled' => env('PRODUCTION_CHECKOUT_COMMITTED_READ_RECEIPTS_ENABLED', false),
    'committed_read_receipt_version' => 'production-checkout-committed-read-v1',
    'funds_mode' => env('PRODUCTION_CHECKOUT_FUNDS_MODE'),
    'account_id' => env('PRODUCTION_CHECKOUT_STRIPE_ACCOUNT_ID'),
    'secret_key' => env('PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY'),
    'return_origin' => env('PRODUCTION_CHECKOUT_RETURN_ORIGIN'),
    'review_lifetime_seconds' => env('PRODUCTION_CHECKOUT_REVIEW_LIFETIME_SECONDS'),
    // Deployment-owned IDs delegate approval of a scoped qualification policy.
    // They are not evidence of a tax exemption or professional qualification.
    'exemption_policy_owner_ids' => [],
];
