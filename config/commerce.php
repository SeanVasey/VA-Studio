<?php

return [
    // Explicit JSON policy for local/testing only. Missing policy keeps tax unresolved.
    // No rate, exemption, account or effective period is provided by default.
    'test_pricing_policy' => env('VASEY_TEST_PRICING_POLICY'),
];
