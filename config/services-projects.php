<?php

return [
    // Explicit synthetic preparation. No production, payment, attachment or delivery authority.
    'test_enabled' => env('VASEY_TEST_SERVICE_PROJECTS_ENABLED', false),
    // Public service catalog pages (BL-64). Code literal, default off: a per-family launch decision (SQ-04 amendment) that
    // needs a reviewed change. The pages show staff-approved services and lead to the ordinary contact inquiry; no payment.
    'catalog_enabled' => false,
];
