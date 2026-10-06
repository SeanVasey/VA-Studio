# Review and refresh collection or album members

This isolated T26 / WP-10 authoring child follows private [collection and album drafts](../product-draft-authoring.md). It is separate from the preceding acceptance candidate. Staff can compare a current draft's retained track snapshots with current track descriptions and explicitly save refreshed snapshots as another immutable draft version.

## What the review means

The comparison retains the composition's track order and shows each track's saved and current title, metadata revision and publication revision. A changed revision can appear even when its title is unchanged. The changed count counts members, not individual fields. Review itself writes no version or audit.

These are descriptive snapshots. A publication revision is a change counter, not proof that a track or product is ready to publish. This action does not select media, grant rights, set prices, choose license terms or alter publication, offers, orders or delivery. The existing ordinary editor still saves freshly captured source descriptions; selecting a historical version still copies its exact historical manifest. Neither existing command changes semantics.

## Exact review and immutable history

`ProductDrafts::reviewMembers` reloads the authorized actor and required MFA enrollment, then locks the product, verifies retained version/member evidence and takes ascending source-track locks. Its canonical `collection-album-member-refresh-v1` hash binds the actor, product identity, current draft and retained version identities, old manifest digest and proposed manifest digest.

`refreshMembers` repeats those checks and locks at application time. A changed draft, source title, metadata/publication revision, actor identity, role or required MFA cannot reuse the review. A successful changed refresh preserves the current draft's title, description, kind and ordered track identities while appending one version and its member rows. It does not modify a source track or a retained version. The unchanged manifest format and existing SQL guards remain authoritative; no migration is needed.

The new version, parent counter and minimized `catalog.product_draft.members_refreshed` audit commit together. The audit records the existing numeric identities and before/after digests plus `member_review_hash`; descriptive text is not copied into audit context.

An unchanged review is a no-op, including repeated identical no-op requests. After a successful changed refresh, replaying the old expected version is refused instead of creating another version. If the response is uncertain, reopen the draft and retained history before reviewing again. Concurrent edits or refreshes serialize on the current draft; a losing stale review cannot overwrite the winner. Locking reads remain current under an older MySQL repeatable-read snapshot.

## Verification and next dependency

Backend source `ce8aaa110f35e35967dfdebb244d13829c33b61a` passed the new domain cases with the unchanged product draft suite on PHP 8.4.26 / SQLite: **41 tests / 180 assertions**, zero failures/skips, 1.105 seconds. The command was `php vendor/bin/phpunit tests/Feature/ProductMemberRefreshTest.php tests/Feature/ProductDraftTest.php --stop-on-error --stop-on-failure`, using a synthetic 32-byte application key. Pint passed on all four changed/new backend PHP files, and `git diff --check` passed.

The same exact backend commit passed native MySQL 8.4.11 with default durability: **59 tests / 986 assertions**, zero failures/skips, 78.218 seconds. The command added `tests/Feature/ProductMemberRefreshConcurrencyTest.php` and the unchanged `tests/Feature/ProductDraftConcurrencyTest.php` to that domain selection, through the isolated disposable MySQL wrapper. All nine new and nine existing concurrency cases ran. Independent read-only source/test review approved the tested backend commit and verified the native JUnit receipt.

The Filament action displays saved and current descriptions and the changed fields before explicit confirmation. Its product identity, version and review hash are locked component state; client-supplied display text does not become write input. Cancelling, submitting, changing action intent or encountering an error clears that review context. Ordinary creation, editing and historical selection retain their existing behavior.

Editor source `435b7ac8d894429ec365917280b180784b35b72d`, composed directly onto the reviewed backend, passed `php vendor/bin/phpunit tests/Feature/ProductMemberRefreshEditorTest.php tests/Feature/ProductDraftEditorTest.php` on SQLite and native MySQL 8.4.11 with default durability: **35 tests / 386 assertions** on each, zero failures/skips, 5.412 and 10.229 seconds respectively. Those cases include forged display payloads, stale source/draft evidence, changed operator identity, role/verification/MFA withdrawal, locked-state mutation, action switching, unexpected failure cleanup and unchanged historical contents.

The final combined SQLite selection at that same source ran the new and existing domain and editor classes together: **76 tests / 566 assertions**, zero failures/skips, 6.215 seconds. The exact command was `php vendor/bin/phpunit tests/Feature/ProductMemberRefreshTest.php tests/Feature/ProductDraftTest.php tests/Feature/ProductMemberRefreshEditorTest.php tests/Feature/ProductDraftEditorTest.php --stop-on-error --stop-on-failure`. Pint passed all seven changed/new PHP files, and `git diff --check` passed.

The browser spec creates its own source track and collection, changes the source title in another tab, checks the read-only review and modal fit, confirms refresh, reloads both retained versions and confirms the unchanged no-op. TypeScript checking and Playwright discovery passed, discovering one case for each configured project. Pinned Chromium 1243 and WebKit 2359 executables were unavailable locally, so **rendered browser acceptance is still pending**. Livewire and static review do not substitute for that evidence or establish new panel-wide privacy-header coverage.

Required CI registration and complete successor acceptance belong to the integrating lead; this child must not be added to a source frozen for an earlier acceptance run. Add these three feature files to the `seller` focused group, taking its base count from 26 to 29, and add `tests/browser/product-member-refresh.spec.ts` to browser targets, taking the base count from 26 to 27. The 32-file cap is unchanged. No frontend target is added.

| Feature class | Added cases | SQLite policy |
| --- | ---: | --- |
| `ProductMemberRefreshTest` | 18 | Execute all |
| `ProductMemberRefreshEditorTest` | 18 | Execute all |
| `ProductMemberRefreshConcurrencyTest` | 9 | Exact native-only exceptions below; all execute on MySQL |

Only these exact methods in `Tests\\Feature\\ProductMemberRefreshConcurrencyTest` need native-only registration:

- `test_native_draft_wait_allows_one_refresh_or_preserves_the_winning_edit` — three competing edit/refresh cases.
- `test_native_source_wait_rejects_a_review_changed_by_the_committing_source_writer` — two source-change cases, including an old caller snapshot.
- `test_native_actor_wait_observes_authority_withdrawal_despite_an_older_snapshot` — four role/MFA withdrawal cases.

The child adds 45 expanded PHP cases in three feature classes, plus two configured browser cases. It does not justify any broader SQLite skip exception or increased feedback budget. The lead must update selector safeguards and regenerate the integrated census/timing evidence against the actual successor base.

## Integration checkpoint

The lead composed independently approved `4fb73dc9` onto the clean `310bf63b` successor in a separate next-batch branch. The nine child files retain their reviewed content before this evidence addition; the published PR6 source is unchanged. Focused registration now contains 29 seller files and 27 browser files, retaining the 32-file cap and all prior selections. Exactly the three reviewed native methods above were added to the SQLite policy; no prior exception changed and both shared-engine classes remain fully executable.

Fresh complete PHPUnit discovery proves **3,615 unique cases in 227 files**, each exactly once across eight MySQL and two SQLite shards. The exact native policy resolves to **380 cases across 120 methods**, adding only this child's nine cases and three methods. The 37 focused-selector, 34 database-receipt and 36 partition safeguards passed, as did TypeScript and diff checks. These are source/discovery checks, not execution of the complete new suite. The existing timing estimates remain unchanged at this checkpoint; a separately verified measured refresh may join this later batch.

The branch then retained the reviewed native lookup correction and measured assignment from `096b4e7`, preserving both histories. Its complete repeated 8+2 partition proof still contains 3,615 cases in 227 files; only the lookup and three new member-refresh classes use the supported timing fallback. The projected assignment is approximately 28m 50s per MySQL shard and 18m 51s per SQLite shard, not observed execution time. All 37 focused safeguards, TypeScript and diff checks pass. The child remains outside published PR6 and needs its own native/full acceptance.

T26 remains open for approved bundle/license/allocation/refund/exclusivity rules, publication/readiness, exact purchased compositions/assets and customer delivery. This authoring review chooses none of those policies and closes no parent completion or launch gate.
