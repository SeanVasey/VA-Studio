<?php

/*
 * Production free grant family 256. Literal default-off: nothing here approves terms, templates, assets or a
 * storage host. Rehearsal additionally requires the local/testing environment and a bound sources capability.
 */
return [
    'enabled' => false,
    'version' => 1,
    'family' => 'production-free-origin-v1',
    'purpose' => 'production-free-license-grant-v1',
    'provenance' => null,
    'approved_terms_hashes' => [],
    'authorization_ttl_seconds' => 300,
    'render_lease_seconds' => 300,
    'storage_root' => null,
];
