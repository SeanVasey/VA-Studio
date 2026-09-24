# [WP-07] Hosted checkout, durable payment inbox and idempotent finalization

Status: **Stripe test-event receipt prerequisite implemented; private test order preparation is the current implementation candidate. The broader work package and issue #7 remain open.** Hosted sessions and verified payment/terminal business effects remain dependent work. This issue is complete only when the acceptance evidence below exists.

- Suggested issue title: `[WP-07] Hosted checkout, durable payment inbox and idempotent finalization`
- Phase: 2
- Dependencies: WP-06; WP-04 grants schema and WP-08 render interface must agree. Live payments require U-04/U-05/U-08.
- Suggested branch: `work/wp-07-payment-inbox-and-finalization`
- Implementation paths: app/Domain/Commerce payments, provider adapters, webhook routes, inbox/outbox migrations and payment integration tests.

## Problem

Only an authoritative successful payment may grant rights, despite duplicate, delayed or out-of-order provider events.

## First reviewable increment

Stripe test-mode hosted checkout through a verified durable inbox to one atomic paid-order/grant effect.

### Receipt prerequisite — 2026-09-09

The dependency-ready slice is `POST /webhooks/stripe`: official SDK raw-body verification, explicit test/own-account configuration, bounded stateless input, immutable encrypted receipt storage, account/mode/event uniqueness, substantive replay conflict detection and a metadata-only operator command. Unknown snapshot events are retained without being treated as payment success. No order or worker is dispatched from a receipt.

This prerequisite can be developed before WP-06 payable quote/assent and WP-04/WP-08 grant/render contracts are complete. It does not satisfy the full checkout-to-grant milestone above. See [receipt contract, configuration and recovery](../stripe-webhook-inbox.md). The integrating PR records the exact tested commit and CI evidence, including independent-process MySQL receipt races.

This historical receipt prerequisite precedes the accepted WP-06 integration. The current handoff below supersedes its original next-task order.

### Order preparation prerequisite — 2026-09-24

After merged PR #51, the current candidate adds [test order preparation](../order-preparation.md) under [D-13](../architecture/D-13-order-preparation.md): owner-checked complete review and affirmative assent, encrypted immutable order/line evidence and one atomic pending inventory/promotion attempt. It preserves legacy snapshots/attempts, rejects client totals, keeps buyer identity unverified and infers no marketing consent. Fresh preparation requires an explicit local/testing policy absent by default, existing fixed test tax and valid scoped inventory. Private canonical capture is bounded to 16 MiB.

The storefront renders all frozen terms and amounts before assent, fixes the body/key during uncertain-result retry and requires renewed assent after recognized validation or stale-review rejection. It persists only bounded opaque recovery locators, never buyer details or order request bodies/keys. Owned status recovery remains separate from fresh preparation, including after expiry or policy withdrawal. Identical owner-scoped retries remain possible after quote expiry or current policy/publication changes. `/checkout` stays 503: no provider session, payment, grant, contract or entitlement is created.

The integrating PR records the actual final candidate, MySQL/SQLite and frontend/browser outcomes, privacy/rollback/race coverage and independent review. Test definitions or this status text do not establish passing verification. The broad acceptance checklist below remains open.

Next dependency: create/reconcile hosted Stripe test sessions with stable provider idempotency outside database locks; add durable inbox processing/recoverable dispatch and authoritative account/mode/object/amount/currency/tax validation. Implement verified terminal inventory/promotion effects, idempotent paid/grant effects or `paid_exception` and the agreed WP-08 grant/render interface. **The current historical order verifier requires pending resource bindings; extend/version it before terminal transitions so original order evidence stays readable.**

## Scope

- Environment/account-scoped provider adapter and stable session idempotency; reconcile timeouts before creating duplicates.
- Raw-body signature verification, durable inbox before acknowledgment and queued processing.
- Authoritative payment/amount/currency/quote checks plus order/inventory lock and business-effect unique constraints.
- Atomic paid/grant/pending-entitlement/outbox creation, or paid_exception without fulfillment when preconditions fail.
- Customer pending/success/failure status and operator exception/reconciliation queue; redirect is read-only.

## Acceptance criteria

- [ ] Invalid signatures/environments create no business effects; duplicate event IDs and distinct events for the same payment cannot duplicate effects.
- [ ] Browser success return without payment grants nothing.
- [ ] Delayed/out-of-order events and worker retries converge to valid states with immutable payment evidence.
- [ ] Late successful exclusive payment records paid_exception with no grant/document/entitlement; prior valid grants stay intact.
- [ ] Provider totals, currency and tax policy must match; reconciliation reports discrepancies instead of treating them as success.

## Verification

Provider contract fixtures and test-mode scenarios: duplicate/replay, missing event, asynchronous success/failure, timeout, wrong amount/currency, race and poison message. Capture redacted IDs and actual state counts. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Disable new checkout; keep verified inbox receipt/reconciliation available for already initiated payments. Do not erase payment/grant rows or mark paid money unpaid.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-07 in provider test mode only until recorded gates permit live use. Read current official provider docs. Use durable inbox/outbox and unique payment-object/order-line effects, then verify replay and late-payment behavior. Never fulfill from a redirect or signature alone.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.

## Current sequencing — 2026-09-24

The [ordered status](../development-order.md) records accepted WP-06 PR #51 and the current private order/assent prerequisite. Continue hosted test checkout and verified terminal effects next, then WP-08 contracts/entitlements; keep unresolved production media/security/legal/tax/customer-recovery and launch gates explicit. This supersedes the September 9 pre-Stripe priority note without treating those external gates or broad package criteria as completed.
