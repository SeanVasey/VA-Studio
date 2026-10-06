# Reviewed offer-draft editing

This bounded T12 / WP-02 / WP-04 child protects a saved offer draft from a stale full editor submission. The editor and browser integration belong to a separate coordinated child. This work does not complete T12, change publication policy or enable sales.

## Original failure and command contract

Before application edits, a disposable regression on base `7ebbf011e1d41785818a02914a2d3667642deedb` saved a newer full form with price 6999, then submitted an older full form intending to change currency while carrying the original price 4999. The original `SaveOfferDraft` overwrote the winning price. `reviewed-offer-evidence/original-red.{xml,log}` retains the intended failure: one case, one assertion, expected 6999, actual 4999. This sequential reproduction is not concurrency evidence.

`ReviewedOfferDraft::review(Offer, User)` captures the current locked offer and track. `updateReviewed(array $review, array $data, User)` accepts exactly the four editable fields: `license_version_id`, `price_minor`, `currency` and `deliverable_asset_ids`. All four must be supplied. Track identity and publication fields cannot be submitted. It returns the fresh persisted offer after verification. The UI must retain the original review in server-locked state, bind it to its actor, record and action, and consume it before submission validation. Submitting must not recapture. Failed, stale or uncertain attempts require explicit close/reopen; no request replay contract is added.

The review has exactly eleven keys: `schema_version` (1), `intent` (`edit_offer_draft`), `actor_id`, `track_id`, `offer_id`, `track_hash`, `offer_hash`, `track_audit_id`, `offer_audit_id`, `display` and `track`. `display` contains fixed track ID plus the four editable fields. `track` contains ID and title for context. This is an internal server-owned identity, not bearer authorization.

Both entry points require a standalone transaction and fresh persisted operator role, verified email and the existing panel's required MFA. Lock order is actor → track → offer. The supplied model's track ID is only a lock-order hint; the locked offer must belong to the locked track. The first ordinary snapshot read follows both resource locks. Full raw row hashes and latest participating subject audit identities bind the captured baseline, including same-second A → B → A restoration and legacy no-op audits. Privileged SQL that bypasses participating audit commands is outside that ABA guarantee.

Semantic no-ops perform no write or audit, and recheck rows, cursors and authority after validation. Changed saves audit only changed field names, current revision ID and canonical before/after display hashes. Saving observers cannot substitute requested fields or publication state. After saving and auditing, the command rechecks authority, the full persisted semantic offer, unchanged raw track and track cursor, and its exact returned audit ID, actor, action, subject and context. Intervening changes roll the transaction back. Unrelated audit sequence gaps remain valid. Raw track JSON retains waveform identity without projecting floating point amplitudes. Known integer and JSON casts are normalized only for the final semantic persisted-row comparison; captured raw hashes remain exact.

## Legacy validation and retained commerce

`OfferDraftInput` extracts the original nonmutating validator. `SaveOfferDraft::handle` remains unchanged; only its private validator delegates. Legacy partial edits, caller-owned nested transactions and legacy audit context remain intact. Domain draft validation still permits integer minor units from 0 through 2147483647, any three uppercase currency letters, an existing license version and at most three distinct existing asset IDs. Publication/readiness and the editor's narrower choices remain separate rules. Numeric IDs normalize to integers for strict saves; deliverable list order remains significant.

Actual application fixture commands create a purchased graph, then edit all four draft fields on that purchased offer. Assertions retain the original published revision, pointer, active state, track, order snapshots, grant, contract and activation graph. Provider, renderer and scanner fixtures are explicitly synthetic. These tests do not claim live Stripe, production content, a genuine scanner or native browser execution.

## Focused evidence

The initial SQLite domain run passed 51 of 57 cases; five errors and one failure exposed an audit subject-ID type mismatch and incomplete/default fixture setup. They are retained in `domain-first.{xml,log}`. Integer audit identity and explicit disposable fixture setup corrected those issues without removing criteria. The next run passed 57/57 with 222 assertions.

The expanded domain and existing offer revision/authority compatibility selection passed 95/95 with 411 assertions in 45.982 seconds: 64 new domain cases with 305 assertions, plus the original compatibility classes. `core-sqlite.{xml,log}` retains that result. Cases cover full-form conflicts, all original validation bounds, malformed/bound reviews, ABA and publication drift, fresh authority, transaction boundaries, observer interference and rollback, exact audit identity, ordered assets, no-op rechecks and retained purchases.

The first bounded MySQL 8.4.11 selection passed the two-full-form conflict and purchased-graph cases. Its remaining audit assertion depended on JSON object key order; MySQL returned the same six keys in storage-defined order. The assertion now checks the complete sorted key set and preserves every value/hash assertion. `core-mysql.{xml,log}` retains that original result. The corrected native selection passed 3/3 with 29 assertions in 18.549 seconds (`core-mysql-keyset.{xml,log}`); the changed SQLite case passed 1/1 with 13 assertions in 0.421 seconds (`keyset-sqlite.{xml,log}`). Pint and whitespace checks passed on the core source. Native race acceptance is recorded separately after execution; SQLite is not evidence for those fences.

Commands use recovered PHP 8.4.26 and a synthetic test application key:

```sh
php vendor/bin/phpunit tests/Feature/ReviewedOfferDraftTest.php tests/Feature/OfferRevisionTest.php tests/Feature/OfferWriterAuthorityTest.php --log-junit /workspace/scratch/b527c7e94bd7/reviewed-offer-evidence/core-sqlite.xml
python3 /workspace/scratch/0c039e9e0645/mysql-runtime/run-tests.py -- php vendor/bin/phpunit tests/Feature/ReviewedOfferDraftTest.php --filter 'test_two_full_forms|test_review_and_semantic_no_op|test_draft_edits_preserve' --log-junit /workspace/scratch/b527c7e94bd7/reviewed-offer-evidence/core-mysql-keyset.xml
php vendor/bin/pint --test app/Domain/Catalog/ReviewedOfferDraft.php app/Domain/Catalog/OfferDraftInput.php app/Domain/Catalog/SaveOfferDraft.php tests/Feature/ReviewedOfferDraftTest.php tests/Support/ReviewedOfferDraftFixtures.php
```

Root owns shared registry/census and inclusive acceptance. Independent exact-source review, composed real editor/browser checks and complete hosted acceptance remain required.
