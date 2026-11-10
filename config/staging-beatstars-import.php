<?php

return [
    // Import of Sean's BeatStars catalog as unpublished drafts with their media (`vasey:staging-beatstars-import`). Default off.
    // Whatever this flag says, the command refuses when the configured or running environment is production or the two
    // differ, outside local, testing and staging, and unless Stripe is in test mode. ops/production/validate-runtime.php
    // refuses any production value but false (a launch-off hold), so the flag is set true on the staging host only for the
    // import run and back to false afterwards (docs/migration/staging-beatstars-import.md).
    'enabled' => env('VASEY_STAGING_BEATSTARS_IMPORT_ENABLED', false),
];
