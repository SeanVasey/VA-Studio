<?php

return [
    // Exact identity/paid-source, approved original terms and an authored delivery policy are prerequisites.
    'rehearsal_enabled' => false,
    'operative_enabled' => false,
    'delivery_policy' => null,
    // The authorization lifetime only bounds when a redemption may start. An admitted transfer gets its own deadline,
    // counted from the redemption commit: transfer_base_seconds plus the snapshot size at transfer_min_bytes_per_second
    // (256 KiB/s), capped at transfer_max_seconds (2 h). A 1 GiB deliverable fits at 256 KiB/s. Same bounds as Free256.
    'transfer_min_bytes_per_second' => 262144,
    'transfer_base_seconds' => 30,
    'transfer_max_seconds' => 7200,
];
