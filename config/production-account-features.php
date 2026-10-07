<?php

// Closed root parent for production account features. Default off: enabling requires a
// separately reviewed production identity binding and provenance. Only the two reviewed
// consumer versions are named; any other feature (including service_projects) stays refused.
return [
    'enabled' => false,
    'provenance' => null,
    'versions' => [
        'listening_library' => 'production-listening-library-identity-v1',
        'consent_preferences' => 'production-consent-preferences-identity-v1',
    ],
];
