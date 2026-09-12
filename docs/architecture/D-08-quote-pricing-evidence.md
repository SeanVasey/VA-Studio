# D-08 — Append immutable pricing evidence to owned quotes

Status: **Proposed architecture, implemented for verification under Sean's continuous-development authorization.** This is a new local decision entry, not acceptance of a production tax policy. Date: 2026-09-12. Owner: implementation lead for the WP-06 contract; Sean and the commerce/accounting reviewer retain U-04 policy decisions.

Keep schema-v1 selection reviews intact. Store one immutable, reproducible pricing record per quote, capturing the calculation algorithm, exact offer/disclosure identities and explicit tax policy. Current calculation policies are test-only; absent policy records unresolved tax rather than an exemption.

## Evidence and options

**Verified:** main `a3b95b8ff62141b7e0237992e1f7d9b057074cd8` retains exact immutable selections and owned full license disclosure through PRs #23/#41. `ReadQuote` owns session identity, current publication/evidence and expiry verification. No production tax policy is established in U-04. The architecture already calls for separate immutable authoritative settlement evidence when a provider determines tax later.

The status quo preserves unknown totals but cannot reproduce tax calculations or test settlement amounts. Rewriting existing quotes would destroy their original meaning and hashes. A new independent cart/order aggregate would duplicate selection and ownership rules before WP-07. An additive one-to-one record reuses the verified selection, gives first requests a shared database lock and has a narrow reversal path. It adds one indexed row per priced quote, with policy input bounded to 8 KiB and at most ten calculation lines; it adds no service, queue or provider fee. These are code limits, not production cost measurements.

**Recommendation in implementation:** use the additive record. Money remains exact through integer division/remainders, and concurrent creation uses the existing quote row lock plus a unique foreign key. Official behavior references checked 2026-09-12: [PHP integer division](https://www.php.net/manual/en/function.intdiv.php), [Laravel transactions](https://laravel.com/framework/docs/13.x/database#database-transactions), [MySQL locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html). The executable tests, rather than those references alone, establish candidate acceptance.

## Effect matrix

| Surface | Change and invariant | Verification / reversal |
| --- | --- | --- |
| Domain | `PriceQuote`, `PricingPolicy`, `PricingSnapshot`, integer money and amount-only settlement comparison; ownership/current eligibility stays in `ReadQuote` | Money/configuration/tampering/binding tests; revert new callers while retaining evidence |
| Data | One `quote_pricings` row per quote; unique public ID, restrictive FK, canonical hash, immutable model and SQL triggers | MySQL/SQLite constraints, empty-table up/down and concurrent first writes; no old-row backfill |
| HTTP | Empty-object POST and owner-checked GET under `/quotes/{quote}/pricing`; existing quote DTO stays stable | Session/authentication/CSRF, bounded input, shared throttles, generic private failures |
| Audit | One `commerce.quote.priced` event on successful first creation; safe IDs/hashes/status only | Replay/race audit counts; no external notification |
| UI/admin/media | Existing storefront, identity assets, admin and private media are unchanged | Existing regression/browser suite; no new visual claims |
| Provider/operations | Explicit local/testing JSON configuration only; comparison consumes normalized observations but makes no provider request | Production rejection and mismatch fixtures; WP-07 must retrieve, verify and retain actual provider evidence |
| Legal/privacy | No exemption/rate is inferred. Safe response omits account identity, private quote hash, assets and license evidence | Exact projection tests; production policy/retention/assent remain separate reviewed decisions |

## Contract, compatibility and recovery

The [pricing contract](../quote-pricing.md) defines all input/output, policy states and comparison limitations. Lock order is quote → tracks/offers through `ReadQuote` → pricing row. The outer transaction retains the quote lock while creating the record/audit. Simultaneous identical requests return one result; competing configured policies return one result and an explicit policy-change error. No provider call occurs inside these locks.

Unknown, changed or expired policy cannot be used to produce an assumed payable total. Replacing configuration cannot change a retained record, even if its textual version is reused. The customer must request a new selection review. The comparator deliberately compares historical arithmetic independently of current eligibility: a late amount match is not payment, assent, inventory, expiry or grant approval. WP-07 must retain its report with independently verified provider evidence and apply those separate checks, including paid-exception handling.

There is no migration of earlier quotes, prices, offers or documents. Deploy the additive migration before code uses it. On incorrect calculation, privacy failure or compatibility regression, disable the new route/caller with a reviewed application revert and retain the new table/audits. Sean's authorized implementation lead can revert this development code. Do not run a destructive down migration after useful evidence exists; empty-table down/up is tested only in disposable databases. After future paid transactions, rollback becomes reconciliation under WP-07, not deletion of pricing records. No production restoration rehearsal is claimed.

Verification requires exact final-head CI on both databases, independent MySQL first-write races and independent licensing/authorization/migration review. PHP/Composer are unavailable in the current scratch runtime; the PR must record actual CI results. No visual style change or provider action is part of this batch.

Decision readiness: **Provisional** for production policy; reversible implementation is authorized. U-04 tax/account policy belongs to Sean + commerce/accounting; U-05 terms/identity to Sean + qualified reviewers; U-08 exclusive timing to Sean + commerce/legal review. Resolve them from actual records before their production scope. Continue WP-06 promotions and shared exclusive inventory next, then WP-07 orders/assent/provider retrieval/finalization and WP-08 delivery. Append D-08 to the decision register without erasing prior entries.
