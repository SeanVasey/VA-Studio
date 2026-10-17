<?php

return [
    // Labelled synthetic rehearsal catalog (`vasey:staging-synthetic-catalog`). Default off. The command refuses in
    // production unconditionally and outside local, testing and staging, whatever this flag says.
    'enabled' => env('VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED', false),
];
