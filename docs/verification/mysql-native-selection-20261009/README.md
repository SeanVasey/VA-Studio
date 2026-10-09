# MySQL native selection for Foundation CI — 2026-10-09

Branch `harness/mysql-native-selection` is based on main `0f9b39ce5d59e2d579d11a8ca15153ced1f565b4`.
The earlier commits are `78f8a7b` and `23ff88e`. This revision applies conditions C1 to C4 from the
independent review of `23ff88e`, whose decision was APPROVE WITH CONDITIONS.

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
| SQLite (2 shards) | Complete suite; the 623 reviewed census cases skip | 7,633 listed, 7,010 executed |
| MySQL (8 GitHub / 4 GitLab shards) | Native selection; zero skips required | 1,703 in 158 files |

The selection is defined in `scripts/ci/database-mysql-selection.json`. It takes whole files, so every
case in a selected file runs, including methods the census does not list. A file is selected if any
of the following holds:

| Rule | Files | Cases |
| --- | --- | --- |
| Owns a (class, method) pair in `scripts/ci/database-sqlite-skips.json` (217 pairs in 101 classes) | 101 | 932, of which 623 are census cases |
| Path matches `^tests/(?:Feature\|Unit)/(?:[A-Za-z0-9]+/)*[A-Za-z0-9]*Migration[A-Za-z0-9]*Test\.php$` | 49 | 589 |
| Listed in the reviewed `include_files` (added for C1) | 12 | 269 |
| All selected files (4 files match both of the first two rules) | 158 | 1,703 |

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

A static scan found no `markTestSkipped` call in these 12 files or anywhere in `tests/Support`.

**`tests/Feature/FreeGrantSchemaRecoveryTest.php` (8 cases) is deliberately left out**, although the
review named it. Its method `test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target`
calls `markTestSkipped` when the driver is not SQLite, so MySQL's zero-skip rule would reject the
shard that ran it. It stays unselected until that method moves to its own file or a reviewed
MySQL-skip policy exists.

## Residual risk pending Sean's decision

The selection still finds MySQL-only behaviour only where SQLite skips it, where the test file is a
migration test, or where the include list names the file. The review found 61 unselected files
(936 cases) that branch on `getDriverName()`, `ATTR_DRIVER_NAME` or `['driver']`. Eleven of them are
now in the include list. `SchemaQualifierDelimiterTest` is the twelfth included file and was not
among the 61.

**That leaves 50 files (672 cases).** They run on SQLite through a fallback branch and do not run on
MySQL in Foundation CI. The coordinator's request said 48; the correct count is 61 − 11 = 50, and it
includes the deliberately excluded `FreeGrantSchemaRecoveryTest`. None of the 50 were added:

| File | Cases |
| --- | --- |
| `tests/Feature/CustomerConsentAdmissionTest.php` | 22 |
| `tests/Feature/CustomerListeningFreshnessTest.php` | 17 |
| `tests/Feature/CustomerListeningNotesCapacityTest.php` | 3 |
| `tests/Feature/CustomerSuppressionRetainedTargetTest.php` | 2 |
| `tests/Feature/CustomerSuppressionSourceBoundaryTest.php` | 7 |
| `tests/Feature/DiscoveryEpochRecoveryTest.php` | 18 |
| `tests/Feature/FinalizationDatabaseLifecycleTest.php` | 4 |
| `tests/Feature/FreeGrantAuthorityTest.php` | 6 |
| `tests/Feature/FreeGrantDownloadsTest.php` | 4 |
| `tests/Feature/FreeGrantSchemaRecoveryTest.php` | 8 |
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
| `tests/Feature/ServiceSupportAttachmentsTest.php` | 8 |
| `tests/Feature/SiteContentDamagedPublicationTest.php` | 4 |
| `tests/Feature/SupportAttachmentRegistrationTest.php` | 12 |
| `tests/Feature/SupportAttachmentsTest.php` | 28 |
| `tests/Feature/TestPaymentExceptionOperationsTest.php` | 25 |
| `tests/Feature/TestUnpaidReleaseTest.php` | 46 |

In total, 397 of the 555 files (5,930 of the 7,633 cases) no longer run on MySQL in Foundation CI.
They still run on SQLite.

## MySQL skip signals (C2)

### Signals the selection no longer raises

These four files skip on MySQL. All four are now unselected:

- `tests/Feature/SupportAttachmentsTest.php` (28 cases) and
  `tests/Feature/ServiceSupportAttachmentsTest.php` (8 cases). In both, `setUp` skips every case on
  MySQL unless `ATTACHMENT_NATIVE_ISOLATED=1`, and neither workflow sets that variable.
- `CustomerListeningFreshnessTest::test_framework_reads_cannot_use_a_temporary_catalog_shadow_while_proof_reads_main`,
  which runs only on SQLite and skips on MySQL.
- `FreeGrantSchemaRecoveryTest::test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target`,
  which also runs only on SQLite and skips on MySQL.

On main, these files are part of the complete MySQL partition, and the receipts' MySQL zero-skip rule
would have rejected their skips. No hosted run reached them, because run 37921309772 timed out first.
With the selection they never run on MySQL, so that failure signal disappears. SQLite still executes
these cases, so no case goes unexecuted on both engines.

**Open release blocker: native attachment-consumer coverage.** Foundation CI has no executed MySQL
evidence for the MySQL paths of `SupportAttachmentsTest` and `ServiceSupportAttachmentsTest`. Closing
the gap needs a reviewed, isolated MySQL run with `ATTACHMENT_NATIVE_ISOLATED=1`, or a selection
change that brings that environment with it.

### Selected files that still skip on MySQL (new evidence; blocks a green MySQL matrix)

I scanned the test-method bodies, and the helpers they call, in every selected file. The scan found
56 discovered cases that call `markTestSkipped` when the driver is not SQLite. None of them comes from
the C1 include list.

| File | How selected | Skipping cases |
| --- | --- | --- |
| `CustomerListeningMigrationTest` | pattern | `test_foreign_or_drifted_objects_are_never_adopted_or_dropped` (3), `test_temporary_shadow_is_never_adopted_or_dropped` (1) |
| `CustomerSuppressionMigrationTest` | pattern | `test_missing_identity_dependency_and_disabled_fk_enforcement_are_refused_without_installing` (1), `test_sqlite_replace_cannot_delete_retained_target_or_intent` (1) |
| `DiscoverySitemapNamespaceAdmissionTest` | census | `test_sqlite_named_inline_check_and_fk_clauses_do_not_reserve_foreign_index_names` (1) |
| `PaidGrantSchemaRecoveryTest` | census | `test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target` (1) |
| `ProductionCheckoutMigrationTest` | pattern | `test_sqlite_duplicate_table_and_trigger_namespace_cannot_hide_a_foreign_marker` (1) |
| `ProductionFreeGrants/ProductionFreeGrantSchemaTest` | census | Three tests that call the `sqliteOnlyRecovery()` helper (37, 1 and 1) |
| `ProductionTaxCheckout/ProductionTaxCheckoutMigrationTest` | pattern | `test_migration_installs_exactly_four_tables_and_twelve_guards_and_a_rerun_is_a_no_op` (1), `test_installer_refuses_drift_gaps_populated_partials_shadows_and_foreign_references` (7) |

The selection did not introduce these skips: the same files are in main's complete MySQL partition,
so main would have been rejected the same way. The selection does not hide them either, because the
MySQL receipts will still reject the shards that run them. A complete green MySQL run therefore needs
one of two reviewed changes, neither of which is made here:

- move the SQLite-only methods into SQLite-only files; or
- add a reviewed MySQL-skip census that mirrors the SQLite one.

The scan is textual. A skip reached through a trait or an indirect helper could be missing from it.

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
  complete inventory and the committed policies. The manifest's block must equal the recomputed one.
- The shards must cover the selection exactly once, any untimed files must be selected files, and
  MySQL skips must be zero.
- SQLite verification is unchanged.

**Collectors.** Both the GitHub and GitLab collectors require all of the following:

- SQLite executed every source case exactly once.
- MySQL executed exactly the recomputed selection, each case once.
- The summed MySQL `executed_cases` equals the selection size.
- Every SQLite skip appears in the set of cases MySQL executed.

**Workflows.** The GitHub partition step is named `Prove the native-selection MySQL test partition`.
In `.gitlab-ci.yml`, only the MySQL jobs pass the flag.

## Real discovery (no database)

All commands ran in `/home/user/wt-mysel` with PHP 8.4.26, doing PHPUnit discovery only.

```
python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql \
  --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection        # rc 0
warning: 73 test file(s) have no entry in scripts/ci/phpunit-timings-mysql.json and use the fallback weight; ...
Proved 1703 selected MySQL-native tests in 158 files (of 7633 discovered tests in 555 files) across 8 nonempty shards.
Shard 1: 251 tests; 23 complete files; estimated 18m 33s.
Shard 2: 195 tests; 19 complete files; estimated 18m 37s.
Shard 3: 187 tests; 19 complete files; estimated 18m 36s.
Shard 4: 201 tests; 19 complete files; estimated 18m 36s.
Shard 5: 218 tests; 20 complete files; estimated 18m 36s.
Shard 6: 212 tests; 19 complete files; estimated 18m 34s.
Shard 7: 213 tests; 19 complete files; estimated 18m 33s.
Shard 8: 226 tests; 20 complete files; estimated 18m 37s.
```

With `--shards=4` (the GitLab count) the run returned rc 0 and proved 1,703 cases across shards of
463, 412, 398 and 430 cases, each estimated at about 37 minutes from the timings file.

Both shard counts produced the same manifest `selection` block:

| Field | Value |
| --- | --- |
| `policy_sha256` | `c14e02a9c0b2a2d00694fd62f2709c9f39e7bbd6e51990461fc666f12bef0338` |
| `sqlite_skip_policy_sha256` | `9080acdabcfc425ac37dcebee23c9f9be00bcd5a6790404977ba41c6994569b0` |
| `files` / `test_cases` | 158 / 1,703 |
| `case_identity_sha256` | `113715a08fb83c6a252e4e7f937a1e7e598425aa46d93aedf41cc25cafc9dd6d` |

**Cross-checks against the verifier.**

- `database_evidence` accepted the real 8-shard manifest and inventories, using a synthesized passing
  JUnit file for shard 1 (251 cases). Its own recomputation gave 158 files and 1,703 cases.
- It refused the real unflagged 8-shard MySQL partition.
- It refused the earlier `78f8a7b` selection, which has no include list, with
  `Partition loses or duplicates cases, files or groups`.

**Unflagged output is unchanged.** Run without the flag, the new script and main's script
(`git show 0f9b39c:scripts/ci/phpunit-shards.py`) both returned rc 0, and `diff -r` found all 18
MySQL 8-shard files and all 6 SQLite 2-shard files byte-identical. `--mysql-native-selection` with
`--prefix=phpunit-ci-sqlite` was refused with rc 1. Every generated `phpunit-ci-*` file was deleted
afterwards.

### Duration estimates (C3)

The estimates printed from the timings file are not reliable. `phpunit-timings-mysql.json` predates
run 37921309772 and has no entry for 73 of the 158 selected files; the timings were not regenerated.
Of the selected cases, 1,355 use `FinalizationDatabaseMigrations`. At the roughly 35 s per case
measured on GitHub:

| Matrix | Finalization cases per shard | Estimated minutes at 35 s (finalization cases only) | Job limit |
| --- | --- | --- | --- |
| GitHub, 8 shards | 199, 192, 183, 149, 189, 144, 177, 122 | about 116, 112, 107, 87, 110, 84, 103, 71 | 150 min |
| GitLab, 4 shards | 337, 324, 349, 345 | about 197, 189, 204, 201 | 240 min (was 90) |

The remaining selected cases per shard are 52, 3, 4, 52, 29, 68, 36 and 104 on GitHub, and 126, 88,
49 and 85 on GitLab. Their time is not included in the estimates.

- **GitLab.** The new 240-minute limit covers the estimate with some headroom. However, GitLab.com
  documents a 3-hour maximum for its hosted runners, and that cap would cancel these shards before
  they finish. I have not verified this cap for the project's runners, and GitLab speed is
  unmeasured. On hosted runners, a reviewed change to 8 GitLab MySQL shards would likely be needed.
- **GitHub.** The 150-minute limit leaves about 30% headroom over the busiest shard's estimate.

## Self-tests

| Command | Result |
| --- | --- |
| `python3 scripts/ci/test-phpunit-shards.py` | rc 0, 47 tests |
| `python3 scripts/ci/test-database-receipts.py` | rc 0, 48 tests |
| `python3 scripts/ci/test-gitlab-database-receipts.py` | rc 0, 27 tests |
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

## Not verified

- No MySQL test has executed the selection, locally or hosted, so pass/fail and real durations are
  unknown. The 56 selected cases that skip on MySQL are expected to fail the zero-skip receipt until
  they are addressed.
- The collectors ran only against synthetic fixtures.
- The GitLab hosted-runner timeout cap was not checked against this project's runner settings.
- The driver-branch and skip scans are textual. They are not execution evidence.
