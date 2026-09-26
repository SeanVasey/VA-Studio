<?php

return [
    'test_access_enabled' => env('VASEY_TEST_DELIVERY_ACCESS_ENABLED', false),
    'test_access_policy' => env('VASEY_TEST_DELIVERY_ACCESS_POLICY'),
    'test_activation_enabled' => env('VASEY_TEST_FULFILLMENT_ACTIVATION_ENABLED', false),
    'test_activation_policy' => env('VASEY_TEST_FULFILLMENT_ACTIVATION_POLICY'),
];
