<?php

return [
    // Enable only after a reviewed stopped/backup upgrade has removed every V1-only reader/writer.
    // A retained V2 row cannot be rolled back to the old reader by disabling this setting.
    'v2_promotion_enabled' => env('VASEY_LISTENING_V2_PROMOTION_ENABLED', false),
    'v2_rollout_review_reference' => env('VASEY_LISTENING_V2_ROLLOUT_REVIEW_REFERENCE'),
];
