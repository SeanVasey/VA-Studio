<?php

return [
    // Merchandise fulfillment through Printful (BL-63, phase 1). Default-off and admitted only where
    // TestEnvironment::admitsTestCommerce() holds (local, testing, staging); production validators hold it false.
    // Nothing here selects a live path: a submission needs durable verified payment first, and an order is only ever
    // created as a Printful draft unless `confirm_orders` is also true. Prices come from staff-entered drafts, never from here.
    'printful_enabled' => env('MERCH_PRINTFUL_ENABLED', false),
    // Confirming a Printful order is what charges the Printful account and starts production. Off by default: with it off,
    // a submission leaves a draft that Sean can inspect in the Printful dashboard and confirm by hand.
    'confirm_orders' => env('MERCH_PRINTFUL_CONFIRM_ENABLED', false),
    'printful' => [
        // Private token entered on the host by Sean. Blank means every client call refuses before any request is built.
        'api_key' => env('PRINTFUL_API_KEY'),
        // Required only for an account-level token (sent as X-PF-Store-Id).
        'store_id' => env('PRINTFUL_STORE_ID'),
        // Hex secret key and base64 public key of the Printful v2 webhook configuration.
        'webhook_secret_key' => env('PRINTFUL_WEBHOOK_SECRET_KEY'),
        'webhook_public_key' => env('PRINTFUL_WEBHOOK_PUBLIC_KEY'),
    ],
];
