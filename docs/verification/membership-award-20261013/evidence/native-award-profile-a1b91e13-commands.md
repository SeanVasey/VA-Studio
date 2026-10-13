# Isolated owned-award diagnostic

Source: `a1b91e139b1814413ff09196a2df44798bc1440e`. No application edits.
Worktree: `/workspace/VA-Studio-membership-award-profile`.
Private MySQL: `8.4.11`, existing container `va-studio-private-mysql-policy-20261013`, owned new schema `vaseyaudio_membership_award_profile`.

Setup from `/workspace/VA-Studio-membership-award`:

```sh
git worktree add --detach /workspace/VA-Studio-membership-award-profile a1b91e139b1814413ff09196a2df44798bc1440e
mkdir -m 700 /workspace/VA-Studio-membership-award-profile/vendor
for package in /workspace/VA-Studio-membership-award/vendor/*; do
  name=$(basename "$package")
  case "$name" in composer|autoload.php|bin) continue;; esac
  ln -s "$package" "/workspace/VA-Studio-membership-award-profile/vendor/$name"
done
cp -a /workspace/VA-Studio-membership-award/vendor/composer /workspace/VA-Studio-membership-award-profile/vendor/composer
cp -a /workspace/VA-Studio-membership-award/vendor/bin /workspace/VA-Studio-membership-award-profile/vendor/bin
cp /workspace/VA-Studio-membership-award/vendor/autoload.php /workspace/VA-Studio-membership-award-profile/vendor/autoload.php
cp /workspace/VA-Studio-membership-award/.env /workspace/VA-Studio-membership-award-profile/.env
chmod 600 /workspace/VA-Studio-membership-award-profile/.env
```

The copied `.env` contains the existing synthetic fixture configuration and was never printed.

From the new profile worktree:

```sh
source /workspace/.va-studio-toolchain/activate.sh
php -r 'require "vendor/autoload.php"; foreach ([App\Domain\Memberships\Production\MembershipAwards::class, App\Domain\Memberships\Production\MembershipRows::class, Tests\Support\MembershipAwardFixtures::class] as $class) { echo $class." ".(new ReflectionClass($class))->getFileName().PHP_EOL; }'
git status --short
docker exec va-studio-private-mysql-policy-20261013 mysql --socket=/run/mysql-policy/mysql.sock -uroot -e 'CREATE DATABASE vaseyaudio_membership_award_profile CHARACTER SET utf8mb4 COLLATE utf8mb4_bin; SELECT VERSION();'
```

Reflection resolved all three classes/traits into this profile worktree. Git status was clean. Database creation succeeded and `SELECT VERSION()` returned `8.4.11`.

One diagnostic case, unchanged original 10-second operation budget:

```sh
source /workspace/.va-studio-toolchain/activate.sh
TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33413 DB_USERNAME=root DB_PASSWORD= DB_DATABASE=vaseyaudio_membership_award_profile DB_URL= DB_SOCKET=/workspace/runtime/mysql-policy-run/mysql.sock CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync VA_MEMBERSHIP_AWARD_DIAGNOSTIC_REPORT=/workspace/runtime/membership-award-evidence/native-award-profile-a1b91e13.json php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- /workspace/runtime/membership-award-evidence/MembershipAwardNativeDiagnosticTest.php --log-junit /workspace/runtime/membership-award-evidence/native-award-profile-a1b91e13.xml > /workspace/runtime/membership-award-evidence/native-award-profile-a1b91e13.txt 2>&1
status=$?
printf '%s\n' "$status" > /workspace/runtime/membership-award-evidence/native-award-profile-a1b91e13.exit
cat /workspace/runtime/membership-award-evidence/native-award-profile-a1b91e13.txt
```

The diagnostic snapshots the current native thread's statement counters without resetting shared counters or changing instrumentation. The report records instrumentation availability, source SHA256 maps before and after, actual wall time, error, retained effects, and per-event count/server-time deltas. Optional digest history may be disabled or truncated; its limits are recorded explicitly. A genuine award refusal is rethrown after writing the report and remains a PHPUnit error.

## Observed result

- PHP 8.4.26, MySQL 8.4.11; source hashes equal before/after for all 362 recorded files.
- PHPUnit: **1 test, 8 assertions, 1 error, 0 failures, 0 skips**, exit **2**. Whole test including migrations/fixtures: **167.629 seconds**.
- Unchanged public award: **15.430449810 seconds**, refused with `operation_deadline` at `MembershipAwardContext::proveSources`, before first effects.
- Durable periods **0**, award events **0**; Laravel transaction level **0**, PDO transaction **false**.
- Award-window current-thread delta: **11,180 prepares / 6.439131470 server seconds**, **11,590 executes / 7.504157157 seconds**, **11,180 closes / 0.232154207 seconds**. Savepoint/release/begin/rollback costs were below 0.002 seconds combined.
- `events_statements_history_long` was disabled; **no digest history** was observed. The SQL event-name count of 66 consists of transaction controls; it does **not** count the 11,590 prepared executions as 66 ordinary SQL queries.

Read-only fallback after the diagnostic finished (whole process, including migrations and fixture setup; **not award-only**):

```sh
docker exec va-studio-private-mysql-policy-20261013 mysql --socket=/run/mysql-policy/mysql.sock -uroot --batch --raw -e "SELECT SCHEMA_NAME, COUNT_STAR, SUM_TIMER_WAIT, ROUND(SUM_TIMER_WAIT / 1000000000000, 6) AS server_seconds, DIGEST_TEXT FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME = 'vaseyaudio_membership_award_profile' ORDER BY SUM_TIMER_WAIT DESC LIMIT 15" > /workspace/runtime/membership-award-evidence/native-award-profile-a1b91e13-whole-process-digests.tsv
docker exec va-studio-private-mysql-policy-20261013 mysql --socket=/run/mysql-policy/mysql.sock -uroot --batch --raw -e "SHOW GLOBAL STATUS LIKE 'Performance_schema_digest_lost'; SELECT @@performance_schema_digests_size AS digest_capacity; SELECT COUNT(*) AS retained_digests, SUM(COUNT_STAR) AS retained_event_count FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME = 'vaseyaudio_membership_award_profile';" > /workspace/runtime/membership-award-evidence/native-award-profile-a1b91e13-digest-availability.tsv
```

The fallback retained 783 digest rows covering 953 events; global digest loss was 0 and capacity 10,000. Its top rows are migration DDL, and it does not account for the award's 11,590 prepared executions. No application call-site timing is claimed from that fallback.

Targeted next change: remove the immediately repeated identity-floor inspection in `BillingSchema::inspect`, then batch equivalent per-name membership/billing catalog reads inside a single inspection while retaining current SHOW definitions, exact namespace/shape checks, original frame checks and the 10-second budget. A whole-frame positive/negative ownership cache is not justified by this measurement.
