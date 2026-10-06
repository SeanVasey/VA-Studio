<?php

return [
    // This child only captures synthetic notices privately. No mail channel/provider is supported.
    'test_enabled' => env('VASEY_TEST_TRANSACTIONAL_NOTIFICATIONS_ENABLED', false),
    'transport' => env('VASEY_TEST_TRANSACTIONAL_NOTIFICATION_TRANSPORT'),
    'policy_version' => env('VASEY_TEST_TRANSACTIONAL_NOTIFICATION_POLICY'),
];
