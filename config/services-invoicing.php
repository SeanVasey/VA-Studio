<?php

return [
    // BL-70 phase 1: Stripe Invoicing for service deposits and balances, test mode only. Default off. It also needs
    // TestEnvironment::admitsTestCommerce(), the service-project policy and the Stripe test credentials; production holds it false
    // (ops/production/validate-runtime.php, LAUNCH_OFF_HOLDS) and no live path exists.
    'test_enabled' => env('VASEY_TEST_SERVICE_INVOICING_ENABLED', false),
];
