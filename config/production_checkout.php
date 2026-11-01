<?php

// A real environment supplies numeric values as strings; ExecutionContextV1 admits only an int.
// Anything else stays as supplied and is refused as unsupported rather than coerced.
$reviewLifetime = env('PRODUCTION_CHECKOUT_REVIEW_LIFETIME_SECONDS');
if (is_string($reviewLifetime) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $reviewLifetime) === 1) {
    $reviewLifetime = (int) $reviewLifetime;
}

return [
    // The composition gate (lane PCC, ProductionCheckoutMount). Literal false: only an exact boolean true, in local, testing or
    // production, registers ProductionCheckoutServiceProvider, mounts routes/production-checkout.php and admits the operator
    // reconciliation command; ProductionCheckoutPrivacy re-checks it on every request. Turning it on is a reviewed code change,
    // and ops/production/validate-runtime.php holds it false. Mounting approves nothing: every switch below stays as configured.
    'mounted' => false,
    'http_enabled' => env('PRODUCTION_CHECKOUT_HTTP_ENABLED', false),
    'fresh_checkout_enabled' => env('PRODUCTION_CHECKOUT_ENABLED', false),
    'reconciliation_enabled' => env('PRODUCTION_CHECKOUT_RECONCILIATION_ENABLED', false),
    // The retrieval-only operator command (`vasey:production-checkout-reconcile`). It also needs the mount, reconciliation and
    // provider I/O switches; it never creates a session or a payment, only retrieves and retains what the provider reports.
    'operator_reconciliation_enabled' => env('PRODUCTION_CHECKOUT_OPERATOR_RECONCILIATION_ENABLED', false),
    'provider_io_enabled' => env('PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED', false),
    'exemption_authoring_enabled' => env('PRODUCTION_CHECKOUT_EXEMPTION_AUTHORING_ENABLED', false),
    'committed_read_receipts_enabled' => env('PRODUCTION_CHECKOUT_COMMITTED_READ_RECEIPTS_ENABLED', false),
    'committed_read_receipt_version' => 'production-checkout-committed-read-v1',
    'funds_mode' => env('PRODUCTION_CHECKOUT_FUNDS_MODE'),
    'account_id' => env('PRODUCTION_CHECKOUT_STRIPE_ACCOUNT_ID'),
    'secret_key' => env('PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY'),
    'return_origin' => env('PRODUCTION_CHECKOUT_RETURN_ORIGIN'),
    'review_lifetime_seconds' => $reviewLifetime,
    // Deployment-owned IDs delegate approval of a scoped qualification policy.
    // They are not evidence of a tax exemption or professional qualification.
    'exemption_policy_owner_ids' => [],
];
