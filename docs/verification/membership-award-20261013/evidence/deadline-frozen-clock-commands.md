# Deterministic membership admission-clock correction

Isolated worktree: `/workspace/VA-Studio-membership-clock`.
Base: `904f08d55fe832aacb37b76cbd04bd0d8a02d314`.
Two-file correction commit: `53070a8b8ca8a6b495c4b63296e2e065e553c859`.
Only `MembershipAwardDeadlineTest.php` and test-only `MembershipAwardMonotonicClock.php` changed. Product source is unchanged. The main award worktree remained frozen throughout.

The earlier real-clock-plus-offset shim let slow real execution consume the virtual 40 + 21 second budget before the intended committing callback. The corrected helper uses an explicitly activated, frozen membership clock and exact synthetic advances. Its anchor is deliberately one hour in the future. Other namespaces retain real clocks, making their frame deadline comparisons lenient. These cases prove membership admission checks against one original deadline, not physical elapsed time, native performance, or interruption of blocking calls. Setup and teardown reset the helper, and membership calls outside active synthetic mode use the real clock. No production clock hook or registration was added.

## SQLite

Executed from the isolated worktree, with owned `vendor/composer`, `vendor/autoload.php`, and `vendor/bin`. Other vendor package directories remain symlinked. Reflection resolved `MembershipAwards` to this isolated worktree.

```sh
source /workspace/.va-studio-toolchain/activate.sh
vendor/bin/pint --test tests/Feature/ProductionMembershipBilling/MembershipAwardDeadlineTest.php tests/Support/MembershipAwardMonotonicClock.php
sha256sum app/Domain/Memberships/Production/MembershipAwards.php tests/Feature/ProductionMembershipBilling/MembershipAwardDeadlineTest.php tests/Support/MembershipAwardMonotonicClock.php > /workspace/runtime/membership-award-evidence/deadline-frozen-clock-sqlite.sources
git rev-parse HEAD > /workspace/runtime/membership-award-evidence/deadline-frozen-clock-sqlite.base
TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionMembershipBilling/MembershipAwardDeadlineTest.php --log-junit /workspace/runtime/membership-award-evidence/deadline-frozen-clock-sqlite.xml > /workspace/runtime/membership-award-evidence/deadline-frozen-clock-sqlite.txt 2>&1
frozen_sqlite_status=$?
printf '%s\n' "$frozen_sqlite_status" > /workspace/runtime/membership-award-evidence/deadline-frozen-clock-sqlite.exit
cat /workspace/runtime/membership-award-evidence/deadline-frozen-clock-sqlite.txt
exit "$frozen_sqlite_status"
```

Result: PHP 8.4.26 / PHPUnit 12.5.34; **3 tests, 38 assertions, zero failures/errors/skips, exit 0**, 16.499 seconds. Executed as an uncommitted two-file change atop the recorded base, then committed unchanged at the correction SHA above. All three source hashes passed `sha256sum -c` after the run. Exact source copies are retained in `deadline-frozen-clock-sqlite-source/`.

Additional completed checks:

```sh
sha256sum -c /workspace/runtime/membership-award-evidence/deadline-frozen-clock-sqlite.sources
php -l tests/Support/MembershipAwardMonotonicClock.php
php -l tests/Feature/ProductionMembershipBilling/MembershipAwardDeadlineTest.php
git diff --check
```

All passed. Earlier `admission-budget-green-v2` remains valid for its recorded previous clock/test bytes: 18 tests, 176 assertions. It is not relabeled as coverage of this correction. Earlier intended red and teardown-error evidence is preserved unchanged.

## Private MySQL

Exact script: `deadline-frozen-clock-native.sh`; command: `bash /workspace/runtime/membership-award-evidence/deadline-frozen-clock-native.sh`.

Root released the profile schema after its three native race cases completed. This selection uses the existing private server's separate `vaseyaudio_membership_award_profile` schema. A different agent's schema-test selection was concurrently running against the main award schema on the same private MySQL instance when this run began; these timings are not isolated performance measurements.

The script records exact commit/status, PHP class source path, MySQL version/schema/socket/port, all PHP source hashes under app/tests/database migrations, JUnit results, exit status, and the post-run source comparison. It runs only the three deadline cases with `--stop-on-failure --stop-on-error`. Final native result is recorded separately after completion.

Completed native result: **MySQL 8.4.11 / PHP 8.4.26 / PHPUnit 12.5.34; 3 tests, 38 assertions, zero errors/failures/skips, exit 0**, 06:32.385 including case setup/teardown. Run commit `53070a8b8ca8a6b495c4b63296e2e065e553c859`; clean tracked status; all **1,841** captured PHP source hashes remained unchanged. Class reflection resolved to the isolated worktree. Connection uses the host socket `/workspace/runtime/mysql-policy-run/mysql.sock` and configured host port 33413; the server reports container socket `/run/mysql-policy/mysql.sock` and internal port 3306. Neither this elapsed test time nor the future-anchor assertions prove physical timing guarantees. The exact script, JUnit, text output, exit, runtime/version, base, status and source inventory/check receipts are retained under `deadline-frozen-clock-native.*`.
