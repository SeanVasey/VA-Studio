# [WP-07] Hosted checkout, durable payment inbox and idempotent finalization

Status: **Stripe test-event receipts, private test orders, hosted test checkout and durable authoritative payment verification are merged. Local/testing finalization, grants, pending fulfillment and customer status merged in PR #64. The broader work package and issue #7 remain open.** Operator exception resolution, production payment policy and the acceptance evidence below remain required.

T19-OPS-01 now has a bounded [operational history candidate](../test-payment-exception-operations.md): audited acknowledgment/review dispositions and leased own-account GET-only test-payment observations. It preserves terminal `paid_exception`, pending resources and blocked fulfillment. This is not financial resolution or completion of T19; the integrating merge request must bind its final tests, review and acceptance.

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

Merged PR #52, following PR #51, adds [test order preparation](../order-preparation.md) under [D-13](../architecture/D-13-order-preparation.md): owner-checked complete review and affirmative assent, encrypted immutable order/line evidence and one atomic pending inventory/promotion attempt. It preserves legacy snapshots/attempts, rejects client totals, keeps buyer identity unverified and infers no marketing consent. Fresh preparation requires an explicit local/testing policy absent by default, existing fixed test tax and valid scoped inventory. Private canonical capture is bounded to 16 MiB.

The storefront renders all frozen terms and amounts before assent, fixes the body/key during uncertain-result retry and requires renewed assent after recognized validation or stale-review rejection. It persists only bounded opaque recovery locators, never buyer details or order request bodies/keys. Owned status recovery remains separate from fresh preparation, including after expiry or policy withdrawal. Identical owner-scoped retries remain possible after quote expiry or current policy/publication changes. The preparation increment creates no provider session, payment, grant, contract or entitlement. Legacy `/checkout` stays 503; the merged hosted increment uses distinct owned order routes.

PR #52 merged at `5e53c2cc57938bfc1b55bfdff4d8b22c1c70fb08` with 797 MySQL tests / 6,396 assertions, 745 SQLite tests / 5,303 assertions and 52 intentional skips, 91 frontend tests, 10 browser cases, all build/audit gates and independent review. The broad acceptance checklist below remains open.

### Hosted test-session acceptance — 2026-09-26

Merged [PR #62](https://github.com/VASEYDEV/VASEYAUDIO/pull/62) implements [hosted test checkout](../hosted-test-checkout.md) under [D-14](../architecture/D-14-hosted-test-checkout.md). Explicit local/testing policy freezes a 60-minute expiry, 15-minute retry window, post-discount fixed-zero-test-tax mapping and retained pending resources. One encrypted durable request is committed before provider I/O, using the same exact idempotency key for concurrent retries. Encrypted session bindings and append-only observations reject terminal regressions; owner GET/return reads do not mutate, and explicit POST/console reconciliation handles uncertainty. There is no dispatch lease or automatic queue. Default console scanning covers only still-retryable unknown intents; aged unknown outcomes require explicit verified session-locator recovery.

Accepted candidate `90c72743`, tree `7989c979`, reached main `10e0f218`. [CI 36217210794](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36217210794) passed 888 MySQL tests / 7,574 assertions, 831 SQLite tests / 6,346 assertions with 57 intentional MySQL-only skips, 150 frontend tests, 12 browser cases, build and dependency audits. Two independent Codex reviews accepted the final source. No actual Stripe transaction or production evidence is claimed. The hosted increment's order/checkout status remains payment-unverified after initiation even if a session observation says complete or paid.

### Durable processing and payment-evidence acceptance — 2026-09-26

Merged [PR #63](https://github.com/VASEYDEV/VASEYAUDIO/pull/63) implements [test payment processing](../test-payment-processing.md) under [D-15](../architecture/D-15-test-payment-processing.md). Separate durable receipt work uses UUID claims, bounded leases, attempt/backoff policy and expired-token fencing. An ID-only asynchronous job and bounded scanner recover missed dispatch; manual replay is explicit and audited. Provider Session/PaymentIntent retrieval happens outside transactions and verifies the retained account/mode/order/attempt/intent/session mapping, exact amount/currency and fixed zero test tax before immutable confirmation. Unknown, pending and authorized outcomes cannot become a successful payment merely because an event or return says so.

Supported verification includes `automatic_async` capture without requiring asynchronous Charge accounting fields. Receipt processing and missing-webhook reconciliation stop at `awaiting_finalization`. No order-paid transition, resource consumption/release, grant, contract, entitlement, refund or live activation occurs. Existing owner projections remain unchanged; internal confirmation is not yet a customer-status API. Processing is disabled by default, local/testing only, and independently gated from fresh hosted checkout. It can recover retained evidence after the fresh-checkout policy is withdrawn.

Accepted candidate `4107d449` reached main `89d7ea1`. [CI 36220050595](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36220050595) passed 145 focused payment tests / 1,092 assertions, 1,033 full MySQL tests / 8,651 assertions, 971 SQLite tests / 7,392 assertions with 62 intentional MySQL-only skips, 150 frontend tests, 12 browser cases, build and audits. Two independent Codex reviews accepted the final source. No actual Stripe transaction is claimed.

### Finalization and frozen grant-interface acceptance — 2026-09-26

[Test payment finalization](../test-payment-finalization.md) and [D-16](../architecture/D-16-test-payment-finalization.md) define the accepted PR #64 increment. A separate disabled-by-default local/testing policy requires authoritative confirmation observed strictly before the original attempt expiry. Finalization may run later; the cutoff is local observation time, not asserted provider payment time. One transaction consumes inventory/promotion resources, records shared-scope unique exclusive sales, one immutable grant per line, encrypted original buyer/seller/assent/selection/pricing/disclosure render input, pending exact-asset entitlements and a pending outbox. An explicit safety failure records `paid_exception`, retains pending resources and creates no grant or entitlement. Corrupt retained evidence fails closed; transient failures remain retryable.

A separate complete-graph verifier preserves the original order/attempt hashes and requires all terminal effects to match on reads/retries. ID-only asynchronous dispatch happens after payment commit, and the scoped UUID-cursor command recovers missed dispatch without provider I/O. Customer read-only status distinguishes verified payment, awaiting finalization, paid/pending contracts and paid exception/blocked fulfillment. This increment does not render PDFs, activate entitlements, issue downloads, resolve exceptions, release unpaid resources, refund money or enable live commerce. Merged [PR #64](https://github.com/VASEYDEV/VASEYAUDIO/pull/64) accepted candidate `cf7657abe897d610a2cf995b7ae4437680717280`, tree `b152cb8ddf32c2760ad5c8b52e8713521369576e`, on main `69b28a9ed108b31a9f6a4498c79c41fd357b12fb`. [CI 36244728854](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36244728854) passed 75 focused finalization tests / 814 assertions, 1,108 full MySQL tests / 9,499 assertions, 1,042 SQLite tests / 8,127 assertions with 66 intentional MySQL-only skips, 179 frontend tests, 12 browser cases, build and dependency audits. Two independent Codex reviews accepted the final source. These are synthetic provider-fixture results, not an actual Stripe transaction or production acceptance.

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

## Current sequencing — 2026-09-26

The [ordered status](../development-order.md) records accepted WP-06 PR #51, order/assent PR #52, dependency integration PR #61, hosted checkout PR #62, payment verification PR #63 and finalization PR #64. Complete WP-08 private original-PDF candidate verification, then guarded activation/downloads. Operator exception resolution, verified unpaid release/refund/dispute workflows and actual test-account interoperability remain open. Keep unresolved production media/security/legal/tax/customer-recovery and launch gates explicit. This supersedes earlier candidate and pre-Stripe priority notes without treating those external gates or broad package criteria as completed.


### Dependent WP-08 implementation — 2026-09-26

[PR #64](https://github.com/VASEYDEV/VASEYAUDIO/pull/64) merged at main `69b28a9` after full CI and independent review. The dependent [private test-contract issuance candidate](../test-contract-issuance.md) implements the agreed frozen render interface; it must receive its own integrated CI/review before acceptance. This preserves the plan order while development proceeds in parallel. Contract issuance leaves entitlements/outbox pending; guarded activation and secure downloads follow it. Broader WP-07 operator exception/refund/dispute and production criteria remain open.
