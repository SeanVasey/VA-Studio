<?php

/**
 * Default-off write side of membership Billing (BL-69): Stripe Checkout in subscription mode and the
 * Customer Portal, test mode only. Everything here is staff-entered configuration and a literal default:
 * no price id, URL or portal configuration is invented in Git. The read-side switches, account and keys
 * stay in production-membership-billing; this file adds only what a write needs, and enabling it
 * approves nothing: the membership policy, the customer identity gate and the Billing policy must all
 * admit the call too, and `MembershipHttpMount` stays a literal false, so no HTTP route reaches it.
 */
return [
    'enabled' => false,
    // plan_version_id => Stripe price id (price_...) staff approved for that plan version. The call
    // re-reads the price from Stripe and refuses unless it matches the plan's currency and amount.
    'price_refs' => [],
    // Absolute URLs on this application's own host (https, or http on localhost in local/testing).
    // success_url may contain the literal {CHECKOUT_SESSION_ID} placeholder.
    'success_url' => null,
    'cancel_url' => null,
    'portal_return_url' => null,
    // Optional bpc_... id of the existing Customer Portal configuration; null uses the account default.
    'portal_configuration_ref' => null,
];
