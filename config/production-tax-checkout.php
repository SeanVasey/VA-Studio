<?php

// Tax255 is default-off. Every value is a literal: nothing here reads the environment, and no
// credential, account, mode, currency or tax registration is supplied by this file. Activation
// needs Sean's explicit authorization and his merchant facts; see
// docs/verification/tax-255-20261007/README.md.
return [
    // Refused everywhere unless true AND the application environment is local or testing.
    'enabled' => false,

    // Identifier of the bound provider transport. null = unbound: requests are built and retained,
    // and every provider call fails closed before any I/O.
    'provider' => null,

    // Merchant facts only Sean can supply. null refuses.
    'funds_mode' => null,
    'account_id' => null,
    'return_origin' => null,

    // Pinned provider contract, identical to the V1 checkout pins (composer.lock and ExecutionContextV1).
    'stripe_sdk_version' => '21.3.2',
    'stripe_api_version' => '2026-08-26.dahlia',
];
