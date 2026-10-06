# Authored production track-policy drafts

This child creates staff-only preparation, not an active merchant policy. Every authored version and independent source acknowledgment remains private, encrypted and immutable. No payment, quote, order, entitlement, original document, delivery control, active-policy pointer, provider request or configured activation flag is created. Existing test-mode commerce records and guards remain unchanged.

`PrepareProductionTrackPolicy::review(?ProductionTrackPolicyDraft $draft, array $authored, User $actor)` returns a signed exact comparison. `SaveProductionTrackPolicy::applyReviewed(array $review, User $actor)` saves only that captured source. Review captures bind the actor, UUID, current revision, complete raw version/review/audit identity and exact original/proposed source. Current authority/MFA is checked before and after lifecycle callbacks. A raw final proof verifies every affected row, original history, audit and actor before commit. Exact no-ops write no version, timestamp, author or audit. A stale comparison, same-content ABA, actor mismatch or altered capture fails closed.

`ReviewProductionTrackPolicy::review(ProductionTrackPolicyVersion $version, User $actor)` captures the current exact authored version. `applyReviewed(array $review, array $reference, User $actor)` retains one independent acknowledgment. Original authors and every editor of that policy are ineligible. The reference explicitly supplies an opaque review reference, lowercase SHA-256 and `authored_source_acknowledged: true`. This acknowledges the authored source; it does not prove a reference resolves, authenticate a Stripe account, approve legal terms or grant activation. Both encrypted acknowledgment and audit explicitly retain `activation_allowed: false` and `external_facts_verified: false`.

## Authored source schema

The closed top-level schema has exactly `schema_version: 1`, `purpose: production_track_policy_draft`, a supplied version identifier, and declarations for all14 categories: seller identity, provider account, currency, tax calculation, assent, license terms, buyer identity, recovery, reservation/exclusives, refunds/disputes, original documents, storage, delivery and privacy.

Each declaration explicitly contains `state`, `choice`, `source_reference`, `source_sha256` and `note`. `declared` requires supplied bounded UTF-8 choice/reference strings and a lowercase64-character source hash. `unresolved` requires explicit null choice/reference/hash and a nonempty explanation. Unknown or missing fields, missing categories, wrong types, control bytes, oversized strings and credential-shaped values are refused. Source references are opaque authored identifiers; no path/URL is opened or fetched. Canonical source is bounded to32KiB, captured reviews to128KiB and each draft to256 immutable versions; version/review/audit membership is checked completely within those limits.

Retained payload hashes identify authenticated randomized ciphertext, never an unkeyed hash of private merchant declarations. Audits contain IDs, revisions and ciphertext hashes only. The application key is used for encryption and review signatures; retain it through configuration and backup/restore. A key change invalidates unsigned-old-context review captures and cannot substitute for historical evidence recovery.

## Persistence and next dependency

The additive migration `2026_10_06_233000_production_track_policy_drafts.php` creates draft identity/revision, immutable encrypted source versions and immutable independent source-review evidence. Both supported database engines guard version sequences, restrictive parents, source-review independence, exact version hash and update/delete retention. Populated rollback refuses before changing a table or trigger. There is no active commerce pointer in this schema.

Next, build typed executable production policy capabilities from actual approved seller/currency/tax/assent/account/identity/storage/refund facts, with explicit independent approvals and operational evidence. Dispatch new production pricing/order/provider/payment/grant/contract/delivery versions by frozen account+mode+purpose/version; preserve historical test readers and SQL guards. A fully declared or acknowledged draft is insufficient for that capability compiler or live activation. The readiness command remains blocked.

## Focused development evidence

The first56-case SQLite selection ran132 assertions but had26 teardown errors: Laravel's ordinary migration teardown attempted the deliberately refused populated rollback. Production safeguards remained intact. The test now uses the repository's existing `FinalizationDatabaseMigrations` disposable-only `db:wipe` lifecycle; no migration/domain guard was weakened. The corrected56 cases passed132 assertions with no errors, failures or skips. Initial scoped Pint formatting findings were corrected only within this child's owned files.

This is the first source checkpoint. Native MySQL guard/race evidence, broader adversarial cases, final source freeze and independent sensitive review remain pending. No hosted workflow, full matrix, live provider, real account, production host, mail delivery, legal review or production activation was executed.
