# MySQL native selection for Foundation CI — 2026-10-09

Branch `harness/mysql-native-selection` is based on main `0f9b39ce5d59e2d579d11a8ca15153ced1f565b4`.
The earlier commits are `78f8a7b`, `23ff88e` and `6d17481`. `6d17481` applied conditions C1 to C4 from
the independent review of `23ff88e`, whose decision was APPROVE WITH CONDITIONS. `916392d` added the
reviewed MySQL skip census. `22371ae` implemented option A for the tests that need a dedicated
schema: the MySQL jobs mark their disposable database, and four more files join the selection. The
branch then merged main at `2f8deb8`, which brought in #70 (merged at `13409a29`) and the new
`tests/Feature/MigrationRecompilationTest.php`; this revision updates the counts to that tree.

This note records partition and self-test evidence only. No local or hosted MySQL test run has
executed the selection, so it is not Foundation acceptance.

## Why

Foundation run 37921309772 could not finish the MySQL matrix. 307 test files use
`tests/Support/FinalizationDatabaseMigrations`, which runs a full `migrate:fresh` before every test
and `db:wipe` after it. On MySQL 8.4 in CI that costs about 35 s per test, so eight 90-minute shards
reached only about 25% of the 7,633 cases. Sean chose to run on MySQL only what SQLite cannot prove,
and to keep the complete suite on SQLite.

## What runs where

| Engine | Scope | Cases |
| --- | --- | --- |
| SQLite (2 shards) | Complete suite; the 623 reviewed census cases skip | 7,642 listed, 7,019 executed |
| MySQL (8 GitHub / 4 GitLab shards) | Native selection; only the 58 reviewed MySQL census cases skip | 1,765 listed, 1,707 executed, in 163 files |

The suite grew from 7,633 to 7,642 cases (555 to 557 files). `tests/Unit/DisposableNativeDatabaseTest.php`
(8 cases, option A) runs only on SQLite. `tests/Feature/MigrationRecompilationTest.php` (1 case, from
#70) matches the migration pattern, so it is selected and also runs on MySQL.

The selection is defined in `scripts/ci/database-mysql-selection.json`. It takes whole files, so every
case in a selected file runs, including methods the census does not list. A file is selected if any
of the following holds:

| Rule | Files | Cases |
| --- | --- | --- |
| Owns a (class, method) pair in `scripts/ci/database-sqlite-skips.json` (217 pairs in 101 classes) | 101 | 932, of which 623 are census cases |
| Path matches `^tests/(?:Feature\|Unit)/(?:[A-Za-z0-9]+/)*[A-Za-z0-9]*Migration[A-Za-z0-9]*Test\.php$` | 50 | 590 |
| Listed in the reviewed `include_files` (added for C1, extended for option A) | 16 | 330 |
| All selected files (4 files match both of the first two rules) | 163 | 1,765 |

Entries in `include_files` must be sorted, unique repository test paths, and PHPUnit must discover
every one of them; the sharder and both verifiers refuse anything else. The list names tests whose
native-only proof passes on SQLite only through a fallback branch:

| File | Cases | Why it is included |
| --- | --- | --- |
| `tests/Feature/ProductionMembershipBilling/BillingNativeDedupRaceTest.php` | 1 | Two-process billing ledger dedup race; SQLite runs the two steps one after the other |
| `tests/Feature/BulkReplaceLicenseDraftSourceTest.php` | 85 | Its `for update` row-lock assertion runs only on MySQL |
| `tests/Feature/ReviewedOfferDraftTest.php` | 64 | Its `for update` row-lock assertion runs only on MySQL |
| `tests/Feature/ReviewedLicenseDraftTest.php` | 30 | Its `for update` row-lock assertion runs only on MySQL |
| `tests/Feature/LicenseTemplateAuthoringTest.php` | 39 | Its `for update` row-lock assertion runs only on MySQL |
| `tests/Feature/ProductionCheckoutExemptionAuthorityTest.php` | 11 | The strict-numeric-storage refusal is reached only on MySQL |
| `tests/Feature/PrivateProductDraftSchemaTest.php` | 4 | Schema test |
| `tests/Feature/ProductionMemberOriginals/MemberActivationCouplingSchemaTest.php` | 10 | Schema test |
| `tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php` | 13 | Schema test |
| `tests/Feature/ServiceProjectSchemaTest.php` | 3 | Schema test |
| `tests/Feature/SupportAttachmentSchemaTest.php` | 4 | Schema test |
| `tests/Unit/SchemaQualifierDelimiterTest.php` | 5 | Schema test |
| `tests/Feature/FreeGrantSchemaRecoveryTest.php` | 8 | MySQL migrator fault and trigger recovery; needs the disposable-database guard (option A) |
| `tests/Feature/SupportAttachmentsTest.php` | 28 | Native attachment consumer; runs on MySQL only with `ATTACHMENT_NATIVE_ISOLATED=1` |
| `tests/Feature/ServiceSupportAttachmentsTest.php` | 8 | Native service attachment consumer; same flag |
| `tests/Feature/CustomerListeningFreshnessTest.php` | 17 | Native shadow-refusal branch; one SQLite-only method is in the MySQL census |

A static scan found no `markTestSkipped` call in the first 12 files or anywhere in `tests/Support`. The
four files added for option A skip only as described under "Option A" below.

## Residual risk pending Sean's decision

The selection still finds MySQL-only behaviour only where SQLite skips it, where the test file is a
migration test, or where the include list names the file. The review found 61 unselected files
(936 cases) that branch on `getDriverName()`, `ATTR_DRIVER_NAME` or `['driver']`. Fifteen of them are
now in the include list. `SchemaQualifierDelimiterTest` is the sixteenth included file and was not
among the 61.

**That leaves 46 files (611 cases).** They run on SQLite through a fallback branch and do not run on
MySQL in Foundation CI. None of them were added:

| File | Cases |
| --- | --- |
| `tests/Feature/CustomerConsentAdmissionTest.php` | 22 |
| `tests/Feature/CustomerListeningNotesCapacityTest.php` | 3 |
| `tests/Feature/CustomerSuppressionRetainedTargetTest.php` | 2 |
| `tests/Feature/CustomerSuppressionSourceBoundaryTest.php` | 7 |
| `tests/Feature/DiscoveryEpochRecoveryTest.php` | 18 |
| `tests/Feature/FinalizationDatabaseLifecycleTest.php` | 4 |
| `tests/Feature/FreeGrantAuthorityTest.php` | 6 |
| `tests/Feature/FreeGrantDownloadsTest.php` | 4 |
| `tests/Feature/PrivateProductDraftTest.php` | 24 |
| `tests/Feature/ProductionAmountInputConsistencyAccessTest.php` | 15 |
| `tests/Feature/ProductionAmountRequirementsAccessTest.php` | 12 |
| `tests/Feature/ProductionCheckoutCommittedReceiptTest.php` | 12 |
| `tests/Feature/ProductionCheckoutPrimaryBoundaryTest.php` | 7 |
| `tests/Feature/ProductionCheckoutReceiptParentTest.php` | 4 |
| `tests/Feature/ProductionCheckoutSourceTransactionTest.php` | 11 |
| `tests/Feature/ProductionFeatures/ProductionFeatureHeldFloorTest.php` | 3 |
| `tests/Feature/ProductionFeatures/ProductionFeatureSealedRunTest.php` | 3 |
| `tests/Feature/ProductionFeatures/ProductionFeatureTransactionEventsTest.php` | 8 |
| `tests/Feature/ProductionFeatures/ProductionFeatureTransactionOwnershipTest.php` | 2 |
| `tests/Feature/ProductionFreeGrants/ProductionFreeGrantLibraryWindowTest.php` | 2 |
| `tests/Feature/ProductionIdentity/ProductionIdentityDependencyAdmissionTest.php` | 9 |
| `tests/Feature/ProductionIdentity/ProductionIdentityJourneyTest.php` | 8 |
| `tests/Feature/ProductionIdentity/ProductionIdentityKeyRotationTest.php` | 10 |
| `tests/Feature/ProductionIdentity/ProductionIdentityNoticeTest.php` | 8 |
| `tests/Feature/ProductionIdentity/ProductionIdentityRuntimeTest.php` | 11 |
| `tests/Feature/ProductionIdentity/ReviewIdentityKeyRotationAdversarialTest.php` | 8 |
| `tests/Feature/ProductionIdentityAdapters/IdentityHistoricalConfigurationAdmissionTest.php` | 4 |
| `tests/Feature/ProductionIdentityAdapters/ProductionAccountFeatureAccessTest.php` | 9 |
| `tests/Feature/ProductionMembership/MembershipRowsFunctionClosureTest.php` | 17 |
| `tests/Feature/ProductionSuppression/ProductionSuppressionSealTest.php` | 2 |
| `tests/Feature/ProductionSuppression/ProductionSuppressionTimestampGuardTest.php` | 1 |
| `tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutGuardTest.php` | 7 |
| `tests/Feature/ProductionTaxCheckout/ProductionTaxSourceV2Test.php` | 5 |
| `tests/Feature/ProductionTrackCapabilitiesGuardsTest.php` | 28 |
| `tests/Feature/ProductionTrackPolicyDraftTest.php` | 76 |
| `tests/Feature/ProductionTrackPreparationPacketGuardsTest.php` | 26 |
| `tests/Feature/ResumableMediaUploadsTest.php` | 25 |
| `tests/Feature/RightsDeclarationWriterTest.php` | 33 |
| `tests/Feature/RightsEvidenceGuardTest.php` | 43 |
| `tests/Feature/ServiceProjectAttachmentAuthorityTest.php` | 17 |
| `tests/Feature/ServiceProjectCredentialResolverTest.php` | 1 |
| `tests/Feature/ServiceProjectRecoveryTest.php` | 7 |
| `tests/Feature/SiteContentDamagedPublicationTest.php` | 4 |
| `tests/Feature/SupportAttachmentRegistrationTest.php` | 12 |
| `tests/Feature/TestPaymentExceptionOperationsTest.php` | 25 |
| `tests/Feature/TestUnpaidReleaseTest.php` | 46 |

In total, 394 of the 557 files (5,877 of the 7,642 cases) no longer run on MySQL in Foundation CI.
They still run on SQLite.

## MySQL skip signals (C2)

### Attachment consumers and other MySQL skips (C2): covered by Foundation once a hosted run passes

- `tests/Feature/SupportAttachmentsTest.php` (28 cases) and `tests/Feature/ServiceSupportAttachmentsTest.php`
  (8 cases) are now selected. Their `setUp` skips on MySQL only when `ATTACHMENT_NATIVE_ISOLATED` is not
  `1`. The MySQL jobs (and only they) now set it, so on CI these cases run their native paths
  instead of skipping. The flag is environment-gated, so it is deliberately not in the census: any skip
  of these cases on CI MySQL is refused as an unlisted skip.
- The two SQLite-only methods,
  `CustomerListeningFreshnessTest::test_framework_reads_cannot_use_a_temporary_catalog_shadow_while_proof_reads_main`
  and `FreeGrantSchemaRecoveryTest::test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target`,
  are now in the MySQL skip census, and their files are selected.

**The native attachment-consumer release blocker is closed for Foundation once a hosted MySQL run
passes.** Until then it is unverified on MySQL 8.4; see the local MySQL 8.0 evidence below.

### MySQL skip census (`scripts/ci/database-mysql-skips.json`)

The census mirrors `scripts/ci/database-sqlite-skips.json`. It holds sorted, unique
(class, method) pairs with purpose `reviewed-sqlite-only-mysql-skip-methods`. It lists exactly the
selected methods whose skip on MySQL is an unconditional driver check. Environment-gated skips such
as `ATTACHMENT_NATIVE_ISOLATED` are not listed. It has 14 methods covering 58 cases.

| Class::method | Cases | Skip site (file:line) and guard |
| --- | --- | --- |
| `CustomerListeningFreshnessTest::test_framework_reads_cannot_use_a_temporary_catalog_shadow_while_proof_reads_main` | 1 | `tests/Feature/CustomerListeningFreshnessTest.php:135`, `DB::getDriverName() !== 'sqlite'` |
| `CustomerListeningMigrationTest::test_foreign_or_drifted_objects_are_never_adopted_or_dropped` | 3 | `tests/Feature/CustomerListeningMigrationTest.php:85`, `DB::getDriverName() !== 'sqlite'` |
| `CustomerListeningMigrationTest::test_temporary_shadow_is_never_adopted_or_dropped` | 1 | `tests/Feature/CustomerListeningMigrationTest.php:105`, `DB::getDriverName() !== 'sqlite'` |
| `CustomerSuppressionMigrationTest::test_missing_identity_dependency_and_disabled_fk_enforcement_are_refused_without_installing` | 1 | `tests/Feature/CustomerSuppressionMigrationTest.php:103`, `DB::connection()->getDriverName() !== 'sqlite'` |
| `CustomerSuppressionMigrationTest::test_sqlite_replace_cannot_delete_retained_target_or_intent` | 1 | `tests/Feature/CustomerSuppressionMigrationTest.php:200`, `DB::connection()->getDriverName() !== 'sqlite'` |
| `DiscoverySitemapNamespaceAdmissionTest::test_sqlite_named_inline_check_and_fk_clauses_do_not_reserve_foreign_index_names` | 1 | `tests/Feature/DiscoverySitemapNamespaceAdmissionTest.php:157`, `DB::getDriverName() !== 'sqlite'` |
| `FreeGrantSchemaRecoveryTest::test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target` | 1 | `tests/Feature/FreeGrantSchemaRecoveryTest.php:234`, `DB::getDriverName() !== 'sqlite'` |
| `PaidGrantSchemaRecoveryTest::test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target` | 1 | `tests/Feature/PaidGrantSchemaRecoveryTest.php:235`, `DB::getDriverName() !== 'sqlite'` |
| `ProductionCheckoutMigrationTest::test_sqlite_duplicate_table_and_trigger_namespace_cannot_hide_a_foreign_marker` | 1 | `tests/Feature/ProductionCheckoutMigrationTest.php:202`, `DB::getDriverName() !== 'sqlite'` |
| `ProductionFreeGrants\ProductionFreeGrantSchemaTest::test_every_contiguous_empty_installation_prefix_resumes_to_the_exact_schema` | 37 | Calls `sqliteOnlyRecovery()` first (`tests/Feature/ProductionFreeGrants/ProductionFreeGrantSchemaTest.php:43`); the helper skips at line 297 on `DB::getDriverName() !== 'sqlite'` |
| `ProductionFreeGrants\ProductionFreeGrantSchemaTest::test_a_temporary_shadow_of_a_parent_or_owned_table_is_refused` | 1 | Calls `sqliteOnlyRecovery()` first (line 113); skip at line 297 |
| `ProductionFreeGrants\ProductionFreeGrantSchemaTest::test_parent_floor_drift_is_refused_before_the_first_owned_ddl` | 1 | Calls `sqliteOnlyRecovery()` first (line 124); skip at line 297 |
| `ProductionTaxCheckout\ProductionTaxCheckoutMigrationTest::test_installer_refuses_drift_gaps_populated_partials_shadows_and_foreign_references` | 7 | `tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutMigrationTest.php:112`, `DB::getDriverName() !== 'sqlite'` |
| `ProductionTaxCheckout\ProductionTaxCheckoutMigrationTest::test_migration_installs_exactly_four_tables_and_twelve_guards_and_a_rerun_is_a_no_op` | 1 | `tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutMigrationTest.php:33`, `DB::getDriverName() !== 'sqlite'` |

All classes are under `Tests\Feature`. No census method is also in the SQLite census, so every
census case still executes on SQLite. These skips were not introduced by the selection: the same
files are in main's complete MySQL partition, where the zero-skip rule would have rejected them.

The census was found by a textual scan of the test-method bodies and the helpers they call in the
selected files. Only a MySQL run proves it exact, which means proving that each listed case really
skips and that no other case skips on MySQL. Every mismatch is refused, so an inexact census turns a
run red; it cannot hide a case.

### Blockers found earlier

1. **Skipped cases that report assertions: resolved by #70, merged at `13409a29`.** Before #70 the
   verifier's `junit` check required a skipped case to report 0 assertions. PHPUnit 12.5 counts `setUp` assertions on a skipped test (`TestRunner.php:130`). The coordinator's
   local SQLite shard runs found 8 SQLite-census skips carrying 1–2 `setUp` assertions, and the census skips
   in `ProductionFreeGrantSchemaTest` (39), `FreeGrantSchemaRecoveryTest` (1), `PaidGrantSchemaRecoveryTest`
   (1) and `CustomerSuppressionMigrationTest` (2) carry `setUp` assertions on MySQL: 43 of the 58 census
   cases. The local MySQL 8.0 run below confirms it for `FreeGrantSchemaRecoveryTest`. #70 changed
   `junit` so a skipped case may carry assertions, while duplicate skip nodes are refused and exact
   census equality still bounds which cases skip (`scripts/ci/database-receipts.py:473-474`). This
   branch has it since merging main at `2f8deb8`; this branch did not change the rule itself.
2. **Dedicated-schema guard: resolved by `tests/Support/DisposableNativeDatabase.php`.** See "Option A"
   below.

## Option A: the CI job's disposable database

**What changed.**

- `tests/Support/DisposableNativeDatabase.php` adds `isAdmitted(string $dedicated)`. It admits the
  dedicated schema, as before. Otherwise it admits the connection's database only when all of these
  hold:
  - `VA_CI_DISPOSABLE_MYSQL=1`;
  - `CI=true` (set by both GitHub Actions and GitLab CI);
  - the `testing` environment;
  - `DB::getDatabaseName() === getenv('DB_DATABASE')`;
  - the name is a non-empty plain identifier of at most 64 characters, is not `vaseyaudio`, and
    contains neither `prod` nor `live`.

  Locally the dedicated schema stays required, because these tests drop every table in the connection's
  database.
- The guards formerly at `tests/Feature/FreeGrantSchemaRecoveryTest.php:26` and
  `tests/Feature/FreeGrantConcurrencyTest.php:23` (now lines 27 and 24) call the helper. Each is still one assertion, so the
  assertion counts do not change.
- `tests/Unit/DisposableNativeDatabaseTest.php` (8 cases) covers both admitted paths. It also covers
  each refusal: a missing or different marker, a missing or different `CI`, environments `local`,
  `staging` and `production`, a database-name mismatch or unset `DB_DATABASE`, an empty name, and
  production-looking names. Environment variables are set with `putenv` and restored in `tearDown`.
- The GitHub `backend-mysql` env and the GitLab `backend-mysql` variables set
  `ATTACHMENT_NATIVE_ISOLATED: '1'` and `VA_CI_DISPOSABLE_MYSQL: '1'`, each with a comment. No other
  job sets them. `test-database-receipts.py` and `test-gitlab-database-receipts.py` pin both variables
  to the MySQL jobs only.

**Static reading of the newly enabled files.**

- **Attachments: the flag is the only skip.** Each file has exactly one `markTestSkipped`, inside
  `if (DB::getDriverName() === 'mysql')` → `if (getenv('ATTACHMENT_NATIVE_ISOLATED') !== '1')`
  (`tests/Feature/SupportAttachmentsTest.php:41-43`, `tests/Feature/ServiceSupportAttachmentsTest.php:39-41`).
- **Attachments: they touch only the current database.** With the flag set they run
  `migrate:fresh --force` on the default connection (`SupportAttachmentsTest.php:45`,
  `ServiceSupportAttachmentsTest.php:43`), which drops and rebuilds the tables of that connection's
  database only. Neither file, nor their fixtures `tests/Support/InquiryConversationFixtures.php`,
  `ServiceProjectFixtures.php`, `CustomerFixtures.php` and `TestOnlyMediaScanner.php`, contains
  `information_schema`, `performance_schema`, `CREATE DATABASE`, `DROP DATABASE`, `SET GLOBAL` or a
  named second connection. Media goes to faked private storage (`fakePrivateMediaStorage()`).
- **`FreeGrantSchemaRecoveryTest`: scoped to the connection's database.**
  - `Schema::dropAllTables()` (lines 31 and 41) uses Laravel's `MySqlBuilder::dropAllTables`, which
    lists only `getCurrentSchemaListing()`, the connection's own database
    (`vendor/laravel/framework/src/Illuminate/Database/Schema/MySqlBuilder.php:12-29`).
  - Trigger inspection reads `information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()` (line 78).
  - Every other statement is `DB::unprepared` DDL on unqualified tables (lines 204–252).
- **`FreeGrantConcurrencyTest`: one read-only server-wide query.** Its only access outside the
  database is a read-only `SELECT` on `performance_schema.data_lock_waits`/`data_locks`/`threads`,
  filtered by `OBJECT_SCHEMA` = the test database and the two worker connection IDs (line 58). The
  workers inherit the job's `DB_*` environment (line 36).

**Local evidence.**

- **SQLite.** `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`,
  using direct PHPUnit with this worktree's `vendor/autoload.php`; `public/build` was absent. The run
  covered `DisposableNativeDatabaseTest`, `FreeGrantSchemaRecoveryTest`, `SupportAttachmentsTest`,
  `ServiceSupportAttachmentsTest` and `CustomerListeningFreshnessTest`. Result: rc 0, **69 tests, 2,247
  assertions, 0 failures, 0 errors, 0 skips**, broken down as 8/25, 8/1,941, 28/159, 8/56 and 17/66
  (tests/assertions per file). `FreeGrantConcurrencyTest` on SQLite: rc 0, 1 test, skipped before the
  guard (as before).

- **MySQL 8.0 (not CI's 8.4).** This used a new, private Oracle MySQL `8.0.46-0ubuntu0.24.04.4`. Its
  datadir was initialized in this session's scratch directory, and it listened on 127.0.0.1:33091 with
  a socket under `/run/mysqld-opta`. The existing `/var/lib/mysql-fnd` daemon was not running and was
  not touched. The server reported `STRICT_TRANS_TABLES`, `performance_schema=1` and `REPEATABLE-READ`.

  Each run used a fresh disposable database and the CI environment: `APP_ENV=testing`,
  `DB_CONNECTION=mysql`, `CI=true`, `VA_CI_DISPOSABLE_MYSQL=1`, `ATTACHMENT_NATIVE_ISOLATED=1`,
  `DB_DATABASE=<that database>`, user `root` as in CI, and the same direct PHPUnit command. The JUnit
  files are in `local-evidence/`. The three groups ran in parallel on three databases, so their wall
  times include contention.

  | Run | Result |
  | --- | --- |
  | `FreeGrantSchemaRecoveryTest` + `FreeGrantConcurrencyTest` (`mysql80-free-grants.xml`) | rc 0: 9 tests, 890 assertions, 1 skip (the census method `test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target`, with `assertions="2"` from `setUp`); 39 s + 91 s |
  | `SupportAttachmentsTest` (`mysql80-support-attachments.xml`) | rc 0: 28 tests, 159 assertions, 0 skips, 0 failures; 2,970 s (about 106 s per case) |
  | `ServiceSupportAttachmentsTest` + `CustomerListeningFreshnessTest` (`mysql80-service-and-listening.xml`) | rc 0: 25 tests, 118 assertions, 1 skip (the census method `test_framework_reads_cannot_use_a_temporary_catalog_shadow_while_proof_reads_main`, with `assertions="0"`); 765 s + 2,015 s |
  | Guard check: `FreeGrantSchemaRecoveryTest` with `VA_CI_DISPOSABLE_MYSQL` unset (`mysql80-free-grants-guard-without-marker.xml`) | rc 1: all 8 fail in `setUp` with "A dedicated synthetic free-grant schema, or the CI job's disposable database, is required." |

  | `MigrationRecompilationTest` from #70 (`mysql80-migration-recompilation.xml`), run after the merge on a fresh database, no parallel load | rc 0: 1 test, 5 assertions, 0 skips; 252 s |

  The only skips were the two new census methods. Each skipped exactly as the census expects. The
  free-grant skip carries 2 `setUp` assertions, which #70's verifier change accepts.

  Do not use these wall times to size CI. An earlier combined run measured about 105 s per attachment
  case with no parallel load (4 cases in 7 minutes), and it was stopped for time. All of these runs are
  much slower than the authors' recorded 12–15 s per case. A hosted MySQL 8.4 run remains the only
  measurement for sizing.

## How the selection is enforced

**Sharder.** `scripts/ci/phpunit-shards.py --mysql-native-selection` is accepted only with
`--prefix=phpunit-ci-mysql`.

- Discovery, the cross-file dependency refusal and the manifest's `source_*` census stay complete.
- Only the selected files are partitioned and weighted. Each shard configuration excludes every
  file not assigned to it.
- Re-discovery must cover every selected case, file and group exactly once.
- The manifest's `selection` block records the policy path, both policy hashes, the selected file
  and case counts, and the case identity hash.
- The sharder refuses an empty selection, a census pair that PHPUnit no longer discovers, and any
  `include_files` entry that is undiscovered or malformed.
- Without the flag, the output is byte-for-byte what it was before this change.

**Shard verifier.** `scripts/ci/database-receipts.py` keeps the selection policy in `POLICY_FILES`.

- For MySQL, `database_evidence` recomputes the selection (census, pattern and include list) from the
  complete inventory and the committed policies. The manifest's block must equal the recomputed one,
  and it now also carries `mysql_skip_policy_sha256`.
- The shards must cover the selection exactly once, and any untimed files must be selected files.
- The MySQL skip census must be present, discovered and inside the selection, and must not overlap
  the SQLite census.
- Each MySQL shard's skipped identities must equal exactly the census identities in that shard. A
  missing skip is refused, and so is an unlisted one.
- SQLite verification is unchanged.

**Collectors.** Both the GitHub and GitLab collectors require all of the following:

- SQLite executed every source case exactly once.
- MySQL executed exactly the recomputed selection, each case once.
- No method is in both censuses.
- MySQL's skipped identities equal exactly the census identities in the selection.
- The summed MySQL `executed_cases` equals the selection size minus those skips.
- Every MySQL skip appears in the set of cases SQLite executed.
- Every SQLite skip appears in the set of cases MySQL executed (not merely listed), so no case goes
  unexecuted on both engines.

**Workflows.** The GitHub partition step is named `Prove the native-selection MySQL test partition`.
In `.gitlab-ci.yml`, only the MySQL jobs pass the flag.

## Real discovery (no database)

All commands ran in `/home/user/wt-mysel` with PHP 8.4.26, doing PHPUnit discovery only.

```
python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql \
  --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection        # rc 0
warning: 78 test file(s) have no entry in scripts/ci/phpunit-timings-mysql.json and use the fallback weight; ...
Proved 1765 selected MySQL-native tests in 163 files (of 7642 discovered tests in 557 files) across 8 nonempty shards.
Shard 1: 257 tests; 24 complete files; estimated 19m 03s.
Shard 2: 208 tests; 19 complete files; estimated 19m 05s.
Shard 3: 209 tests; 20 complete files; estimated 19m 06s.
Shard 4: 208 tests; 19 complete files; estimated 19m 04s.
Shard 5: 243 tests; 20 complete files; estimated 19m 05s.
Shard 6: 211 tests; 21 complete files; estimated 19m 06s.
Shard 7: 218 tests; 20 complete files; estimated 19m 05s.
Shard 8: 211 tests; 20 complete files; estimated 19m 05s.
```

With `--shards=4` (the GitLab count) the run returned rc 0 and proved 1,765 cases across shards of
477, 425, 429 and 434 cases, each estimated at about 38 minutes from the timings file.

Both shard counts produced the same manifest `selection` block:

| Field | Value |
| --- | --- |
| `policy_sha256` | `959c7709e58661ef74a09af1bbbc0576ed71cc483e29c57872ad489b93ec6801` |
| `sqlite_skip_policy_sha256` | `9080acdabcfc425ac37dcebee23c9f9be00bcd5a6790404977ba41c6994569b0` |
| `mysql_skip_policy_sha256` | `3f8effc254318548c3e7791b0d2b468b731469dc5f581dd43c06ed212c2a6208` |
| `files` / `test_cases` | 163 / 1,765 |
| `case_identity_sha256` | `770ae7631854d0ea28cc7b0d71fb36291512242228257c7b1a4889d246069ab9` |

**Cross-checks against the verifier.**

- At `6d17481`, `database_evidence` accepted the real 8-shard manifest and inventories, using a
  synthesized passing JUnit file for shard 1. Its own recomputation matched the manifest.
- It refused the real unflagged 8-shard MySQL partition.
- It refused the earlier `78f8a7b` selection, which has no include list, with
  `Partition loses or duplicates cases, files or groups`.
- With the census in place, `database_evidence` accepted every shard of the real 8-shard and 4-shard
  evidence. Each shard used synthesized JUnit in which exactly the census cases skip with 0
  assertions: 58 MySQL skips in total at this revision. A shard whose census cases ran instead of skipping was refused
  with `MySQL skip identities differ from the reviewed SQLite-only policy`.

**Unflagged output is unchanged.** Run without the flag, the new script and main's script
(`git show 0f9b39c:scripts/ci/phpunit-shards.py`), each run on the `22371ae` tree (7,641 tests in 556
files), both returned rc 0, and `diff -r` found all 18 MySQL 8-shard files and all 6 SQLite 2-shard
files byte-identical. `--mysql-native-selection` with
`--prefix=phpunit-ci-sqlite` was refused with rc 1. Every generated `phpunit-ci-*` file was deleted
afterwards.

### Duration estimates (C3)

The estimates printed from the timings file are not reliable. `phpunit-timings-mysql.json` predates
run 37921309772 and has no entry for 78 of the 163 selected files; the timings were not regenerated.
Of the selected cases, 1,372 use `FinalizationDatabaseMigrations`. At the roughly 35 s per case
measured on GitHub:

| Matrix | Finalization cases per shard | Estimated minutes at 35 s (finalization cases only) | Job limit |
| --- | --- | --- | --- |
| GitHub, 8 shards | 241, 166, 159, 171, 177, 174, 160, 124 | about 141, 97, 93, 100, 103, 102, 93, 72 | 210 min |
| GitLab, 4 shards | 361, 333, 338, 340 | about 211, 194, 197, 198 | 240 min (was 90) |

The remaining selected cases per shard are 16, 42, 50, 37, 66, 37, 58 and 87 on GitHub, and 116, 92,
91 and 94 on GitLab. Their time is not included in the estimates. That includes the 36 attachment
cases, which run `migrate:fresh` per test without using `FinalizationDatabaseMigrations`; they sit in
GitHub shards 2 and 4.

- **GitLab.** The new 240-minute limit covers the estimate with some headroom. However, GitLab.com
  documents a 3-hour maximum for its hosted runners, and that cap would cancel these shards before
  they finish. I have not verified this cap for the project's runners, and GitLab speed is
  unmeasured. On hosted runners, a reviewed change to 8 GitLab MySQL shards would likely be needed.
- **`MigrationRecompilationTest`.** Selected through the migration pattern since the merge of main. It
  is not counted above, because it uses neither `FinalizationDatabaseMigrations` nor `RefreshDatabase`;
  it runs `migrate:fresh` four times itself. That is about 4 × 35 s in CI. It sits in GitHub shard 1
  and GitLab shard 2. It compares counts of declared classes, which is independent of the driver, so it
  should hold on MySQL. It passed on local MySQL 8.0 in 252 s, about 63 s per `migrate:fresh` on that
  machine.
- **GitHub.** The busiest shard (shard 1, 241 such cases) is estimated at about 141 minutes. The
  stale timing weights put it there. That left about 6% headroom under 150 minutes, so the limit is
  now 210 minutes (about 50% headroom); fresh timings from the first hosted run should replace both
  the estimate and the limit.

## Self-tests

| Command | Result |
| --- | --- |
| `python3 scripts/ci/test-phpunit-shards.py` | rc 0, 48 tests |
| `python3 scripts/ci/test-database-receipts.py` | rc 0, 55 tests |
| `python3 scripts/ci/test-gitlab-database-receipts.py` | rc 0, 30 tests |
| `python3 scripts/ci/test-ci-scope.py` | rc 0, 27 tests |
| `python3 scripts/ci/test-workflow-cadence.py` | rc 0, 14 tests |
| `python3 scripts/ci/test-focused-tests.py` | rc 0, 50 tests |
| `python3 scripts/ci/test-gitlab-setup.py` | rc 0, 12 tests |
| `python3 scripts/ci/test-gitlab-writer-feedback.py` | rc 0, 7 tests |
| `python3 scripts/ci/test-php-test-runtime.py` | rc 0, 5 tests |
| `python3 scripts/ci/test-related-browser-stage.py` | rc 1: 4 of 12 fail, the same 4 that fail on unchanged `0f9b39c` in this checkout (environmental) |
| `node --test scripts/ci/apply-playwright-webkit-offline-backport.test.mjs` (Node 24.21.0) | 33 pass, 0 fail |

The new tests for C1 and C4 check these rejections:

- An include file missing from discovery is refused by the sharder, by `native_selection` and by
  `database_evidence`.
- An include file that is assigned but not run, or left out of the partition, is refused by the
  sharder's proof.
- MySQL archives built without the include file are refused by the verifier.
- A malformed, unsorted, duplicated or missing `include_files` is refused by both policy readers.
- The committed include list must be sorted, must name existing files, and must not overlap the
  pattern.
- Collector-only tests: when every per-shard proof is forged to a selection without the include file,
  the GitHub and GitLab collectors' own recomputation refuses it. Receipts that consistently
  under-report one executed MySQL case are refused by the GitHub sum check.

Each mutation was applied in place, the tests run, and the file restored; `cmp` confirmed the
restored files were byte-identical.

| Mutation | Result |
| --- | --- |
| M5: GitHub collector trusts the executed MySQL set | killed by the new collector test |
| M11: GitLab collector trusts the executed MySQL set | killed by the new collector test |
| M12: GitHub collector drops the `executed_cases` sum | killed by the new collector test |
| Verifier ignores `include_files` | killed |
| Verifier drops the include discovery check | killed |
| Sharder ignores `include_files` | killed |
| Sharder drops the include discovery check | killed |

The MySQL skip census adds tests for these cases:

- A forged MySQL shard with an unlisted skip is refused.
- A listed method that executed on MySQL instead of skipping is refused.
- A census that is missing, undiscovered, outside the selection or overlapping the SQLite census is
  refused by the sharder and by the verifier.
- An unsorted, duplicated or malformed census is refused by both readers.
- At collector level, with per-shard proofs forged so that only the collector's checks apply, the
  GitHub and GitLab collectors each refuse three cases: an overlap between the censuses, MySQL skips
  that differ from the census, and a MySQL census identity that SQLite also skipped.

Each census mutation was applied in place, the tests run, and the file restored; `sha256sum -c`
confirmed all three files were byte-identical afterwards. All 20 were killed:

| Mutation | Result |
| --- | --- |
| S1–S4: sharder drops the undiscovered, overlap, outside-selection or sorted check | killed |
| S5: sharder omits the census hash from the manifest | killed |
| V1: `junit` accepts any MySQL skips | killed |
| V2–V5: verifier accepts a missing census, or drops the undiscovered, overlap or outside-selection check | killed |
| V6: selection block without the census hash | killed |
| V7: reader drops the sorted check | killed |
| G1–G4: GitHub collector drops the overlap check, the skip equality or the MySQL-skip-executed-on-SQLite check, or uses an unadjusted executed sum | killed |
| L1–L4: the same four in the GitLab collector | killed |
| Re-run of M1, M4, M5, M11 and M12 | killed |

The option A workflow pins were checked the same way, restored with `cmp`. All 4 were killed:

| Mutation | Result |
| --- | --- |
| W1: GitHub SQLite job also sets `VA_CI_DISPOSABLE_MYSQL` | killed |
| W2: GitHub MySQL job loses `ATTACHMENT_NATIVE_ISOLATED` | killed |
| W3: GitLab SQLite job also sets `ATTACHMENT_NATIVE_ISOLATED` | killed |
| W4: GitLab MySQL job loses `VA_CI_DISPOSABLE_MYSQL` | killed |

## Not verified

- No hosted run has executed the selection, so pass/fail and real durations are unknown. Only a
  MySQL run of the whole selection proves the census exact.
- The local MySQL evidence used Oracle MySQL 8.0.46, not CI's 8.4. It ran only the option A files and
  `MigrationRecompilationTest`.
- The collectors ran only against synthetic fixtures.
- The GitLab hosted-runner timeout cap was not checked against this project's runner settings.
- The driver-branch and skip scans are textual. They are not execution evidence.
