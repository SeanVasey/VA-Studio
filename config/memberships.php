<?php

return [
    // Synthetic local/testing preparation only; MembershipPolicy refuses production.
    'test_mode_enabled' => env('VASEY_TEST_MEMBERSHIPS_ENABLED', false),
];
