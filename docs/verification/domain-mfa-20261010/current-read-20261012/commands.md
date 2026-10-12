# MFA batch execution record, 2026-10-12

All PHPUnit commands run in `/workspace/VA-Studio-mfa` after `source /workspace/.va-studio-toolchain/activate.sh`.
Private Oracle MySQL version read with `/workspace/runtime/bin/mysql -e 'SELECT VERSION();'`: **8.4.11**.
The wrapper uses Docker's `/run/mysql/mysql.sock`; PHP uses its host bind path below. An initial diagnostic
incorrectly passed the host socket path to the container wrapper and returned connection error 2002; the corrected
version query succeeded. This was not a test outcome.

Red application is unchanged from `cb69203e3c7947366ee8a75758cd2f0f927bcfed` (the #95 MFA implementation).
Corrected regression commit: `1c9b445243da2ae4fdb70d883e1bd67251d9341e`.
Fixed application and tests: `abe2956bef770bf8bedbe02e0c49500e9b2065da`.

Common MySQL command prefix:

```sh
env TMPDIR=/tmp/va-studio-importer-h1-20261012 APP_ENV=testing APP_DEBUG=false \
 DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33396 DB_USERNAME=root DB_PASSWORD= \
 DB_DATABASE=vaseyaudio_mfa DB_URL= DB_SOCKET=/workspace/runtime/mysql-run/mysql.sock \
 CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --no-progress
```

Red suffix: `tests/Feature/RightsScopeWriterConcurrencyTest.php --filter test_nested_scope_write_refuses_attestation_consumed_after_its_repeatable_read_snapshot --log-junit /workspace/runtime/mfa-evidence/mysql-red.xml`.
Output redirected to `mysql-red.txt`; exit **1**, **1 test / 8 assertions / 1 intended failure / 0 errors or skips**.
The explicit committed-marker and hidden-snapshot assertions precede the failure: old source admitted reuse.

Green suffix: `tests/Feature/RightsScopeWriterConcurrencyTest.php tests/Feature/RightsScopeStaffMfaTest.php tests/Feature/RightsScopeCommandAttestationTest.php --filter '/(RightsScopeStaffMfaTest|RightsScopeCommandAttestationTest|test_nested_scope_write_refuses_attestation_consumed_after_its_repeatable_read_snapshot)/' --log-junit /workspace/runtime/mfa-evidence/mysql-green.xml`.
Output redirected to `mysql-green.txt`. Exit **0**: **21 tests / 381 assertions / zero failures, errors or skips** in 205.599 seconds. JUnit confirms the complete 13-case staff-MFA and 7-case command-attestation files plus the new older-snapshot regression.

The first red attempt used test commit `b3186a23410cf39042dd6e79e32ce089a24dbeab` with the unchanged app.
Its cloned connection retained the primary's configured name, causing Eloquent writes to route back to the
primary and wait on the secondary's lock. It was stopped with SIGTERM (exit **143**); its partial output and empty
JUnit are retained as `mysql-setup-stopped.*`. No red/green application claim comes from this attempt. The
corrected fixture gives its second connection a distinct configured name and proves distinct connection IDs.

Policy commands:

- `python3 -I scripts/ci/test-database-receipts.py`: **61 tests, OK**, receipt-policy.txt.
- `python3 -I scripts/ci/test-phpunit-shards.py`: **52 tests, OK**, sharder-policy.txt.
- Initial `python3 -I scripts/ci/test-focused-selection.py`: nonexistent filename, exit **2**, focused-policy.txt.
- Correct path `python3 -I scripts/ci/test-focused-tests.py`: **50 tests, 1 failure**, focused-policy-corrected.txt.
  The new skip was inserted among an existing frozen prefix. Moving it to the append-only end restored the prefix;
  no self-test or existing skip was changed. Final repeat: **50 tests, OK**, focused-policy-final.txt, exit **0**.
- `php vendor/bin/pint --test app/Domain/Staff/StaffWriteAuthority.php tests/Feature/RightsScopeWriterConcurrencyTest.php`:
  passed, exit **0**, pint.txt.
- `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`:
  exit **0**, **1,846 selected cases in 166 files of 7,801 discovered in 562 files**. This is selection, not execution.
  Three missing timing entries use the existing fallback (listed in native-selection.txt). Generated files removed.
  The worktree's untracked vendor/bin symlink was replaced with copied wrappers before discovery so it loaded the
  worktree autoloader. No dependency changed.

Tests need the normal private local socket/filesystem via sandbox escalation. Default sandbox metadata/socket
restrictions are not used as application evidence. No hosted matrix, production host or real accounts were used.

SQLite command (separate worktree `/workspace/VA-Studio-mfa-sqlite`, detached `abe2956b`, same toolchain):

```sh
env TMPDIR=/tmp/va-studio-importer-h1-20261012 APP_ENV=testing APP_DEBUG=false \
 DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= \
 CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --no-progress \
 tests/Feature/PromotionAdministrationStaffMfaTest.php tests/Feature/RightsScopeStaffMfaTest.php \
 tests/Feature/RightsScopeCommandAttestationTest.php tests/Feature/RightsScopeCommandTest.php \
 tests/Feature/RightsScopeWriterAuthorityTest.php tests/Feature/StagingEnvironmentAdmissionTest.php \
 tests/Feature/RightsScopeWriterConcurrencyTest.php \
 --log-junit /workspace/runtime/mfa-evidence/sqlite.xml
```

Output `sqlite.txt`; exit **0**, **83 cases / 918 assertions / 11 skips / zero failures or errors** in 103.164
seconds. All 11 skips are the concurrency file's declared MySQL-only cases (72 executed cases pass). They are
not native evidence. The distinct native green set above exercises only the new method in that concurrency file,
not its ten existing cases. No full-file MySQL or independent-process attestation race is claimed.
