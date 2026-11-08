<?php

return [
    // Nonbinding local/testing document issuance only; no entitlement or download activation.
    // Current v2 runtime requires the exact explicit v2 policy. A retained v1 config is refused.
    'test_issuance_enabled' => env('VASEY_TEST_CONTRACT_ISSUANCE_ENABLED', false),
    'test_issuance_policy' => env('VASEY_TEST_CONTRACT_ISSUANCE_POLICY'),

    // Production license profile `vasey-license-pdf-v1` (built, not selected for issuance). Sean's signature PNG lives
    // on the private local disk, never in Git or under public/; the path is relative to that disk. A missing file or
    // bytes that differ from the pinned SHA-256 refuse rendering.
    'vasey_license_v1' => [
        'signature_path' => env('VASEY_LICENSE_SIGNATURE_PATH'),
        'signature_sha256' => env('VASEY_LICENSE_SIGNATURE_SHA256'),
    ],
];
