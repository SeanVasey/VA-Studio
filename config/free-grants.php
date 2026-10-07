<?php

return [
    'test_enabled' => env('VASEY_TEST_FREE_GRANTS_ENABLED', false),
    // Operative admission has a separate reviewed identity/terms adapter prerequisite.
    'operative_enabled' => env('VASEY_OPERATIVE_FREE_GRANTS_ENABLED', false),
];
