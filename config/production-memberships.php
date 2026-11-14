<?php

/** Authoring is separate from commerce. No invoice, license or grant producer is installed. */
return [
    'enabled' => false,
    'authoring_enabled' => false,
    'version' => 'production-membership-period-v1',
    'provenance' => null,
    'approved_policy_hash' => null,
    // The customer member HTTP mount (routes/production-memberships.php, C4e). Literal false: only an exact boolean
    // true, in local or testing, registers the routes, and MembershipHttpMount re-checks it on every request. Mounting
    // approves nothing: no policy, definition, profile or terms hash, wording, capability or activation.
    'http_mounted' => false,
];
