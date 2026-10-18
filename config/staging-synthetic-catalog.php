<?php

return [
    // Labelled synthetic rehearsal catalog (`vasey:staging-synthetic-catalog`). Default off. Whatever this flag says, the
    // command refuses when the configured or running environment is production or the two differ, outside local,
    // testing and staging, and on a catalog that already holds non-synthetic published tracks or approved licenses.
    'enabled' => env('VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED', false),
];
