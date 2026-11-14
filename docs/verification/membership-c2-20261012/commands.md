# Local commands and results

All PHP commands use `source /workspace/.va-studio-toolchain/activate.sh` first. Initial application source is `46e91e50b02caaa7ce88c3e3e3f521dfa9eccc5b`; native dictionary comparison is corrected at `dfbd0ba0bb331d565d6d69021e955950653a5d11`. Test-only follow-ups and exact result counts are in README. `public/build` is absent. Real PHP/database execution uses authorized sandbox escalation because the normal sandbox synthesizes temporary-directory ownership and cannot reach the private socket. No application check was weakened.

Output paths below are the original runtime paths, copied into `logs/` and `xml/`. Console output is preserved, including original failures. The first seven-case red/green logs were retained before the complete test batch was frozen; they are historical supporting evidence. A replay selection for those seven cases is `tests/Feature/ProductionMembershipBilling/BillingRetrievalAttemptsTest.php --filter=test_first_timeout_is_retained_without_claiming_an_invoice_and_retry_can_succeed`, with the SQLite environment below. The original red/green shell invocations were not separately archived verbatim.

## Whole SQLite directory

Worktree `/workspace/VA-Studio-membership-c2-sqlite`, detached `46e91e50`; exit **2** (195 tests, 1048 assertions, 2 test errors, 5 declared native-only skips):

```sh
env TMPDIR=/tmp/va-studio-importer-h1-20261012 APP_ENV=testing APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --no-progress \
  tests/Feature/ProductionMembershipBilling \
  --log-junit /workspace/runtime/membership-c2-evidence/sqlite.xml \
  > /workspace/runtime/membership-c2-evidence/sqlite.txt 2>&1
```

## Corrected and added SQLite cases

Same worktree switched to `1a14437ecdbded5646e1234a7138d056d036f81c`; exit **0**, 5/25, zero skips:

```sh
env TMPDIR=/tmp/va-studio-importer-h1-20261012 APP_ENV=testing APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --no-progress \
  tests/Feature/ProductionMembershipBilling/BillingRetrievalAttemptsTest.php \
  tests/Feature/ProductionMembershipBilling/BillingObservationLedgerTest.php \
  tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php \
  --filter '/(test_existing_wrong_scope_or_late_hint_is_refused_by_writer_and_database|test_another_invoice_returned_for_a_matching_binding_records_a_refusal_without_claiming_the_requested_invoice|test_contiguous_empty_tail_restarts_but_a_data_bearing_unguarded_prefix_is_not_adopted)/' \
  --log-junit /workspace/runtime/membership-c2-evidence/sqlite-corrections.xml \
  > /workspace/runtime/membership-c2-evidence/sqlite-corrections.txt 2>&1
```

## Native MySQL selection

Worktree `/workspace/VA-Studio-membership-c2`, `46e91e50` test classes loaded before the later test-only edits. Application source stayed unchanged. Private Oracle MySQL 8.4.11; exit **2**, 16 setup errors and 0 assertions, zero skips:

```sh
env TMPDIR=/tmp/va-studio-importer-h1-20261012 APP_ENV=testing APP_DEBUG=false \
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33396 DB_USERNAME=root DB_PASSWORD= \
  DB_DATABASE=vaseyaudio_catalog_import DB_URL= DB_SOCKET=/workspace/runtime/mysql-run/mysql.sock \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --no-progress \
  tests/Feature/ProductionMembershipBilling/BillingRetrievalAttemptsTest.php \
  tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php \
  tests/Feature/ProductionMembershipBilling/BillingObservationLedgerTest.php \
  tests/Feature/ProductionMembershipBilling/BillingNativeDedupRaceTest.php \
  tests/Feature/ProductionMembershipBilling/BillingWebhookRedeliveryTest.php \
  --filter '/(BillingRetrievalAttemptsTest|test_fresh_schema_is_owned_without_identity_child_dependencies_and_retry_is_idempotent|test_bindings_invoices_observations_events_positions_and_attempts_are_immutable|test_another_invoice_returned_for_a_matching_binding_records_a_refusal_without_claiming_the_requested_invoice|BillingNativeDedupRaceTest|test_first_failed_job_retains_its_hint_link_and_redelivery_and_sweep_still_dispatch_until_success)/' \
  --log-junit /workspace/runtime/membership-c2-evidence/mysql.xml \
  > /workspace/runtime/membership-c2-evidence/mysql.txt 2>&1
```

## Corrected and added native cases

Primary worktree at `1a14437e`, separate disposable schema on the same private server; interrupted with SIGTERM, shell exit **143**, once the original run showed the shared setup failure. No completed PHPUnit summary or passing count is claimed; the empty XML is retained with `.partial` suffix. The original selection is the only other process using this server and has its own schema. No schema is shared by the two selections.

```sh
/workspace/runtime/bin/mysql -e 'CREATE DATABASE IF NOT EXISTS vaseyaudio_membership_corrections'
env TMPDIR=/tmp/va-studio-importer-h1-20261012 APP_ENV=testing APP_DEBUG=false \
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33396 DB_USERNAME=root DB_PASSWORD= \
  DB_DATABASE=vaseyaudio_membership_corrections DB_URL= DB_SOCKET=/workspace/runtime/mysql-run/mysql.sock \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --no-progress \
  tests/Feature/ProductionMembershipBilling/BillingRetrievalAttemptsTest.php \
  tests/Feature/ProductionMembershipBilling/BillingObservationLedgerTest.php \
  tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php \
  --filter '/(test_existing_wrong_scope_or_late_hint_is_refused_by_writer_and_database|test_another_invoice_returned_for_a_matching_binding_records_a_refusal_without_claiming_the_requested_invoice|test_contiguous_empty_tail_restarts_but_a_data_bearing_unguarded_prefix_is_not_adopted)/' \
  --log-junit /workspace/runtime/membership-c2-evidence/mysql-corrections.xml \
  > /workspace/runtime/membership-c2-evidence/mysql-corrections.txt 2>&1
```

## Native correction and final disjoint selections

The native correction `dfbd0ba0` was admitted first by this single canary, exit **0**, **1 test / 8 assertions / zero skips**:

```sh
env TMPDIR=/tmp/va-studio-importer-h1-20261012 APP_ENV=testing APP_DEBUG=false \
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33396 DB_USERNAME=root DB_PASSWORD= \
  DB_DATABASE=vaseyaudio_catalog_import DB_URL= DB_SOCKET=/workspace/runtime/mysql-run/mysql.sock \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --no-progress \
  tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php \
  --filter=test_fresh_schema_is_owned_without_identity_child_dependencies_and_retry_is_idempotent \
  --log-junit /workspace/runtime/membership-c2-evidence/mysql-schema-canary.xml \
  > /workspace/runtime/membership-c2-evidence/mysql-schema-canary.txt 2>&1
```

After that pass, the exact retained runner `run-native-final.sh` was invoked in three independent tool processes at `dfbd0ba0`. The runner uses separate schemas and `--stop-on-error --stop-on-failure`, with disjoint case selections. Each command exited **0**. `timeout`: **7/98**; `attempts`: **7/50**; `integration`: **5/61**; every selection has zero failures/errors/skips. With the separate canary, these are **20 distinct cases / 217 assertions**:

```sh
/workspace/runtime/bin/mysql -e 'CREATE DATABASE vaseyaudio_membership_timeout'
bash docs/verification/membership-c2-20261012/run-native-final.sh timeout
bash docs/verification/membership-c2-20261012/run-native-final.sh attempts
bash docs/verification/membership-c2-20261012/run-native-final.sh integration
```

The SQLite worktree was also switched to `dfbd0ba0`. Both affected whole files passed **27/230, zero skips, exit 0**:

```sh
env TMPDIR=/tmp/va-studio-importer-h1-20261012 APP_ENV=testing APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --no-progress \
  tests/Feature/ProductionMembershipBilling/BillingRetrievalAttemptsTest.php \
  tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php \
  --log-junit /workspace/runtime/membership-c2-evidence/sqlite-schema-final.xml \
  > /workspace/runtime/membership-c2-evidence/sqlite-schema-final.txt 2>&1
```

## Policy checks and census results

```sh
python3 scripts/ci/test-focused-tests.py
python3 scripts/ci/test-phpunit-shards.py
python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql \
  --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection
```

The two self-test commands passed **50** and **52** cases respectively, both exit 0. The first census used an unsupported `--prefix=phpunit-ci-mysql-c2` and exited 1. Correct prefix discovery at `46e91e50` passed with 1857/7816. A later attempt refused existing generated outputs (exit 1); after removing only these generated files, final discovery at `1a14437e` passed with **1860/7819**, 167 native files. Generated files were removed afterwards. Eight shards are the handoff's census recipe, not a hosted job change or actual timing measurement.

Pint used the worktree autoloader and `vendor/laravel/pint/builds/pint` on the changed PHP files. `pint.txt`, `pint-hint-cases.txt` and `pint-fixture-fixes.txt` each exit 0. The last two cover the added hint cases and the two corrected fixtures respectively. The final `--test` invocation at `1a14437e` also passed all 13 changed PHP files; its exact subprocess argv is `logs/pint-final-command.json` and unmodified output is `logs/pint-final.txt`. No Foundation or GitLab workflow was dispatched.

The later single-file Pint check at `dfbd0ba0` used the same PHP autoload invocation with `--test app/Domain/Memberships/Billing/BillingSchema.php`; direct command exit0, retained `logs/pint-native-final.txt`. The final-source MySQL runs and the two-file SQLite run are complete; no further application edits followed.
