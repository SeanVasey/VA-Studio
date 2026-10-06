<?php

return [
    // Synthetic local/testing accounts only. Production enrollment and recovery stay disabled.
    'test_purchase_claims_enabled' => env('VASEY_TEST_PURCHASE_CLAIMS_ENABLED', false),
    'test_accounts_enabled' => env('VASEY_TEST_CUSTOMER_ACCOUNTS_ENABLED', false),
    // This transport only writes private local test messages. It never sends mail or logs proofs.
    'test_identity_enabled' => env('VASEY_TEST_CUSTOMER_IDENTITY_ENABLED', false),
    'identity_transport' => env('VASEY_TEST_CUSTOMER_IDENTITY_TRANSPORT'),
];
