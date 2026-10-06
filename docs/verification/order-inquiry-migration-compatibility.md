# Legacy rollback compatibility for order inquiries

This test-only child composes final candidate `a3cc2e45a31246f3020b5e476d6f3a51c9d143e4` with backend `1f85e964b98fef4ce5e3364d33d7f07081e66198` at merge `63e5712c0983afac581cb2b4047e193a19645069`. The new `2026_10_06_000049_inquiry_order_contexts.php` migration has restrictive foreign keys to `orders` and `customer_inquiries`. A legacy test that removes either parent must first remove the empty additive child, just as the migrator would.

Repository-wide searches of migration filenames, direct table drops, dynamic drop loops and rollback commands identified five affected existing test files. The compatibility patch adds exactly thirteen executable lines:

| Existing test | Dependency adjustment |
| --- | --- |
| OrderPreparationMigrationTest | Down 49 before the existing 48 → 47 → earlier-child chain; up 49 after 48 and the rebuilt order parent |
| SharedInventoryMigrationTest | Same child-first rollback and parent-first restoration |
| PromotionMigrationTest | Same child-first rollback and parent-first restoration |
| QuotePricingMigrationTest | Same child-first rollback and parent-first restoration |
| CustomerInquiryMigrationTest | Setup drops empty 49 before the existing empty 43 child, allowing the original 31 parent-schema tests to run |

The inquiry migration test already isolates its historical parent by removing the message child in setup; it intentionally leaves both later children absent for its parent-specific installation, interruption, retention and foreign-schema checks. The existing disposable lifecycle wipes that test database after each case. No operational migration, production schema, business code or migration guard is changed here.

Deleting only the thirteen added lines reproduces all five original files byte-for-byte against `a3cc2e4`. Every existing assertion, fixture, method identity, data provider and guard check is preserved. Checkout/payment/finalization-only rollback tests do not remove either new parent and need no change. No foreign-key disabling, new test identity, skip, registry, timeout, concurrency setting or selector-cap change was introduced.

## Bounded validation

The exact six selected existing methods were:

- `OrderPreparationMigrationTest::test_empty_order_tables_roundtrip_without_changing_existing_pending_inventory_and_pricing`
- `SharedInventoryMigrationTest::test_empty_inventory_tables_roundtrip_without_changing_quotes`
- `PromotionMigrationTest::test_empty_new_tables_can_roundtrip_without_rewriting_earlier_quote_or_pricing_evidence`
- `QuotePricingMigrationTest::test_additive_migration_roundtrip_keeps_existing_quote_evidence`
- `CustomerInquiryMigrationTest::test_every_empty_rollback_prefix_and_absent_table_can_resume_without_changing_parent_evidence`
- `CustomerInquiryMigrationTest::test_empty_rollback_and_reapply_preserve_site_and_operator_evidence_and_restore_guards`

Both invocations used `php vendor/bin/phpunit`, the five explicit `tests/Feature/*MigrationTest.php` paths above, `--filter` containing the six method names joined with `|`, and `--log-junit` for the retained XML. SQLite used the ordinary test configuration. The MySQL invocation used the existing `mysql-runtime/run-tests.py --` wrapper to create a fresh private-loopback database with a synthetic application key.

- SQLite: **6/6 cases, 109 assertions**, zero failures/errors/skips, **8.71 seconds** in JUnit.
- MySQL **8.4.11**: **6/6 cases, 109 assertions**, zero failures/errors/skips, **52.98 seconds** in JUnit. The wrapper independently reported `innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, `innodb_doublewrite=ON`, and binary logging enabled. Foreign-key enforcement was left enabled.
- PHP syntax and `git diff --check` passed. The source-preservation proof and both raw XML/log pairs are retained in the task evidence under `order-inquiry-compat-*`.

These focused roundtrips prove the additive child ordering while retaining the original quote, pricing, inventory, promotion, site, operator and audit assertions. They do not substitute for the backend author's new migration guard/race tests or the final composed hosted gates.
