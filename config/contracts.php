<?php

return [
    // Nonbinding local/testing document issuance only; no entitlement or download activation.
    // Current v2 runtime requires the exact explicit v2 policy. A retained v1 config is refused.
    'test_issuance_enabled' => env('VASEY_TEST_CONTRACT_ISSUANCE_ENABLED', false),
    'test_issuance_policy' => env('VASEY_TEST_CONTRACT_ISSUANCE_POLICY'),
];
