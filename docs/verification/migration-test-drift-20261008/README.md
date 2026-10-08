# Migration test drift on main, October 8, 2026

Base: `f7224ed3` (origin/main). Worktree `/home/user/VA-Studio-migtests`, branch `harness/migration-test-drift`. The lane agent did not commit; the integration owner committed the files listed under "What changed".

Status: SQLite census complete. Red/green done for every fixed file (SQLite, plus MySQL 8.4.11 for the files that run natively). Committed by the integration owner as `bc18ca66` (tests) and `ca1d1c1a` (this record), then merged with main `72045620` as `6039b246`; the final green and neighbour runs below are on that merged tree.

## Rules followed

- Fixes are in test fixtures and test-support code only. No migration, guard, refusal message, application class or workflow changed.
- No assertion was removed or loosened, and nothing was skipped. Every adversarial case is kept. Three fixtures now also prove their baseline before the refusal cases run (see group C).
- No hash-pinned file was touched. `resources/contracts/*/profile-assets.json` pins only `app/Domain/Grants/...` and `scripts/render-*-grant.php`.
- No SQLite skip was added, so `scripts/ci/database-sqlite-skips.json` is unchanged.

## How the census ran

`python3 scripts/ci/phpunit-shards.py --shards=4 --prefix=phpunit-ci-local` partitioned 7242 cases in 501 files (manifest: `evidence/census-shard-manifest.json`). Each shard then ran with the worktree runner (`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --configuration=phpunit-ci-local-<n>.xml --log-junit ...`), with `public/build` absent. The generated `phpunit-ci-local-*` files were deleted afterwards.

The shard script calls `php vendor/bin/phpunit` itself. In this worktree, `vendor/bin` is a symlink into the main checkout, which loads a second Composer autoloader. A PATH shim in the session scratchpad routed only that call to the runner above. Partition discovery was otherwise unchanged.

Shard 2 first died at 821/1811 on a PHP compile-time fatal (C1 below), and that fatal stopped every later test in the shard (`evidence/census-shard-2-fatal.txt`). Shard 2 was re-run in full with `--exclude-filter` set to that one method name, so every other case in it has a result.

| Shard | Tests | Failures | Errors | Skipped | Exit |
|---|---|---|---|---|---|
| 1 | 1811 | 12 | 11 | 133 | 2 |
| 2 (re-run, 1 method excluded) | 1810 | 1 | 0 | 136 | 1 |
| 3 | 1810 | 0 | 1 | 170 | 2 |
| 4 | 1810 | 1 | 6 | 175 | 2 |

**33 failing ids in total** (`evidence/census-f7224ed3-sqlite.tsv`, one row each with its first error line). That is the 17 already known, 16 more, and 1 process-killing fatal.

## Census and classification

(a) means a stale test fixture that predates a later, legitimate schema or API addition. (b) means a real defect in a migration, guard or rollback path. (env) means the failure comes from this worktree's symlinked `vendor/` and not from the code. **No failure is (b).**

| # | Test id(s) at f7224ed3 | Count | First error line | Introduced by | Class |
|---|---|---|---|---|---|
| A1 | `ProductionTrackCapabilitiesMigrationOwnershipTest`: `test_red_predecessor_foreign_trigger_on_absent_owned_schema_is_preserved_by_refusal`, the four `...absent...` data sets (#17–#20) of `test_every_unowned_modified_partial_shadow_or_external_dependency_is_preserved_before_ddl`, `test_exact_empty_owned_teardown_recreate_and_repeated_absent_noop_preserve_unrelated_schema` | 6 | `LogicException: Unexpected production capability external foreign key reference; rollback refused before schema changes.` | `ff4c6749` (246 production checkout: `CheckoutSchema` authority/basis/review `candidate_id` FKs) | (a) |
| A2 | `ProductionTrackCapabilitiesGuardsTest::test_models_and_populated_rollback_refuse_history_changes`, `::test_empty_migration_can_rollback_and_recreate_all_three_tables` | 2 | `Failed asserting that exception of type "LogicException" matches expected exception "RuntimeException". Message was: "Unexpected production capability external foreign key reference..."` / `LogicException: Unexpected production capability external foreign key reference...` | `ff4c6749` | (a) |
| B1 | `DiscoveryEpochMigrationTest::test_real_artisan_rollback_preserves_data_guards_bookkeeping_and_forward_migrate` (empty, populated) | 2 | `Operational teardown admitted.` | `1be99f0f` (242, the first migration after 240) | (a) |
| B2 | `ServiceProjectSchemaTest::test_actual_rollback_failure_retains_original_rows_and_migration_bookkeeping` | 1 | `Retained service evidence must refuse rollback.` | `48f2ee95` (245 `free_grant_origins`, the first migration after 244) | (a) |
| C | `RightsEvidenceGuardMigrationTest::test_additive_install_retry_and_operational_rollback_preserve_exact_historical_rows_audits_and_protection`, `::test_only_the_missing_suffix_of_a_recognized_installation_prefix_is_restored` (prefix 0–7) | 9 | `LogicException: Unexpected rights evidence additional trigger for tracks; existing schema and data are unchanged. Investigate before retrying.` | `7ecaa7e9` (240 discovery epoch adds `cde_1_*` on `tracks` and `cde_2_*` on `rights_declarations`) | (a) |
| C1 | `PrivateTrackReviewTest::test_unexpected_readiness_failure_cannot_render_ready_or_return_sensitive_exception_details` | 1 (fatal) | `PHP Fatal error: Declaration of PublicationReadiness@anonymous::blockers(Track $track): array must be compatible with PublicationReadiness::blockers(Track $track, ?CarbonInterface $at = null): array`, reported by PHPUnit as `Premature end of PHP process` | `7ecaa7e9` (added the optional `$at` parameter) | (a) |
| D1 | `Unit\BulkLicenseDraftSourceBrowserEvidenceTest`: `test_actual_helper_checks_every_selected_row_audit_and_unchanged_purchased_graph`, `test_actual_helper_rejects_extra_audit_context_without_readback_effects_or_private_text`, `test_complete_selected_row_receipt_detects_a_timestamp_only_write_for_native_noop_and_replay_comparisons` | 3 | `Isolated bulk-license source evidence refused. Failed asserting that 1 is identical to 0.` | `7ecaa7e9` (the epoch triggers on `license_templates`/`license_versions`) | (a) |
| D2 | `Unit\OfferDraftBrowserEvidenceTest::test_actual_read_only_helper_proves_price_audits_and_rejects_unrelated_private_changes` | 1 | `Isolated offer-draft evidence refused.` | `7ecaa7e9` (the epoch trigger on `offers`) | (a) |
| E | `FinalizationDatabaseLifecycleTest::test_cleanup_and_the_next_setup_remain_isolated` (4 data sets) | 4 | `PHP Fatal error: Cannot redeclare class ComposerAutoloaderInit...` | none (environment) | (env) |
| E | `IsolatedContractRendererAcceptanceTest::test_two_isolated_children_preserve_every_frozen_section_in_the_same_valid_multipage_pdf`, `TestContractIssuanceTest::test_real_isolated_renderer_can_issue_one_private_pdf_from_an_actual_paid_grant`, `TestContractProfileSuccessorTest::test_real_scrubbed_child_dispatches_only_the_trusted_successor_implementation`, `TestContractSuccessorActivationTest::test_exact_v2_policy_captures_real_request_and_worker_preserves_one_original` | 4 | `Test contract issuance is unavailable.` / `'ready'` expected, `'retry'` actual | none (environment) | (env) |

### Group A: capability rollback fixtures (A1, A2)

**Cause.** Migration 236's `down()` runs `CapabilityMigrationOwnership::preflight()`, which refuses while any table outside its three owned tables holds a foreign key to them. That refusal is deliberate. The fixtures in both tests removed only the dependents that existed when they were written: 239 `production_buyer_assent_observations` (dropped) and the 238 packets (via 238's own `down()`).

`ff4c6749` added the 246 checkout schema. `CheckoutSchema::definitions()` gives `production_checkout_exemption_authorities`, `production_checkout_exemption_bases` and `production_checkout_reviews` a `candidate_id` FK to `production_track_capability_candidates`, and their children hang off them. 246's `down()` deliberately refuses teardown ("Retain production checkout authority..."). A fresh migrate shows 13 transitive dependents of the three owned tables: 2 packet tables, the 239 table and the 10 checkout tables. There is no external view or trigger.

**Why (a).** The schema addition is legitimate, and the guard is right to refuse. The fixture was meant to represent an isolated 236 installation and no longer did.

**What else this exposed.** At f7224ed3, the `external view`, `external trigger` and `temporary external view` refusal cases in the ownership test passed for the wrong reason: the checkout FK made them refuse, not the drift each case injects. The red and green refusal reasons are in `evidence/refusal-reasons-{red,green}-ProductionTrackCapabilitiesMigrationOwnershipTest.txt`. After the fix, each case is refused for its own drift ("external view or trigger reference"), and the four absent-name cases run at all.

**Fix.** A new `tests/Support/CapabilityRollbackFixture::isolateCapabilityTables()` works as follows:

1. It derives every transitive FK dependent of the three owned tables from the live catalog on the current connection, using `Schema::getTableListing()` and `Schema::getForeignKeys()`, so it works on SQLite and on MySQL.
2. It asserts each dependent is empty, then drops it leaves first with FK enforcement unchanged.
3. The 238 packet tables still go through 238's own ownership-checked `down()`.
4. It asserts that no FK dependent remains.

A later dependent therefore can no longer silently break or satisfy these cases, and a non-empty or cyclic dependent fails setup loudly. Both tests call this helper. The old hard-coded `Schema::drop('production_buyer_assent_observations')` and the 238 `down()` calls are replaced by it.

### Group B: fixed `--step 1` rollbacks (B1, B2)

**Cause.** Both tests call `migrate:rollback` (or `app('migrator')->rollback()`) with the path of one migration and `step => 1`. `Migrator::getMigrationsForRollback()` takes the newest *recorded* migration, which is now `2026_10_07_259000_production_membership_billing`. That migration is not in the given path, so the migrator prints `Migration not found`, does nothing and exits 0. The guarded `down()` of 240 or 244 is never reached, and the test reports "teardown admitted".

Reproduction on a fresh SQLite database: step 1 exits 0 with `2026_10_07_259000_production_membership_billing ... Migration not found`. Step 21 reaches 240 and throws `LogicException: Retain discovery epoch, guards and migration bookkeeping; operational teardown is unsupported.` 240 sits at position 18 (0-based) in rollback order.

**Why (a).** Each test assumed its migration was the newest one. Later migrations broke that assumption. The `down()` methods still refuse correctly.

**Fix.** Each test derives the smallest step that reaches its migration from `app('migration.repository')->getMigrations(PHP_INT_MAX)`, the same order the migrator uses, and asserts the migration is recorded. The later migrations are still skipped as not found, so none of their `down()` methods run. The original assertions are unchanged: the exact refusal message, unchanged rows and bookkeeping, no DROP/ALTER/DELETE/UPDATE, and the forward migrate.

### Group C: rights evidence guard fixture

**Cause.** 035's `up()` doubles as an exact-prefix retry and repair. Its `preflightAdditionalTriggers()` admits only its own seven guards plus its predecessors' `tracks_public_url_*` and `tracks_publication_version_*` triggers, and refuses anything else. That refusal is deliberate and has its own test (`test_an_additional_trigger_on_a_protected_table_is_refused_before_restoring_missing_guards`). `7ecaa7e9` (240) legitimately added AFTER triggers `cde_1_*` on `tracks` and `cde_2_*` on `rights_declarations`, so every 035 retry on a fully migrated database refused.

**Why (a).** 035 runs before 240 in every real migrate. The fixture used today's full schema to stand for the schema that exists when 035 runs.

**What else this exposed.** At f7224ed3, 15 of the refusal cases passed only because of the `cde_*` triggers ("additional trigger for tracks"), not because of the drift each case injects. Examples are the non-prefix order, cascading FK, changed column, orphan references and nonpositive identity cases (`evidence/refusal-reasons-red-RightsEvidenceGuardMigrationTest.txt`). After the fix, each is refused for its own reason (`...-green-...txt`): installation order, table definition, retained rights reference, retained nonpositive verified identity, and so on.

**Fix.** `setUp()` drops exactly the 240 triggers on the two protected tables, derived from `DiscoveryEpoch::guards($driver)` filtered by table. It then proves the baseline: 035's `up()` on the reduced fixture must be an admitted no-op with zero DDL. Every refusal case now starts from an accepted 035 installation, and a future trigger on either table fails setup loudly instead of making the refusal cases vacuous.

### C1: `PrivateTrackReviewTest` (outside the known 17)

`7ecaa7e9` added an optional second parameter to `PublicationReadiness::blockers(Track $track, ?CarbonInterface $at = null)`. The test's anonymous subclass still declared `blockers(Track $track): array`. PHP rejects that override when the class is declared, as a compile-time fatal, and the fatal ends the whole PHPUnit process. In the census it stopped shard 2 at 821 of 1811. In CI it would abort whichever shard holds this file and hide every later result in that shard.

**Fix.** The override signature now matches the parent (`?CarbonInterface $at = null`, plus the import). The body and every assertion are unchanged: a 503 with "Private track review is unavailable.", the no-store/noindex headers and no leaked details. This is (a).

### Group D: browser-evidence helpers (outside the known 17)

`tests/browser/prepare-bulk-license-draft-source.php` and `tests/browser/prepare-offer-draft.php` are CLI helpers for the Playwright specs. Unit tests (D1, D2) exercise them for real. Both snapshot every row of every table and require that no pre-existing row changes, apart from the rows they expect the operator to edit.

`7ecaa7e9` (240) added the discovery epoch: a single-row counter that triggers on `license_templates`, `license_versions`, `offers`, `users` and others advance on every write. Instrumented runs, which were not committed, showed the only changed row: `catalog_discovery_epoch#1 {"epoch":42} -> {"epoch":46}` after the bulk helper created its drafts, and `catalog_discovery_epoch` after the offer edit. This is (a).

**Fix.** Each helper still compares every other row exactly, and handles the epoch row with an explicit, stricter rule:

- The epoch row's identity is exact: one row, `id = 1`, `schema_version = 1`, integer `epoch`.
- During bulk preparation, the epoch may only move forward.
- At verify:
  - In the no-op phase (`prepared`, zero expected updates), the epoch must be exactly unchanged. This is a stronger no-write check than before.
  - In edit phases, the epoch may only move forward.

The fixed refusal text, the unrelated-change refusal and the timestamp-only canary all still hold. D1 and D2 are green in the worktree and in the real-vendor export.

**Flagged for review.** These helpers also run under the Playwright specs `bulk-license-draft-source.spec.ts` and `offer-draft-authoring.spec.ts`, and no browser run was possible here.

### Group E: environment artefacts (not fixed, nothing to fix)

In this worktree, `vendor/*` and `vendor/bin` are symlinks into `/home/user/VA-Studio/vendor`. Two consequences:

- `FinalizationDatabaseLifecycleTest` spawns `vendor/bin/phpunit`, which loads the main checkout's autoloader next to the worktree's.
- The isolated renderer child runs under `open_basedir=<project root>`, so it cannot read symlinked vendor files outside the root. Reproduced directly: `require(...polyfill-mbstring/bootstrap.php): open_basedir restriction in effect`.

To confirm, a scratch export of `f7224ed3` was built with a real (hard-linked) vendor tree, and the same tests were run there with the standard `php vendor/bin/phpunit`. All passed:

| Test file | Result in the real-vendor export |
|---|---|
| FinalizationDatabaseLifecycleTest | 4/4 |
| IsolatedContractRendererAcceptanceTest | 1/1 |
| TestContractSuccessorActivationTest | 4/4 |
| TestContractIssuanceTest | 52/52 |
| TestContractProfileSuccessorTest | 16/16 |

Receipts are in `evidence/realenv-*.txt`. CI installs a real vendor tree, so these failures do not apply there.

## What changed

| File | Change |
|---|---|
| `tests/Support/CapabilityRollbackFixture.php` (new) | Catalog-derived, cross-driver isolation of the 236 tables (group A) |
| `tests/Feature/ProductionTrackCapabilitiesMigrationOwnershipTest.php` | setUp uses the helper (A1) |
| `tests/Feature/ProductionTrackCapabilitiesGuardsTest.php` | Both rollback cases use the helper (A2) |
| `tests/Feature/DiscoveryEpochMigrationTest.php` | Derived rollback step (B1) |
| `tests/Feature/ServiceProjectSchemaTest.php` | Derived rollback step (B2) |
| `tests/Feature/RightsEvidenceGuardMigrationTest.php` | setUp removes the 240 triggers on the protected tables, then proves the 035 baseline (C) |
| `tests/Feature/PrivateTrackReviewTest.php` | Override signature matches the parent (C1) |
| `tests/browser/prepare-bulk-license-draft-source.php` | Explicit discovery-epoch rule (D1) |
| `tests/browser/prepare-offer-draft.php` | Explicit discovery-epoch rule (D2) |
| `CHANGELOG.md` | Unreleased → Fixed entry |

## Red/green

Unless marked otherwise, runs are SQLite with the worktree runner. Red means the f7224ed3 file and green means the changed file. Raw outputs are in `evidence/`.

| File | Red at f7224ed3 | Green |
|---|---|---|
| ProductionTrackCapabilitiesMigrationOwnershipTest | 23 tests, 6 errors | OK 23 tests / 606 assertions |
| ProductionTrackCapabilitiesGuardsTest | 28 tests, 1 error, 1 failure | OK 28 / 126 |
| DiscoveryEpochMigrationTest | 9 tests, 2 failures | OK 9 / 42 |
| ServiceProjectSchemaTest | 3 tests, 1 failure | OK 3 / 19 |
| RightsEvidenceGuardMigrationTest | 33 tests, 9 errors | OK 33 / 195 |
| PrivateTrackReviewTest | fatal, process ended | OK 16 / 215 |
| Unit\BulkLicenseDraftSourceBrowserEvidenceTest | 3 failures | OK 4 / 119 (real-vendor export) |
| Unit\OfferDraftBrowserEvidenceTest | 1 failure | OK 2 / 82 (real-vendor export) |

### Native MySQL 8.4.11

These runs used a private `mysqld` 8.4.11 (`--no-defaults --user=root --socket= --mysqlx=OFF --skip-log-bin`) on a free loopback port, with its datadir in the session scratchpad and a fresh `vaseyaudio_test` schema per run. The runs were serialized. The instance was shut down, and its datadir deleted, after a container restart. The green files were byte-identical copies of the worktree files, run from the scratchpad path.

| Run | Result |
|---|---|
| Red Discovery rollback cases | 2 failures, "Operational teardown admitted." |
| Red Rights additive + prefix 0 | 2 errors, "additional trigger for tracks" |
| Green DiscoveryEpochMigrationTest | OK 9 / 42 |
| Green RightsEvidenceGuardMigrationTest | OK 33 / 191, 0 skipped |
| Red Guards (2 rollback cases) | 1 error, 1 failure |
| Green Guards (2 rollback cases) | OK 2 / 52 |
| Red ServiceProjectSchemaTest rollback case | 1 failure |
| Green ServiceProjectSchemaTest rollback case | OK 1 / 3 |

`ProductionTrackCapabilitiesMigrationOwnershipTest` always uses its own SQLite connection, so MySQL does not apply to it.

## Neighbouring tests, receipts, Pint

- Merged tree `6039b246` (lane plus main `72045620`), SQLite, worktree runner, `public/build` absent (`evidence/green-merged/`):

| Run | Result |
|---|---|
| ProductionTrackCapabilitiesMigrationOwnershipTest | OK 23 / 606, rc 0 |
| ProductionTrackCapabilitiesGuardsTest | OK 28 / 126, rc 0 |
| DiscoveryEpochMigrationTest | OK 9 / 42, rc 0 |
| ServiceProjectSchemaTest | OK 3 / 19, rc 0 |
| RightsEvidenceGuardMigrationTest | OK 33 / 195, rc 0 |
| PrivateTrackReviewTest | OK 16 / 215, rc 0 |
| Unit\BulkLicenseDraftSourceBrowserEvidenceTest | OK 4 / 119, rc 0 |
| Unit\OfferDraftBrowserEvidenceTest | OK 2 / 82, rc 0 |
| Every `tests/Feature/*Migration*Test.php` and `tests/Feature/**/*Migration*Test.php` (49 files, one run each) | 49 files rc 0, 0 failed (`sqlite-neighbours.txt`, file list in `neighbour-files.txt`) |
| `python3 -I scripts/ci/test-database-receipts.py` | 34 tests OK (`database-receipts-selftest.txt`) |

The 49-file count replaces the earlier 58-file estimate: it is the exact glob result on the merged tree.
- `python3 -I scripts/ci/test-database-receipts.py`: 34 tests OK.
- `/home/user/VA-Studio/vendor/bin/pint --test` on all 9 changed PHP files: passed. A deliberately misformatted probe file fails, which shows the check runs.

## Untested conditions and notes

- No browser run. The two `tests/browser` helpers changed (group D), and their Playwright specs were not run because the preinstalled browsers do not match the pinned version.
- Same latent issue, not fixed. `tests/browser/prepare-license-draft.php` (used only by `license-template-authoring.spec.ts`, with no PHPUnit coverage) has the same all-rows equality at lines 46 and 95. It is likely to refuse for the same epoch reason. Recommended follow-up: give it the same explicit epoch rule, with a browser run.
- Operational observation (not a defect). After 240, re-running 035's `up()` as a repair on a real database always refuses, because the `cde_*` triggers are unknown to 035. The same holds for 236's `down()` after 246. Both refusals are fail-closed by design. If production rights guards ever need repair, it has to be a new forward migration.
- One run under heavy host load had a transient failure: the D1 worker's seeded contract did not issue ("Synthetic contract did not issue"). The rerun passed. This is recorded here, not fixed.
- The census is SQLite only. A full MySQL census was not run; the cost policy reserves that for Foundation CI.
