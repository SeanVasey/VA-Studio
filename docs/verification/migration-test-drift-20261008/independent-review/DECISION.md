# Independent review: migration test drift (PR #58), October 8, 2026

Reviewer: independent Claude reviewer. I did not write this lane.

## What was reviewed

| Item | SHA |
|---|---|
| Reviewed merge commit (worktree `/home/user/VA-Studio-review-migdrift`, detached) | `6039b246d09cb77b4125f57f177b44b5f86f1055` |
| Lane test commit | `bc18ca665a954856dcdad23492c972b34932c677` |
| Lane docs commit | `ca1d1c1af99213c8bd37a582c4c07719ac1f2451` |
| Base (main) | `7204562005fc04099d09f7560cb2f7cac98051d7` |
| F1 fix (coordinator, after my first findings) | `a7d99fa1` (test), on branch head `5b35e696`. The test delta from `6039b246` to `5b35e696` is only `tests/Feature/ProductionTrackPolicyEngineTest.php`; everything else in `eca1bbc9` and `5b35e696` is docs, which I did not review. |

The diff was `git diff 72045620 6039b246 -- tests CHANGELOG.md`, read alongside `docs/verification/migration-test-drift-20261008/README.md`.

## Verdict for a development merge

**APPROVE** for a development merge of branch head `5b35e696`.

My first verdict on `6039b246` was APPROVE WITH CONDITIONS. Its blocking-relevant condition was F1, and F1 is resolved at `a7d99fa1`. I diffed that commit against my probe: apart from comment wording and the removal of the now-unused `Schema` import (no `Schema::` use remains), it is the same code. I ran the exact `a7d99fa1` file on MySQL 8.4.11: OK 1 test / 34 assertions, rc 0. On SQLite the case is skipped by design, rc 0.

No fixture change weakens a migration guard test. No refusal case passes for the wrong reason at the reviewed SHAs. Every refusal case I checked now refuses for its own injected drift, on SQLite and on MySQL 8.4.11.

The changes do the reverse: at main, 18 refusal cases (3 capability-ownership cases and 15 rights cases) were vacuous, because an ambient later schema object made them refuse. I reproduced that independently.

A remaining condition for final integrated verification, not for this development merge: the lane README already records that the two browser helpers have had no Playwright run.

## Findings

| # | Severity | Location | Finding |
|---|---|---|---|
| F1 | Medium (adjacent, pre-existing, not introduced). **Resolved at `a7d99fa1`.** | `tests/Feature/ProductionTrackPolicyEngineTest.php:35-46` | Same stale capability-rollback fixture as group A: it drops only 239 and the 238 packets, then calls 236 `down()`. On MySQL 8.4 it errors with `LogicException: Unexpected production capability external foreign key reference` (`review-evidence/mysql84-adjacent-ProductionTrackPolicyEngineTest-myisam.txt`). It is in `scripts/ci/database-sqlite-skips.json`, so the SQLite census skipped it. An untracked copy using `CapabilityRollbackFixture::isolateCapabilityTables()` in place of lines 36-41 passes on MySQL: OK 1 test / 34 assertions (`mysql84-probe-PolicyEngine-with-helper.txt`). A full MySQL census was out of scope, so other MySQL-only drift may exist; a scripted scan of SQLite-skipped methods that exercise rollbacks is listed under "Untested conditions". |
| F2 | Low (advisory) | `tests/browser/prepare-offer-draft.php:105`, `tests/browser/prepare-bulk-license-draft-source.php:52,170` | In edit phases the epoch rule is forward-only and unattributed. An epoch advance not caused by the reviewed edit, such as an epoch-only write or a net-zero write to a dependency table, is admitted. The probe "winner + two extra epoch-only bumps 43->45: ADMITTED" shows this. It is not a regression: before migration 240 no epoch existed, and every other row is still compared exactly. An unrelated `users` row change and a `license_templates` timestamp-only write are still refused. The no-op phase is stricter than before. A selected-offer `updated_at`-only write in `prepared` was previously invisible because `updated_at` is excluded for that row; it is now refused through the exact epoch. An exact expected-delta rule would be tighter, but it would also be brittle. No change is required. |
| F3 | Low (pre-existing, not introduced) | `RightsEvidenceGuardMigrationTest.php:154`; `ProductionTrackCapabilitiesMigrationOwnershipTest.php:60,121`; `ServiceProjectSchemaTest.php:78`; `ProductionTrackCapabilitiesGuardsTest.php:157` | Refusal assertions are generic: a message prefix, any `LogicException`, or `expectException(RuntimeException)`, which a `QueryException` would also satisfy. That genericity is why ambient objects could make cases vacuous unnoticed. This PR's mitigations hold. The rights `setUp()` baseline proof makes a future unexpected trigger fail setUp loudly. The capability files have positive controls: empty teardown must succeed. The populated rollback really throws plain `RuntimeException('Retain populated production capability evidence.')` on both engines, and the service rollback throws its own exact `down()` message. Recommended follow-up: assert the specific refusal reason per case. |
| F4 | Info | `tests/Support/CapabilityRollbackFixture.php:35` | The helper's final assertion covers only FK dependents. Non-FK dependents, such as a later external view or trigger referencing the capability tables, are not removed. They are still caught loudly by the positive controls in each file: `test_exact_empty_owned_teardown...` and `test_empty_migration_can_rollback...` would fail. So they cannot silently satisfy the refusal cases. On MySQL, the full-schema 236 `down()` first refuses with "external or additional table guard" (the checkout tables' triggers), not the FK message. Those triggers go away with their tables, which is why the MySQL rollback cases pass. |
| F5 | Info | `tests/Support/CapabilityRollbackFixture.php:71-82` | When the helper refuses on a non-empty or cyclic dependent, it may already have dropped other empty disposable leaves. That is acceptable for a disposable per-test database: the failure is loud, and the non-empty table is retained (probed). |
| F6 | Info | coverage | After this PR, no test asserts the real-world refusals that the fixtures now remove: 236 `down()` on the full schema after 246, and 035 `up()` on the full schema after 240. Before, these were only vacuous side effects. My probe shows 236 `down()` on the full schema refuses with zero DDL on both engines (SQLite: "external foreign key reference"; MySQL: "external or additional table guard"). Optional follow-up: add an explicit positive test. |

### Check-by-check conclusions

1. **Original assertions are intact.** In the capability files, the removed `assertDatabaseCount(..., 0)` lines are replaced by stronger helper assertions: every derived dependent and both packet tables must be empty, and no FK dependent may remain afterwards. The 238 `down()` still runs, inside the helper. No other assertion was removed, and the refusal texts are unchanged.

   My probe copies logged every refusal reason (`probe-sqlite-reasons-*.log`, `probe-mysql84.log`). Each one matches its own injected drift, and the lists match the lane's green files. My red reproduction from main's test files (`probe-sqlite-reasons-RightsRed.log`, `probe-sqlite-reasons-OwnershipRed.log`) confirms what main did. The nonprefix, cascading, changed-column, orphan and nonpositive rights cases all refused with "additional trigger for tracks". The capability "external view", "external trigger" and "temporary external view" cases refused with "external foreign key reference". The CHANGELOG count of 18 checks out: 3 + 15.
2. **`CapabilityRollbackFixture`:**
   - Derivation uses `Schema::getTableListing(getCurrentSchemaName(), false)` plus `getForeignKeys()`, which works on SQLite and on MySQL. Both engines derive the same 13 transitive dependents: 2 packet tables, 239, and 10 checkout tables. That set is a superset of the direct referrers found by an independent catalog scan.
   - A non-empty transitive dependent fails setup ("review_probe_grandchild must be empty") and survives.
   - A dependency cycle fails ("acyclic foreign-key graph").
   - The owned tables remain after isolation.
   - FK enforcement is never toggled.
   - Every test using it runs on a per-test `migrate:fresh` database: `FinalizationDatabaseMigrations`, or the ownership test's own `:memory:` connection. So no MySQL implicit commit can leak into other tests.
3. **Rights `setUp()` removes exactly the right triggers.** It removes exactly the 6 `cde_1_*`/`cde_2_*` triggers. On SQLite, all other 48 `cde_*` triggers remain: on the epoch table and the other 15 dependencies. A future unexpected trigger fails setUp loudly on both engines:
   - an unowned AFTER UPDATE trigger on `tracks` gives "additional trigger for tracks";
   - an epoch-like `cde_99_update` on `rights_declarations` gives "additional trigger for rights_declarations".

   A renamed or removed 240 trigger would make `DROP TRIGGER` fail loudly.
4. **Derived rollback steps reach the guarded `down()` and skip the later ones.**
   - Step = position + 1: 19 of 79 for 240, 16 of 79 for 244.
   - The exact `down()` message is asserted for 240. My probe asserts it for 244 too.
   - No DDL or DML runs, and the `migrations` row count is unchanged, so no later `down()` ran. Laravel's `Migrator::rollbackMigrations` only prints "Migration not found" for entries outside `--path`.
   - Minimality: step = position reports 18/18 "Migration not found", exits 0, never reaches 240, and changes nothing. The old `--step 1` failure was loud ("teardown admitted"), not vacuous.
   - The ordering query is the repository's own `getMigrations()`, the same one the migrator uses, so it orders identically on both engines.
5. **Browser helpers meet their stated rule.**
   - Prepare: forward-only.
   - Verify: exact in the no-op phase (`prepared`, the only `expectedCount === 0` phase) and forward-only in edit phases.
   - Epoch identity is exact: one row, `id` 1, `schema_version` 1, integer epoch.
   - Only `catalog_discovery_epoch` is removed, and only after its own check. Every other table and row is still compared exactly, along with the guard hash.
   - Probes: a no-op epoch bump is refused (offer and bulk). A backwards epoch is refused. An unrelated dependency write in an edit phase is refused. For what is admitted, see F2.
6. **Runs:** see below. All changed files pass on SQLite. On MySQL 8.4.11, Discovery (9), Service (3), Rights (33) and Guards (all 28, including the 2 changed rollback cases) pass.
7. **Probes:** see "Untracked files".

## Runs

The worktree was at `6039b246`, with `public/build` absent. Runner `R`:
`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <args>`.
Raw outputs are in `review-evidence/`.

### SQLite (phpunit.xml defaults)

| Command | Result | rc | Evidence |
|---|---|---|---|
| `R tests/Feature/ProductionTrackCapabilitiesMigrationOwnershipTest.php` | OK 23 tests / 606 assertions | 0 | `sqlite-ProductionTrackCapabilitiesMigrationOwnershipTest.txt` |
| `R tests/Feature/ProductionTrackCapabilitiesGuardsTest.php` | OK 28 / 126 | 0 | `sqlite-ProductionTrackCapabilitiesGuardsTest.txt` |
| `R tests/Feature/DiscoveryEpochMigrationTest.php` | OK 9 / 42 | 0 | `sqlite-DiscoveryEpochMigrationTest.txt` |
| `R tests/Feature/ServiceProjectSchemaTest.php` | OK 3 / 19 | 0 | `sqlite-ServiceProjectSchemaTest.txt` |
| `R tests/Feature/RightsEvidenceGuardMigrationTest.php` | OK 33 / 195 | 0 | `sqlite-RightsEvidenceGuardMigrationTest.txt` |
| `R tests/Feature/PrivateTrackReviewTest.php` | OK 16 / 215 | 0 | `sqlite-PrivateTrackReviewTest.txt` |
| `R tests/Unit/BulkLicenseDraftSourceBrowserEvidenceTest.php` | OK 4 / 119 | 0 | `sqlite-BulkLicenseDraftSourceBrowserEvidenceTest.txt` |
| `R tests/Unit/OfferDraftBrowserEvidenceTest.php` | OK 2 / 82 | 0 | `sqlite-OfferDraftBrowserEvidenceTest.txt` |
| `REVIEW_PROBE_LOG=… R tests/Feature/ReviewProbeCapabilityFixtureTest.php` (final probe version) | OK 5 / 78 | 0 | `probe-sqlite-CapabilityFixture.txt`, `probe-sqlite-capability.log` |
| `REVIEW_PROBE_SCENARIO={future,renamed_cde,cde_on_other_table} R --filter test_review_probe_setup_outcome tests/Feature/ReviewProbeRightsSetUpTest.php` | OK 1/2, 1/2, 1/7 | 0, 0, 0 | `probe-sqlite-rights-setup-*.txt` |
| `R tests/Feature/ReviewProbeRightsReasonsTest.php` | OK 33 / 195 | 0 | `probe-sqlite-reasons-Rights.{txt,log}` |
| `R tests/Feature/ReviewProbeOwnershipReasonsTest.php` | OK 23 / 606 | 0 | `probe-sqlite-reasons-Ownership.{txt,log}` |
| `R --filter rollback tests/Feature/ReviewProbeGuardsReasonsTest.php` | OK 2 / 53 | 0 | `probe-sqlite-reasons-Guards.{txt,log}` |
| `R tests/Feature/ReviewProbeServiceReasonsTest.php` | OK 3 / 21 | 0 | `probe-sqlite-reasons-Service.{txt,log}` |
| `R tests/Feature/ReviewProbeDiscoveryReasonsTest.php` | OK 9 / 52 | 0 | `probe-sqlite-reasons-Discovery.{txt,log}` |
| `R --filter 'cascading\|orphan\|changed_required\|nonpositive\|nonprefix' tests/Feature/ReviewProbeRightsRedReasonsTest.php` (main's file plus logging) | OK 6 / 22; all 6 refused for "additional trigger for tracks" (vacuous at main) | 0 | `probe-sqlite-reasons-RightsRed.{txt,log}` |
| `R --filter 'external\|foreign key child' tests/Feature/ReviewProbeOwnershipRedReasonsTest.php` (main's file plus logging; the filter matched all 21 cases) | 21 tests, 4 errors (the absent-name red cases); external view/trigger cases refused for "external foreign key reference" | 2 (expected red) | `probe-sqlite-reasons-OwnershipRed.{txt,log}` |
| `R --filter review_probe tests/Unit/ReviewProbeOfferEpochTest.php` | OK 5 / 37 | 0 | `probe-sqlite-offer-epoch.txt`, `probe-sqlite-browser-epoch.log` |
| `R tests/Feature/ReviewProbePolicyEngineAtA7d99fa1Test.php` (the `a7d99fa1` file) | 1 test skipped (MySQL-only by design) | 0 | `sqlite-probe-PolicyEngine-at-a7d99fa1.txt` |
| `R --filter review_probe tests/Unit/ReviewProbeBulkEpochTest.php` | first run: 1 failure, "Synthetic contract did not issue" in the seed worker under host load (the transient the lane README already records); rerun OK 2 / 17 | 1, then 0 | `probe-sqlite-bulk-epoch.txt`, `probe-sqlite-bulk-epoch-rerun.txt` |

### Native MySQL 8.4.11

The daemon was private: `--no-defaults --user=root`, port 3871 on 127.0.0.1, `--socket=/tmp/claude-0/rvmd.sock --mysqlx=OFF --skip-log-bin`, with its datadir in the scratchpad.

- **Durability flags.** The first attempt ran with default durability and was too slow for the session's background limit. I stopped it myself, partway through the Rights file, with no result. I restarted the daemon with `--innodb-flush-log-at-trx-commit=0 --innodb-doublewrite=OFF`. These flags affect durability only, not SQL semantics.
- **Environment.** `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3871 DB_DATABASE=vaseyaudio_test DB_URL= DB_SOCKET= DB_USERNAME=root DB_PASSWORD=`.
- **Databases.** The `vaseyaudio_test` schema was recreated before each run, by `scratchpad/rv-mysql.sh`. Ad-hoc probe runs used a separate `vaseyaudio_probe` schema.
- **Summary.** `review-evidence/mysql84-summary.txt`.

| Command (`R --display-skipped …`) | Result | rc | Evidence |
|---|---|---|---|
| `tests/Feature/DiscoveryEpochMigrationTest.php` | OK 9 / 42 | 0 | `mysql84-DiscoveryEpochMigrationTest.txt` |
| `tests/Feature/ServiceProjectSchemaTest.php` | OK 3 / 19 | 0 | `mysql84-ServiceProjectSchemaTest.txt` |
| `--filter 'test_models_and_populated_rollback_refuse_history_changes\|test_empty_migration_can_rollback_and_recreate_all_three_tables' tests/Feature/ProductionTrackCapabilitiesGuardsTest.php` | OK 2 / 52 | 0 | `mysql84-ProductionTrackCapabilitiesGuardsTest-rollback.txt` |
| `tests/Feature/RightsEvidenceGuardMigrationTest.php` | OK 33 / 191, 0 skipped | 0 | `mysql84-RightsEvidenceGuardMigrationTest.txt` |
| `tests/Feature/ReviewProbeCapabilityFixtureTest.php` (first version) | 5 tests, 1 failure | 1 | `mysql84-probe-CapabilityFixture.txt` |
| same, after making the full-schema assertion engine-aware (see note) | OK 5 / 78 (full file, `vaseyaudio_probe`); `--filter full_schema` OK 1 / 3 | 0, 0 | `mysql84-probe-CapabilityFixture-rerun.txt`, `mysql84-probe-CapabilityFixture-fullschema-rerun.txt`, `probe-mysql84-capability-rerun.log` |
| `--filter rollback tests/Feature/ReviewProbeGuardsReasonsTest.php` | OK 2 / 53; populated refusal is exactly `RuntimeException: Retain populated production capability evidence.` | 0 | `mysql84-probe-GuardsReasons.txt`, `probe-mysql84.log` |
| `--filter rollback tests/Feature/ReviewProbeServiceReasonsTest.php` | OK 1 / 5 | 0 | `mysql84-probe-ServiceReasons.txt` |
| `--filter real_artisan_rollback tests/Feature/ReviewProbeDiscoveryReasonsTest.php` | OK 2 / 32 | 0 | `mysql84-probe-DiscoveryReasons.txt` |
| `REVIEW_PROBE_SCENARIO={future,renamed_cde,cde_on_other_table} --filter test_review_probe_setup_outcome tests/Feature/ReviewProbeRightsSetUpTest.php` | OK 1/2, 1/2, 1/5 | 0, 0, 0 | `mysql84-probe-RightsSetUp-*.txt` |
| `tests/Feature/ReviewProbeRightsReasonsTest.php` | OK 33 / 191. All 25 logged refusals match their own drift. Four MySQL messages are more specific than SQLite's, as expected: index part, foreign key, column definition (2). The 2 temporary cases report a temporary table shadow. The only "additional trigger for tracks" refusals are the 5 cases whose injected drift is itself a foreign trigger on `tracks`. | 0 | `mysql84-probe-RightsReasons.txt`, `probe-mysql84-reasons-Rights.log` |
| `tests/Feature/ProductionTrackCapabilitiesGuardsTest.php` (full file) | OK 28 / 126, 0 skipped | 0 | `mysql84-ProductionTrackCapabilitiesGuardsTest.txt` |
| Adjacent: `--filter test_owned_policy_tables_require_innodb_when_session_defaults_to_myisam tests/Feature/ProductionTrackPolicyEngineTest.php` at `6039b246` (file unchanged by the lane at that SHA) | 1 test, 1 error: `LogicException: Unexpected production capability external foreign key reference` (F1) | 2 | `mysql84-adjacent-ProductionTrackPolicyEngineTest-myisam.txt`, `mysql84-adjacent-summary.txt` |
| Adjacent probe: the same method in `tests/Feature/ReviewProbePolicyEngineHelperTest.php`, using the new helper | OK 1 / 34 | 0 | `mysql84-probe-PolicyEngine-with-helper.txt` |
| F1 fix verification: `git show a7d99fa1:tests/Feature/ProductionTrackPolicyEngineTest.php`, class renamed to `ReviewProbePolicyEngineAtA7d99fa1Test`, full file (`vaseyaudio_probe`) | OK 1 / 34 | 0 | `mysql84-probe-PolicyEngine-at-a7d99fa1.txt` |

**Note on the first probe-CapabilityFixture failure.** The failing case was my own probe assertion, not the PR. I had assumed the full-schema 236 `down()` would refuse with "external foreign key reference". MySQL's preflight reports the checkout tables' triggers first, as "external or additional table guard". That is still a fail-closed refusal with zero DDL. I relaxed the probe to accept any `Unexpected production capability external …` refusal and re-ran the whole probe file on both engines. Both pass (5 / 78).

### Pint

| Command | Result | rc |
|---|---|---|
| `/home/user/VA-Studio/vendor/bin/pint --test` on the 9 changed PHP files | `{"result":"passed"}` | 0 (`pint-test.txt`) |
| Negative control: the same command on a deliberately misformatted canary (deleted afterwards) | `fail` | 1 (`pint-negative-control.txt`) |

## Untested conditions

- **No Playwright run** for `bulk-license-draft-source.spec.ts` or `offer-draft-authoring.spec.ts`; the preinstalled browsers do not match the pinned version. The helpers were exercised only through their PHPUnit drivers and my probes.
- **No full MySQL census.**
  - Only `ProductionTrackPolicyEngineTest`'s MyISAM case was checked among the MySQL-only tests (F1, now fixed).
  - A scripted scan found 11 SQLite-skipped methods whose bodies exercise rollbacks, drops or preflights. They are in `CustomerInquiryMigrationTest`, `InquiryNotificationMigrationTest`, `NativeSchemaIsolationTest`, `ProductionTaxCheckoutNativeInstallerTest`, `MembershipCreditEngineTest`, `SiteContentConcurrencyTest` and `CustomerInquiryAdministrationConcurrencyTest`.
  - The 10 besides F1 were not run, so they may hold MySQL-only drift of the same class, or refusal cases that pass vacuously.
- **Rights scoped-drop exactness** (the 48 remaining `cde_*` triggers) was counted on SQLite only. On MySQL it is implied: the baseline `up()` is an admitted no-op, and the MySQL scenario probes pass.
- **MySQL tuning.** The MySQL runs used relaxed InnoDB durability (`flush_log_at_trx_commit=0`, doublewrite off). They are a single-connection, non-concurrency run, so they are not evidence for race behaviour.
- **Real vendor tree.** The `vendor/` in the worktree is symlinked into the main checkout. The group-E environment artefacts in the lane README were not re-examined.
- **Base SHA.** The lane's census base was `f7224ed3`. I reviewed against `72045620`, the base of the reviewed merge.

## Untracked files left by this review

These are not committed. Only the files under `independent-review/` belong to the deliverable. Probe sources:

- `tests/Feature/ReviewProbeCapabilityFixtureTest.php`
- `tests/Feature/ReviewProbeRightsSetUpTest.php`
- `tests/Feature/ReviewProbeRightsReasonsTest.php`
- `tests/Feature/ReviewProbeRightsRedReasonsTest.php`
- `tests/Feature/ReviewProbeOwnershipReasonsTest.php`
- `tests/Feature/ReviewProbeOwnershipRedReasonsTest.php`
- `tests/Feature/ReviewProbeGuardsReasonsTest.php`
- `tests/Feature/ReviewProbeServiceReasonsTest.php`
- `tests/Feature/ReviewProbeDiscoveryReasonsTest.php`
- `tests/Feature/ReviewProbePolicyEngineHelperTest.php`
- `tests/Feature/ReviewProbePolicyEngineAtA7d99fa1Test.php`
- `tests/Unit/ReviewProbeOfferEpochTest.php`
- `tests/Unit/ReviewProbeBulkEpochTest.php`

Delete them before any shard partitioning in this worktree. Otherwise `phpunit-shards.py` would discover them.
