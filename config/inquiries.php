<?php

return [
    // Enable only after the public notice, private retention policy and responsible operator are chosen.
    'enabled' => env('CONTACT_INQUIRIES_ENABLED', false),
    'test_order_inquiries_enabled' => env('CONTACT_TEST_ORDER_INQUIRIES_ENABLED', false),
    'privacy_notice' => env('CONTACT_INQUIRIES_PRIVACY_NOTICE'),
    'retention_policy_reference' => env('CONTACT_INQUIRIES_RETENTION_REFERENCE'),
    'operator_user_id' => env('CONTACT_INQUIRIES_OPERATOR_ID'),
];
