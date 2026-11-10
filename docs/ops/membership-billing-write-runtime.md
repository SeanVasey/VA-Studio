# Membership billing: Stripe Checkout and Customer Portal (write side, test mode)

BL-69, phase 1. The domain services and two operator console commands exist and ship **off**. There is no HTTP route:
`production-memberships.http_mounted` stays the literal `false` (`MembershipHttpMount`), and a test asserts that no route or HTTP class
references these services. Nothing is sold at v1 (DD-50); this is reversible development behind the existing default-off policies. Live
mode, live keys and real prices are refused by `BillingPolicy` and need Sean's separate authorization.

## What exists

| Piece | Where | What it does |
| --- | --- | --- |
| Write gateway interface | `app/Domain/Memberships/Billing/BillingProviderWriteGateway.php` | `retrievePrice`, `createSubscriptionCheckoutSession`, `createPortalSession`. Separate from the retrieval-only `BillingProviderGateway`, which gained no write. |
| Stripe SDK implementation | `StripeSdkBillingWriteGateway.php` | The only write-capable Billing class. Pinned SDK and API version `2026-08-26.dahlia`, test-mode own-account key, no SDK retries, no open DB transaction, the key's account must equal `account_ref` before any write, parameters re-validated against an allowlist, failures redacted. |
| Subscription Checkout service | `MembershipSubscriptionCheckout.php` | Hosted Checkout Session in `subscription` mode for one signed-in member and one approved plan version. |
| Customer Portal service | `MembershipCustomerPortal.php` | Portal session for the member's own approved subscription customer. |
| Subscription events | `BillingWebhookIntake.php` | `customer.subscription.updated` and `.deleted` are retained as deduplicated hints. |
| Console | `membership-billing:test-checkout`, `membership-billing:test-portal` | Operator rehearsal, never scheduled. |

## Configuration (all staff-entered, all off by default)

`config/production-membership-billing-checkout.php` is a literal default-off file. Nothing in it is a price, URL or account fact, and Git
holds no real value.

| Key | Meaning |
| --- | --- |
| `enabled` | The write switch. Exactly `true` admits. |
| `price_refs` | `plan_version_id => price_...`: the Stripe test-mode price staff approved for that membership plan version. |
| `success_url`, `cancel_url` | Absolute URLs on this application's own host (`app.url`); https, or http only for `localhost` in `local`/`testing`. `success_url` may carry `{CHECKOUT_SESSION_ID}` once. |
| `portal_return_url` | Same rules. Where the Portal's "return" link goes. |
| `portal_configuration_ref` | Optional `bpc_...` id of the existing Customer Portal configuration. `null` uses the account default. |

A call also needs every existing gate: `production-membership-billing` (`enabled`, `provider_io_enabled`, `mode = test`, `account_ref`,
`approved_subscription_policy_hash`, `secret_key`), `production-memberships` (`enabled`, `provenance`, `approved_policy_hash` equal to the plan
version's hash), the customer identity gate, and an environment that admits test commerce. `BillingPolicy` is stricter than that last gate
and admits `local` and `testing` only, so staging refuses (`provenance`) until a separate decision widens it.

## Behaviour

**Checkout.** In order: environment, request key (16 to 80 characters of `[A-Za-z0-9_-]`), Billing policy, configured price, membership
policy, the plan version row (sealed, hash equals both approved hashes), the member proven current, no existing approved subscription for the
member (`already_subscribed`), then a **read** of the Stripe price. The price must be an active, test-mode, `recurring`, `licensed`, `per_unit`
price with no tiers, custom amount or quantity transform, whose currency and `unit_amount` equal the plan version's `billing_currency` and
`billing_price_minor`. Only then is the Session created: one line item at quantity 1, `billing_mode` `flexible`, our own opaque
`client_reference_id` and metadata (a domain-separated hash of the member, the plan version id, a purpose), no customer, email, discount,
trial, tax or Connect field. The answer must be a test-mode, open, `subscription` session echoing our reference and metadata, with
`amount_total` and `currency` equal to the plan's and an `https://checkout.stripe.com/` URL, or it is never handed out.

**Idempotency.** The Stripe idempotency key is a hash of the member, plan version, configured price and the caller's request key. The same
request key returns the original session; a new key is a new session. No random or time value enters the parameters, so a retry is
byte-identical (Stripe refuses a reused key with different parameters). Reusing a request key after the 24-hour session lifetime is the
caller's problem to avoid.

**Portal.** The Stripe customer comes only from the member's sealed v2 subscription approval (`BillingSubscriptionApprovals`), never from a
caller. No approval is `no_subscription`; approvals naming more than one customer are `ambiguous_source`. The session must be test mode, for
that customer, return to the configured URL and be an `https://billing.stripe.com/` URL. The URL is returned once and never stored or logged.
A request key names one user action: Stripe issues the portal URL short-lived, so a replay after it lapses would return an expired session;
use a fresh key per click.

**Binding is still staff.** Creating a Checkout Session grants nothing. After a rehearsal checkout completes, tying the new `sub_`/`cus_` to
the member is the existing staff approval (`BillingSubscriptionApprovals`). Automating that from `checkout.session.completed` is phase 2.

**Subscription events.** The webhook endpoint (`BillingWebhookIntake`) verifies the signature, the pinned API version, `livemode = false` and
no `account` (Connect) field, deduplicates by event id, and for `customer.subscription.updated` and `.deleted` also requires the embedded
object to be a subscription in the same mode. The event is stored as `no_invoice_hint` with its subscription reference sealed in the hint
payload. It never observes, settles, awards or queues a retrieval, so arrival order and repetition change nothing: **`invoice.paid`,
re-read from Stripe, stays the only event that settles a period.** A cancellation therefore ends future awards simply because no further
invoice is paid; it does not claw back a settled period. Enable these event types on the test-mode endpoint alongside the existing invoice
ones (`invoice.paid`, `invoice.payment_succeeded`, `invoice.payment_failed`, `invoice.voided`, ...).

## Smart Retries and the Customer Portal are Stripe settings, not code

- **Smart Retries** (retrying a failed renewal at machine-chosen times) is a Stripe Dashboard setting for the account's billing/subscription
  failed-payment rules. No code here turns it on or depends on it. Whichever retry rule is chosen, a retried payment that succeeds reaches
  us as `invoice.paid` and is settled the normal way; a failed or exhausted retry never awards. **Sean decides** the retry window and the
  action when retries are exhausted (cancel, mark unpaid or leave past due) with the lifecycle policy (U-10). Verify the control in the test-mode
  Dashboard before relying on this paragraph, because Stripe moves these screens.
- **Customer Portal.** A configuration already exists in the test account. What a member may do there (update payment method, cancel,
  switch plan) is set in that configuration, not here. Staff should confirm switching plans is off or limited to approved prices, because a
  plan change there would move the subscription to a price our approval did not bind, and `BillingSettlement` would refuse that invoice
  rather than award it.

## Rehearsal (test mode, local)

```
php artisan membership-billing:test-checkout <user-id> <plan-version-id> [--request-key=<key>] [--show-url]
php artisan membership-billing:test-portal <user-id> [--request-key=<key>] [--show-url]
```

Both print only a reason code on refusal. `--show-url` prints the bearer link; leave it off in shared terminals. Re-running checkout with the
same `--request-key` returns the original session.

## What Sean supplies (nothing is invented in Git)

1. The test-mode Stripe price id(s) for each membership plan version, created in the test Dashboard with the amount and currency of the
   plan version (BL-69 stores no price and reads Stripe's to compare).
2. The own host's return URLs (`success_url`, `cancel_url`, `portal_return_url`) and, if not the account default, the `bpc_` portal
   configuration id.
3. Whether a member may re-subscribe after cancelling. Phase 1 refuses a member who already has an approved subscription binding.
4. The retry/dunning window and exhaustion action (Smart Retries), and which Portal actions members get.

## Not built (phase 2)

HTTP mount, `checkout.session.completed` handling and automatic binding, a staff view of live subscription state (the read gateway already
has `retrieveSubscription`), re-subscribe after cancellation, tax, trials and coupons, and everything live.
