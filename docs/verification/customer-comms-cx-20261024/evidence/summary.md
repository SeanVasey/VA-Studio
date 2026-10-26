# Lane CX evidence summary (copied from the lane scratchpad; raw logs not committed)

## Native MySQL 8.4.11 chunks on 70e909f0 (private server, port 3467)
- legal-a 00:46:49 HEAD=70e909f0 dirty=0: OK (2 tests, 9 assertions)  exit=0 00:57:30
- legal-b 00:57:30 HEAD=70e909f0 dirty=1: OK (2 tests, 11 assertions)  exit=0 01:06:28
- rights-mig 01:06:28 HEAD=70e909f0 dirty=1: Tests: 2, Assertions: 4, Failures: 1.  exit=1 01:15:09
- race-a 01:15:09 HEAD=70e909f0 dirty=1: OK (2 tests, 71 assertions)  exit=0 01:21:02
- race-b 01:21:02 HEAD=70e909f0 dirty=1: OK (2 tests, 75 assertions)  exit=0 01:27:27
- guards 01:27:27 HEAD=70e909f0 dirty=1: OK (2 tests, 27 assertions)  exit=0 01:32:35

## Mutations (SQLite, mutation worktree at 70e909f0; each must fail its tests)
- M1 approval reference projected publicly KILLED FAILURES! | Tests: 2, Assertions: 29, Failures: 2.
- M2 markup allowed in legal text KILLED FAILURES! | Tests: 27, Assertions: 27, Failures: 2.
- M3 legal page served from an unpublished slot check removed KILLED FAILURES! | Tests: 2, Assertions: 77, Failures: 1.
- M4 export selects every order KILLED FAILURES! | Tests: 1, Assertions: 1, Failures: 1.
- M5 download token not checked KILLED FAILURES! | Tests: 1, Assertions: 6, Failures: 1.
- M6 download not consumed KILLED FAILURES! | Tests: 1, Assertions: 10, Failures: 1.
- M7 export bound to account removed KILLED FAILURES! | Tests: 1, Assertions: 4, Failures: 1.
- M8 retention unset accepted KILLED FAILURES! | Tests: 1, Assertions: 6, Failures: 1.
- M9 enrolled authenticator not required KILLED FAILURES! | Tests: 1, Assertions: 3, Failures: 1.
- M10 approval leaves customer access active KILLED FAILURES! | Tests: 1, Assertions: 2, Failures: 1.
- M11 mail sender binding to preflight facts removed KILLED FAILURES! | Tests: 1, Assertions: 1, Failures: 1.
- M12 real mailer admitted in testing KILLED FAILURES! | Tests: 1, Assertions: 4, Failures: 1.
- M13 send-once state check removed KILLED ERRORS! | Tests: 1, Assertions: 19, Errors: 1.
- M14 withdrawn authority not rechecked before send KILLED FAILURES! | Tests: 1, Assertions: 4, Failures: 1.

## SQLite related-suite run (CustomerComms dir plus touched suites)
    == tests/Feature/CustomerComms
    OK, but some tests were skipped!
    Tests: 96, Assertions: 765, Skipped: 4.
    == tests/Feature/CustomerAccountMigrationTest.php
    OK (7 tests, 561 assertions)
    == tests/Feature/CustomerConsentMigrationTest.php
    OK (20 tests, 198 assertions)
    == tests/Feature/CustomerListeningMigrationTest.php
    OK, but some tests were skipped!
    Tests: 13, Assertions: 37, Skipped: 4.
    == tests/Feature/CustomerListeningPrimaryProofTest.php
    OK (18 tests, 72 assertions)
    == tests/Feature/CustomerPurchaseClaimConcurrencyTest.php
    OK, but some tests were skipped!
    Tests: 3, Assertions: 0, Skipped: 3.
    == tests/Feature/CustomerPurchaseClaimMigrationTest.php
    OK (8 tests, 37 assertions)
    == tests/Feature/CustomerPurchaseClaimTest.php
    OK (31 tests, 338 assertions)
    == tests/Feature/CustomerSuppressionKeyAdmissionTest.php
    OK, but some tests were skipped!
    Tests: 19, Assertions: 133, Skipped: 2.
    == tests/Feature/CustomerSuppressionMigrationTest.php
    OK, but some tests were skipped!
    Tests: 31, Assertions: 134, Skipped: 2.
    == tests/Feature/CustomerSuppressionNativeAdmissionTest.php
    OK, but some tests were skipped!
    Tests: 1, Assertions: 0, Skipped: 1.
    == tests/Feature/CustomerSuppressionRetainedTargetTest.php
    OK (2 tests, 8 assertions)
    == tests/Feature/CustomerSuppressionSourceBoundaryTest.php
    OK (7 tests, 90 assertions)
    == tests/Feature/CustomerSuppressionTest.php
    OK (34 tests, 298 assertions)
    == tests/Feature/DiscoveryEpochMigrationTest.php
    OK (9 tests, 42 assertions)
    == tests/Feature/HostedCheckoutMigrationTest.php
    1) Tests\Feature\HostedCheckoutMigrationTest::test_empty_checkout_tables_roundtrip_without_rewriting_existing_prepared_order_evidence
    Tests: 8, Assertions: 108, Errors: 1.
    == tests/Feature/MembershipCreditMigrationTest.php
    OK (38 tests, 115 assertions)
    == tests/Feature/OrderPreparationMigrationTest.php
    1) Tests\Feature\OrderPreparationMigrationTest::test_empty_order_tables_roundtrip_without_changing_existing_pending_inventory_and_pricing
    Tests: 2, Assertions: 36, Errors: 1.
    == tests/Feature/ProductionBuyerAssentObservationMigrationTest.php
    OK (4 tests, 24 assertions)
    == tests/Feature/QuotePricingMigrationTest.php
    1) Tests\Feature\QuotePricingMigrationTest::test_additive_migration_roundtrip_keeps_existing_quote_evidence
    Tests: 1, Assertions: 21, Errors: 1.
    == tests/Feature/SiteImageMigrationTest.php
    OK (1 test, 8 assertions)
    == tests/Feature/SiteRelatedTrackImageMigrationTest.php
    OK (37 tests, 335 assertions)
    == tests/Feature/TransactionalNotificationConcurrencyTest.php
    OK, but some tests were skipped!
    Tests: 6, Assertions: 0, Skipped: 6.
    == tests/Feature/TransactionalNotificationMigrationTest.php
    OK (20 tests, 270 assertions)
    == tests/Feature/TransactionalNotificationRecoveryCommandTest.php
    OK (11 tests, 46 assertions)
    == tests/Feature/TransactionalNotificationRecoveryTest.php
    OK (10 tests, 46 assertions)
    == tests/Feature/TransactionalNotificationTest.php

## Safeguards on 70e909f0
    == test-phpunit-shards.py
    Ran 52 tests in 1.113s
    FAILED (failures=1)
    == test-ci-scope.py
    Ran 27 tests in 8.508s
    OK
    == test-workflow-cadence.py
    Ran 14 tests in 0.018s
    OK
    == test-focused-tests.py
    Ran 53 tests in 0.152s
    OK
    == test-database-receipts.py
    Ran 61 tests in 64.164s
    OK
    test-phpunit-shards.py rerun after adding the worker to branching_helper_files: 52 OK

## Native selection
Proved 2614 selected MySQL-native tests in 253 files (of 9801 discovered tests in 721 files) across 8 nonempty shards.
