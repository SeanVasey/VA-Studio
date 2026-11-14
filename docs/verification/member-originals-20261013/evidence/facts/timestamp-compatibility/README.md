# Actual catalog timestamp spelling compatibility

The first composed real-producer canary (owned by the operation lane) failed before invoking preparation because this new member document validator accepted UTC whole-second timestamps only. `CurrentSelection::load` delegates to `PreparationSelection::interpret`; `PreparationSelection.php:225` projects retained license timestamps through its private pure `iso()` helper at line323. That helper uses Carbon `toISOString()`, which returns six fractional digits. The original catalog values and hashes must not be normalized or rewritten.

This component test invokes that actual pure helper for approved_at/effective_from/effective_until and passes the resulting values through the real complete member definition reader. It is a pure formatter/document compatibility test, not a catalog/database/publication proof. No DB-backed bodies or native workload ran in this correction.

Old production source: `5d6c58da50267a636e393ce3754ef473516923aa`. The only worktree addition at the old run was the new test; old-source.json captures that state and the affected dependencies. Exact external test bytes are retained. Old result: **1 test, 3 assertions, 1 intended refusal failure, zero errors/skips, exit1,0.023s**. The test catches the actual `MemberGrantException('invalid_document')` and fails on that refusal, rather than relying on a missing API.

Fixed commit: `a7265e8c` (full SHA and all11,505 tracked hashes in final-source.json). Correction admits exact UTC whole-second or six-microsecond spellings only, requires DateTimeImmutable roundtrip for valid calendar/time fields, and compares effective bounds by instant rather than lexical spelling. It never replaces the retained input strings. `MemberGrantRecords::utc()` still requires exactly19 database timestamp bytes before calling the validator.

Fixed result using the same external test file plus whole previous documents/records files: **51 tests,95 assertions,zero failures/errors/skips,exit0,0.288s**. The21 timestamp cases cover actual producer spelling/full-document+license hashes, mixed precision, nullable effective endpoints, equal/reversed instants, noncanonical precision/offset/case/separator, invalid dates/times and trailing newline/NUL. Prior documents/records account for30/66 of that selection. PHP8.4.26, PHPUnit12.5.34. Pint on the two changed files passed; both changed files passed php-l, and git diff--check was clean. External-to-repository diff retains formatting/import differences (if any); production fixed source equals committed bytes. Existing native schema and E9 facts source is unchanged.

Commands from `/workspace/VA-Studio-member-original-facts`, after `source /workspace/.va-studio-toolchain/activate.sh`:

```sh
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php -r 'require "vendor/autoload.php"; exit((new PHPUnit\TextUI\Application)->run(["phpunit", "--colors=never", "--filter", "test_actual_catalog_timestamp_spelling_is_retained_without_changing_hashes", "--log-junit", "/workspace/runtime/member-original-facts-evidence/timestamp-compatibility/old.xml", "/workspace/runtime/member-original-facts-evidence/timestamp-compatibility/MemberGrantDocumentTimestampTest.php"]));'
php -r 'require "vendor/autoload.php"; require "vendor/laravel/pint/builds/pint";' -- app/Domain/Grants/Member/MemberGrantDocumentValues.php tests/Unit/ProductionMemberOriginals/MemberGrantDocumentTimestampTest.php
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php -r 'require "vendor/autoload.php"; exit((new PHPUnit\TextUI\Application)->run(["phpunit", "--colors=never", "--stop-on-failure", "--stop-on-error", "--log-junit", "/workspace/runtime/member-original-facts-evidence/timestamp-compatibility/fixed.xml", "/workspace/runtime/member-original-facts-evidence/timestamp-compatibility/MemberGrantDocumentTimestampTest.php", "tests/Unit/ProductionMemberOriginals/MemberGrantDocumentsTest.php", "tests/Unit/ProductionMemberOriginals/MemberGrantRecordsTest.php"]));'
php -l app/Domain/Grants/Member/MemberGrantDocumentValues.php
php -l tests/Unit/ProductionMemberOriginals/MemberGrantDocumentTimestampTest.php
git diff --check
```

Initial readonly discovery mistyped the source directory as `app/Services/Production/MemberGrants/MemberGrantDocumentValues.php` and returned file-not-found (exit2); subsequent rg resolved the actual `app/Domain/Grants/Member/` location. That discovery error was unrelated to test execution. No secrets, environment files or database files are released.
