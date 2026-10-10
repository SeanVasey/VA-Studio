# MySQL native selection for Foundation CI — 2026-10-09

Branch `harness/mysql-native-selection` branched from main `0f9b39ce5d59e2d579d11a8ca15153ced1f565b4` and
merged main `596d2133` (which contains #70 and #81) at `2f8deb8`.
The earlier commits are `78f8a7b`, `23ff88e` and `6d17481`. `6d17481` applied conditions C1 to C4 from
the independent review of `23ff88e`, whose decision was APPROVE WITH CONDITIONS. `916392d` added the
reviewed MySQL skip census. `22371ae` implemented option A for the tests that need a dedicated
schema: the MySQL jobs mark their disposable database, and four more files join the selection. The
branch then merged main at `2f8deb8`, which brought in #70 (merged at `13409a29`) and the new
`tests/Feature/MigrationRecompilationTest.php`. `552589d` updated the counts to that tree. This revision
applies the conditions of the independent re-review of `552589d` (APPROVE WITH CONDITIONS): residual and
pattern files are pinned in the policy, and blockers and warnings are recorded explicitly.
`5b42498` moved GitLab to the same 8 shards. On branch `harness/foundation2-fixes`, the latest revision
moved both providers to 16 MySQL shards using the hosted timings of Foundation run 37967128232
(`fa6d89f`), and the current revision raises that to 24 shards for timeout headroom; see
[Duration estimates](#duration-estimates-c3).

This note records partition and self-test evidence. Foundation run 37967128232 executed the selection
on hosted MySQL in 8 shards. Only shard 2 passed, so the run is not Foundation acceptance.

## Why

Foundation run 37921309772 could not finish the MySQL matrix. 307 test files use
`tests/Support/FinalizationDatabaseMigrations`, which runs a full `migrate:fresh` before every test
and `db:wipe` after it. On MySQL 8.4 in CI that run measured about 35 s per test, so eight 90-minute
shards reached only about 25% of the 7,633 cases. For this selection that figure proved too low: run
37967128232 measured about 54 s per selected case (see Duration estimates). Sean chose to run on MySQL only what SQLite cannot prove,
and to keep the complete suite on SQLite.

## What runs where

| Engine | Scope | Cases |
| --- | --- | --- |
| SQLite (2 shards) | Complete suite; the 623 reviewed census cases skip | 7,642 listed, 7,019 executed |
| MySQL (24 shards on GitHub and GitLab) | Native selection; only the 58 reviewed MySQL census cases skip | 1,765 listed, 1,707 executed, in 163 files |

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

## Residual risk pending Sean's decision (enforced)

The selection finds MySQL-only behaviour only in these places:

- where SQLite skips it;
- where the test file is a migration test;
- where the include list names the file.

Test files that branch on the driver but are in none of these stay off MySQL. **That is now enforced, not
just disclosed.** `scripts/ci/database-mysql-selection.json` lists them in `residual_files`.
`scripts/ci/test-phpunit-shards.py` scans every `tests/**/*.php` file for driver branching: `getDriverName`,
`ATTR_DRIVER_NAME`, a `['driver']` subscript, `->driver` (not the `driver()` method calls of session or
cache), and `DB_CONNECTION` reads through `getenv`/`env`/`$_ENV`/`$_SERVER`. The rule has two halves:

- **Test files.** Every branching test file (`tests/Feature|Unit/**/*Test.php`) must be selected (it owns
  a census method, is in `pattern_files` or is in `include_files`) or be listed in `residual_files`. A
  residual file that no longer branches, or that is now selected, also fails, so the list stays exact.
  The sharder and both verifiers also refuse a residual file that is undiscovered or selected.
- **Helper files.** Branching non-test files under `tests/` are support classes, race workers,
  paid-development fixture snapshots and browser scripts. PHPUnit does not run them as tests. They are
  listed exactly in `branching_helper_files`: 96 files, 66 in `tests/Support`, 20 in `tests/Fixtures` and
  10 in `tests/browser`. A new or removed branching helper is a reviewed policy edit. I chose this over
  requiring each helper's users to be covered, because finding the users would need call-graph
  analysis: traits, `Process` worker paths and fixture snapshots. The exact list is simpler and leaves
  nothing implicit. Listing a helper does not claim MySQL coverage for it.

The scan matched one test file the earlier review's narrower patterns missed:
`tests/Unit/CommerceGuardBytesTest.php`. It mocks `getDriverName` and touches no database.

**47 files (625 cases) remain on SQLite only.** They run on SQLite, often through a fallback branch, and
do not run on MySQL in Foundation CI. Whether to add any of them is Sean's decision.

| File | Cases | Why it branches |
| --- | --- | --- |
| `tests/Feature/CustomerConsentAdmissionTest.php` | 22 | Reads `sqlite_master` or `information_schema` by driver to snapshot the schema around consent admission |
| `tests/Feature/CustomerListeningNotesCapacityTest.php` | 3 | Adds MySQL-only assertions inside the capacity checks (lines 46, 88) |
| `tests/Feature/CustomerSuppressionRetainedTargetTest.php` | 2 | Drops its temporary shadow table with driver-specific DDL |
| `tests/Feature/CustomerSuppressionSourceBoundaryTest.php` | 7 | Creates the foreign boundary table as TEMPORARY only on MySQL |
| `tests/Feature/DiscoveryEpochRecoveryTest.php` | 18 | Installs and checks DiscoveryEpoch guards for the active driver |
| `tests/Feature/FinalizationDatabaseLifecycleTest.php` | 4 | Runs lifecycle workers per connection driver; MySQL retains views |
| `tests/Feature/FreeGrantAuthorityTest.php` | 6 | MySQL-only authority and shadow refusal branches (lines 62, 79) |
| `tests/Feature/FreeGrantDownloadsTest.php` | 4 | SQLite-only branch (line 160) |
| `tests/Feature/PrivateProductDraftTest.php` | 24 | Driver-specific REPLACE verb and a MySQL-only engine check |
| `tests/Feature/ProductionAmountInputConsistencyAccessTest.php` | 15 | Driver-specific access path; one assertion requires MySQL |
| `tests/Feature/ProductionAmountRequirementsAccessTest.php` | 12 | Driver-specific access path; one assertion requires MySQL |
| `tests/Feature/ProductionCheckoutCommittedReceiptTest.php` | 12 | Builds `CurrentRows` readers for the active driver |
| `tests/Feature/ProductionCheckoutPrimaryBoundaryTest.php` | 7 | Proves `PrimaryBoundary` for the active driver, with a native branch |
| `tests/Feature/ProductionCheckoutReceiptParentTest.php` | 4 | Builds a `CurrentRows` reader for the active driver |
| `tests/Feature/ProductionCheckoutSourceTransactionTest.php` | 11 | `CurrentRows` reader and driver-specific source-transaction checks |
| `tests/Feature/ProductionFeatures/ProductionFeatureHeldFloorTest.php` | 3 | Expects a different refusal and version on MySQL |
| `tests/Feature/ProductionFeatures/ProductionFeatureSealedRunTest.php` | 3 | Builds a `CurrentRows` reader for the active driver |
| `tests/Feature/ProductionFeatures/ProductionFeatureTransactionEventsTest.php` | 8 | Creates TEMPORARY foreign tables only on MySQL |
| `tests/Feature/ProductionFeatures/ProductionFeatureTransactionOwnershipTest.php` | 2 | Creates a TEMPORARY foreign table only on MySQL |
| `tests/Feature/ProductionFreeGrants/ProductionFreeGrantLibraryWindowTest.php` | 2 | Native-only branch that asserts the driver is not SQLite (line 49) |
| `tests/Feature/ProductionIdentity/ProductionIdentityDependencyAdmissionTest.php` | 9 | Driver-specific dependency admission fixtures |
| `tests/Feature/ProductionIdentity/ProductionIdentityJourneyTest.php` | 8 | Builds `CurrentRows` readers for the active driver |
| `tests/Feature/ProductionIdentity/ProductionIdentityKeyRotationTest.php` | 10 | Builds `CurrentRows` readers for the active driver |
| `tests/Feature/ProductionIdentity/ProductionIdentityNoticeTest.php` | 8 | Uses the active driver's identity guard SQL |
| `tests/Feature/ProductionIdentity/ProductionIdentityRuntimeTest.php` | 11 | `CurrentRows` reader; one branch asserts MySQL |
| `tests/Feature/ProductionIdentity/ReviewIdentityKeyRotationAdversarialTest.php` | 8 | `CurrentRows` reader and a driver-specific fixture |
| `tests/Feature/ProductionIdentityAdapters/IdentityHistoricalConfigurationAdmissionTest.php` | 4 | Builds a `CurrentRows` reader for the active driver |
| `tests/Feature/ProductionIdentityAdapters/ProductionAccountFeatureAccessTest.php` | 9 | Builds a `CurrentRows` reader for the active driver |
| `tests/Feature/ProductionMembership/MembershipRowsFunctionClosureTest.php` | 17 | MySQL-only and SQLite-only branches in the row-function closure checks |
| `tests/Feature/ProductionSuppression/ProductionSuppressionSealTest.php` | 2 | Builds a `CurrentRows` reader for the active driver |
| `tests/Feature/ProductionSuppression/ProductionSuppressionTimestampGuardTest.php` | 1 | Extra SQLite-only malformed raw-text cases; MySQL-tolerant message check |
| `tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutGuardTest.php` | 7 | SQLite-only guard cases (the data provider is empty on MySQL) |
| `tests/Feature/ProductionTaxCheckout/ProductionTaxSourceV2Test.php` | 5 | Builds a `CurrentRows` reader for the active driver |
| `tests/Feature/ProductionTrackCapabilitiesGuardsTest.php` | 28 | Asserts the SQLite driver (line 62) |
| `tests/Feature/ProductionTrackPolicyDraftTest.php` | 76 | SQLite-only branch (line 424) |
| `tests/Feature/ProductionTrackPreparationPacketGuardsTest.php` | 26 | SQLite-only branch (line 197) |
| `tests/Feature/ResumableMediaUploadsTest.php` | 25 | Driver-specific temporary-table cleanup |
| `tests/Feature/RightsDeclarationWriterTest.php` | 33 | Driver-specific trigger DDL in one fixture |
| `tests/Feature/RightsEvidenceGuardTest.php` | 43 | SQLite- and MySQL-specific guard branches (14 sites) |
| `tests/Feature/ServiceProjectAttachmentAuthorityTest.php` | 17 | Driver-specific temporary-table DDL |
| `tests/Feature/ServiceProjectCredentialResolverTest.php` | 1 | Records the driver in a probe file |
| `tests/Feature/ServiceProjectRecoveryTest.php` | 7 | Driver-specific temporary tables and recovery branches |
| `tests/Feature/SiteContentDamagedPublicationTest.php` | 4 | Driver-specific damage fixture |
| `tests/Feature/SupportAttachmentRegistrationTest.php` | 12 | Schema-qualified table name chosen by driver |
| `tests/Feature/TestPaymentExceptionOperationsTest.php` | 25 | SQLite-only branch (line 350) |
| `tests/Feature/TestUnpaidReleaseTest.php` | 46 | Driver-specific trigger DDL in one fixture |
| `tests/Unit/CommerceGuardBytesTest.php` | 14 | Mocks `getDriverName` to render guard SQL for both drivers; touches no database (found by the wider scan) |

In total, 394 of the 557 files (5,877 of the 7,642 cases) no longer run on MySQL in Foundation CI.
They still run on SQLite.

## MySQL skip signals (C2)

### Attachment consumers and other MySQL skips (C2): proven on hosted MySQL in Foundation run 38077166247

- `tests/Feature/SupportAttachmentsTest.php` (28 cases) and `tests/Feature/ServiceSupportAttachmentsTest.php`
  (8 cases) are now selected. Their `setUp` skips on MySQL only when `ATTACHMENT_NATIVE_ISOLATED` is not
  `1`. The MySQL jobs (and only they) now set it, so on CI these cases are configured to run their
  native paths instead of skipping. Foundation run 38077166247 confirmed this on hosted MySQL 8.4.11. The flag is environment-gated, so it is deliberately not in the census: any skip
  of these cases on CI MySQL is refused as an unlisted skip.
- The two SQLite-only methods,
  `CustomerListeningFreshnessTest::test_framework_reads_cannot_use_a_temporary_catalog_shadow_while_proof_reads_main`
  and `FreeGrantSchemaRecoveryTest::test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target`,
  are now in the MySQL skip census, and their files are selected.

**The native attachment-consumer release blocker is closed for Foundation.** It was to stay open until an
exact-SHA Foundation run passed on hosted MySQL 8.4 (the local MySQL 8.0 evidence below could not close it). It closed
on 2026-10-10 with run [38077166247](https://github.com/SeanVasey/VA-Studio/actions/runs/38077166247)
on the exact SHA `0f23e63f`: both files ran on hosted MySQL 8.4.11 with no unlisted skip, all 26 receipts were verified and
the run succeeded (`docs/verification/foundation-20261009/`, fourth run).

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

**Warning.** Set `VA_CI_DISPOSABLE_MYSQL=1` only against a private, disposable mysqld that nothing else
uses. The guarded tests drop every table in `DB_DATABASE`, so copying the CI environment onto a shared
or long-lived server wipes that database. The helper's docblock and
`docs/verification/ci-database-receipts.md` carry the same warning.

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
- The sharder refuses:
  - an empty selection;
  - a census pair that PHPUnit no longer discovers;
  - any `include_files` entry that is undiscovered or malformed;
  - any difference between the pattern's discovered matches and the pinned `pattern_files` (50 files),
    so a rename or a new migration test is a reviewed policy edit;
  - a `residual_files` entry that is undiscovered or selected.

  The verifier makes the same two `pattern_files` and `residual_files` checks.
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

### 24 shards (current)

Run in `/home/user/wt-b2` on `harness/foundation2-fixes` (base `fa6d89f`) with the refreshed timings,
PHPUnit discovery only:

```
python3 scripts/ci/phpunit-shards.py --shards=24 --prefix=phpunit-ci-mysql \
  --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection        # rc 0
warning: 124 test file(s) have no entry in scripts/ci/phpunit-timings-mysql.json and use the fallback weight; ...
Proved 1765 selected MySQL-native tests in 163 files (of 7642 discovered tests in 557 files) across 24 nonempty shards.
Shard 1: 85 tests; 1 complete files; estimated 75m 51s.
Shard 2: 74 tests; 4 complete files; estimated 64m 49s.
Shard 3: 65 tests; 6 complete files; estimated 65m 21s.
Shard 4: 73 tests; 5 complete files; estimated 65m 08s.
Shard 5: 71 tests; 6 complete files; estimated 64m 58s.
Shard 6: 68 tests; 7 complete files; estimated 65m 21s.
Shard 7: 72 tests; 7 complete files; estimated 65m 17s.
Shard 8: 73 tests; 7 complete files; estimated 65m 23s.
Shard 9: 73 tests; 6 complete files; estimated 65m 08s.
Shard 10: 73 tests; 8 complete files; estimated 65m 23s.
Shard 11: 73 tests; 8 complete files; estimated 65m 29s.
Shard 12: 73 tests; 8 complete files; estimated 65m 15s.
Shard 13: 73 tests; 8 complete files; estimated 65m 29s.
Shard 14: 93 tests; 8 complete files; estimated 65m 02s.
Shard 15: 73 tests; 7 complete files; estimated 65m 08s.
Shard 16: 73 tests; 7 complete files; estimated 65m 08s.
Shard 17: 70 tests; 8 complete files; estimated 65m 30s.
Shard 18: 70 tests; 7 complete files; estimated 64m 58s.
Shard 19: 75 tests; 8 complete files; estimated 65m 23s.
Shard 20: 73 tests; 7 complete files; estimated 65m 02s.
Shard 21: 77 tests; 8 complete files; estimated 64m 47s.
Shard 22: 73 tests; 8 complete files; estimated 65m 24s.
Shard 23: 69 tests; 7 complete files; estimated 64m 47s.
Shard 24: 73 tests; 7 complete files; estimated 64m 58s.
```

- **Selection block.** The manifest `selection` block and `timings_sha256`
  (`acf203966c63d31f62eaaedcaad45fbc81f447d06b07d02621373972c41ddb37`) are unchanged.
- **Verifier.** `database_evidence` accepted all 24 real shards, each with synthesized JUnit in which
  exactly the census cases skip: 58 MySQL skips in total. The same evidence checked as 8 or 16 shards
  was refused with `Partition loses or duplicates cases, files or groups`.
- **Archive bound.** Each of the 24 shards was zipped with all 53 expected members, as the collector
  receives them, and passed `archive()`. The largest was 577,357 bytes zipped and 5,634,273 bytes
  unpacked, inside the 8 MiB and 16 MiB bounds; `MAX_ZIP_MEMBERS` is 56.
- **Unflagged output.** Without the flag, the branch's sharder and `origin/main`'s (`f57e725`;
  unchanged here) both returned rc 0. `diff -r` found the 50 MySQL 24-shard files and the 6 SQLite
  2-shard files byte-identical. `--mysql-native-selection` with `--prefix=phpunit-ci-sqlite` was
  refused with rc 1.
- **Clean-up.** Only the `phpunit-ci-mysql-*` and `phpunit-ci-sqlite-*` outputs were moved to scratch
  and deleted. The flag requires the `phpunit-ci-mysql` prefix, and no file with either prefix existed
  beforehand.

### 16 shards (historical, `fa6d89f`)

Run in `/home/user/wt-b2` on `harness/foundation2-fixes` (base `2ce5bae`) with the refreshed timings,
PHPUnit discovery only:

```
python3 scripts/ci/phpunit-shards.py --shards=16 --prefix=phpunit-ci-mysql \
  --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection        # rc 0
warning: 124 test file(s) have no entry in scripts/ci/phpunit-timings-mysql.json and use the fallback weight; ...
Proved 1765 selected MySQL-native tests in 163 files (of 7642 discovered tests in 557 files) across 16 nonempty shards.
Shard 1: 108 tests; 8 complete files; estimated 98m 41s.
Shard 2: 110 tests; 10 complete files; estimated 98m 31s.
Shard 3: 102 tests; 10 complete files; estimated 98m 49s.
Shard 4: 106 tests; 9 complete files; estimated 98m 02s.
Shard 5: 108 tests; 10 complete files; estimated 98m 02s.
Shard 6: 111 tests; 11 complete files; estimated 98m 01s.
Shard 7: 111 tests; 11 complete files; estimated 98m 50s.
Shard 8: 109 tests; 10 complete files; estimated 98m 18s.
Shard 9: 109 tests; 11 complete files; estimated 98m 41s.
Shard 10: 130 tests; 11 complete files; estimated 98m 17s.
Shard 11: 110 tests; 10 complete files; estimated 98m 06s.
Shard 12: 106 tests; 10 complete files; estimated 98m 38s.
Shard 13: 113 tests; 11 complete files; estimated 98m 35s.
Shard 14: 109 tests; 10 complete files; estimated 98m 27s.
Shard 15: 111 tests; 11 complete files; estimated 98m 31s.
Shard 16: 112 tests; 10 complete files; estimated 98m 34s.
```

- **Selection block.** The manifest `selection` block is unchanged (table below). `timings_sha256` is
  `acf203966c63d31f62eaaedcaad45fbc81f447d06b07d02621373972c41ddb37`.
- **Verifier.** `database_evidence` accepted all 16 real shards, each with synthesized JUnit in which
  exactly the census cases skip: 58 MySQL skips in total. The same evidence checked as 8 shards was
  refused with `Partition loses or duplicates cases, files or groups`.
- **Archive bound.** A 16-shard MySQL archive has 37 members: the manifest, the source listing, 16
  configurations, 16 listings, and the shard's results, start and receipt files. The verifier's old
  fixed bound of 32 members would have refused it, so the bound became `MAX_ZIP_MEMBERS = 40` (56 since
  the 24-shard change). The
  exact expected-name check is unchanged. A real 37-member archive was 504,049 bytes zipped and
  5,002,633 bytes unpacked, inside the 8 MiB and 16 MiB bounds.
- **Unflagged output.** Without the flag, the branch's sharder and `origin/main`'s (`f57e725`; the
  sharder is unchanged in this change) both returned rc 0. `diff -r` found the 34 MySQL 16-shard files
  and the 6 SQLite 2-shard files byte-identical. `--mysql-native-selection` with
  `--prefix=phpunit-ci-sqlite` was refused with rc 1.
- **Clean-up.** Every generated `phpunit-ci-mysql-*` and `phpunit-ci-sqlite-*` file was deleted.

### 8 shards (historical)

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

GitLab used the same 8 shards from `5b42498` until the 16-shard change (`fa6d89f`). Before that,
`--shards=4` (GitLab's former count) returned rc 0 and proved 1,765 cases across shards of 477, 425,
429 and 434 cases, each estimated at about 38 minutes from the timings file.

Every shard count (4, 8, 16 and 24) produced the same manifest `selection` block:

| Field | Value |
| --- | --- |
| `policy_sha256` | `bc2b913e3918dee8f13adda2bd6e67f63c76619b4b40c7514465ca5867cb4ecb` |
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
- With the census in place, `database_evidence` accepted every shard of the real 8-shard and (historical)
  4-shard evidence. Each shard used synthesized JUnit in which exactly the census cases skip with 0
  assertions: 58 MySQL skips in total at this revision. A shard whose census cases ran instead of skipping was refused
  with `MySQL skip identities differ from the reviewed SQLite-only policy`.

**Unflagged output is unchanged.** Run without the flag, the new script and main's script
(`git show 0f9b39c:scripts/ci/phpunit-shards.py`), each run on the `22371ae` tree (7,641 tests in 556
files), both returned rc 0, and `diff -r` found all 18 MySQL 8-shard files and all 6 SQLite 2-shard
files byte-identical. `--mysql-native-selection` with
`--prefix=phpunit-ci-sqlite` was refused with rc 1. Every generated `phpunit-ci-*` file was deleted
afterwards.

### Duration estimates (C3)

**Measured on hosted MySQL.** Foundation run 37967128232 (attempt 1, `workflow_dispatch`, commit
`f57e7256891d0d3c117e151a36f7fb967c724ab7`, tree `c6a982f7f84cc661d9ab2ac39837072318e62e37`) ran this
selection on hosted MySQL 8.4.11 in 8 shards with a 210-minute limit:

| 8-shard job | Cases | Finalization cases | Outcome |
| --- | --- | --- | --- |
| 2 | 208 | 166 | Passed in 185.6 min of test time (11,137.8 s); receipt accepted |
| 3 | 209 | 159 | Ran all 209 in 186.5 min (11,191.4 s); 8 errors, all in `CustomerInquiryMigrationTest`, fixed in `5ffcc97` |
| 1 | 257 | 241 | Cancelled at 210 min. At least 148 cases had completed (144 passed, 4 skipped, no failures); case 126 at 174.1 min, case 148 by 203.6 min |
| 4, 5, 6, 7 | 208, 243, 211, 218 | 171, 177, 174, 160 | Cancelled at 210 min |
| 8 | 211 | 124 | Cancelled at 210 min after 126 cases at 188.5 min: 90 passed, 35 skipped and 1 error at case 89 (`ProductionFeatureMigrationTest::test_recorded_gap_and_non_prefix_installation_refuse_without_repair`, SQLSTATE 1824) |

An earlier revision of this note said shards 1 and 8 had "passed 126 tests each". That was wrong.
Shard 1 had completed at least 148 cases, and shard 8's 126 included the error above. The figures
come from the progress lines of the job logs; GitHub job setup (job start to the PHPUnit step) was
59.6 to 72.6 s in shards 1, 2, 3 and 8, about 1.2 minutes at most.

**Native-only test bugs.** Four test files fail on MySQL but not on SQLite, because MySQL refuses to
drop or create a table against a missing or remaining foreign-key partner:
- `CustomerInquiryMigrationTest`: 8 errors in shard 3, fixed in `5ffcc97`.
- `ProductionFeatureMigrationTest`: the shard 8 error above (SQLSTATE 1824), fixed in `466ea46`.
- `CustomerAccountMigrationTest`: SQLSTATE 3730, fixed in `8ffea7d`. The independent review of
  `bed64ee` reproduced 2 errors (the shard holding it was cancelled). Its setUp left 46 empty newer
  dependents of `customer_accounts` in place, not only `customer_saved_tracks`, because MySQL names
  only the first blocking constraint.
- `SharedInventoryMigrationTest`: SQLSTATE 3730, fixed in `a994c79`. The scan below found it.

Red and green runs on a private MySQL 8.0.46, plus the SQLite runs before and after, are in
[`docs/verification/foundation-20261009/run2/`](../foundation-20261009/run2/):
`customer-account-migration-*`, `production-feature-migration-*` and `shared-inventory-migration-*`
(`-mysql80-red.txt`, `-mysql80-green.txt`, `-sqlite.txt`), and
`customer-account-migration-mysql80-green-all.txt` for the whole account file (7 tests, 481 assertions).

**Parent-rollback scan.** Every selected test file that rolls back a migration or drops a table was
checked against the real foreign-key graph, not regular expressions:
- **Inputs.** The 360 constraints come from a fully migrated MySQL 8.0.46 database. Table ownership
  comes from applying the 80 migrations one file at a time and diffing the catalog after each; all
  191 tables are attributed.
- **Rule.** A newer child of a removed parent counts as handled only when the test also removes it.
- **Results.** 28 files remove a parent (35 when every migration a file references is treated as
  possibly rolled back).
  - `SharedInventoryMigrationTest` was a real gap, confirmed on MySQL as SQLSTATE 3730 at
    `free_definitions_scope`.
  - The other flags are not gaps on MySQL:
    - `CustomerSuppressionMigrationTest` drops `customer_accounts` only in a SQLite-only method with
      foreign keys off.
    - `PrivateProductDraftSchemaTest` drops a prefixed fixture `users` table.
    - `SupportAttachmentsTest` drops temporary shadow tables.
    - `CustomerAccountMigrationTest` was flagged only through its comment naming
      `CapabilityRollbackFixture`.

The 35 s per case used for the earlier estimates (measured on the whole suite in run 37921309772) was
too low for this selection. In the two complete JUnit logs the 417 cases average 53.5 s: the 325
Finalization cases (31 files) average 57.7 s, and the other 92 cases (8 files) 39.0 s. Shard time
divided by its Finalization cases is 67.1 s (shard 2) and 70.4 s (shard 3), which is the "about 68 s
per Finalization case" figure used below. At 68 s the 1,372 Finalization cases are about 26
compute-hours.

**Timings refresh.** `scripts/ci/phpunit-timings-mysql.json` was regenerated with
`scripts/ci/phpunit-timings.py --driver mysql` from exactly those two logs:
- shard 2 (`phpunit-ci-mysql-2-results.xml`, SHA256 `030768ca…dfdf967f`);
- shard 3 (`phpunit-ci-mysql-3-results.xml`, SHA256 `e0080284…bd61753`).

It now holds 39 files and 417 cases, with a fallback of 53,547 ms per case. The generator writes the
whole file from the logs it is given, so the 233 earlier rows (run 37410220669 and focused samples) are
gone. Those rows are stale for MySQL: the 19 files measured again took 5–10 s per case in them and
41–80 s now. Merging the new rows into the stale ones was evaluated on the real inventory and rejected.
That mix put the busiest of 16 shards at about 182 minutes of modelled time. The regenerated file
gives about 105 minutes on the same model.
- `CustomerInquiryMigrationTest`'s row comes from its erroring run.
- 124 of the 163 selected files have no row and use the fallback.
- The fallback is the generator's mean; the tooling has no reviewed setting for it, so it was not
  raised toward 68 s by hand.
- The MySQL timings file feeds only the MySQL native-selection partition on GitHub and GitLab. SQLite
  reads `phpunit-timings-sqlite.json`, which is unchanged. Regenerating it from the complete SQLite
  shard 1 log of the same run (47.5 min) is a follow-up.

**24 shards (current).** Real discovery with the refreshed timings splits the selection as below.
All columns are estimates; none is a measurement of these shards.

| Shard | Cases | Finalization cases | Minutes at 68 s per Finalization case | Sharder estimate (min) | Projected minutes (k = 1.0 / 1.48 / 1.88) |
| --- | --- | --- | --- | --- | --- |
| 1 (`BulkReplaceLicenseDraftSourceTest` alone; measured) | 85 | 85 | 96 | 76 | 122 / 122 / 122 |
| 2 | 74 | 73 | 83 | 65 | 69 / 100 / 126 |
| 3 | 65 | 65 | 74 | 65 | 67 / 74 / 81 |
| 4 | 73 | 23 | 26 | 65 | 55 / 81 / 103 |
| 5 | 71 | 56 | 63 | 65 | 62 / 71 / 78 |
| 6 | 68 | 62 | 70 | 65 | 65 / 74 / 82 |
| 7 | 72 | 64 | 73 | 65 | 70 / 99 / 124 |
| 8 | 73 | 73 | 83 | 65 | 70 / 99 / 123 |
| 9 | 73 | 73 | 83 | 65 | 70 / 104 / 132 |
| 10 | 73 | 38 | 43 | 65 | 59 / 87 / 110 |
| 11 | 73 | 65 | 74 | 65 | 67 / 93 / 115 |
| 12 | 73 | 42 | 48 | 65 | 60 / 84 / 104 |
| 13 | 73 | 59 | 67 | 65 | 67 / 97 / 122 |
| 14 | 93 | 62 | 70 | 65 | 64 / 75 / 84 |
| 15 | 73 | 42 | 48 | 65 | 61 / 90 / 114 |
| 16 | 73 | 71 | 80 | 65 | 70 / 103 / 131 |
| 17 | 70 | 66 | 75 | 66 | 68 / 93 / 114 |
| 18 | 70 | 36 | 41 | 65 | 59 / 75 / 89 |
| 19 | 75 | 28 | 32 | 65 | 66 / 70 / 74 |
| 20 | 73 | 55 | 62 | 65 | 64 / 94 / 120 |
| 21 | 77 | 26 | 29 | 65 | 56 / 82 / 103 |
| 22 | 73 | 73 | 83 | 65 | 70 / 104 / 131 |
| 23 | 69 | 63 | 71 | 65 | 66 / 89 / 108 |
| 24 | 73 | 72 | 82 | 65 | 68 / 92 / 111 |

- **Columns.** "Minutes at 68 s" counts Finalization cases only, the coordinator's measure; the
  busiest shard is about 96 minutes. "Projected" uses the measured time for the 39 measured files and
  for `BulkReplaceLicenseDraftSourceTest` (below). For every other file it uses 57.7 s per Finalization
  case and 39.0 s per other case, multiplied by k.
- **Calibrating k.** k = 1.0 assumes unmeasured files run like the measured ones. The cancelled shards
  say they do not.
  - In shard 1, case 126 completed 174.1 minutes into the test step. In shard 8 it completed at
    188.5 minutes.
  - On the model those first 126 cases are 117.7 and 100.0 minutes, so k ≈ 1.48 and 1.88.
  - Shard 8's 35 skipped cases are counted, because they skip in the test body after `setUp()` has
    prepared the database.
  - An earlier revision used about 203 minutes for both shards, which gave k ≈ 1.72 and 2.03. That
    overstated both: 203.6 minutes is when shard 1 had completed case 148.
- **`BulkReplaceLicenseDraftSourceTest`, measured.** Old shard 1 ran it as cases 21 to 105.
  - The first file prints a timestamped line per case. Cases 21 to 63 took at most 63.1 minutes:
    about 86–88 s each, against 57.7 s in the model.
  - At about 86 s the 85 cases take about 122 minutes. The log bounds the file at no more than about
    138 minutes; the review gave 116 to 137.
  - The table uses 122 minutes for shard 1 at every k.
- **The projection is rough.** k is fitted on two shards and applied to every unmeasured file.
  Shard 1's k is dominated by `BulkReplaceLicenseDraftSourceTest` itself.
- **Why 24.** The 16-shard projection (historical table below, as computed then) put the busiest job
  over GitLab's 175-minute limit. That still holds with the corrected k and the measured
  `BulkReplaceLicenseDraftSourceTest`:
  - 16 shards: busiest job about 154–195 minutes;
  - 20 shards: about 126–158 minutes;
  - 24 shards: about 122–132 minutes.
  - With 24 shards, shard 1 holds only `BulkReplaceLicenseDraftSourceTest` (about 122 minutes
    measured), the whole-file floor.
  - The busiest other shard projects at about 104 minutes (k = 1.48) to 132 minutes (k = 1.88,
    shard 9).
- **GitHub.** The busiest job (about 122–132 minutes, plus about 1.2 minutes of setup) is well under
  the 210-minute limit, which stays.
- **GitLab.** At GitHub's speed the busiest job leaves about 40 minutes under the 175-minute limit.
  GitLab's small hosted runners are smaller than GitHub's and unmeasured here, so that margin is not
  established. The 3-hour hosted cap leaves no room to raise the limit. See Open blockers for the
  dispatch order.
- **`MigrationRecompilationTest`.** Selected through the migration pattern since the merge of main. It
  is not in the Finalization counts: it runs `migrate:fresh` four times itself, which adds a few minutes
  to shard 2. It compares counts of declared classes, which is independent of the driver, so it should
  hold on MySQL. It passed on local MySQL 8.0 in 252 s.
- **Attachment consumers.** The two attachment-consumer files (36 cases, `migrate:fresh` per test
  without `FinalizationDatabaseMigrations`) sit in shards 7 and 18.

**Cost per full run.** Both pipelines start only manually:
- GitHub Foundation runs only on `workflow_dispatch` with an exact `expected_sha`.
- GitLab's `workflow: rules` admit only `web` or `api` pipelines whose `EXPECTED_SHA` equals the
  commit; every other source is `when: never`.
- Nothing runs on push, merge request or schedule.

The figures:
- **MySQL test time.** About 36 hours at k = 1.48 and 43 hours at k = 1.88 (27 at k = 1.0), with
  `BulkReplaceLicenseDraftSourceTest` at its measured 122 minutes. The shard count does not change
  this; it is the same 1,765 cases. (An earlier revision said 41–47 hours, from the overstated k.)
- **Job setup.** GitHub job setup measured 1.0–1.2 minutes per job, so 24 jobs add about half an hour.
  Every shard beyond 8 adds one more setup.
- **GitLab compute minutes.** GitLab.com's documentation gives a cost factor of 1 for small Linux
  hosted runners (compute minutes = job seconds / 60 × cost factor). The `.gitlab-ci.yml` jobs carry no
  runner tags.
  - At GitHub's speed the 24 MySQL jobs would use about 2,200–2,650 compute minutes per pipeline.
  - The 2 SQLite jobs (48.2 and 60.3 minutes on GitHub) and the other jobs add more, so the total is
    roughly 2,300–2,800 minutes.
  - GitLab's small runners are unmeasured. If they are slower, minutes rise in proportion.
  - GitLab's documented Free quota is 400 compute minutes a month, so one full pipeline would need
    roughly 6–7 months of a Free quota.
  - The namespace's plan, its remaining quota and any purchased minutes were not checked.
  - A job cancelled at its timeout still consumes its minutes.
- **GitHub billing.** `SeanVasey/VA-Studio` is public. GitHub's billing documentation says Actions
  usage is free for public repositories on standard GitHub-hosted runners, so a Foundation run is not
  billed.
- **GitHub concurrency.** It is capped at 20 jobs on GitHub Free and 40 on Pro; the account's plan was
  not checked. Under 20, some of the 24 MySQL shards wait for others to finish. That lengthens wall
  time but not the per-job limit, because waiting does not count against `timeout-minutes`.

**16 shards (historical, `fa6d89f`).** The same refreshed timings split 16 ways. These projections used
the overstated k (1.72 / 2.03) and are kept as computed then:

| Shard | Cases | Finalization cases | Minutes at 68 s per Finalization case | Sharder estimate (min) | Projected minutes (k = 1.0 / 1.72 / 2.03) |
| --- | --- | --- | --- | --- | --- |
| 1 | 108 | 96 | 109 | 99 | 104 / 173 / 202 |
| 2 | 110 | 106 | 120 | 99 | 105 / 179 / 210 |
| 3 | 102 | 100 | 113 | 99 | 102 / 136 / 151 |
| 4 | 106 | 29 | 33 | 98 | 81 / 132 / 153 |
| 5 | 108 | 82 | 93 | 98 | 94 / 134 / 150 |
| 6 | 111 | 105 | 119 | 98 | 101 / 139 / 155 |
| 7 | 111 | 100 | 113 | 99 | 102 / 170 / 199 |
| 8 | 109 | 101 | 114 | 98 | 105 / 176 / 205 |
| 9 | 109 | 108 | 122 | 99 | 105 / 172 / 200 |
| 10 | 130 | 68 | 77 | 98 | 92 / 143 / 165 |
| 11 | 110 | 89 | 101 | 98 | 103 / 157 / 180 |
| 12 | 106 | 73 | 83 | 99 | 95 / 143 / 163 |
| 13 | 113 | 77 | 87 | 99 | 94 / 147 / 170 |
| 14 | 109 | 82 | 93 | 98 | 102 / 135 / 149 |
| 15 | 111 | 72 | 82 | 99 | 94 / 160 / 188 |
| 16 | 112 | 84 | 95 | 99 | 96 / 149 / 172 |

At 68 s per Finalization case the busiest of these was about 122 minutes, but the projection put it at
about 179–210 minutes, and total compute at about 41–47 hours rather than 26. That led to 24 shards.
At that time 20 shards were projected at about 146–172 minutes.

Historical estimates, superseded by the measurements above (35 s per Finalization case, Finalization
cases only):

| Matrix | Finalization cases per shard | Estimated minutes at 35 s | Job limit |
| --- | --- | --- | --- |
| 8 shards (GitHub; GitLab from `5b42498`) | 241, 166, 159, 171, 177, 174, 160, 124 | about 141, 97, 93, 100, 103, 102, 93, 72 | 210 min GitHub, 175 min GitLab |
| GitLab, 4 shards | 361, 333, 338, 340 | about 211, 194, 197, 198 | 240 min (was 90) |

## Open blockers

- **GitLab is dispatched only after GitHub has measured shard 1.** At GitHub's speed the busiest
  24-shard job is about 122–132 minutes. Shard 1, `BulkReplaceLicenseDraftSourceTest` alone, was
  measured at about 122 minutes.
  - GitLab's small hosted runners are smaller than GitHub's and unmeasured, and the 175-minute limit
    cannot rise under the 3-hour hosted cap.
  - Mitigation: GitLab is dispatched only after a hosted GitHub Foundation run has measured shard 1's
    duration. If that measurement shows GitLab's runners would exceed 175 minutes, the next step is to
    split `BulkReplaceLicenseDraftSourceTest` into two files (a floor of about 60 minutes). It is not
    split now.
  - More shards cannot help shard 1.
- **GitLab compute minutes.** At GitHub's speed a full GitLab pipeline is projected at roughly
  2,300–2,800 compute minutes, against a documented Free quota of 400 a month. The namespace's plan
  and quota were not checked. Sean's cost policy applies before any GitLab dispatch.
- **No MySQL claims before a passing hosted run.** Run 37967128232 is not acceptance: only shard 2
  passed. None of these may be claimed until one exact-SHA Foundation run passes on hosted MySQL 8.4:
  - MySQL-native coverage of the selection;
  - exactness of the MySQL skip census;
  - closure of the attachment-consumer blocker.

  The local MySQL 8.0 runs above do not count.

- **Indirect driver branching is not counted in the 47 residual files.** The delta review of `2ac0f3d`
  found 26 further test files that are neither selected nor residual but call listed branching helpers
  (`branching_helper_files`); 20 of them are `PaidGrant*` tests using `PaidGrantDependencyFixtures`.
  Most of those helper branches are guard assertions, but `PaidGrantCommitFrameProbe` (lines 51 and 83)
  behaves differently on MySQL, so that path runs only on SQLite. The residual figure therefore
  understates the driver-branching code MySQL never executes. The scan also does not recognise
  `getConfig('driver')` or a `config('database.default')` comparison; neither occurs in an uncovered
  file today. Both are follow-ups, not enforced by this change.

## Next steps

1. Dispatch one exact-SHA Foundation run of the 24-shard candidate on hosted MySQL 8.4.
2. Regenerate `scripts/ci/phpunit-timings-mysql.json` from every complete shard JUnit of that run
   with `scripts/ci/phpunit-timings.py`, so the 124 untimed files get real weights. Then revisit the
   shard count and the 210-minute GitHub and 175-minute GitLab limits.
3. Regenerate `scripts/ci/phpunit-timings-sqlite.json` from complete SQLite JUnit (run 37967128232's
   shard 1 log is complete) in a separate change.
4. Dispatch GitLab only after step 1 measures shard 1 on GitHub (see Open blockers), then measure the
   GitLab shard durations and confirm they stay under 175 minutes.
5. Sean decides whether any of the 47 residual files should join the selection.
6. Follow-up CI change: require every test file that names a listed branching helper to be selected or
   residual (one level), and add `getConfig('driver')` and `database.default` comparison patterns to the scan.

## Self-tests

| Command | Result |
| --- | --- |
| `python3 scripts/ci/test-phpunit-shards.py` | rc 0, 52 tests |
| `python3 scripts/ci/test-database-receipts.py` | rc 0, 60 tests (59 before the 16-shard change) |
| `python3 scripts/ci/test-gitlab-database-receipts.py` | rc 0, 32 tests (31 before the 24-shard change, 30 before the GitLab 8-shard change) |
| `python3 scripts/ci/test-ci-scope.py` | rc 0, 27 tests |
| `python3 scripts/ci/test-workflow-cadence.py` | rc 0, 14 tests |
| `python3 scripts/ci/test-focused-tests.py` | rc 0, 50 tests |
| `python3 scripts/ci/test-gitlab-setup.py` | rc 0, 12 tests |
| `python3 scripts/ci/test-gitlab-writer-feedback.py` | rc 0, 7 tests |
| `python3 scripts/ci/test-php-test-runtime.py` | rc 0, 5 tests |
| `python3 scripts/ci/test-related-browser-stage.py` | rc 1: 4 of 12 fail, the same 4 that fail on unchanged `0f9b39c`, `1f89dec` and `2ce5bae` in these checkouts (environmental) |
| `node --test scripts/ci/apply-playwright-webkit-offline-backport.test.mjs` (Node 24.21.0) | 33 pass, 0 fail |

The `node --test` row is from an earlier revision and was not rerun for the 16- or 24-shard changes.

**24-shard change.** Both providers now run 24 MySQL shards.
- **GitHub.** The matrix has 24 entries, the job name ends `/24)`, the partition step passes
  `--shards=24`, and the aggregate step verifies twenty-six receipts. `COUNTS` is
  `{"mysql": 24, "sqlite": 2}`.
- **GitLab.** The matrix has 24 entries with `SHARD_COUNT: '24'` and the same `COUNTS`: twenty-six
  receipts and 32 upstream jobs.
- **Archive bound.** `MAX_ZIP_MEMBERS` is 56. A 24-shard MySQL archive holds 53 members, and the exact
  expected-name check and the size bounds are unchanged.
- **Fixtures.** The synthetic fixture has 24 migration files (27 selected of 35), so all 24 synthetic
  shards are non-empty.
- **Refusal tests.**
  - GitHub: refuses jobs from 4, 8 or 16 shards; missing shard 9, 17 or 24; a duplicate shard 24; old
    `/4)`, `/8)` or `/16)` names; and 4-, 8- or 16-shard archives and short manifests.
  - GitLab: refuses one 4-, 8- or 16-shard archive (including in slot 16); the first 4, 8 or 16 slots
    filled with old archives; a 4/8/16 mix; and, in a new test, a missing shard 17 or 24 job or a job
    named for shard 25.
- **Mutations,** each restored and checked with `cmp`:

| Mutation (24-shard change) | Result |
| --- | --- |
| GitHub `COUNTS` mysql back to 16 | 9 failures, 2 errors |
| GitLab `COUNTS` mysql back to 16 | 6 failures, 1 error |
| `MAX_ZIP_MEMBERS` back to 40 | GitHub suite 84 failures, 1 error; GitLab suite 10 failures, 2 errors |
| `MAX_ZIP_MEMBERS` 52 (one short of a 24-shard archive) | 84 failures, 1 error |
| GitHub `--shards=16` | 1 failure |
| GitHub matrix back to 16 entries | 2 failures |
| GitHub job name `/16)` | 1 failure |
| GitHub aggregate step says eighteen | 1 failure |
| GitLab `SHARD_COUNT: '16'` | 1 failure |
| GitLab matrix without `'24'` | 1 failure |

**16-shard change (historical, `fa6d89f`).** Both providers then ran 16 MySQL shards.
- **GitHub.** The workflow matrix has 16 entries, the job name ends `/16)`, and the partition step
  passes `--shards=16`. `database-receipts.py` has `COUNTS = {"mysql": 16, "sqlite": 2}`, and the
  aggregate step verifies eighteen receipts.
- **GitLab.** The matrix has 16 entries with `SHARD_COUNT: '16'`, and `gitlab-database-receipts.py`
  has the same `COUNTS`: eighteen receipts and 24 upstream jobs. `timeout: 175m` is unchanged.
- **Fixtures.** The synthetic fixture has 16 migration files (19 selected of 27), so every one of the
  16 synthetic shards is non-empty, as the sharder requires of real ones.
- **New and widened tests.**
  - GitHub collector: jobs and archives from 4 or 8 shards are refused, as are a missing shard 9 or
    16, a duplicate shard 16, and old `/4)` or `/8)` denominators.
  - The archive member bound fits every committed count (37 members for MySQL) and refuses one member
    more.
  - GitLab collector: refuses one 4-shard archive, one 8-shard archive, the first four slots as
    4-shard, the first eight as 8-shard, and a 4/8 mix, each with `Missing, duplicate or unexpected
    ZIP entry`.
- **Mutations,** each restored and checked with `cmp`:

| Mutation | Result |
| --- | --- |
| GitHub `COUNTS` mysql back to 8 | 7 failures, 2 errors |
| GitLab `COUNTS` mysql back to 8 | 5 failures |
| `MAX_ZIP_MEMBERS` back to 32 | GitHub suite 80 failures, 1 error; GitLab suite 10 failures, 2 errors |
| Member bound removed from `archive` | 1 failure |
| GitHub `--shards=8` | 1 failure |
| GitHub matrix back to 8 entries | 2 failures |
| GitLab `SHARD_COUNT: '8'` | 1 failure |

**GitLab 8-shard change (historical, `5b42498`).** `.gitlab-ci.yml` runs `backend-mysql` as 8 shards with `SHARD_COUNT: '8'`
and `timeout: 175m`, and the GitLab collector's `COUNTS` is `{"mysql": 8, "sqlite": 2}` (ten receipts,
16 upstream jobs). The GitLab self-test pins all three. A new test replaces one MySQL archive, then the
first four, with evidence built for 4 shards; the collector refuses both with `Missing, duplicate or
unexpected ZIP entry`, because the per-shard config and listing members are named for the shard count.
Mutations, each restored and checked with `cmp`: `COUNTS` back to 4 (5 failures), `timeout: 240m`
(1 failure), the 4-entry matrix (1 failure). Real discovery at 8 shards with the flag gave the
partition and selection hashes above; without it, the new and main sharders produced byte-identical
MySQL 8-shard (18 files) and SQLite 2-shard (6 files) output. The generated files were deleted.

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

The re-review's C2 and C5 add these tests:

- Driver-branching scan: a fixture tree with each branching form, plus negative cases (`driver()` method
  calls, `'DB_CONNECTION' => ...` writes). It checks the exact test and helper sets and that each list
  stays exact.
- The repository-wide scan over `tests/**/*.php` against the committed policy.
- `pattern_files` pinning in the sharder and the verifier, including a migration test renamed out of
  the pattern.
- `residual_files` must be discovered and unselected (sharder and verifier).
- The new list shapes are validated by both readers.
- A MySQL census skip that carries `setUp` assertions is accepted, and refused without its census entry.
- `test_exact_reviewed_sqlite_skips_and_no_mysql_skip` is renamed to
  `test_each_engine_skips_exactly_its_own_census_and_a_skip_may_carry_setup_assertions`.

Each mutation was applied in place and the files restored; `sha256sum -c` confirmed all three files were
byte-identical afterwards:

| Mutation | Result |
| --- | --- |
| P1: policy drops a `residual_files` entry | killed |
| P2: policy adds a stale residual entry (`BulkLicenseDraftSourceAuthoringActionTest`, no driver branching) | killed |
| P3: policy drops a `branching_helper_files` entry | killed |
| P4: policy drops a `pattern_files` entry | killed |
| S1: sharder ignores `pattern_files` | killed |
| S2: sharder drops the residual check | killed |
| S3: scan no longer recognises `->driver` | killed |
| S4: scan ignores a stale residual entry | killed |
| S5: scan ignores unlisted helpers | killed |
| V1: verifier ignores `pattern_files` | killed |
| V2: verifier drops the residual check | killed |
| Real rename: `tests/Feature/PromotionMigrationTest.php` moved to `tests/Integration/` | refused by the sharder (rc 1, "pattern_files differ … missing: tests/Feature/PromotionMigrationTest.php") and by the repository self-test (rc 1); moved back, and `sha256sum -c` confirmed it |

## Not verified

- Only 2 of 8 shards of hosted run 37967128232 completed, so most selected files have no measured
  hosted time and no hosted pass. Only a MySQL run of the whole selection proves the census exact.
- The 24-shard durations are estimates. The "projected" range rests on a factor fitted to two
  cancelled shards' progress counts and applied uniformly to the unmeasured files.
- No 16- or 24-shard run exists on either provider. Per-job setup time and GitLab runner speed were
  not measured, and the GitLab namespace's plan and compute quota were not checked.
- The local MySQL evidence used Oracle MySQL 8.0.46, not CI's 8.4. It ran only the option A files and
  `MigrationRecompilationTest`.
- The collectors ran only against synthetic fixtures.
- The GitLab hosted-runner 3-hour cap is taken from GitLab.com's documentation; it was not checked
  against this project's runner settings, and no GitLab shard duration has been measured.
- The driver-branch and skip scans are textual. They are not execution evidence.
