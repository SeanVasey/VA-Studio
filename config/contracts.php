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

    // Sound-kit license (BL-66, `vasey-kit-license-pdf-v1`): the licensor display name printed in the kit license and on its
    // signature line. Blank refuses rendering. Sean's signature is the same private PNG as `vasey_license_v1` above. Built, not
    // selected for any issuance.
    'vasey_kit_license' => [
        'licensor_name' => env('VASEY_KIT_LICENSE_LICENSOR_NAME'),
    ],

    // Selection of `vasey-license-pdf-v2` for NEW paid and paid-tax originals (App\Domain\Grants\VaseyLicenseIssuance).
    // A code literal, never an environment read: only an exact `true` set in `local` or `testing` admits, and
    // ops/production/validate-runtime.php holds it false. Existing originals keep the profile recorded at their origin.
    'vasey_license_v2_issuance' => false,
];
