<?php

return [
    // A separate Tax V2 family. No live producer, endpoint or active customer import is enabled.
    'rehearsal_enabled' => false,
    'operative_enabled' => false,
    'http_mounted' => false,
    'downloads_enabled' => false,
    'snapshot_seconds' => 300,
    'transfer_min_bytes_per_second' => 262144,
    'transfer_base_seconds' => 30,
    'transfer_max_seconds' => 7200,
    'delivery_policy' => null,
];
