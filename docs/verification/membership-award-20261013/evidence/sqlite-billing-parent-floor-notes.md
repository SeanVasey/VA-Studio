# Billing parent-floor exception compatibility

Only product change after native batch-2: `BillingSchema.php` imports `MembershipException` and catches that exact type from delegated membership ownership. Only reason `parent_floor` is mapped to `BillingException(parent_floor)`; other membership reasons rethrow unchanged. The removed duplicate identity inspection remains removed. `MembershipSchema.php` and `MembershipSchemaCatalog.php` had no further edits.

There was no existing membership/billing schema test for this exact exception-class compatibility. Two runtime-only cases in `BillingParentFloorCompatibilityTest.php` were run alongside existing `BillingSchemaPreparationTest::test_fresh_schema_is_owned_without_identity_child_dependencies_and_retry_is_idempotent`.

Exact command and environment: `sqlite-billing-parent-floor.sh`. Result: **3 tests, 15 assertions, zero errors/failures/skips**, exit **0**, **5.624 seconds** on SQLite/PHP 8.4.26. The cases prove an absent final empty identity guard refuses with `BillingException(parent_floor)` without schema repair or billing writes, an unrelated temporary membership table refusal remains `MembershipException(shadowed_schema)` while retaining its marker, and existing fresh-schema ownership/retry stays idempotent. The four bound file hashes match before/after. Native behavior is not tested by these SQLite cases.

`php -l app/Domain/Memberships/Billing/BillingSchema.php` passed. `vendor/bin/pint --test app/Domain/Memberships/Billing/BillingSchema.php app/Domain/Memberships/Production/MembershipSchema.php app/Domain/Memberships/Production/MembershipSchemaCatalog.php` passed. `git diff --check` for the three owned files passed.

The first probe mistakenly removed `pi_verifications_insert`, which creates a guard hole and is correctly refused earlier by `IdentityMigrationOwnership` with `LogicException`. Initial run: **3 tests, 12 assertions, 1 error, zero failures/skips**, exit **2**, **4.879 seconds**. This was a runtime fixture mistake; the product was unchanged between runs. Initial test source/log/XML/command/hashes are retained with `-initial` filenames. Correcting the runtime probe to remove the final `pi_outcomes_delete` guard exercises the actual incomplete-empty-prefix path. No passing result is claimed for the initial run.

All three product files are released and frozen for root integration. No commits, native sessions, deadline changes, repository test edits, or wider optimizations were performed.
