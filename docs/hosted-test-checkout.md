# Hosted Stripe test checkout

Status: merged in [PR #62](https://github.com/VASEYDEV/VASEYAUDIO/pull/62), 2026-09-26. Accepted candidate `90c72743`, tree `7989c979`, reached main `10e0f218` after final CI and two independent Codex reviews. Merged [PR #52](https://github.com/VASEYDEV/VASEYAUDIO/pull/52) supplies the immutable [order preparation](order-preparation.md) prerequisite. This acceptance uses synthetic provider fixtures, not an actual Stripe transaction or production deployment. Subsequent [payment processing](test-payment-processing.md) merged in PR #63. The [finalization candidate](test-payment-finalization.md) has separate acceptance gates.

## Explicit test boundary

Fresh checkout requires `local` or `testing`, `STRIPE_TEST_CHECKOUT_ENABLED=true`, `STRIPE_MODE=test`, an own-account `STRIPE_ACCOUNT_ID`, and `VASEY_TEST_CHECKOUT_POLICY`. The SDK additionally requires a valid `sk_test_` credential in `STRIPE_TEST_SECRET_KEY`. Credentials remain host secrets. Staging and production cannot enable this path. A connected ChatGPT Stripe account does not configure the Laravel runtime.

The policy is a strict JSON object with exactly these fields and types; this example uses a synthetic local return origin:

```json
{
  "schema_version": 1,
  "purpose": "test_hosted_checkout",
  "version": "local-fixture-v1",
  "provider_lifetime_seconds": 3600,
  "retry_seconds": 900,
  "pending_resources": "retain_until_authoritative_finalization",
  "tax": "fixed_test_zero",
  "return_origin": "http://localhost:8000"
}
```

Missing, extra or differently typed fields fail closed. Lifetime and retry values are exactly 60 and 15 minutes; they are not configurable production policy. The origin is HTTPS, or HTTP on localhost/loopback, with no path, query, fragment, credentials or trailing slash. Success and cancel URLs are constructed on the server using the retained origin and opaque order UUID.

An existing prepared order must still have verified pending resource bindings. Its intent must be committed before the original order-attempt deadline, checked again before transaction completion. The intent freezes that deadline, creation time, retry deadline and provider expiry. The 60-minute provider expiry is measured from intent creation; retry never changes it. This deliberately separates initial eligibility from provider uncertainty: an existing intent can retry during its retained 15-minute window after the old attempt deadline. Expiry does not release inventory or promotion capacity. Those pending resources remain retained until separately verified finalization. The dependent finalization candidate admits only confirmations observed strictly before the original attempt expiry; a later confirmation becomes a paid exception under its explicit test policy even if provider session creation/retry was still allowed.

## Frozen amount mapping

`CheckoutEvidence` accepts USD fixed test tax at exactly zero, tied to the same Stripe account. The retained total must be 50–99,999,999 minor units. Every line has quantity one, zero tax and a total equal to its post-discount tax basis. That basis becomes Stripe `unit_amount`; existing local discounts are already reflected in the line amounts. Stripe promotion codes, automatic tax and adaptive pricing are disabled. No second provider discount is applied.

The request uses payment mode, card only, generic numbered test-license product names and opaque order/attempt/intent metadata on both session and PaymentIntent creation data. It does not send retained buyer identity or full license terms. API version is pinned to `2026-08-26.dahlia`. This narrow mapping does not implement production tax, refunds, invoicing, customer verification or additional payment methods.

## Durable intent and provider boundary

A short order-row transaction creates at most one immutable checkout intent and its audit before provider I/O. The canonical request and captured policy are encrypted with authenticated Laravel encryption; integrity hashes cover ciphertext. The stable key is `vasey-checkout-v1-{intent UUID}`. Retained verification reconstructs the exact request from immutable order evidence rather than today's mutable pricing or publication state.

Provider I/O occurs outside database transactions. The adapter rejects transactions on every already-open Laravel connection, live credentials, Connect/account/context overrides and disabled TLS verification. It uses the official API base, verifies the credential's own account with `GET /v1/account`, disables SDK network retries, and applies a three-second connect timeout and ten-second request timeout. Its synchronous SDK transport override is restored after each call, including failures. Raw SDK errors are replaced with a generic unavailable result without chaining their request bodies, secrets or private URLs.

Hosted session creation has no dispatch lease, automatic queue or exactly-once outbound-call claim. Merged payment processing introduces leases for receipt processing, not for creating hosted sessions. Concurrent calls can reach Stripe with the same retained request and exact provider idempotency key. A crash or timeout leaves the durable intent recoverable. Before a create, the service checks the retry deadline again after account lookup. After 15 minutes it makes no further create attempt without a known session binding; an unknown outcome becomes `reconciliation_required`.

On retrieval, the adapter expands line items and, if necessary, makes one bounded `limit=100` line-item request. A remaining partial list fails closed. Domain validation compares test mode, object/session identity, account context, currency, order reference, exact metadata, expiry, totals, zero discount/tax/shipping, card-only methods and the complete quantity-one line amount multiset. A syntactically valid PaymentIntent ID is retained only as a locator; it is not authoritative payment verification.

## Immutable binding and observations

The first validated response binds one provider session to the intent. Its allowlisted evidence, including the private checkout URL, is encrypted; subsequent observations are append-only. Raw customer details and provider error bodies are not retained. Model/database guards and uniqueness constraints preserve intent, binding and observation evidence.

Observations permit `open`, `complete` and `expired`. An older in-flight `open` response cannot replace a terminal observation. A contradictory terminal status fails closed. This monotonicity concerns session status, not settled payment state. A stored provider `payment_status=paid`, redirect arrival or valid webhook signature does not mark an order paid or issue rights.

## Owner API and recovery

| Route | Behavior |
| --- | --- |
| `POST /orders/{order}/checkout` | Empty JSON object only; initiate or recover the same intent/session. |
| `GET /orders/{order}/checkout` | Owner-checked retained projection only; no provider request or mutation. |
| `POST /orders/{order}/checkout/reconcile` | Empty JSON object only; explicitly retrieve a known session or retry an unresolved intent within its window. |
| `GET /orders/{order}/checkout/return` | Owner-checked return page; query parameters prove nothing and do not finalize payment. |

POSTs reject query parameters and nonempty bodies. Standard session ownership, CSRF and route throttles apply. Responses are private/no-store with cookie variance, no-referrer, nosniff and noindex headers. Unknown or wrong-owner orders do not disclose checkout evidence. The legacy `POST /checkout` still returns 503.

Projection states are `not_started`, `pending`, `reconciliation_required`, `open`, `complete` or `expired`. Only a validated open session before its frozen expiry exposes the allowlisted `https://checkout.stripe.com/c/pay/cs_test_…` URL to its owner. Local time expiry hides the URL and requests reconciliation; it does not invent a provider terminal result. The existing checkout projection reports `paymentStatus: not_verified` and `fulfillmentStatus: not_started`. The existing order projection changes payment status from `not_started` to `not_verified` once an intent exists. These historical browser contracts remained unchanged in PR #63: its internal immutable confirmation was not exposed as new customer status. The separate [finalization candidate](test-payment-finalization.md#customer-status) adds `verified`, finalization status and pending-contract/blocked fulfillment to owner-checked reads. Verified payment hides checkout/retry actions and provider URLs; browser reads still never perform payment or finalization.

Console recovery is manual and does not depend on transient after-commit dispatch:

```sh
php artisan vasey:reconcile-test-checkout --limit=25
php artisan vasey:reconcile-test-checkout INTENT_UUID
php artisan vasey:reconcile-test-checkout INTENT_UUID --session=cs_test_KNOWN_LOCATOR
```

The default scan selects intents with no bound session whose retained retry deadline is still in the future, oldest first, with a limit from 1 to 100. This prevents aged unknown outcomes from starving retryable intents. It does not discover unknown Stripe sessions or scan all existing session observations. A specific intent retrieves its bound session. An explicit `--session` requires a specific intent and supplies only a locator: the retrieved object must pass all retained evidence checks and cannot replace a different binding. This permits recovery after an uncertain creation has outlived the retry window. Without a locator, that expired unknown intent remains unresolved. Output contains only opaque intent IDs and status/generic errors, never private URLs or SDK bodies.

## Verification, rollback and next dependency

[Final CI 36217210794](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36217210794) passed 888 MySQL tests / 7,574 assertions, 831 SQLite tests / 6,346 assertions with 57 intentional MySQL-only skips, 150 frontend tests, 12 Chromium/WebKit browser cases, build and dependency audits. Coverage includes gateway HTTP fixtures, wrong-account/mode/amount/metadata rejection, timeout/replay, owner/CSRF/read-only return behavior and independent-process MySQL intent/binding/observation races. Two independent Codex reviews accepted the final source. The PR retains earlier failing discovery/setup runs and their corrections. These executed fixtures and browser checks do not establish real Stripe interoperability or physical-device acceptance.

Disabling fresh initiation does not erase durable uncertainty. Retained recovery still verifies environment, account and credentials and uses the original request/policy; do not delete intents, replay under a new key or release resources merely because a return or timeout occurred. Do not remove evidence tables during rollback.

Merged PR #63 implements [authoritative test PaymentIntent validation and durable inbox processing](test-payment-processing.md). The dependent [finalization candidate](test-payment-finalization.md) extends historical verification and introduces atomic resource effects, unique grants with frozen render input, pending entitlements/outbox or `paid_exception`, followed by WP-08 deterministic contracts, activation and secure delivery. Each candidate requires its own acceptance. These increments do not close WP-07, any production policy gate, the 103-item parity ledger or migration/cutover obligations.

## Official references

- [Create Checkout Session](https://docs.stripe.com/api/checkout/sessions/create): provider expiry bounds and request fields; this application's stricter 60-minute policy is local.
- [Idempotent requests](https://docs.stripe.com/api/idempotent_requests): stable key/request replay; local durable recovery and the 15-minute send window remain application responsibilities.
- [Retrieve Checkout Session](https://docs.stripe.com/api/checkout/sessions/retrieve)
- [List session line items](https://docs.stripe.com/api/checkout/sessions/line_items): expanded lists may require retrieval.
- [Checkout fulfillment](https://docs.stripe.com/checkout/fulfillment): a browser return is insufficient payment/fulfillment evidence.
