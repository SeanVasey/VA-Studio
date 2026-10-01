# T12-BULK-TAGS-01: reviewed tag additions

October 1, 2026. Bounded implementation candidate based on local integration commit `50e5b7c4b4f254f6f6adaba143e4d7814beca327`, tree `acf639f2eacd77e52f5112c628b151fbb2dcc377`. This advances single-seller catalog management under T12 / WP-02 / WP-09 and part of FP033. T12 and FP033 remain open. The integrating candidate must preserve and accept this exact child source before completion is claimed.

## Operator contract

Select 1–25 explicit tracks on the current page in Admin → Tracks and choose **Add tags**. Enter 1–20 literal tags of at most 80 characters. **Review additions** shows every track's title, exact ID, current tags and proposed tags, with the number that will change and the number already containing all additions. **Add reviewed tags** is the explicit save. Existing tag order is retained; new exact values are appended once. Case and spelling follow the ordinary metadata editor's rules. Each resulting track remains limited to 20 tags. This is addition only: no tag replacement or removal, title, slug, license, offer, price, media or publication change.

The reviewed actor, IDs, metadata versions, additions and before/after tags are server-captured Livewire locked state. Back, close, selection changes and mounted-input changes consume the review. Back preserves the entered additions but requires another review before save. A reviewed record disappearing or changing, including an otherwise unchanged target, rejects the entire batch. No automatic conflict retry overwrites newer edits.

Known validation/conflict failures say **No changes were saved by this attempt.** and return to the additions form, requiring fresh review. A database or lost-acknowledgement uncertainty says **The save result could not be confirmed.** and instructs the operator to reload tracks and review their current tags; the old review is consumed. Network failure before an HTTP response can still leave a visible old browser state. Reusing that old review cannot overwrite a committed revision. A fresh review after a successful save shows an explicit no-op if all additions are already present.

## Domain, authorization and evidence

`BulkAddTrackTags::review(array $ids, array $additions, User $actor): array` captures a read-only snapshot; `apply(array $review, User $actor): array` returns exact changed and unchanged IDs. Each command locks the persisted actor first, checks the existing fresh `administer-catalog` gate and current admin-panel MFA enrollment, then locks the bounded track IDs in ascending order. The review cannot be applied by another operator. The page also rechecks the current persisted authority and panel MFA on every reactive request.

Apply checks all reviewed targets before writes, then invokes the unchanged `SaveTrackMetadata` command under one outer transaction. That command remains authoritative for metadata validation, optimistic revision, published readiness, reserved URLs and minimized per-track audits. Audit failure or a rejected target rolls back every track and every audit from the attempt. No-op rows keep their version, timestamp and audit count. Existing legacy fields that would be normalized by the ordinary editor cause bulk rollback and require an explicit ordinary metadata review first; a tag-only action cannot normalize a title or another unrelated field. There is no new table, migration, test endpoint or batch audit containing draft plaintext.

Current readiness can legitimately block a tag edit after an exclusive sale. This child does not weaken that rule. A successful synthetic non-exclusive paid/delivery fixture retains commerce, media, rights, contract and delivery rows unchanged; the original public URL and publication state remain intact.

## Executed local evidence

- Targeted PHP 8.4 / SQLite: `BulkTrackTagsTest`, `BulkTrackTagsConcurrencyTest`, and existing `TrackMetadataTest`: 24 definitions, 21 passed, 221 assertions, 3 explicit MySQL-only skips. This includes changed mounted-input invalidation and a lost post-commit acknowledgement modeled through the real successful command.
- PHP lint / Pint, TypeScript and production frontend build, Blade view compilation, and whitespace checks passed. These are focused development checks, not a full integration gate.
- The ordinary native spec was independently source-reviewed at blob `7431308968fbdc8187e522c4ce1be7e1f2b75f85`, SHA-256 `f9f8246935ee2411c44894323aa7e543e2266aa92eddc34bab63e2819647a454`. It creates its own private drafts through the normal admin forms and covers exact review IDs and ordered lists, Back, persistence, no-op, later-ID competing edits from an independent authenticated context, earlier-row preservation, recovery, keyboard focus and mobile modal fit. Wrapper inventory lists one journey in each of Chromium desktop and WebKit mobile. Inventory and source review are not native execution.

## Retained acceptance gates

Actual MySQL 8.4 execution is pending. Three definitions require independent PHP processes, distinct connection IDs/PIDs, committed fixtures and a parent autocommit observer. The overlapping `[A,B]` / `[B,C]` batches use separate actors so the observer proves a wait on shared track B's exact `PRIMARY` record, rather than confusing actor serialization with track contention. The winner commits both targets, the loser rejects the whole batch and its unique target remains unchanged. The two actor-authority orderings prove an exact `users.PRIMARY` wait: batch-first finishes before withdrawal; withdrawal-first denies every tag write. SQLite explicitly skips these definitions and supplies no concurrency evidence.

Actual Chromium/WebKit execution, a complete integration PHP/MySQL/SQLite and frontend gate, and final independent domain/authorization review remain pending. No provider, live account, production payment, real customer identity, rights policy, license or selling-price decision is created by this child. Owner-defined catalog presets, bulk licensing, scheduling/private review, finer staff roles and the broader T12/FP033 scope remain separate work. Literal operator-entered tags have no new blocking owner-policy decision.

Rollback is an interface/command revert retaining normal track versions and audit evidence. Do not decrement revisions or delete earlier tag/audit history to undo an operator change; a later ordinary audited metadata edit can remove unwanted tags deliberately.
