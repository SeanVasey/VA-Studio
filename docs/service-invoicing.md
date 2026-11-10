# Service invoicing (BL-70 phase 1, Stripe test mode only)

Staff can issue a Stripe invoice for a service project's authored deposit or balance, and a verified paid invoice moves the
project's `paymentState`. This is development evidence behind several default-off gates. It uses Stripe **test mode only**, never
live funds. DD-03 still defers service payments past v1, and nothing here completes a project, authorizes delivery or grants rights.

## Gates (all must hold)

| Gate | Where |
|---|---|
| `TestEnvironment::admitsTestCommerce()` (local, testing, staging) | `ServiceInvoicePolicy`, `StripeSdkInvoiceGateway` |
| `VASEY_TEST_SERVICE_INVOICING_ENABLED` / `services-invoicing.test_enabled`, strictly `true` (default `false`) | `config/services-invoicing.php` |
| The service-project flag (`services-projects.test_enabled`) | `ServiceProjectPolicy` |
| `payments.stripe.mode = test`, own account id, exact `sk_test_` key | policy and gateway |
| Webhook and payment-processing flags for settlement (`STRIPE_WEBHOOK_ENABLED`, `STRIPE_TEST_PAYMENT_PROCESSING_ENABLED`) | existing receiver and `PaymentProcessingPolicy` |

Production refuses by environment, and `ops/production/validate-runtime.php` additionally refuses the flag unless it is `false`
or unset (`holds.vasey_test_service_invoicing_enabled`, `LAUNCH_OFF_HOLDS`); the cutover check (PCL-13) lists it among the
test-commerce flags. Flag off: the staff command answers 404, the operator actions are hidden and invoice webhook events are
acknowledged and recorded as `unsupported` (`service_invoicing_off`).

The Stripe test webhook endpoint must also subscribe to `invoice.paid`, `invoice.payment_failed`, `invoice.voided` and
`invoice.marked_uncollectible`. The endpoint, API version and secret are the existing ones (`/webhooks/stripe`).

## Issuing

On the project page in the admin panel (MFA-satisfied staff), "Issue test deposit invoice" and "Issue test balance invoice"
take one input, the number of days until due (1 to 90, staff-set, no default). Nothing numeric comes from the caller.

- **Deposit**: the accepted quote's `depositMinor`, for a project that is accepted, in progress, awaiting review or scope reviewed.
  A zero deposit has nothing to invoice.
- **Balance**: `totalMinor - depositMinor`, only once every milestone is approved (`scope_reviewed`). That timing is a staff
  safeguard, not a payment term (open question below).
- USD only. Any other quote currency is refused. Amounts are integer minor units.
- The command reserves a local row first (`service_invoices`, unique on project, kind, quote and revision), then asks Stripe, then
  records the provider ids. The idempotency key is `sha256(service-invoice-v1|project|kind|quote|revision)` and each Stripe step
  adds its own prefix, so a repeat after a crash or timeout resumes the same row and cannot create a second invoice. A repeat
  with different terms is a 409. A voided invoice is re-issued as the next revision, with a new key.
- Stripe objects: a Customer (created once per account, then reused; only the account email and an opaque project reference are
  sent), a draft invoice (`collection_method=send_invoice`, `days_until_due`, `currency=usd`, `auto_advance=false`, pending
  invoice items excluded, metadata naming the local invoice and project), one line for the authored amount, then finalize.
  `auto_advance=false` means Stripe does not email or advance the invoice on its own; staff share the **hosted invoice page** URL
  shown on the project page. The returned invoice is checked against the local row (customer, total, amount due, currency,
  collection method, open status, due date, hosted URL on `invoice.stripe.com`) before anything is recorded.
- The gateway pins `2026-08-26.dahlia`, requires an exact `sk_test_` key and proves the configured account through
  `GET /v1/account` before any other request, sets no network retries, refuses to run inside a transaction, and surfaces only a
  constant error (SDK exceptions can carry keys and email addresses).

## Settlement

The existing receiver verifies the signature, `livemode=false`, own account (no `account` or `context`) and deduplicates by
event id. `ProcessStripeReceipt` routes the four invoice events to `ServiceInvoiceSettlement`:

1. The event is a locator only. Its type, status and amounts are never read.
2. The invoice is re-read from Stripe through the own-account test-mode gateway. A provider outage is a retry.
3. A re-read invoice that no local row names is `unsupported` (`not_a_service_invoice`), so other uses of the account's Stripe
   invoices are untouched. A local row stuck in `creating` (crash after Stripe finalized, before the local record) is adopted
   when the invoice's metadata names it and its facts match.
4. The re-read object decides: `paid` records a payment only when `amount_paid` equals the authored amount, `amount_remaining`
   is 0, the currency is USD, `collection_method=send_invoice`, the customer and metadata match, nothing was paid off Stripe,
   overpaid or credited, and a paid time exists. Any contradiction is **quarantined** with no payment recorded (the existing
   receipt work shows `quarantined / payment_changed`). `void` and `uncollectible` follow the provider; `open` after
   `invoice.payment_failed` only counts a failure. A voided invoice that later reads paid, or a paid invoice that later reads
   void, is a contradiction and is quarantined.
5. A verified payment is one immutable `service_invoice_payments` row (unique per invoice and per Stripe invoice id, tied to the
   receipt that verified it, with a hash of the compared provider facts). Redelivery or a second event for a settled invoice
   only re-checks the evidence (`already_paid`).

`paymentState` on the project projection is derived, never stored or commanded: `not_collected` with no verified payment for the
current quote, `partially_paid` below the quote's `totalMinor`, `paid_in_full` at or above it. `deliveryAuthorized` stays `false`.
The project commands are unchanged: none of them can invoice, mark paid, complete or deliver, and a test pins that.

## Data

| Table | Meaning | Retention class |
|---|---|---|
| `service_invoices` | Coordination row per invoice: `creating`, `open`, `paid`, `void`, `uncollectible`. Identity columns are fixed by trigger, paid and void are final, and `paid` needs a matching payment row. Never deleted. | indefinite, held with the project |
| `service_invoice_payments` | Immutable verified service payment. Insert allowed only when it matches an open or uncollectible invoice's project, quote, amount and currency. Never updated or deleted. | indefinite |

Both tables carry SQLite and MySQL triggers (migration `2026_11_10_287000_service_invoices.php`). They do not touch orders,
`verified_payments`, entitlements or grants.

## Not built (phase 2 and later)

- No project completion or delivery command, no entitlement, no refund, void or credit-note command (void and credit in the Stripe
  Dashboard if needed; the lane follows the provider). No per-milestone amounts: a quote authors only a total and a deposit.
- No live mode, no Stripe Tax or tax computation, no customer-facing delivery of the hosted URL beyond staff sharing it.
- No real Stripe call has been made by this change. The SDK gateway is tested against a recording transport and the settlement
  against a fake gateway; the request shapes for the pinned API version (`invoices.create`, `add_lines`, `finalizeInvoice`) are
  taken from the installed `stripe-php` types and still need one test-mode rehearsal.

## Open questions for Sean

1. **Tax.** Invoices carry no tax; amounts are exactly the authored totals. Whether service invoices need tax (Stripe Tax or
   authored lines) is undecided.
2. **Refund and cancellation terms.** The quote's cancellation text is retained but no refund path exists. What happens to a paid
   deposit on cancellation is undecided.
3. **Balance timing and milestone amounts.** The balance is invoiced after every milestone is approved. If milestones should carry
   their own authored amounts, the quote schema needs that field first.
4. **Customer email at Stripe.** The buyer's account email is sent to Stripe (test mode here) to create the Customer. Confirm
   that is acceptable for staging, and what the privacy page should say before any live use.
5. **Who shares the hosted URL.** Phase 1 relies on staff sending the hosted invoice page to the buyer; no email is sent by the app.
