# Native catalog batching canaries

Application base: `9ec090737e66e7f89075600cca96a6174ef2076d` (subsequent committed CI/docs changes do not alter this application base).
Worktree: `/workspace/VA-Studio-membership-award`.
Only edited application files: `MembershipSchema.php`, `BillingSchema.php`, new `MembershipSchemaCatalog.php`.
Private MySQL 8.4.11; owned schema `vaseyaudio_membership_award_profile`, socket `/workspace/runtime/mysql-policy-run/mysql.sock`, loopback port 33413.

For each canary, the two-file tracked diff plus new helper diff and three-file SHA256 inventory were captured immediately before execution in `native-batch-N.patch` and `native-batch-N-source-sha256.txt`. Source remained frozen through that run. No application deadline, DDL definition, or validation predicate was removed.

## Canary 1

```sh
source /workspace/.va-studio-toolchain/activate.sh
TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33413 DB_USERNAME=root DB_PASSWORD= DB_DATABASE=vaseyaudio_membership_award_profile DB_URL= DB_SOCKET=/workspace/runtime/mysql-policy-run/mysql.sock CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionMembershipBilling/MembershipFirstAwardTest.php --filter test_owned_award_commits_one_period_and_one_event_and_replays_the_original_result --log-junit /workspace/runtime/membership-award-evidence/native-batch-1.xml > /workspace/runtime/membership-award-evidence/native-batch-1.txt 2>&1
status=$?
printf '%s\n' "$status" > /workspace/runtime/membership-award-evidence/native-batch-1.exit
```

Observed: **1 test, 0 assertions, 1 setup error, 0 failures/skips**, exit **2**. `array_all` supplies value and key; passing the one-argument built-in `is_string(...)` directly raised `ArgumentCountError`. No award executed and no performance improvement is claimed from this run. Corrected to an explicit one-value closure.

## Canary 2

Added the callback correction and native non-DDL positive table opens before the first batched metadata read. Current SHOW/shadow checks remain; the transaction retains the positive tables' metadata locks. Standalone autocommit inspection still has no atomicity claim.

```sh
source /workspace/.va-studio-toolchain/activate.sh
vendor/bin/pint --test app/Domain/Memberships/Production/MembershipSchemaCatalog.php app/Domain/Memberships/Production/MembershipSchema.php app/Domain/Memberships/Billing/BillingSchema.php
TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33413 DB_USERNAME=root DB_PASSWORD= DB_DATABASE=vaseyaudio_membership_award_profile DB_URL= DB_SOCKET=/workspace/runtime/mysql-policy-run/mysql.sock CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionMembershipBilling/MembershipFirstAwardTest.php --filter test_owned_award_commits_one_period_and_one_event_and_replays_the_original_result --log-junit /workspace/runtime/membership-award-evidence/native-batch-2.xml > /workspace/runtime/membership-award-evidence/native-batch-2.txt 2>&1
status=$?
printf '%s\n' "$status" > /workspace/runtime/membership-award-evidence/native-batch-2.exit
```

Pint passed before execution. Observed PHPUnit: **1 test, 5 assertions, 1 error, 0 failures/skips**, exit **2**, whole test including migration/fixture setup **133.638 seconds**. Schema setup now completes; the first owned award still reaches `operation_deadline` at `MembershipAwardContext::proveSources`, before its first inserts. This optimization is therefore **not sufficient** and the positive native award remains blocked. No larger native selection was run.
