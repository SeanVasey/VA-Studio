# Merchandise fulfillment through Printful (BL-63, phase 1)

Status: **built behind a default-off flag, tested against a fake client only, never exercised against the real Printful
API.** There is no storefront, no merch line on the quote/order chain and no priced merch catalog. FP-010 and FP-088 are
not met; see their rows in `docs/remaining-parity-coverage.csv`.

Sean's decision (2026-11-10): merch development is not deferred, and fulfillment uses Printful print-on-demand. The store
sells and takes payment through the existing verified Stripe path; Printful receives an order only after durable verified
payment.

## What phase 1 contains

| Piece | Where |
| --- | --- |
| Flag and policy | `config/merch.php`, `Merch/Fulfillment/MerchFulfillmentPolicy.php` |
| Printful client interface, HTTP implementation | `Merch/Fulfillment/Printful/` |
| Fake client for tests | `tests/Support/FakePrintfulClient.php` |
| Record a paid order for fulfillment | `Merch/Fulfillment/RecordMerchFulfillment.php` |
| Submit to Printful, once | `Merch/Fulfillment/SubmitMerchFulfillment.php` |
| Signed webhook receiver | `Merch/Fulfillment/ReceivePrintfulWebhook.php`, `PrintfulWebhookController`, `POST /webhooks/printful` |
| Tables | `merch_fulfillments`, `merch_fulfillment_events` (migration `2026_11_10_286300_merch_printful_fulfillment.php`) |

## Representation

A merch line is **not** a quote line yet. The quote/order chain is built around licenses and rights scopes; threading
physical goods (a postal address, a shipping charge, stock) through it is phase 2. Phase 1 binds an existing store `Order`
that has a `VerifiedPayment` to a `merch_fulfillments` row (unique per order and per verified payment). The row holds the
Printful recipient and order items **encrypted** (application key), a hash of that request, Printful's order id and status,
the tracking number and URL, and a failure code.

- **Prices.** None are invented and none are sent. Merch drafts (`MerchDraft`) carry no price today; staff-entered prices
  arrive with the phase 2 catalog. `retail_price` is deliberately not accepted into the Printful request.
- **Shipping.** The shipping charge must come from Printful's rate quote (`PrintfulClient::shippingRates`, `POST
  /v2/shipping-rates`). Phase 1 only provides the call; nothing charges it yet.
- **Proof of what was charged.** Phase 1 trusts the caller's items for a paid order. Binding the items to the charged amount
  needs merch quote lines (phase 2). Until then nothing calls `RecordMerchFulfillment` from checkout, and the flag stays off.

## Flow and guarantees

1. `RecordMerchFulfillment`: refuses unless the flag admits it and a `VerifiedPayment` exists for the order; validates the
   recipient and items; creates one row per order. The same request again returns the row; a different request for the same
   order is `MERCH_REQUEST_CONFLICT`.
2. `SubmitMerchFulfillment`: re-checks the flag, the verified payment bound to this very row and that the order has no refund or
   dispute at all: an unreleased `refund_dispute_holds` row **or any `refund_dispute_objects` row** for it (a full refund records
   no hold, so the object is the signal; fail closed). It claims the row with a single conditional `UPDATE` (`recorded` to `submitting`, with a
   five-minute lease), so two workers cannot both submit. On the first attempt it creates a **draft** order carrying our
   `external_id` (`vasey-<public_id>`). On every later attempt it looks the order up by external id **first** and creates only
   if Printful says it does not exist. This is how a lost reply, a crashed worker or a replayed job never makes a second order.
3. A definite rejection (4xx) is recorded as `failed` with a code and is not retried automatically. A timeout, 429 or 5xx
   returns the row to `recorded` (outcome unknown) and reports `MERCH_PRINTFUL_UNAVAILABLE`. A missing or refused credential
   also returns it to `recorded`; it never strands an order as failed.
4. **Confirmation charges the Printful account**, so it needs its own literal-true switch, `MERCH_PRINTFUL_CONFIRM_ENABLED`.
   The check above runs again immediately before confirming, and the confirmation is claimed with one conditional `UPDATE`
   (`submitted` to `confirming`), so two callers cannot both send it. A definite rejection (4xx: the request did not take
   effect) returns the row to `submitted` with the failure code. An unknown outcome (timeout, 429, 5xx, or a 2xx whose body
   cannot be read) **stays `confirming`** with the lease cleared and the failure code recorded, because the charge may have
   landed. The next `handle()` on that row (or one whose confirming lease has lapsed) looks the order up by external id
   before anything is re-sent. That run first takes the row's reconciliation lease with a conditional update and holds it
   through the lookup and the decision, so a second worker never acts on the same or an older lookup. Printful `pending`,
   `inprocess`, `partial` or `fulfilled` marks it `confirmed`; `draft` re-confirms once, and only while the confirm switch
   is still on (hold check first); any other answer, a draft with the switch off, a missing order, or a failed lookup raises `MERCH_CONFIRMATION_UNRESOLVED` (409) and leaves the row in `confirming` for an operator.
   With it off (the default) a submission leaves a Printful draft that Sean can inspect and confirm in the Printful dashboard.
5. Nothing submits before verified payment: record and submit each check it, in code and by the unique key to the payment.

States: `recorded`, `submitting`, `submitted`, `confirming`, `confirmed`, `shipped`, `failed`, `canceled`.

### Resolving an unresolved `confirming` row (operator)

Find them: `select public_id, external_id, printful_order_id, failure_code, updated_at from merch_fulfillments where state = 'confirming' and (lease_expires_at is null or lease_expires_at <= now());`
Open the order in the Printful dashboard by `printful_order_id`. If it is confirmed or in production, set `state = 'confirmed'`
and `printful_status` to what Printful shows. If it is still a draft, confirm it in the dashboard (or leave it: the next run
re-confirms a draft once) and then mark it `confirmed`. If it is canceled or failed, set `state = 'canceled'` or `'failed'`
with the matching `failure_code`. Never re-send a confirmation by hand without looking at the Printful order first: a second
confirmation can charge the account twice. `MERCH_CONFIRMATION_UNRESOLVED` is the error `handle()` raises for these rows. A recovered order that Printful reports as neither a draft nor confirmed (canceled, failed, on hold, or unknown) is recorded as `submitted` with failure code `PRINTFUL_STATUS_UNEXPECTED`, and `handle()` raises `MERCH_PRINTFUL_STATUS_UNEXPECTED` for an operator. While another caller holds a confirmation or its reconciliation, `handle()` raises `MERCH_CONFIRMATION_IN_PROGRESS` instead of returning the row as if it were settled; an unexpected error during reconciliation releases the lease so the next run reconciles again.

## Webhook

`POST /webhooks/printful`, throttled to 60 requests a minute per client address, 404 while the flag is off, body limit 64 KiB, enforced on the bytes actually received (`PrintfulWebhookBodyLimit`, a bounded stream read before any parse or the flag check; `Content-Length` is not trusted) with 413 `PRINTFUL_WEBHOOK_TOO_LARGE`.

- **Signature** (Printful v2, "Event signing"): `x-pf-webhook-signature` is the hex HMAC-SHA256 of the raw body keyed with the
  hex-decoded secret key of the webhook configuration. An invalid or missing signature is refused with 401 before anything is
  parsed or stored. If `PRINTFUL_WEBHOOK_PUBLIC_KEY` is set, `x-pf-webhook-public-key` must equal it.
- **Replay.** Printful sends no event id, so the key is the hash of type, order id, shipment id and `occurred_at` (not
  `retries`). A unique key on `merch_fulfillment_events` rolls a replay back whole; the response says `duplicate`.
- **Effects, forward only.** `shipment_sent` records tracking and `shipped`; `order_failed` records `failed`
  (`PRINTFUL_ORDER_FAILED`, without Printful's free-text reason); `order_canceled` records `canceled`; `order_created` and
  `order_updated` update the Printful status. A late event never moves a shipped, failed or canceled order backwards. Events
  for orders that are not ours are stored as `unmatched`. No body or address is kept, only identifiers and a body hash.

## Unverified (no Printful credentials exist here)

Everything below was read from https://developers.printful.com/docs/v2-beta/ on 2026-11-10 and **never called**:

- `POST /v2/orders` (draft; field names `external_id`, `shipping`, `recipient`, `order_items`), `POST
  /v2/orders/{id}/confirmation`, `GET /v2/orders/@{external_id}`, `POST /v2/shipping-rates`, Bearer auth, `X-PF-Store-Id`.
- That a duplicate `external_id` on create is rejected or de-duplicated by Printful. The design does not depend on it.
- That the webhook HMAC covers exactly the raw body bytes, and the exact event payload fields used (`data.order.id`,
  `external_id`, `status`, `data.shipment.id`, `tracking_number`, `tracking_url`).
- Order item shapes other than `source: catalog` (store sync products), and the order statuses Printful really sends.
- The v2 API is documented as beta (`2.0.0-beta`).

## What Sean must supply before this can run anywhere

1. A Printful account, an API token (entered on the host as `PRINTFUL_API_KEY`; never committed), the store id if the token
   is account-level (`PRINTFUL_STORE_ID`), and Printful billing for confirmed orders.
2. A Printful v2 webhook configuration pointing at `/webhooks/printful` over HTTPS; its secret key becomes
   `PRINTFUL_WEBHOOK_SECRET_KEY` and its public key `PRINTFUL_WEBHOOK_PUBLIC_KEY`.
3. The merch catalog: which products and variants, their Printful catalog variant ids and artwork, and the retail prices.
4. Returns, refunds and shipping-charge policy for physical goods (U-12), and counsel review of the terms for physical sales.
5. The decision when to flip `MERCH_PRINTFUL_ENABLED` (and, separately, `MERCH_PRINTFUL_CONFIRM_ENABLED`) on staging.

## Gates, holds and classification

- `MerchFulfillmentPolicy` is admitted only where `TestEnvironment::admitsTestCommerce()` is (local, testing, staging) and the
  flag is literally `true`. It is listed with the admitting gates in `StagingEnvironmentAdmissionTest`.
- `ops/production/validate-runtime.php` refuses `MERCH_PRINTFUL_ENABLED` and `MERCH_PRINTFUL_CONFIRM_ENABLED` unless
  `false`, in the environment file and in the effective configuration (own delimited block).
- Both tables are classified in `RetentionSchedule` as held with the order. **Gap:** the customer data-rights export and
  deletion workflow do not read them yet, although the encrypted recipient is customer data. That must be closed before the
  flag is lifted anywhere real.
- Not done in phase 1: storefront, cart and checkout for merch lines, a shipping charge, stock, returns, a refund path for an
  order Printful has already shipped, staff screens for failed fulfillments, and a queue job or command that submits
  `recorded` rows.

## Tests

`tests/Feature/Merch/MerchPrintfulFulfillmentTest.php` and `tests/Feature/Merch/PrintfulWebhookTest.php` (SQLite, fake
client, `Http::preventStrayRequests()`), plus the merch block in `tests/ops/test_production_runtime_validator.py`. Race
behavior of the claim and the unique keys is reasoned and tested on SQLite only unless the native MySQL result is recorded
in the commit.
