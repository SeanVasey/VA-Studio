<?php

/*
 * Production runtime admissions (lane PCC). No request path reads these: ops/production/validate-runtime.php cross-checks them
 * against the candidate file, and scripts/ops/capability-boundary.php holds both false on staging. They mirror the flags of the
 * same name that scripts/ops/private-server-preflight.php reads: only an exact `true` admits a real mail transport
 * (`MAIL_MAILER=smtp`, implicit TLS) or Stripe live mode in the production profile. Admitting live mode stages a live
 * credential; it never turns production checkout on, which stays a reviewed code change (docs/ops/production-activation-packet.md).
 */
return [
    'real_mail_admitted' => env('VASEY_PRODUCTION_REAL_MAIL_ADMITTED', false),
    'live_stripe_admitted' => env('VASEY_PRODUCTION_LIVE_STRIPE_ADMITTED', false),
];
