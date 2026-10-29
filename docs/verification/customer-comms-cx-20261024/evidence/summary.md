# Lane CX evidence summary (copied from the lane scratchpad; raw logs not committed)

## Final: merged head `0bd4519c` (main `a8f17e5c` merged; fixes `dce310cd`), 2026-10-29

### Native MySQL 8.4.11 (private server, fresh datadir, port 3467)
- legal-a 15:34:01 HEAD=0bd4519c dirty=0: OK (3 tests, 26 assertions) exit=0 15:46:00
- legal-b 15:46:00 HEAD=0bd4519c dirty=0: OK (2 tests, 11 assertions) exit=0 15:57:05
- rights-mig 15:57:05 HEAD=0bd4519c dirty=0: OK (2 tests, 10 assertions) exit=0 16:08:19
- race-a 16:08:19 HEAD=0bd4519c dirty=0: OK (2 tests, 171 assertions) exit=0 16:16:15
- race-b 16:16:15 HEAD=0bd4519c dirty=0: OK (2 tests, 101 assertions) exit=0 16:24:56
- guards 16:24:56 HEAD=0bd4519c dirty=0: OK (2 tests, 27 assertions) exit=0 16:34:29

Chunks: legal-a = `LegalSiteSchemaMigrationTest` migrated_schema, late_image, down_refuses; legal-b = round_trip, foreign_or_missing; rights-mig = `CustomerDataRequestMigrationTest`; race-a/race-b = `CustomerDataRightsConcurrencyTest` (4); guards = the `database_guards` tests in `CustomerDataRightsTest` and `TransactionalMailTest`. 13 of 13 pass.

### SQLite on `0bd4519c` (direct PHPUnit, `:memory:`)

- `tests/Feature/CustomerComms`: OK, but some tests were skipped! Tests: 96, Assertions: 765, Skipped: 4.
- `tests/Feature/CustomerAccountMigrationTest.php`: OK (7 tests, 561 assertions)
- `tests/Feature/CustomerConsentMigrationTest.php`: OK (20 tests, 198 assertions)
- `tests/Feature/CustomerInquiryAdminTest.php`: OK (9 tests, 117 assertions)
- `tests/Feature/CustomerInquiryAdministrationConcurrencyTest.php`: OK, but some tests were skipped! Tests: 43, Assertions: 181, Skipped: 39.
- `tests/Feature/CustomerInquiryConcurrencyTest.php`: OK, but some tests were skipped! Tests: 11, Assertions: 0, Skipped: 11.
- `tests/Feature/CustomerInquiryHttpTest.php`: OK (99 tests, 3193 assertions)
- `tests/Feature/CustomerInquiryMigrationTest.php`: OK, but some tests were skipped! Tests: 27, Assertions: 287, Skipped: 3.
- `tests/Feature/CustomerListeningMigrationTest.php`: OK, but some tests were skipped! Tests: 13, Assertions: 37, Skipped: 4.
- `tests/Feature/CustomerPurchaseClaimMigrationTest.php`: OK (8 tests, 37 assertions)
- `tests/Feature/FoundationTest.php`: OK (32 tests, 216 assertions)
- `tests/Feature/HostedCheckoutMigrationTest.php`: OK (8 tests, 138 assertions)
- `tests/Feature/InstallationReportTest.php`: OK (34 tests, 153 assertions)
- `tests/Feature/OrderPreparationMigrationTest.php`: OK (2 tests, 56 assertions)
- `tests/Feature/PromotionMigrationTest.php`: OK (1 test, 33 assertions)
- `tests/Feature/PublicPagesSitemapTest.php`: OK (18 tests, 50 assertions)
- `tests/Feature/QuotePricingMigrationTest.php`: OK (1 test, 35 assertions)
- `tests/Feature/SharedInventoryMigrationTest.php`: OK (1 test, 43 assertions)
- `tests/Feature/SiteContentConcurrencyTest.php`: OK, but some tests were skipped! Tests: 4, Assertions: 0, Skipped: 4.
- `tests/Feature/SiteContentDamagedPublicationTest.php`: OK (4 tests, 59 assertions)
- `tests/Feature/SiteContentHttpTest.php`: OK (16 tests, 206 assertions)
- `tests/Feature/SiteContentTest.php`: OK (11 tests, 54 assertions)
- `tests/Feature/SiteContentUnavailableHttpTest.php`: OK (7 tests, 364 assertions)
- `tests/Feature/SiteEditorialContentTest.php`: OK (10 tests, 118 assertions)
- `tests/Feature/SiteEditorialHttpTest.php`: OK (11 tests, 695 assertions)
- `tests/Feature/SiteImageConcurrencyTest.php`: OK, but some tests were skipped! Tests: 6, Assertions: 0, Skipped: 6.
- `tests/Feature/SiteImageEditorTest.php`: OK (6 tests, 92 assertions)
- `tests/Feature/SiteImageHttpTest.php`: OK (10 tests, 279 assertions)
- `tests/Feature/SiteImageLibraryTest.php`: OK (32 tests, 1290 assertions)
- `tests/Feature/SiteImageMigrationTest.php`: OK (1 test, 8 assertions)
- `tests/Feature/SiteImagePublicTest.php`: OK (8 tests, 1115 assertions)
- `tests/Feature/SiteImageReleaseDamageTest.php`: OK (6 tests, 28 assertions)
- `tests/Feature/SiteImageReleaseTest.php`: OK (7 tests, 59 assertions)
- `tests/Feature/SiteRelatedTrackContentTest.php`: OK (34 tests, 68 assertions)
- `tests/Feature/SiteRelatedTrackDamageTest.php`: OK (3 tests, 17 assertions)
- `tests/Feature/SiteRelatedTrackEditorTest.php`: OK (10 tests, 58 assertions)
- `tests/Feature/SiteRelatedTrackHttpTest.php`: OK (4 tests, 73 assertions)
- `tests/Feature/SiteRelatedTrackImageMigrationTest.php`: OK (37 tests, 335 assertions)
- `tests/Feature/SiteScheduleConcurrencyTest.php`: OK, but some tests were skipped! Tests: 5, Assertions: 0, Skipped: 5.
- `tests/Feature/SiteScheduleHttpTest.php`: OK (18 tests, 220 assertions)
- `tests/Feature/SiteScheduleRunnerTest.php`: OK (10 tests, 56 assertions)
- `tests/Feature/SiteScheduleTest.php`: OK (18 tests, 73 assertions)
- `tests/Feature/StorefrontMetadataTest.php`: OK (7 tests, 150 assertions)
- `tests/Feature/TestContractIssuanceMigrationTest.php`: OK (13 tests, 172 assertions)
- `tests/Feature/TestFulfillmentActivationMigrationTest.php`: OK (8 tests, 81 assertions)
- `tests/Feature/TestOrderFinalizationMigrationTest.php`: OK (15 tests, 237 assertions)
- `tests/Feature/TestPaymentEvidenceMigrationTest.php`: OK (20 tests, 205 assertions)
- `tests/Feature/TransactionalNotificationConcurrencyTest.php`: OK, but some tests were skipped! Tests: 6, Assertions: 0, Skipped: 6.
- `tests/Feature/TransactionalNotificationMigrationTest.php`: OK (20 tests, 270 assertions)
- `tests/Feature/TransactionalNotificationRecoveryCommandTest.php`: OK (11 tests, 46 assertions)
- `tests/Feature/TransactionalNotificationRecoveryTest.php`: OK (10 tests, 46 assertions)
- `tests/Feature/TransactionalNotificationTest.php`: OK (52 tests, 334 assertions)
- `tests/Unit/ProductionEnvironmentTemplateTest.php`: OK (6 tests, 1829 assertions)
- `tests/Unit/StagingRuntimeValidatorTest.php`: OK (83 tests, 503 assertions)
- `tests/Unit/TestCommerceProfileValidatorTest.php`: OK (66 tests, 599 assertions)

Totals: 55 files, 1025 tests, 15839 assertions, 82 skips (all reviewed MySQL-only census methods), 0 failures or errors.

### Frontend and safeguards on `0bd4519c`

- `npx tsc --noEmit`: exit 0
- `npx vitest run`: 70 files, 1177 tests passed
- scripts/ci/test-phpunit-shards.py: Ran 52 tests in 0.385s OK
- scripts/ci/test-ci-scope.py: Ran 27 tests in 5.121s OK
- scripts/ci/test-workflow-cadence.py: Ran 14 tests in 0.014s OK
- scripts/ci/test-focused-tests.py: Ran 53 tests in 0.107s OK
- scripts/ci/test-database-receipts.py: Ran 61 tests in 46.879s OK
- tests/ops/test_staging_capability_profile.py: Ran 18 tests in 0.117s OK
- tests/ops/test_staging_code_drift.py: Ran 10 tests in 0.161s OK
- tests/ops/test_production_kit.py: Ran 50 tests in 4.736s OK
- tests/ops/test_production_runtime_validator.py: Ran 28 tests in 24.393s OK
- `composer validate --strict`: valid
- Native selection: Proved 2636 selected MySQL-native tests in 254 files (of 9874 discovered tests in 725 files); SQLite skip census 306 entries (main's 302 first, CX's 4 appended).

### Mutations (each must make its tests fail)

- M1 approval reference projected publicly KILLED FAILURES! | Tests: 2, Assertions: 29, Failures: 2. (at `70e909f0`)
- M2 markup allowed in legal text KILLED FAILURES! | Tests: 27, Assertions: 27, Failures: 2. (at `70e909f0`)
- M3 legal page served from an unpublished slot check removed KILLED FAILURES! | Tests: 2, Assertions: 77, Failures: 1. (at `70e909f0`)
- M4 export selects every order KILLED FAILURES! | Tests: 1, Assertions: 1, Failures: 1. (at `70e909f0`)
- M5 download token not checked KILLED FAILURES! | Tests: 1, Assertions: 6, Failures: 1. (at `70e909f0`)
- M6 download not consumed KILLED FAILURES! | Tests: 1, Assertions: 10, Failures: 1. (at `70e909f0`)
- M7 export bound to account removed KILLED FAILURES! | Tests: 1, Assertions: 4, Failures: 1. (at `70e909f0`)
- M8 retention unset accepted KILLED FAILURES! | Tests: 1, Assertions: 6, Failures: 1. (at `70e909f0`)
- M9 enrolled authenticator not required KILLED FAILURES! | Tests: 1, Assertions: 3, Failures: 1. (at `70e909f0`)
- M10 approval leaves customer access active KILLED FAILURES! | Tests: 1, Assertions: 2, Failures: 1. (at `70e909f0`)
- M11 mail sender binding to preflight facts removed KILLED FAILURES! | Tests: 1, Assertions: 1, Failures: 1. (at `70e909f0`)
- M12 real mailer admitted in testing KILLED FAILURES! | Tests: 1, Assertions: 4, Failures: 1. (at `70e909f0`)
- M13 send-once state check removed KILLED ERRORS! | Tests: 1, Assertions: 19, Errors: 1. (at `70e909f0`)
- M14 withdrawn authority not rechecked before send KILLED FAILURES! | Tests: 1, Assertions: 4, Failures: 1. (at `70e909f0`)
- M15 delivery guard no longer proves its notice KILLED (at `dce310cd`, `TransactionalMailTest` database_guards: 1 failure)
- M16 roundtrip keeps the mail child while the notification family is rebuilt KILLED (at `dce310cd`, `QuotePricingMigrationTest`: 1 error)

## Earlier run on `70e909f0` (superseded)

Native 10 of 12 then (the `CustomerDataRequestMigrationTest` trigger-order assertion failed on MySQL); SQLite roundtrips `QuotePricing`, `OrderPreparation` and `HostedCheckout` errored. Both fixed in `dce310cd`; see the packet.
