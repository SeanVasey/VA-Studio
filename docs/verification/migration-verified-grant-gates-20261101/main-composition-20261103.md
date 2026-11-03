# MIG composition onto accepted main — 2026-11-03

Source-only composition, runtime UNRUN. This packet does not enable historical verified grants.

## Exact source and ownership

- Accepted main parent: `048244a6baf893a5d5f5581e466f353af24faaf7`.
- MIG source: `26636968a5c1059f0c65dbc31640bbf44c43b2ef`; delta base: `a7bc63cc5c5785353f13e882f49d0ab9dcff55b3`.
- Branch: `codex/mig-main-composition-20261103`; isolated worktree `/workspace/VA-Studio-mig-main`.
- The 30 paths below are the complete source delta. All are byte-identical to MIG source except the operator-guide paragraph correcting accepted main's existing authority consultation. This note is the sole additional path. The two deltas have zero overlapping paths; all other main files remain unchanged.
- No census, workflow, lock, environment, policy-price or runtime-log changes. Existing verification packet artifacts are retained as historical source evidence, not rewritten or promoted to current results.

- `app/Domain/Migration/CatalogOnboarding/PrivateSourceFiles.php`
- `app/Domain/Migration/History/CompetingExclusiveSale.php`
- `app/Domain/Migration/History/HistoryDelta.php`
- `app/Domain/Migration/History/HistoryNormalizer.php`
- `app/Domain/Migration/History/HistoryReconciliation.php`
- `app/Domain/Migration/History/HistorySourceFiles.php`
- `app/Domain/Migration/History/LegacyGrantReview.php`
- `app/Domain/Migration/History/LegacyHistoryArchive.php`
- `database/migrations/2026_11_01_264010_legacy_history_contract_declarations.php`
- `docs/migration/legacy-history-archive.md`
- `docs/verification/migration-verified-grant-gates-20261101/README.md`
- `docs/verification/migration-verified-grant-gates-20261101/delta-old-source.txt`
- `docs/verification/migration-verified-grant-gates-20261101/independent-mig-declaration-design-a2537055-DECISION.md`
- `docs/verification/migration-verified-grant-gates-20261101/independent-mig-declaration-design-eb5b5881-DECISION.md`
- `docs/verification/migration-verified-grant-gates-20261101/independent-mig-final-d54317c4-DECISION.md`
- `docs/verification/migration-verified-grant-gates-20261101/independent-mig-source-2c256834-DECISION.md`
- `docs/verification/migration-verified-grant-gates-20261101/mutation-drop-missing-declarations.txt`
- `docs/verification/migration-verified-grant-gates-20261101/mutation-v2-record-key-namespace.txt`
- `docs/verification/migration-verified-grant-gates-20261101/verification-receipts-20261102.json`
- `docs/verification/migration-verified-grant-gates-20261101/zero-old-source.txt`
- `tests/Feature/Migration/LegacyHistoryArchiveTest.php`
- `tests/Feature/Migration/LegacyHistoryCommandTest.php`
- `tests/Feature/Migration/LegacyHistoryDeclarationMigrationTest.php`
- `tests/Feature/Migration/LegacyHistoryReviewConditionsTest.php`
- `tests/Fixtures/migration/history-synthetic/snapshots/README.md`
- `tests/Fixtures/migration/history-synthetic/snapshots/baseline-v1.json`
- `tests/Fixtures/migration/history-synthetic/snapshots/missing-extra-v1.json`
- `tests/Unit/Migration/HistoryDeltaTest.php`
- `tests/Unit/Migration/HistoryNormalizerTest.php`
- `tests/Unit/Migration/HistoryPrivateSourceFilesTest.php`

## Behavior carried and activation boundary

The carried implementation retains competing-record provenance across export versions/copies/omissions, includes zero-value unrefunded exclusives, and preserves the v1 record-key namespace. The additive declaration sidecar records missing-original declarations without fabricated bytes or hashes. Same-source retained v1 batches remain `declaration_history_coverage_unproven`; physically unarchived retained v2 memberships remain `retained_export_not_archived` until genuine resume. Historical relinks and actor exclusions remain blockers. Original assertions and guards are unchanged.

V3 is an operator review procedure, not persisted enforced reconciled-delta approval. Immutable coverage/relink authorization and owner custody are still open. A local/test `verified_grant` classification creates no entitlement, download, account or active grant. Non-local archive admission remains closed; production activation remains OFF. Actual owner approval, verified custody and exact evidence are not replaced by synthetic results or this composition.

Accepted main's checkout already calls the authority gate, but still refuses exclusive inventory even when store authority is present. The guide now states this accurately; no checkout code changes.

## Main identity/account compatibility boundary

The new declaration migration adds `actor_id -> users.id` (restrict-on-delete); its other foreign keys target legacy history batches/contracts. It adds no incoming foreign key to `customer_accounts` or the seven production identity tables and does not alter users columns, parent guards or identity-owned schema. `IdentityMigrationOwnership::dependencies()` verifies the exact parent definitions/namespace for users, customer_accounts and quote_owners. Its identity dependent scan targets identity-owned tables; `assertAccountUpgradeDependents()` separately inventories incoming customer_accounts keys and references. An extra users actor reference is not, by source inspection alone, an unlisted customer_accounts dependency. No demonstrated admission defect was found and no admission/census guard is modified. Live migration ordering and admission compatibility remain untested on this composed source.

Nearest existing main regressions to run on the fully migrated composition, in addition to the declaration migration selectors, are `Tests\Feature\ProductionIdentity\AccountAdmissionGuardUpgradeTest` methods:

- `test_fresh_ordering_and_populated_up_down_preserve_old_definitions_and_every_retained_row`
- `test_migration_only_dependency_floor_refuses_foreign_view_and_incoming_key_before_ddl`
- `test_migration_only_scanner_accepts_legitimate_triggers_and_refuses_foreign_or_altered_dependency`
- Native current-catalog boundary: `test_native_account_frame_captures_once_reuses_cheaply_and_proves_terminal_in_full`.

These are existing selectors, not new acceptance claims. Their original driver requirements remain intact. Identity V1's pending fixture/metadata work is a separate contract and is not a new prerequisite for MIG without a demonstrated dependency. Original MIG and V1 worktrees, including ROOT's two dirty V1 selection metadata files, are untouched.

## Finite existing verification selections for ROOT

First collect the actual test inventory on the frozen composition; no counts below are inferred as current results. Focused SQLite/source suite files:

- `tests/Unit/Migration/HistoryNormalizerTest.php`
- `tests/Unit/Migration/HistoryDeltaTest.php`
- `tests/Unit/Migration/HistoryPrivateSourceFilesTest.php`
- `tests/Unit/NormalizedCatalogSourceTest.php`
- `tests/Feature/Migration/LegacyHistoryReviewConditionsTest.php`
- `tests/Feature/Migration/LegacyHistoryArchiveTest.php`
- `tests/Feature/Migration/LegacyHistoryCommandTest.php`
- `tests/Feature/Migration/LegacyHistoryArchiveMigrationTest.php`
- `tests/Feature/Migration/ExclusivityAuthorityTest.php`
- `tests/Feature/Migration/LegacyHistoryDeclarationMigrationTest.php`

First bounded native integration selection (seven original cases including provider expansions):

- `LegacyHistoryDeclarationMigrationTest::test_populated_v1_upgrade_and_resume_preserve_originals_actors_reviews_and_guards`
- `LegacyHistoryDeclarationMigrationTest::test_sidecar_guards_enforce_identity_version_original_shape_foreign_keys_and_immutability`
- `LegacyHistoryDeclarationMigrationTest::test_uninserted_competitor_and_customer_in_older_partial_export_refuse_until_genuine_resume`, both original `interruptedMembershipReviewStates` datasets.
- `LegacyHistoryReviewConditionsTest::test_moved_competitor_versions_keep_their_authors_excluded_after_copy_and_omission`
- `LegacyHistoryArchiveTest::test_a_second_unrefunded_exclusive_sale_blocks_review_of_both`, both original `exclusiveAmounts` datasets (zero and positive).

These selections do not spawn process workers. If ROOT later selects the two existing `ExclusivityAuthorityConcurrencyTest` methods `test_native_concurrent_freezes_from_one_head_record_exactly_one_event` and `test_native_concurrent_declarations_on_an_empty_ledger_record_exactly_one_authority`, each needs two genuine App child witnesses for `tests/Support/exclusivity-authority-race-worker.php` through `Tests\Support\ExclusivityAuthorityRace`. Bind actual script/bootstrap/App/basePath/vendor/private state plus PID, PPID, starttime, UID, cwd and executable for both original worker roles. Parent origins alone do not prove child origins. Preserve the original 60-second process, 20-second parent barrier and 30-second child barrier; do not import F9's lease or namespace. No worker harness is created here.

## Evidence and untested conditions

Only Git/source composition and host structural checks were performed. PHP, lint, PHPUnit, SQL, generators, builds, installs and private/runtime output inspection were not run. ROOT owns actual source-bound SQLite/native selection, lease/admission, cleanup and any later exact-source review. Historical SQLite 124/1191 and native 16/164 receipts concern their recorded old source only; they are not results for this composition. No historical proof, mutant result or review is upgraded. Production custody, format/coverage authorization, real exports, exclusive activation and historical grant activation remain unproved or closed as previously recorded.
