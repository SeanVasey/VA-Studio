# Authored production track-policy drafts

This child implements staff-only preparation. Authored versions and independent source acknowledgments remain private, encrypted and immutable. It creates no active merchant policy, payment, quote, order, entitlement, original document, delivery control, provider request or activation flag. Existing test-mode commerce records and guards remain unchanged.

## Domain API and source authority

`PrepareProductionTrackPolicy::review(?ProductionTrackPolicyDraft $draft, array $authored, User $actor)` returns a signed exact comparison. `SaveProductionTrackPolicy::applyReviewed(array $review, User $actor)` saves only that captured source. The model types belong to `App\Domain\Commerce\Policy\Models`; the services and authored schema belong to `App\Domain\Commerce\Policy`. Captures bind the actor, UUID, revision, complete raw version/review/audit identity and exact original/proposed source. Current authority and MFA are checked before and after lifecycle callbacks. Raw final proof verifies every affected row, original history, audit and actor before commit. Exact no-ops write no version, timestamp, author or audit. Stale comparisons, same-content ABA, actor mismatch and altered captures fail closed.

`ReviewProductionTrackPolicy::review(ProductionTrackPolicyVersion $version, User $actor)` captures the current exact authored version. `applyReviewed(array $review, array $reference, User $actor)` retains one independent acknowledgment. Original authors and every editor of that policy are ineligible to acknowledge its current source. A valid historical acknowledgment remains retained if its reviewer later authors a successor; that person cannot acknowledge the successor. Every retained source version and acknowledgment is authenticated, decrypted and checked when reading the graph.

The review reference explicitly supplies an opaque `reference`, lowercase `source_sha256` and `authored_source_acknowledged: true`. It acknowledges authored source, without proving that a reference resolves, authenticating a Stripe account, approving legal terms or allowing activation. Both encrypted acknowledgment and audit retain `activation_allowed: false` and `external_facts_verified: false`. These are internal domain APIs; this child adds no HTTP route, operator UI, import, public request handler or unattended activation command.

## Authored source schema

The closed top-level schema has exactly `schema_version: 1`, `purpose: production_track_policy_draft`, a supplied version identifier, and declarations for all 14 categories below. Each declaration explicitly contains `state`, `choice`, `source_reference`, `source_sha256` and `note`.

`declared` requires supplied bounded UTF-8 choice/reference strings and a lowercase 64-character source hash. `unresolved` requires explicit null choice/reference/hash and a nonempty explanation. Unknown or missing fields, missing categories, wrong types, control bytes, oversized strings and credential-shaped values are refused. Source references are opaque authored identifiers; no path or URL is opened or fetched. Canonical source is bounded to 32 KiB, captured reviews to 128 KiB and each draft to 256 immutable versions; complete version/review/audit membership is checked within those limits.

Retained hashes identify authenticated randomized ciphertext, never an unkeyed hash of private merchant declarations. Audits contain IDs, revisions and ciphertext hashes only. The application key signs comparisons and encrypts private payloads. Retain it through configuration and backup/restore; a key change invalidates earlier signed captures and cannot recover historical ciphertext.

## Persistence and concurrency

The additive migration `2026_10_06_233000_production_track_policy_drafts.php` creates draft identity/revision, immutable encrypted source versions and immutable independent source-review evidence. SQLite and MySQL guard sequences, restrictive parents, source-review independence, exact version hash and update/delete retention. Insert guards explicitly refuse an existing primary or unique identity, including SQLite `REPLACE` with recursive delete triggers disabled. Populated rollback refuses before changing a table or trigger. A retained revision-zero parent without a complete version is refused by domain reads; it is never silently repaired.

Commands require a standalone transaction. They lock fresh authority before the draft and ordered versions. Every semantic resource read and final raw proof is a current locking read: an authority retrieval callback opening an old MySQL REPEATABLE READ snapshot before the draft fence cannot authorize a stale semantic no-op. All model/authority callbacks finish before the final raw row proof. Returned models are hydrated from proven rows without another retrieval callback after that proof.

## Next production contract

A declared or acknowledged draft is insufficient for a production capability compiler. The next implementation must consume independently approved actual facts and exact operational evidence, rather than treating authored choices or environment settings as proof. No value below is supplied by development fixtures or inferred from an existing account connection.

| Required category | Next executable capability requires |
| --- | --- |
| `seller_identity` | Approved actual seller identity and its authority to sell the catalog. |
| `provider_account` | Actual provider account, mode and payment purpose associated with that seller; credentials and account capability proven separately. |
| `currency` | Explicit approved supported currency and integer minor-unit mapping. |
| `tax_calculation` | Approved tax approach, calculation identity and reconciliation fields; no assumed zero tax or invented rate. |
| `assent` | Exact published terms/license/checkout assent mapping retained in the order snapshot. |
| `license_terms` | Approved real offers, prices, rights and immutable license versions. |
| `buyer_identity` | Approved verified account or guest claim model and frozen purchaser identity. |
| `recovery` | Approved claim/recovery behavior and separately demonstrated transactional mail transport. |
| `reservation_and_exclusives` | Explicit reservation/expiry/exclusive rules and their serialized production state transitions. |
| `refunds_and_disputes` | Approved operational/refund/dispute policy and authoritative provider reconciliation. |
| `original_documents` | Approved production rendering profile and immutable original document evidence. |
| `storage` | Selected private durable storage, worker access and demonstrated backup/restore. |
| `delivery` | Snapshot-derived production entitlement and short-lived private download authorization, with the selected transfer behavior tested. |
| `privacy` | Approved purchaser/claim data handling, retention and consent behavior; unknown marketing consent remains unknown. |

Dispatch new production pricing/order/provider/payment/grant/contract/delivery versions by frozen account, mode, purpose and schema version. Preserve existing historical test readers and SQL guards. The source-backed barrier inventory remains in `production-commerce-readiness.md`; no current test-only money policy or production-readiness result is widened by this child. A real provider account connection has already been observed in the decision register; runtime credentials, seller/currency/tax/capture facts, production application adapters and external acceptance are separate remaining dependencies.

## Focused development evidence

The initial 56-case SQLite selection ran 132 assertions but had 26 teardown errors: ordinary migration teardown attempted the deliberately refused populated rollback. The test uses the existing `FinalizationDatabaseMigrations` disposable-only wipe lifecycle; safeguards were preserved. Corrected initial 56 cases passed 132 assertions.

The first hardened selection discovered 77 cases. SQLite executed 69 domain cases with 146 assertions, one fixture failure comparing identical retained acknowledgment values in different array key order, and eight explicit MySQL race skips. Native MySQL executed 77 cases with 732 assertions, passed all eight wait-evidenced races and retained the same single fixture failure. Canonical row comparison corrected that fixture.

Before repair, seven newly selected SQLite cases reproduced four replacement substitutions and one malformed revision-zero type error; two existing guards already refused their selected replacement shapes. An independent native canary then demonstrated stale-review acceptance under an actor callback-created snapshot and an exact draft record wait, without overwriting the committed successor. Its identical corrected canary passed one case and 84 assertions, returning blocked and retaining the successor graph.

Final corrected SQLite discovered 86 cases: all 76 domain cases passed 161 assertions with ten explicit MySQL-only skips, no errors or failures. Final corrected native MySQL passed all 86 cases and 875 assertions with no errors, failures or skips and ten retained exact record-wait receipts. All captured executable source hashes matched before and after both final selections.

Run the affected selection with:

```sh
php vendor/bin/phpunit tests/Feature/ProductionTrackPolicyDraftTest.php tests/Feature/ProductionTrackPolicyConcurrencyTest.php
```

MySQL cases use separate real processes and connections, REPEATABLE READ isolation, normal transaction durability, and `performance_schema` evidence of the exact requested PRIMARY record lock before release. They cover both worker orders for edits, edit versus source acknowledgment, competing acknowledgments, edit versus authority withdrawal, and stale semantic no-op after a pre-fence consistent read. SQLite provides no concurrency claim.

Scoped formatting, PHP syntax, diff integrity and eight workflow cadence guards passed. Source hashes, actual selections, original negatives and final receipts are retained at the integration checkpoint. Independent sensitive review binds the corrected executable source and final commit. No hosted workflow, full matrix, live provider, production account change, host deployment, delivered email, legal approval or production activation was executed. Complete integrated acceptance remains deferred to the final exact reviewed candidate.
