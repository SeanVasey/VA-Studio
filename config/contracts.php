<?php

return [
    // Nonbinding local/testing document issuance only; no entitlement or download activation.
    'test_issuance_enabled' => env('VASEY_TEST_CONTRACT_ISSUANCE_ENABLED', false),
    'test_issuance_policy' => env('VASEY_TEST_CONTRACT_ISSUANCE_POLICY'),
];
