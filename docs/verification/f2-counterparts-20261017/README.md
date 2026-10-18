# F2c: native MySQL counterparts for the 25 SQLite-only census methods

Lane F2c, October 17 to 18, 2026, branch `harness/f2-counterparts-20261017`, based on main `efa6eea5b007f1f2623c4009c1e9bef737f06bee`. This batch reconciles the 25 methods in the reviewed MySQL skip census (`scripts/ci/database-mysql-skips.json`), which run only on SQLite and skip on MySQL, against meaningful native MySQL counterparts. That reconciliation is item 3 of the [development agenda](../../handoff/2026-10-15/DEVELOPMENT-AGENDA.md) and the remaining F2 test prerequisite. It is not full F2 acceptance. Full F2 acceptance, the complete native matrix and the manual Foundation verification remain gates for the final integrated candidate.

The change is test-only plus census data and two workflow comments. No application, migration, schema, provider, activation, lockfile, resource-profile or frozen contract file is changed. Nothing was pushed, no pull request was opened and no workflow was dispatched.

## Outcome in brief

- Of the 25 SQLite-only methods, 5 already had a meaningful native counterpart (classification a), 14 needed one (classification b) and 6 are genuinely SQLite-specific (classification c).
- All 14 counterparts for the (b) set are implemented as 14 new MySQL-only test methods, 16 cases in all because one method has three data sets.
- **Corrected after independent review (P2-1):** rows 18 and 23 were misclassified as (c). Row 18's `binding` data set already has a native counterpart, `ProductionTaxCheckoutCommitAdmissionTest::test_native_global_binding_capture_reads_a_peer_commit_after_the_repeatable_read_snapshot` (line 580), so it is (a). The caveat: its committed peer injects at the bind frame's `TransactionBeginning`, so it may prove a current read rather than the commit-time fence. Row 18's `reviewed` data set (`ptx_s_u3`) and row 23 are (b) and **remain open F2 work**. The construction to try is to drop the index before the frame and commit a separate peer at `TransactionCommitting`. So the (b) set is not fully closed.
- Each new method skips on SQLite and is appended to the SQLite skip census (`scripts/ci/database-sqlite-skips.json`, 266 to 280 entries). The first 150 entries and their pinned hash `d93da4e7a13a8c6aff8f97f363eb6d93dde884bad53c9ae50eeb0ed32514c71c` are unchanged. The MySQL skip census is unchanged at 25 methods, because the original SQLite-only methods still skip on MySQL.
- On the private MySQL 8.4.11 server, all 16 new cases pass (120 assertions, no failures, errors or skips). Two first-attempt failures are retained below.
- A red proof was run for every counterpart: with the native protection removed by a temporary, uncommitted mutation, each counterpart fails.
- List-only native selection discovery moves from 2432 of 9202 cases in 233 files to 2448 of 9218 cases in 233 files. The selection policy file itself needed no change, because every affected test file was already selected.

## Tested source

| Commit | Content |
| --- | --- |
| `e528c17442d6b8800bb02c50a27b3ed1e4747e18` | The 14 counterpart methods |
| `b573a53dbd4ec5ea86983195c5f6bbb9c1358ebc` | Fix to how the parent-shadow counterpart builds its temporary table (one method body) |
| `ca046f0973152e07c7563b392d94b7411a448f87` | Native refusal reason pinned in the billing callback counterpart (one expectation and comment). This is the final test source. |
| `06b55b1e8bdfc016cd906e3c601c8c3769808230` | Census append and the two workflow comments. No test, application or database source differs from `ca046f09`. |

The commit that adds this packet changes documentation only.

Source carry between commits is exact. Between `e528c174` and `ca046f09`, `git diff --stat` shows only two files: `ProductionFreeGrantSchemaTest.php` (the parent-shadow method body: 2 lines removed, 3 added) and `BillingSubscriptionApprovalTest.php` (one expectation plus one comment). Native runs made at `e528c174` for any other method therefore apply byte for byte to `ca046f09`. Between `ca046f09` and `06b55b1e`, `git diff --name-only -- app tests database routes config` is empty.

## Runtime

- PHP 8.4.26 (CLI, NTS), PHPUnit 12.5.34, SQLite 3.45.1 through PDO.
- Oracle MySQL Community Server 8.4.11, private to this lane: datadir `/var/lib/va-mysql84/data`, loopback port 33584, socket `/var/lib/va-mysql84/run/mysqld.sock`. The server ran with its defaults: `innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, `restrict_fk_on_non_standard_key=ON`, REPEATABLE-READ. One timing probe briefly relaxed the two durability settings and they were restored at once (see Failed, interrupted and non-acceptance runs). Every recorded test run used the defaults.
- Node 24.22.0 for the Python safeguards that call Node.
- `public/build` was absent for every PHP run.
- Server lifecycle: started at 17:30 UTC on October 17 with `/var/lib/va-mysql84/start.sh` as a background task. At 19:30 UTC the harness stopped that task at its two-hour background limit. The error log records `Received SHUTDOWN from user <via user signal>` and no completion line, so that stop is not recorded as a clean shutdown, and it interrupted red run r8. The remaining four native runs each used a bounded foreground session that started the server with the same script, ran one selection under a hard `timeout`, and shut down with `mysqladmin shutdown`. Each of those four sessions logged `Shutdown complete (mysqld 8.4.11)`. The first one also logged InnoDB/XA crash recovery after the earlier signal stop. The last normal shutdown completed at 19:54:14 UTC on October 17, and the socket was gone afterwards. The container rebooted around 00:36 UTC on October 18 while no mysqld was running and no test run was in progress. Nothing after the reboot needed the server.

## Inventory of the 25 SQLite-only methods

"Native counterpart" means a test that runs on MySQL in a file the native selection already selects. All test classes are under `Tests\Feature`.

| # | Class::method | What it proves | Why it is SQLite-only | Class | Native counterpart |
| --- | --- | --- | --- | --- | --- |
| 1 | `CustomerListeningFreshnessTest::test_framework_reads_cannot_use_a_temporary_catalog_shadow_while_proof_reads_main` | A temporary `tracks` table that shadows framework reads makes the listening read refuse with 503 instead of releasing shadow metadata | Uses SQLite `main`/`temp` schema resolution | b | New: `test_native_session_temporary_catalog_shadow_is_refused_before_release` (a MySQL session temporary table shadows even schema-qualified names) |
| 2 | `CustomerListeningMigrationTest::test_foreign_or_drifted_objects_are_never_adopted_or_dropped` (3 data sets) | A foreign index, column or trigger on `customer_saved_tracks` makes `up()` and `down()` refuse without changing the schema or history | Reads `sqlite_master` and uses SQLite trigger syntax | b | New: `test_native_foreign_or_drifted_objects_are_never_adopted_or_dropped` (same three drifts in MySQL syntax, compared through `SHOW CREATE TABLE` and `information_schema.TRIGGERS`) |
| 3 | `CustomerListeningMigrationTest::test_temporary_shadow_is_never_adopted_or_dropped` | A temporary shadow of the owned table is neither adopted nor dropped | SQLite temp catalog | b | New: `test_native_session_temporary_shadow_is_never_adopted_or_dropped` |
| 4 | `CustomerSuppressionMigrationTest::test_missing_identity_dependency_and_disabled_fk_enforcement_are_refused_without_installing` | With foreign keys disabled and `customer_accounts` missing, and again with foreign keys re-enabled, installation refuses before any DDL | `PRAGMA foreign_keys` | b | New: `test_native_disabled_fk_checks_and_missing_identity_dependency_are_refused_without_installing` (`SET FOREIGN_KEY_CHECKS`). It also proves the disabled flag alone refuses while every dependency is intact. The only related native case, `CustomerConsentAdmissionTest` data set 9, is in `residual_files`, so it never runs on MySQL. |
| 5 | `CustomerSuppressionMigrationTest::test_sqlite_replace_cannot_delete_retained_target_or_intent` | `INSERT OR REPLACE` cannot delete a retained target or intent | SQLite conflict-resolution syntax | b | New: `test_native_replace_cannot_delete_retained_target_or_intent`. MySQL `REPLACE` deletes the duplicate row and then inserts; a probe confirmed it fires BEFORE DELETE guards. The existing two-engine test covers UPDATE, DELETE and duplicate insert, not REPLACE. |
| 6 | `DiscoverySitemapNamespaceAdmissionTest::test_sqlite_named_inline_check_and_fk_clauses_do_not_reserve_foreign_index_names` | A foreign index carrying an owned constraint name does not falsely block installation | SQLite's schema-global index namespace | a | Existing: `test_native_foreign_table_local_unique_names_do_not_reserve_owned_constraint_names` and `test_native_check_and_fk_names_have_separate_constraint_namespaces` in the same file. MySQL index names are table-local. |
| 7 | `FreeGrantSchemaRecoveryTest::test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target` | A `users` table whose primary key is `(id, tenant)` is refused before owned DDL | SQLite DDL | b | New: `test_native_composite_dependency_primary_key_is_not_a_unique_id_target` (keeps id `bigint unsigned AUTO_INCREMENT` and pins the message "Free grant dependency primary key changed.") |
| 8 | `PaidGrantSchemaRecoveryTest::test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target` | Same, for the paid-grant schema | SQLite DDL | b | New: `test_native_composite_dependency_primary_key_is_not_a_unique_id_target` |
| 9 | `PaidTaxDownloadJourneyTest::test_new_delivery_public_reads_refuse_while_schema_admission_is_still_pending` | A public read of a new delivery table during delivery-schema admission is refused | Hooks a `SQLiteGrammar` subclass | b | New: `test_native_new_delivery_public_reads_refuse_while_schema_admission_is_still_pending` (`MySqlGrammar` subclass; admission compiles the native plan through the same `compileCreate` path) |
| 10 | `PaidTaxSchemaRecoveryTest::test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target` | Same as 7, with a retained V1 row referencing `users` | SQLite DDL | b | New: `test_native_composite_dependency_primary_key_is_not_a_unique_id_target`. MySQL 8.4 itself refuses to re-create the referenced parent with error 6125, and the test asserts that. It then sets `restrict_fk_on_non_standard_key=OFF` for its own session only, builds the damaged parent, restores the setting, and proves the application still refuses. |
| 11 | `ProductionCheckoutMigrationTest::test_sqlite_duplicate_table_and_trigger_namespace_cannot_hide_a_foreign_marker` | A foreign table named like an owned trigger cannot hide behind the exact owned trigger | Its skip message says SQLite has separate table and trigger namespaces | b | New: `test_native_coexisting_table_and_trigger_namespaces_cannot_hide_a_foreign_marker`. A probe confirmed MySQL also lets a table and a trigger share a name. The native test installs every owned table first, so no installation gap can explain the refusal, and it pins the exact refusal message. |
| 12 | `ProductionFreeGrants\ProductionFreeGrantSchemaTest::test_a_temporary_shadow_of_a_parent_or_owned_table_is_refused` | Temporary shadows of the parent `users` (reason `parent_floor`) and of an owned table (`shadowed_schema`) are refused | SQLite temp catalog | b | The owned half already existed: `test_native_temporary_shadow_of_an_owned_table_is_refused`. New for the parent half: `test_native_temporary_shadow_of_a_parent_table_is_refused` (same columns, no rows, so only the shadow differs) |
| 13 | `ProductionFreeGrants\ProductionFreeGrantSchemaTest::test_every_contiguous_empty_installation_prefix_resumes_to_the_exact_schema` (37 data sets) | Every empty installation prefix from 0 to 36 statements resumes to the exact schema | Replays `sqlite_master` statement text. Its skip message notes that the native counterpart was untested. | b | New: `test_native_every_contiguous_empty_installation_prefix_resumes_to_the_exact_schema`. It covers all 37 prefixes inside one method, replaying the server's own `SHOW CREATE TABLE` and `SHOW CREATE TRIGGER` text, and compares complete native definitions rather than names only. |
| 14 | `ProductionFreeGrants\ProductionFreeGrantSchemaTest::test_parent_floor_drift_is_refused_before_the_first_owned_ddl` | A drifted `customer_accounts` replacement is refused before owned DDL | `PRAGMA legacy_alter_table` rename | b | New: `test_native_parent_floor_drift_is_refused_before_the_first_owned_ddl` (`RENAME TABLE` plus a drifted replacement whose `active` column is `varchar(1)`) |
| 15 | `ProductionMemberOriginals\MemberGrantSourceAdmissionTest::test_sqlite_verified_member_refuses_both_honest_and_mislabeled_configuration_before_factories` | Verified production provenance refuses a SQLite frame, even one whose configuration is mislabeled `mysql` | The refused frame must be non-MySQL | a | Existing: `test_native_verified_member_provenance_requires_and_binds_actual_mysql_despite_rehearsal_membership`, the native acceptance half. The refusal half needs a non-native frame by definition. |
| 16 | `ProductionMembershipBilling\BillingAwardFenceTest::test_sqlite_replay_lock_holds_writer_intent_even_without_a_period_insert` | The award fence `lock()` alone takes writer intent and blocks a peer insert | SQLite database-level write lock | a | Existing native row-lock races in `MembershipNativeAwardRaceTest`: `test_concurrent_owned_awards_return_identical_ids_and_commit_one_period_and_one_event` (the parent holds `lock()` alone and a worker waits on fence row 1) and `test_hint_insert_waits_for_award_fence_then_refuses_committed_receipt_without_undoing_award` |
| 17 | `ProductionMembershipBilling\BillingSubscriptionApprovalTest::test_encryption_callback_cannot_remove_a_billing_guard_before_the_write` | An encryption callback that drops a billing guard cannot let the approval write proceed; SQLite rolls the drop back | Transactional DDL | b | New: `test_native_encryption_callback_ddl_cannot_write_an_approval_after_its_implicit_commit`. On MySQL the DROP implicitly commits. The test proves the refusal `changed_primary_or_schema` before any write, zero approvals, the guard staying absent, and later approvals refused with `schema_prefix`. |
| 18 | `ProductionTaxCheckout\ProductionTaxCheckoutCommitAdmissionTest::test_global_provider_identity_reuse_in_an_unrelated_request_refuses_before_commit` (2 data sets) | A provider identity reused in an unrelated request, injected in the committing delegate behind a dropped unique index, refuses before commit and rolls the index back | Drops and restores a unique index inside the open transaction | c | None meaningful. MySQL DDL commits implicitly (probe below), so the injection would commit the staged evidence itself. Without dropping the index, the native unique index refuses the duplicate at insert time. No dedicated native duplicate test of `ptx_b_u1` or `ptx_s_u3` was found or run here. |
| 18 (corrected) | same method | | | `binding`: a (with caveat); `reviewed`: b, remaining | Independent review P2-1: the existing native peer-commit test (line 580) covers `binding` with a separate committed connection and no open transaction. A peer counterpart for `reviewed` (`ptx_s_u3`) is not yet written. |
| 19 | `ProductionTaxCheckout\ProductionTaxCheckoutCommitAdmissionTest::test_historical_owner_key_withdrawal_in_a_committing_delegate_rolls_back_new_provider_evidence` (2 data sets) | Owner-key drift injected by replacing the account guard inside the transaction refuses and rolls back new evidence | Transactional guard replacement | c | None meaningful. The guard cannot be removed and restored inside a MySQL transaction, and peer DDL would wait on the metadata lock. On MySQL the guard itself prevents the drift: `CustomerAccountMigrationTest::test_raw_identity_rewrites_deletion_and_access_version_replay_are_refused` (pattern-selected) refuses a raw `owner_key` rewrite. |
| 20 | `ProductionTaxCheckout\ProductionTaxCheckoutMigrationTest::test_installer_refuses_drift_gaps_populated_partials_shadows_and_foreign_references` (7 data sets) | The Tax255 installer refuses damaged installations before DDL | SQLite namespace injection | a | Existing: `ProductionTaxCheckoutNativeInstallerTest::test_native_installer_refuses_before_ddl`, with 13 native damage data sets covering all seven SQLite damages and more |
| 21 | `ProductionTaxCheckout\ProductionTaxCheckoutMigrationTest::test_migration_installs_exactly_four_tables_and_twelve_guards_and_a_rerun_is_a_no_op` | Exact installed text, rerun no-op, frozen V1 installer rerun | SQLite dictionary text | a | Existing: `ProductionTaxCheckoutNativeSchemaTest::test_native_dictionary_matches_the_generated_guards_and_a_rerun_is_a_no_op` and `ProductionTaxCheckoutNativeInstallerTest::test_native_frozen_v1_installer_reruns_with_tax255_present_and_tables_are_inert` |
| 22 | `ProductionTaxCheckout\ProductionTaxCommittedReadReceiptTest::test_sqlite_final_raw_order_fence_detects_late_decryption_callback_drift_and_restores_guard` | The final raw order fence detects drift injected behind a dropped guard in a decryption callback, and rollback restores the guard | Transactional guard removal | c | None meaningful. On MySQL the DROP would commit the original probe itself, which is equivalent to the callback issuing COMMIT and outside the fence's contract. |
| 23 | `ProductionTaxCheckout\ProductionTaxCommittedReadReceiptTest::test_sqlite_global_payment_alias_in_an_unrelated_request_refuses_original_raw_fence` | Same pattern as 18, for the receipt fence | Transactional index replacement | b, remaining (corrected from c) | Independent review P2-1: the peer construction (drop the index before the frame, commit a separate peer at `TransactionCommitting`) is plausible and not yet written. Remaining F2 work. |
| 24 | `ProductionTaxCheckout\ProductionTaxCommittedReadReceiptTest::test_sqlite_historical_owner_key_drift_refuses_and_owned_abort_restores_original_relation` | Same pattern as 19, for the receipt fence | Transactional guard replacement | c | None meaningful. The guard-side protection is the same account test cited in 19. |
| 25 | `ProductionTaxCheckout\ProductionTaxCommittedReadReceiptTest::test_sqlite_receipt_birth_refuses_disabled_rollback_journaling` | Receipt birth refuses `journal_mode=OFF` | No MySQL session equivalent | c | None. The nearest native protection is non-transactional storage, covered on the checkout write path by `test_native_nontransactional_owned_table_refuses_before_any_new_write_even_with_a_misleading_comment`. That test covers writes, not receipt birth. |

Classification counts: (a) 5 (rows 6, 15, 16, 20, 21), (b) 14 (rows 1 to 5, 7 to 14 and 17), (c) 6 (rows 18, 19 and 22 to 25).

Row 17 and rows 18, 19 and 22 to 24 all use DDL inside a callback. They are classified differently for a concrete reason. In row 17, nothing is staged before the callback, so on MySQL the writer still has to refuse after its frame has been committed away. That is meaningful native behaviour, and the new test proves it. In the (c) rows, the callback's DDL would itself commit staged provider evidence or the probe, so the SQLite assertion that nothing is retained cannot hold on MySQL for any application, and no application code can prevent it.

## SQL probes on the private server

These probes ran directly through the `mysql` client on 8.4.11 in a throwaway schema `vaseyaudio_probe`, which was dropped afterwards. They are not test results.

- A table `pco_0_insert` and a trigger `pco_0_insert` coexist; `information_schema` lists both.
- `START TRANSACTION; INSERT ...; DROP TRIGGER ...; ROLLBACK;` leaves the inserted row present, so the DROP committed implicitly.
- `REPLACE INTO` a row that a BEFORE DELETE guard protects fails with SQLSTATE 45000 from the guard, and the row is unchanged.
- `CREATE TEMPORARY TABLE tracks AS SELECT * FROM db.tracks` succeeds. Afterwards `SHOW CREATE TABLE db.tracks` reports `CREATE TEMPORARY TABLE`, and schema-qualified reads see the temporary rows.
- With foreign-key checks off, re-creating a referenced parent with `PRIMARY KEY (id, tenant)` fails with error 6125 while `restrict_fk_on_non_standard_key=ON`. It succeeds, with deprecation warning 6124, when that is set OFF for the session.

## Census and selection

- `scripts/ci/database-sqlite-skips.json`: the 14 methods are appended after entry 266 (the diff is insertions only), giving 280 entries. Recomputing the hash of the first 150 entries gives `d93da4e7a13a8c6aff8f97f363eb6d93dde884bad53c9ae50eeb0ed32514c71c`, unchanged. File SHA-256 after the change: `daa82a0a2c3e27d7912d1ef3042b56c03779c6640b003b5a2e14b7f175209014`.
- `scripts/ci/database-mysql-skips.json`: unchanged, 25 methods, SHA-256 `8d3d4cd29681f3fda8ac899c989285d3d395cf915095e56d27878f70ee0ca5c4`.
- `scripts/ci/database-mysql-selection.json`: unchanged, SHA-256 `995aa8958bc59763a3f95a79e1e24b09f695c84fa37387591ddb16491d03e9cf`. Every counterpart lives in a file that is already selected: a migration-pattern file, an `include_files` entry, or a file that already owned a SQLite-census method.
- The command `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection` exited 0 at both points:
  - With base `efa6eea5` test sources and census, temporarily checked out in this worktree and restored afterwards: "Proved 2432 selected MySQL-native tests in 233 files (of 9202 discovered tests in 677 files)". Selection case identity `a07629666232e2654f6cc25d8ffd42569637ec7abbe76d86c19d502e636c4b3a`, 70 untimed files.
  - With the new sources and census: "Proved 2448 selected MySQL-native tests in 233 files (of 9218 discovered tests in 677 files)". Selection case identity `036f98674945f70ddfa0ee6b9a8173c784bd87f6bb55bcc813aae971bc700de6`, 70 untimed files. The difference of 16 cases is exactly the new cases.
  - Discovery lists tests without executing them. The 18 generated `phpunit-ci-mysql-*` files from each run were recorded by SHA-256 and removed from the worktree. The new manifest SHA-256 is `de0a01fab9a1d8c69279dae5db24f83e2f82275e0aa0f9782755eda40fe66517`.
- Workflow comments, comments only, with no trigger, job or step changes: `.github/workflows/final-verification.yml` now reads "2448 of 9218 cases in 233 files" and "fallback weights for 70 newer files". `.gitlab-ci.yml` now reads "2448 cases in 233 files" and "70 newer files". Discovery reported 70 untimed files at base as well, so the previous 69 and 39 untimed-file comments were already stale before this batch.

## Commands

SQLite PHPUnit, run from the worktree:

```
env -u CI -u VA_CI_DISPOSABLE_MYSQL APP_ENV=testing APP_DEBUG=false DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync DB_CONNECTION=sqlite DB_DATABASE=:memory: php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --log-junit <file> <test files> [--filter <regex>]
```

Native PHPUnit used the same command with the database variables replaced by `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33584 DB_USERNAME=root DB_PASSWORD= DB_DATABASE=<schema> VA_LOCAL_DISPOSABLE_MYSQL=<schema>`. The schema was `vaseyaudio_catalog_import`, or `vaseyaudio_free_grants` for `FreeGrantSchemaRecoveryTest`, whose fixture requires that dedicated schema locally. `CI` and `VA_CI_DISPOSABLE_MYSQL` were explicitly unset. The local marker is the operator declaration described in the PR #131 packet; no CI declaration was manufactured. Each native run filtered to the new method names, for example `--filter '/::test_native_composite_dependency_primary_key_is_not_a_unique_id_target$/'`.

## Results

### Native MySQL green runs

| Run | Source | Cases | Tests / assertions | Failures / errors / skips | Duration |
| --- | --- | --- | --- | --- | --- |
| n1 | e528c174 | Free composite key | 1 / 5 | 0 / 0 / 0 | 0.7 s |
| n2 | e528c174 | Paid and PaidTax composite keys | 2 / 11 | 0 / 0 / 0 | 2.1 s |
| n3 | e528c174 | Listening catalog shadow, listening migration shadow | 2 / 8 | 0 / 0 / 0 | 455 s |
| n4 | e528c174 | Listening drift, index and column | 2 / 8 | 0 / 0 / 0 | 365 s |
| n5 | e528c174 | Listening drift trigger, checkout namespace | 2 / 9 | 0 / 0 / 0 | 398 s |
| n6 | e528c174 | Suppression REPLACE, suppression foreign_key_checks | 2 / 16 | 0 / 0 / 0 | 382 s |
| n7 | e528c174 | PaidTax pending admission | 1 / 6 | 0 / 0 / 0 | 312 s |
| n8 | e528c174 | Free256 all 37 prefixes | 1 / 39 | 0 / 0 / 0 | 209 s |
| n9 | e528c174 | Free256 parent shadow, parent floor | 2 / 5 | 0 / 1 / 0 (failed run; the parent floor case passed with 4 assertions) | 336 s |
| n10 | b573a53d | Free256 parent shadow | 1 / 4 | 0 / 0 / 0 | 289 s |
| n11 | b573a53d | Billing callback DDL | 1 / 3 | 1 / 0 / 0 (failed run) | 197 s |
| n12 | ca046f09 | Billing callback DDL | 1 / 10 | 0 / 0 / 0 | 310 s |

The final passing set is 16 of 16 cases with 120 assertions and no failures, errors or skips. Each case passed at `ca046f09` or at an earlier commit whose bytes for that method are unchanged at `ca046f09`. The two failed runs are explained under Failed, interrupted and non-acceptance runs. Durations are observed local fixture times, mostly `migrate:fresh` at roughly three minutes per case on this host. They are not performance evidence.

### SQLite whole-file runs at 06b55b1e

All ten touched files were run whole on SQLite at the final source:

| Run | Files | Tests / assertions | Failures / errors / skips | Duration |
| --- | --- | --- | --- | --- |
| s-a | CustomerListeningFreshness, CustomerListeningMigration, CustomerSuppressionMigration | 62 / 237 | 0 / 0 / 7 | 194 s |
| s-b | FreeGrant, PaidGrant and PaidTax schema recovery, ProductionCheckoutMigration | 51 / 5585 | 0 / 0 / 11 | 60 s |
| s-c | PaidTaxDownloadJourney, BillingSubscriptionApproval | 31 / 255 | 0 / 0 / 2 | 327 s |
| s-d | ProductionFreeGrantSchema | 53 / 198 | 0 / 0 / 5 | 156 s |

The total is 197 tests and 6275 assertions, with no failures or errors and 25 skips. A cross-check of the JUnit files against the census found that all 25 skipped cases belong to SQLite-census methods: the 16 new cases plus 9 cases of existing native-only methods. No skip is unlisted. All 14 SQLite-only siblings in these files executed and passed. These scopes are separate and are not a full-suite run.

### Python safeguards at 06b55b1e

Each command was run with Node 24:

| Command | Result |
| --- | --- |
| `python3 scripts/ci/test-phpunit-shards.py` | 52 tests OK, exit 0 |
| `python3 scripts/ci/test-ci-scope.py` | 27 OK, exit 0 |
| `python3 scripts/ci/test-workflow-cadence.py` | 14 OK, exit 0 |
| `python3 scripts/ci/test-focused-tests.py` | 53 OK, exit 0 (this suite holds the pinned 150-entry hash assertion) |
| `python3 scripts/ci/test-database-receipts.py` | 61 OK, exit 0 |
| `python3 scripts/ci/test-gitlab-database-receipts.py` | 32 OK, exit 0 |
| `python3 scripts/ci/test-gitlab-writer-feedback.py` | 7 OK, exit 0 |
| `python3 scripts/ci/test-php-test-runtime.py` | 5 OK, exit 0 |
| `python3 scripts/ci/test-gitlab-setup.py` | 12 OK, exit 0 |
| `python3 scripts/dev/test-bootstrap-macos.py` | 13 OK, exit 0 |
| `python3 scripts/test-publish-github.py` | 2 OK, exit 0 |
| `python3 scripts/ci/test-related-browser-stage.py` | 12 tests, 4 failures, exit 1 |

The same four browser-stage tests, by name, already failed on unchanged base `efa6eea5` before any edit, and the sorted failure lists from the pre-commit run and the `06b55b1e` run are identical. The test bootstraps the browser fixture, which needs a Vite build, and `public/build` is deliberately absent. It runs in the final-verification browser job after `npm run build`, not in the preflight backend-quality job. It is recorded as a pre-existing local environment failure, not as a pass. The same safeguards also ran on the uncommitted census edit before the reboot, with identical results.

`python3 scripts/ci/ci-scope.py classify` was run with a synthetic `pull_request` event from `efa6eea5` to this branch head. It returned mode `full`, because test and policy paths changed. That means preflight would route this candidate through backend-quality, not documentation-only. It ran at the packet commit `ca63e8e9`, after the packet itself was committed. Its step output and summary went to a scratch directory. The git-ignored `ci-scope.json` it writes into the worktree was deleted afterwards, leaving the worktree clean.

## Red/green proofs

All application mutations were applied together as one temporary, uncommitted working-tree change on top of `ca046f09`, and then reverted with `git checkout -- app/ database/ tests/`. The working tree was verified clean afterwards. The application mutation diff has SHA-256 `765e614df29b168fe35651c1386bace564cd3016af4734be556d5d05503752a5`. The one test-side fault injection, for REPLACE, has SHA-256 `1584d13a6f25e17d9c1424b3293d1816e64385e9c0d3bfa31f63c21b903dfe71`. Each counterpart failed with the protection removed and passed with it present.

| Counterpart | Mutation | Red result |
| --- | --- | --- |
| Free, Paid and PaidTax composite keys (r1, r2) | Removed each schema's PRIMARY-key dictionary check | 3 errors. Each migration reached owned DDL and failed late with MySQL error 6125 instead of the application's LogicException. |
| Listening catalog shadow (r3) | Disabled the `CREATE TEMPORARY TABLE` check in `ListeningEvidence::table()` | Failure: "A session temporary catalog shadow was treated as captured primary evidence." |
| Listening migration shadow (r3) | Disabled the MySQL shadow check in the migration's `refuseShadow()` | Failure: "Temporary shadow was accepted." |
| Listening drift, all three data sets (r4, r5a) | Made the migration's MySQL `owned()` proof return early | 3 failures: "Drift was accepted." |
| Checkout namespace (r5b) | Skipped TABLES rows whose reserved name belongs to a trigger | Failure: "Foreign table hidden behind exact trigger." |
| Suppression REPLACE (r6a) | Test-side fault injection: the table's guard triggers dropped before REPLACE | Failure: "Expected retained REPLACE refusal", so a REPLACE was accepted |
| Suppression foreign_key_checks (r6b) | Removed the `@@SESSION.foreign_key_checks` probe in `ConsentMigrationAdmission` | Failure at the first step, where every dependency is intact: "Expected nondestructive migration refusal" |
| PaidTax pending admission (r7) | Allowed delivery-table reads before admission in `PaidTaxRows::qualifiedTable()` | Failure: "Public new-table access succeeded before complete native schema admission." |
| Free256 prefixes (r8b) | Stopped resume from planning missing guards for an existing table | Error: `ProductionFreeGrantException` after 3 assertions; the mutated resume path is caught at the first partial prefix |
| Free256 parent shadow (r9a) | Disabled the parent-floor `TEMPORARY` check | Failure: "Must refuse: parent_floor" |
| Free256 parent floor (r9b) | Disabled the parent-floor column comparison | Failure: "Must refuse: parent_floor" |
| Billing callback DDL (r11) | Made `MembershipRows::assertCurrent()` a no-op | Failure: the refusal reason became `permanent_primary_required` instead of `changed_primary_or_schema` |

Limits of these proofs: the billing red shows that the counterpart detects loss of the frame proof, but a later independent check still refused, so this mutation does not show that an approval would be written. The suppression foreign_key_checks red covers the disabled-flag step only; the missing-dependency steps were not separately mutated. The REPLACE red does not establish which of the two tables accepted the REPLACE.

## Failed, interrupted and non-acceptance runs

Each of these is retained as recorded and none is counted as a pass:

- **n9, at `e528c174`:** 2 tests, 5 assertions, 1 error. `CREATE TEMPORARY TABLE users LIKE db.users` fails on MySQL with error 1066 ("Not unique table/alias"), so the parent-shadow case errored before any refusal was observed. Commit `b573a53d` switched to `CREATE ... AS SELECT ... WHERE 1 = 0` without changing the assertions, and n10 passed. The parent-floor case in the same run passed.
- **n11, at `b573a53d`:** 1 test, 3 assertions, 1 failure. The native refusal reason was `changed_primary_or_schema`, not the guessed `schema_prefix`. Investigation showed that the MySQL implicit commit ends the `MembershipRows` frame (its savepoint marker and transaction) before the schema re-check. Commit `ca046f09` pins that native reason, keeps every other assertion, and still asserts `schema_prefix` for the later schema checks. n12 passed. The r11 red shows the pinned reason is load-bearing.
- **r8, at `ca046f09` with mutations:** 1 test, 0 assertions, 1 error, "SQLSTATE[08S01] ... 1053 Server shutdown in progress". The harness stopped the background server at its time limit during this run. This is an environment interruption, not a red result. It was rerun as r8b in a bounded session.
- **Development runs at `efa6eea5` with uncommitted test edits:** g1 (the free composite key: 1 test, 5 assertions, passed) and a SQLite skip check (16 tests, 11 assertions, 16 skips). They are superseded by the committed-source runs above.
- **Timing probes at `efa6eea5`:** one existing native test, `CustomerListeningMigrationTest::test_repeated_complete_owned_install_preserves_customer_preferences_and_real_credit_history`, took 191.9 s with default durability. A second probe with `innodb_flush_log_at_trx_commit=2` and `sync_binlog=0` took 162.7 s. Both settings were restored to 1 straight away, before every recorded run. These probes are not acceptance evidence.

## Untested conditions

- No hosted CI, Focused feedback, Foundation or GitLab run. The counterparts have not run on hosted MySQL, and CI receipt verification of the new census equality is untested in a hosted run.
- The full native selection was not run. Only the 16 new native cases ran on MySQL. The other cases in their files, including the existing native-only methods, were not rerun natively here.
- Native concurrency for the new counterparts was not exercised. They are single-session tests.
- The PaidTax composite counterpart depends on MySQL 8.4's `restrict_fk_on_non_standard_key`, which is deprecated and session-settable. A server without that variable would error at that step.
- The remaining (c) methods have no native counterpart by design. Rows 19 and 24 might admit the same peer pattern (drop `customer_accounts_update` before the frame, then have a peer rewrite `owner_key`), but held-read row locks may make it infeasible; it was not tried (review P3-1). The original statement here, that no native test touches `ptx_b_u1`, was wrong; see the row 18 correction.
- The private server's last stop before the bounded sessions was a harness signal stop with no logged completion, followed by crash recovery on restart. The final stop was a normal shutdown with "Shutdown complete" logged.
- Full F2 acceptance remains open, as do raw-worker and later-hook normalization, the browser, provider and host checks, activation, and release.

## Adjacent items not changed

- `README.md` still says MySQL may skip "61 reviewed SQLite-only cases (17 methods)". The MySQL census held 25 methods already at base. README is shared with other lanes, so it is listed here rather than edited.
- No CHANGELOG entry was added, following the PR #131 and #132 F2 batches, which changed only tests, CI policy and verification documents. Add one in the final integration if the release notes should mention the counterparts.
- An independent reviewer still needs to check this batch before merge, as AGENTS.md requires for migration and fixture changes.

## Independent review

A separate reviewer returned APPROVE WITH CONDITIONS at `06a231448a1f7a47bc250cc8e2d1ad3c5b91245c` with no P0 or P1 ([decision record](independent-review-06a23144-DECISION.md), imported verbatim). Its one condition, P2-1, is applied above: row 18 is split into `binding` (a, with caveat) and `reviewed` (b, remaining), and row 23 is reclassified as (b, remaining). Rows 18 `reviewed` and 23 are remaining F2 work, together with full F2 acceptance. P3-1 to P3-6 are deferred to the next F2 batch and recorded in `docs/handoff/2026-10-07/codex-deferred-20261011.md`:
- P3-1: try the peer pattern for rows 19 and 24;
- P3-2: pin SQLSTATE 45000 and the guard message in the REPLACE counterpart;
- P3-3: pin the `up()` messages in the listening counterparts;
- P3-4: optional double mutation for the billing red;
- P3-5: probe `restrict_fk_on_non_standard_key` with a named failure;
- P3-6: stale skip-message and comment text.

## Selection after merging main (lead, 2026-10-18)

After merging main at `65b81f5b` (PRs #139, #140 and #141), the generator proves 2,448 selected MySQL-native tests in 233 files of **9,247** discovered tests in 678 files; 70 files still use fallback timings. The selected count is unchanged. Discovery grew by 29 cases from the newly merged account-feature and synthetic-catalog tests, none of which are native-selected. The GitHub workflow comment now reads 2448 of 9247. The GitLab comment states only the selected count, which is unchanged.
