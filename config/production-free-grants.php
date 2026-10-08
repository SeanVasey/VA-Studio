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
    // Delivery timing. The authorization TTL above is the valid-to-start deadline: redeeming must begin before it.
    // The snapshot then has its own bound, and the client stream gets transfer_base_seconds plus the asset size at
    // transfer_min_bytes_per_second (256 KiB/s), capped at transfer_max_seconds (2 h). A 1 GiB asset fits at 256 KiB/s.
    'snapshot_seconds' => 300,
    'transfer_min_bytes_per_second' => 262144,
    'transfer_base_seconds' => 30,
    'transfer_max_seconds' => 7200,
    // Delivery snapshots: held flock slots (each snapshot is at most 1 GiB, so the spool holds at most
    // slots x 1 GiB) and the free-space reserve that must remain after a snapshot, in bytes (16 MiB).
    'spool_slots' => 3,
    'spool_reserve_bytes' => 16777216,
    'storage_root' => null,
];
