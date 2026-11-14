# Commands and source identities

PHP commands activate `/workspace/.va-studio-toolchain/activate.sh` (PHP 8.4.26, PHPUnit 12.5.34). The worktree has its own Composer autoload files, and `public/build` is absent. The original output directory is `/workspace/runtime/membership-policy-evidence`; completed outputs are preserved in `logs/` and `xml/` without replacing initial failures.

All PHPUnit invocations use this exact PHP entry point, followed by the selection arguments in the table:

```sh
php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' --
```

Shared environment: `TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_URL= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`.

SQLite adds `DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_SOCKET=`. Native MySQL adds `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33413 DB_USERNAME=root DB_PASSWORD= DB_SOCKET=/workspace/runtime/mysql-policy-run/mysql.sock` and the selection's private `DB_DATABASE`. The empty password belongs only to the disposable local socket account; no live credentials are involved.

Every selection appends `--log-junit /workspace/runtime/membership-policy-evidence/NAME.xml > /workspace/runtime/membership-policy-evidence/NAME.txt 2>&1`. The table records NAME, source and exact selection arguments; it factors repeated environment/entry-point text for readability, rather than claiming a separate runner script was executed. Original pre-checkpoint shell invocations were not separately archived verbatim.

| NAME | Application/test source | Selection arguments |
| --- | --- | --- |
| sqlite-canary | `6cd0f4ab` | `tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php --filter=test_fresh_schema_is_owned_without_identity_child_dependencies_and_retry_is_idempotent` |
| mysql-canary | `6cd0f4ab` | Same canary, native private `vaseyaudio_membership_policy` |
| sqlite-policy-first | `96078e09` | `tests/Feature/ProductionMembership/MembershipPolicyFactsTest.php --stop-on-error --stop-on-failure` |
| sqlite-policy-second | `fc8b74c7` | `tests/Feature/ProductionMembership/MembershipPolicyFactsTest.php` |
| mysql-canary-second | `fc8b74c7` | Same canary, native private `vaseyaudio_membership_policy` |
| sqlite-interval-first | `90f363d0` | `tests/Feature/ProductionMembershipBilling/BillingIntervalOwnershipTest.php` |
| mysql-interval-canary | `45ec5631` | `tests/Feature/ProductionMembershipBilling/BillingIntervalOwnershipTest.php --filter=test_both_insert_directions_refuse_every_cross_ledger_endpoint_combination --stop-on-error --stop-on-failure`, native private `vaseyaudio_membership_intervals` |
| sqlite-interval-red | Unchanged base application `11a716e7`; test-only commit `d43d2dd8` | Same single interval method, SQLite in `/workspace/VA-Studio-membership-policy-red` |
| sqlite-affected | Checkout `061c9284`, application `45ec5631` | `tests/Feature/ProductionMembership tests/Feature/ProductionMembershipBilling` |
| mysql-policy | Checkout `061c9284`, application `45ec5631` | `tests/Feature/ProductionMembership/MembershipPolicyFactsTest.php --filter '/(test_real_staff_writer\|test_nonstaff_unverified\|test_gate_callback_revocation\|test_proofs_refuse_other_issuer\|test_a_commit_and_reopen)/' --stop-on-error --stop-on-failure`, private `vaseyaudio_membership_policy` |
| mysql-interval-integration | Checkout `061c9284`, application `45ec5631` | `tests/Feature/ProductionMembershipBilling/BillingIntervalOwnershipTest.php tests/Feature/ProductionMembershipBilling/BillingNativeIntervalOwnershipRaceTest.php tests/Feature/ProductionMembershipBilling/BillingNativeDedupRaceTest.php tests/Feature/ProductionMembershipBilling/BillingNativeCrossInvoiceClaimTest.php --filter '/(test_exact_legacy\|test_legacy_cross\|test_upgrade_refuses\|test_independent_transactions\|BillingNativeDedupRaceTest\|BillingNativeCrossInvoiceClaimTest)/' --stop-on-error --stop-on-failure`, private `vaseyaudio_membership_intervals` |
| mysql-interval-red | Base application `11a716e7`; test-only commit `d43d2dd8` | `tests/Feature/ProductionMembershipBilling/BillingNativeIntervalOwnershipRaceTest.php --stop-on-error --stop-on-failure`, private `vaseyaudio_membership_intervals_red`, old-source worktree |
| sqlite-clock-red | Application `45ec5631`; new test committed at `c54a3d20` | `tests/Feature/ProductionMembership/MembershipPolicyClockTest.php` |
| sqlite-policy-final | `df3656b6a74151c5e2f6b89264ad733f70678c0e` | `tests/Feature/ProductionMembership/MembershipPolicyFactsTest.php tests/Feature/ProductionMembership/MembershipPolicyClockTest.php tests/Feature/ProductionMembershipBilling/BillingNativeIntervalSnapshotTest.php` |
| mysql-policy-final | `df3656b6a74151c5e2f6b89264ad733f70678c0e` | `tests/Feature/ProductionMembership/MembershipPolicyFactsTest.php tests/Feature/ProductionMembership/MembershipPolicyClockTest.php --filter '/(test_real_staff_writer\|test_terminal_clock_callback)/' --stop-on-error --stop-on-failure`, private `vaseyaudio_membership_policy` |
| mysql-snapshot-final | `df3656b6a74151c5e2f6b89264ad733f70678c0e` | `tests/Feature/ProductionMembershipBilling/BillingNativeIntervalSnapshotTest.php --stop-on-error --stop-on-failure`, private `vaseyaudio_membership_intervals` |

The backslashes before `|` escape Markdown table separators only; the shell regex uses ordinary `|` alternatives. Subsequent final invocations explicitly saved `$?` to NAME.exit. For `sqlite-affected`, `mysql-policy` and `mysql-interval-integration`, terminal tool sessions expired before exit retrieval: final console and JUnit summaries are retained, but an observed shell exit code is not claimed. No existing application/test file changed during those runs; subsequent additions were separate test files and metadata, absent from their initial discovery.

## Private MySQL

Oracle MySQL **8.4.11**, verified by `docker exec va-studio-private-mysql-policy-20261013 mysql --socket=/run/mysql-policy/mysql.sock -uroot -N -e 'SELECT VERSION()'`. Cached image: `mysql@sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242`.

The new owned datadir was initialized before startup with `mysqld --no-defaults --initialize-insecure --datadir=/var/lib/mysql` in the private container mount. Startup:

```sh
docker run -d --name va-studio-private-mysql-policy-20261013 --user 1000:1000 \
  --entrypoint mysqld -p 127.0.0.1:33413:3306 \
  -v /workspace/runtime/mysql-policy-data:/var/lib/mysql \
  -v /workspace/runtime/mysql-policy-run:/run/mysql-policy mysql:8.4 \
  --no-defaults --datadir=/var/lib/mysql --socket=/run/mysql-policy/mysql.sock \
  --port=3306 --bind-address=0.0.0.0 --mysqlx=OFF \
  --pid-file=/run/mysql-policy/mysqld.pid --log-error=/run/mysql-policy/server.err
```

A minimal raw-SQL probe separately confirmed MySQL error 1442 for a trigger's locking read of its own insertion table. That observation guided the opposite-ledger-only locking read at `45ec5631`; it was not a passing application test. One PHP lint attempt omitted toolchain activation and returned `php: command not found`; both files passed lint after activation. These exploratory command outputs were not separately archived as receipts, and are not counted as test passes.

## Quality and test inventory

```sh
python3 scripts/ci/test-focused-tests.py
python3 scripts/ci/test-phpunit-shards.py
DB_CONNECTION=mysql python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection
```

`logs/pint-final-argv.json` records the exact `php vendor/laravel/pint/builds/pint --test` argv and changed PHP file list. Initial formatting and selection-policy failures remain alongside their correction results. Native discovery is a partition/census check, not execution of 1881 native cases. Its eight-way timing estimates are historical balancing weights, not measurements; actual hosted native jobs remain at 24 shards. No workflow was dispatched by this discovery command.
