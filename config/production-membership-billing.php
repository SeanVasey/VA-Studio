<?php

/**
 * Default-off Billing259 provider-evidence adapter. Nothing here is a price, currency, allowance or
 * account fact, and no value awards credits. Root binds the environment at registration; until then
 * every value is a literal default. The SDK and API versions are BillingProviderPin constants.
 */
return [
    'enabled' => false,
    'provider_io_enabled' => false,
    'account_ref' => null,
    'mode' => null,
    'approved_subscription_policy_hash' => null,
    'secret_key' => null,
    'webhook_secret' => null,
];
