# Scope-bound exclusive offer preparation

This WP-06 increment freezes exclusive commercial evidence in **inactive, local/testing-only revisions**. It builds on merged PR #44's shared inventory without presenting an exclusive that the current non-exclusive quote/pricing workflow cannot honor. It creates no reservation, payment attempt, grant or ownership transfer.

## Internal command and identity

`PrepareExclusiveOffer::handle(offer, scopeId, reference, actor)` requires authorized catalog staff, a separate inactive offer, an effective independently reviewed exclusive license, verified current rights and matching exact deliverables/preview. It rejects production and requires an explicit private scope-link reference. It does not choose a TTL or require reservation policy because it acquires no inventory.

Version 2 retains the existing immutable commercial, license, rights and file evidence, plus `purpose: test_exclusive_preparation` and an `inventory` object containing the scope ID/public ID, a canonical hash of immutable scope identity and a hash of the linkage reference. Private references remain in their existing protected scope/link records and do not enter the snapshot or audits verbatim. Scope identity is an operator assertion; preparation does not establish chain of title or choose legal terms.

The new revision, exact scope link, current-revision pointer and audits commit together. `is_active` stays false. The existing `offer_revisions.published_at` column records when this immutable revision was frozen; it does not establish storefront publication. The schema needs no migration or backfill. Existing SQL immutability guards retain both v1/v2 revisions and links.

Matching current snapshot/reference retries reuse the same revision/link without duplicate audits, including retries by another authorized operator. A changed price, license, file or explicit scope/reference creates a successor. Historical relationships never change or inherit a new scope implicitly. Several variants may prepare the same scope; preparation neither occupies nor proves availability for sale. Scope blocking rejects preparation and current verification without deleting prior evidence; unblocking permits an identical replay.

## Locking and verification

Preparation locks track → offer → current license/rights/revision → underlying scope. Track identity is immutable, so it avoids a consistent database read before acquiring the track/offer locks. Current-revision and latest-revision queries use locking reads to avoid stale MySQL `REPEATABLE READ` snapshots during a duplicate request. Transactions retry database deadlocks at most five times; no provider is called.

`VerifyOfferFiles` is shared with non-exclusive publication and freshly hashes every selected deliverable, preview and recording-related asset. It bypasses cached digest results when creating commercial evidence. Preparation rechecks license/readiness after hashing so an effective-end boundary cannot silently create a new revision. `preparedExclusiveBlockers` verifies frozen commercial evidence plus exact immutable linkage and current scope controls; this internal result is separate from public sale eligibility.

## Historical and public boundaries

Existing v1 non-exclusive snapshots, quotes, full disclosure and pricing stay readable. Preparation refuses to convert or deactivate an existing v1 offer. Public publication, catalog, quote and pricing paths still reject v2 exclusive preparations, even if an out-of-band write sets the active flag. No new HTTP route, Filament control, public policy or UI theme change is added.

Next implementation must version exclusive activation, quote/disclosure and pricing together, enforce scope-aware availability and atomic holds, and define promotion eligibility and pending non-exclusive cutoff explicitly. Do not enable an exclusive by deleting the existing non-exclusive guards. WP-07 must bind orders/assent and promotion/inventory attempts before requesting provider payment, then reconcile sold, unpaid-release and paid-exception outcomes. WP-08 supplies buyer contracts and entitlements. U-05/U-08 production decisions remain unresolved.

## Evidence and rollback

`ExclusiveOfferTest` covers successful immutable evidence, replay, successor preservation, shared variants, legacy catalog/quote/pricing compatibility, public exclusion, authorization/environment controls, invalid references/scopes/licenses/files/rights/prices, administrative blocks, same-size media corruption, license expiry during verification, enclosing transaction rollback, mismatched links and SQL history guards. `ExclusiveOfferConcurrencyTest` uses independent MySQL processes/connections and explicit lock barriers for identical requests, shared-scope variants and administrative block races; SQLite skips these three cases explicitly.

The PR records actual tested commit, counts, CI URLs, dependency audits and independent-review status. PHP/Composer are unavailable in the implementation workspace, so PHP/MySQL/SQLite/browser results must come from actual GitHub Actions runs. Test definitions alone establish no pass. No production load, real source rights, physical device or completed purchase has been verified by this increment.

Roll back the caller/code while retaining immutable evidence; no destructive migration is needed. Current public readers already reject these inactive v2 records. Preserve records for forward integration and review rather than rewriting them as v1 or deleting their links.

## Subsequent activation integration

The [exclusive selection contract](exclusive-selection.md) adds separate immutable activation evidence for exact v2 preparations under explicit test-only policy. It preserves these snapshots and the inactive default. Public activation now requires that evidence and versioned quote/disclosure/pricing support; an active flag alone remains insufficient. The original preparation boundary above describes PR #48.
