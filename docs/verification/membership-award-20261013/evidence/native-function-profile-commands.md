# Native award function attribution, original 10-second admission deadline

This is a local synthetic diagnostic, not an award pass, provider claim, or host verification.

Profile worktree: `/workspace/VA-Studio-membership-award-profile`, detached at `36fc22d0d94585ba13724e14093ceabc725c762a`. The exact main-worktree metadata batching files from `native-batch-2` were copied into it. `native-batch-2-source-sha256.txt` binds that input. The original bodies of three functions were retained in private methods and public/private wrappers gathered only inclusive elapsed nanoseconds and call counts in a diagnostic global. The global was reset/enabled immediately before the unchanged public award and disabled immediately afterward. No deadline, return behavior, predicates, consumers, or shared counters were changed. The diagnostic rethrows the real operation exception after writing its report.

Instrumentation is separately bound by `native-function-profile-instrumentation.patch` and `native-function-profile-instrumentation-sha256.json`. Original unwrapped copies are in `function-profile-original/`. The test-only external file is `MembershipAwardFunctionDiagnosticTest.php`. Private Composer autoload files resolve application and fixture classes into the profile worktree; dependencies use the existing package directories. The synthetic environment file was copied privately and not printed.

Native MySQL **8.4.11**, owned schema `vaseyaudio_membership_award_profile`, socket `/workspace/runtime/mysql-policy-run/mysql.sock`, port `33413`. No other schema was dropped. The read-only object census is retained as `native-function-profile-object-census.tsv`; it did not support the hypothesis of many stale application schemas amplifying inspection cost.

## Exact diagnostic invocation

Cwd: `/workspace/VA-Studio-membership-award-profile`.

```sh
source /workspace/.va-studio-toolchain/activate.sh
TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33413 DB_USERNAME=root DB_PASSWORD= DB_DATABASE=vaseyaudio_membership_award_profile DB_URL= DB_SOCKET=/workspace/runtime/mysql-policy-run/mysql.sock CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync VA_MEMBERSHIP_AWARD_DIAGNOSTIC_REPORT=/workspace/runtime/membership-award-evidence/native-function-profile-batch-2.json php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- /workspace/runtime/membership-award-evidence/MembershipAwardFunctionDiagnosticTest.php --log-junit /workspace/runtime/membership-award-evidence/native-function-profile-batch-2.xml > /workspace/runtime/membership-award-evidence/native-function-profile-batch-2.txt 2>&1
status=$?
printf '%s\n' "$status" > /workspace/runtime/membership-award-evidence/native-function-profile-batch-2.exit
cat /workspace/runtime/membership-award-evidence/native-function-profile-batch-2.txt
```

## Actual result

PHPUnit **1 test, 8 assertions, 1 error, 0 failures/skips**, exit **2**. Total test including migrations/fixture setup **119.296 seconds**. The public award took **13.841690479 seconds** and refused `operation_deadline` in `MembershipAwardContext::proveSources`, before award effects. Durable periods **0**, award events **0**, Laravel transaction level **0**, PDO transaction **false**. All **363** before/after source hashes match.

| Function | Calls | Inclusive seconds |
| --- | ---: | ---: |
| IdentityMigrationOwnership::inspect | 64 | 11.096746386 |
| MembershipSchema::up | 35 | 8.096291572 |
| BillingSchema::inspect | 5 | 1.574834307 |

These timers nest and **must not be summed** as independent costs. Identity inspection's inclusive time is approximately **80.17%** of the public interval. This proves repeated identity ownership inspections dominate the remaining measured runtime; it does not attribute individual dictionary SQL statements within those calls.

Per-thread prepared-protocol deltas: **9,110 prepares / 6.039507858 server seconds**, **9,110 executes / 6.716369495 seconds**, **9,110 close-statement events / 0.204876944 seconds**. The separate 66 SQL events comprise transaction/savepoint controls; they are not a count of all statements. The summary baseline completion can appear in deltas, and protocol/nested event categories can overlap. `events_statements_history_long` was disabled, so no complete digest history is claimed.

No additional native suite, source optimization, result caching, or deadline revision was performed after this diagnostic. The main three product files remained frozen. A complete positive native award and concurrency race proofs remain untested at this checkpoint.
