# Explicit commerce audit attribution

October 2, 2026. This correction makes customer audit identity explicit before participating commerce commands acquire resource locks. It does not enable production checkout or change ownership, prices, licensing, payment verification or fulfillment policy.

## Contract and callers

Customer command entry points accept a trailing optional `User`. Omitted or explicit null means anonymous/system even when ambient authentication exists. HTTP quote, order and test-checkout controllers explicitly pass the request user; clients cannot supply an audit actor ID in request data.

`CommerceAuditActor` locks a supplied persisted user inside the transaction before owner, quote, track, pricing, campaign, scope or order resources. Missing or unsaved identities fail before effects. A customer does not need administrator status, verified email or staff MFA. Existing opaque owner-key checks still authorize the selection and order; audit attribution does not associate historic orders with an account.

CreateQuote, PriceQuote, ReservePricedQuote, ReserveQuoteInventory, PromotionUsage, ReviewOrder, PrepareOrder and HostedCheckout start participate. Outer orchestrators acquire and propagate the same actor before nested calls. Existing caller-owned transactions, rollback and immutable evidence remain intact. Provider network calls remain outside transactions.

`AuditEvent::recordAttributed` stores the exact supplied nullable ID without consulting ambient authentication. The existing `record` method retains its behavior for unrelated callers. Provider binding, receipt processing, authoritative test-payment verification and finalization explicitly record system null, so synchronous execution cannot inherit a shopper's identity or introduce a late user foreign-key lock.

## Reproduced and verified behavior

The initial 41-case regression reproduced 29 failures against the earlier source. Expanded customer/guest feedback passed 54 cases with 192 assertions; the separate ambient-auth system verification/finalization/replay case passed 14 assertions. These overlapping development runs are not added together.

The final isolated MySQL 8.4.11 run executed 23 cases with 1,447 assertions, zero failures, errors or skips. Twenty cases exercise ten customer entry points against manifest capture in both orders; two exercise existing-order replay versus checkout; one proves explicit guest work can commit while an ambient authenticated user's row is locked. The barriers inspect actual requesting/blocking connections and the user primary-key record wait, and preserve strict immutable-row comparisons.

Two earlier MySQL failures compared in-memory insertion-order attributes with freshly fetched database-order attributes. Baseline snapshots now use `fresh()->getAttributes()`; strict equality remains. Earlier missing-import and socket-startup failures are retained separately. The final 14-class SQLite regression passed: 366 reported cases, 365 executed, 3,601 assertions, zero errors or failures, and one existing MySQL-only HostedCheckout skip. An initial run without APP_KEY failed setup and is not passing evidence; its replacement uses an isolated test-only key.

The three exact MySQL-only methods are registered for SQLite skips, and both new classes are routed through focused commerce checks. Focused routing's 26 safeguards and receipt verification's 24 safeguards passed. Formatting and whitespace checks passed. Final source hashes are recorded in `commerce-audit-final-source-hashes.json` outside the repository; raw reports remain distinct.

## Acceptance and remaining work

Independent source review found no introduced blocker in the 19 PHP files. Acceptance still requires review of the exact composed commit and full native GitLab checks. Local subsets do not authorize a merge by themselves. This batch establishes the listed attribution fences, not universal deadlock freedom, production payment readiness, account recovery, refunds or operational exception resolution.
