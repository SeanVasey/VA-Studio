# T12-BULK-METADATA-01: reviewed metadata edits

October 2, 2026. Bounded authoring child under T12 / WP-02, advancing FP033. Based on the reusable metadata preset source in PR #95, repaired tree `a5c25ce22a2d4727f7704134a0e65fe3c596f7a2`. Final source and hosted run acceptance belong in the integrating PR; this child does not complete T12 or FP033.

## Operator contract

Select 1–25 explicit tracks visible on the current filtered Admin → Tracks page and choose **Edit metadata**. Artist, BPM, musical key, genre and mood each start at **Keep**. **Set** requires an ordinary valid value; **Clear** is available for nullable fields. Artist remains required. The initial modal explicitly hydrates all five Keep choices on the server.

**Review metadata changes** shows the exact track IDs, titles, chosen operations and current/proposed values. **Save reviewed metadata** is the explicit write. Back preserves the entered choices while consuming the old review. Cancel, changed inputs/selection, a different table page/search/filter/sort or another action consumes the review. The page independently checks actual current-page membership and the captured table context at both review and apply. Broad select-all transport and unsupported page sizes are rejected.

Titles, URLs, tags, descriptions, media, publication, offers, prices, licenses and purchased evidence remain retained. Existing tag additions and copied-preset draft creation keep their own actions. This action does not publish tracks or establish rights.

## Domain and recovery

`BulkUpdateTrackMetadata::review(ids, changes, actor)` captures a read-only review. `apply(review, actor)` returns exact changed/unchanged IDs. The complete five-field proposal accepts only explicit Keep/Set/Clear shapes; unknown keys and noncanonical submitted reviews are rejected. All-Keep requires an explicit choice before review, while a genuine already-matching Set/Clear is a no-op.

Each operation locks the persisted actor first, uses the current locking catalog Gate and MFA reads, then acquires ascending track locks. The review contains actor/ID/version identity, exact five-field before/after values and a fingerprint of all stored track attributes. Kept-field or protected-attribute drift invalidates the entire batch, including otherwise unchanged targets.

Apply validates all targets and revision capacity before writes, then invokes ordinary `SaveTrackMetadata` under one outer transaction. A locking database reload verifies the exact reviewed result and unchanged protected attributes. This handles database JSON representation without accepting unrelated normalization. Legacy metadata requiring normalization must first use the ordinary editor. Validation/readiness, stale/deleted targets, authority withdrawal, audit failure and overflow abort all writes/audits. Real no-ops preserve exact rows, timestamps, versions and audit counts.

Known conflicts say **No changes were saved by this attempt.** and require fresh review. An uncertain database/transport result says **The save result could not be confirmed.** and requires reload; the old review is consumed. No new table, migration or provider configuration is required. Revert the interface/domain increment to remove this workflow while retaining existing tracks and audit evidence.

## Executed development evidence

- PHP 8.4.26 / SQLite new classes: 37 reported cases, 31 passed with 441 assertions; six exact MySQL-only cases skipped. These include real mounted Filament actions, explicit current-page guards, review lifecycle, current authority/MFA, no-op, stale/protected-field drift, overflow, ordinary readiness and atomic audit rollback.
- Existing track metadata, bulk tags and preset functional cases: 43 passed with 524 assertions on the integrated bulk UI source. TypeScript, Blade compilation and whitespace checks passed.
- The complete fixed operator feedback suite then passed on clean source `da7638bf5daeee7c83444c76b2499f50465e4c55`, tree `a8362c22d1a13b0014cf87e9ea475ba93359d0e7`: 143 reported, 128 executed, 15 exact MySQL-only skips, 1,355 assertions and zero failures/errors. The later documentation update records these results; focused feedback remains separate from hosted merge acceptance.
- Focused-selection safeguards passed 23 cases; database-receipt safeguards passed 24. The strict SQLite policy lists only the three new MySQL method identities, expanding to six cases; full required gates are unchanged.
- The first local run found a real missing server Keep default, now repaired. Selection assertions now follow Filament's actual dispatch/Alpine hydration protocol and retain native checkbox checks. The published fixture receives an ordinary metadata edit before snapshots because its legacy SQL-null tags otherwise require unrelated normalization. Protected-attribute assertions and the domain refusal remain intact.

## Retained acceptance gates

MySQL must actually execute the distinct-actor overlapping-batch race, both actor-withdrawal lock orders and three old Repeatable Read snapshot revocations. Independent worker PIDs/connection IDs and `performance_schema` observation must prove waits on the exact track/user primary records and whole-batch preservation for the loser. SQLite supplies no MySQL concurrency evidence.

The native spec defines two ordinary-control journeys in both Chromium desktop and mobile WebKit. It creates its private drafts through Filament and covers exact review, Set/Clear/Keep, Back/Cancel, persistence, selected-only mutation, validation recovery, no-op, actual selection reset, filtered selection and competing-context Keep-field conflict with whole-batch recovery. TypeScript and discovery are not native execution; local browsers remain unavailable after the invalid browser download. Hosted browser/full database acceptance and final independent tested-source review are required before acceptance.

Next bounded candidate is authenticated operator track review: current descriptive metadata, verified artwork/tagged preview and actual publication blockers in one protected admin modal, reusing existing operator media routes. Anonymous unlisted access requires its own approved policy. Track scheduling also remains required; its next design must add monotonic publication identity, fresh authority/MFA and pinned publication evidence, and make manual publication/withdrawal participate in cancellation/supersession. Accepted CMS scheduling alone does not supply these track-specific guarantees. Broader license bulk editing, granular helper permissions/recovery, complete preset/default parity and production/cutover decisions stay open.
