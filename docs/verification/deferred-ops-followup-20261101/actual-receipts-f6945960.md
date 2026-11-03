# DEF-OPS sanitized actual receipt index — 2026-11-02

Exact reviewed executable candidate: `f694596031b5a0dea5c9979f74344bcdba1cbf34`.
Earlier executed source: `b1ab9c6694d8eb19c19db1681f2cf61b816903c4`.
[Independent final decision](independent-defops-final-f6945960-DECISION.md): **DEVELOPMENT APPROVE; no P0–P2**.
This index is the current status; README's dated preparation queues are historical.
Publication/integration remains member → MIG → A5 → DEFOPS, with root's exact integrated-SHA/preflight/protection duties. No finding is declared merged/closed here.

## Source identity and transfer

The only executable difference from b1ab to f694 is two audit-context assertion comparisons in the relay test: both complete maps are key-sorted with `SORT_STRING`, then compared with strict `assertSame`. Keys, scalar types, values and every later assertion remain. Production relay/evidence code, backup runtime/tests, cache-prefix test and dependencies are byte-identical across those sources. Thus unchanged earlier results transfer only to their actual scopes; they do not create a whole-file f694 native pass.

| Path | SHA256 at f694 | b1ab identity |
| --- | --- | --- |
| `app/Domain/Customers/TestIdentityLinks/TestIdentityLinkRelay.php` | `7c25e3442a9b839f493648560abe10c4a9d2c0fc42a5f04ad1488c29ed6e204a` | identical |
| `app/Console/Commands/TestCommerceEvidence.php` | `14b7d7bb4d6119b1b96dc1d85b3d7ab07bc4c76c05f938e4f5530e5f73ce5341` | identical |
| `tests/Feature/TestIdentityLinkRelayCommandTest.php` | `5a7f9fc66b840edcdb2b1811216691bb9a4ea0f1cbd3dbf0d39a6af043f542fa` | two assertion maps normalized; focused reverified |
| `tests/Feature/TestCommerceEvidenceCommandTest.php` | `93d9c54a5fa96acec88cd46e2c2f8253e6ba5c1f37113ddb737d15102b4b65c9` | identical |
| `tests/Feature/PruneExpiredCacheRowsTest.php` | `4c1901bbde307032e00af28ddbbc8a3d8373c3430ab174d14afcdf8a95e6b766` | identical |
| `tests/ops/test_staging_backup_offhost.py` | `8d5e81a3ccd19ad3e0315f1281f637092ab214580c9114ed9b7c0c7b9e84ea30` | identical |
| `ops/staging/backup.sh` | `6ab527b0ebf4b32a7e2cbc3e553fce334938a775857370634e2430a47b06d8f0` | identical |
| `composer.json` | `d1a5bd0e099f2f6ed34448b16a84425129038849a95f09b51e353a5d04caf908` | identical |
| `composer.lock` | `254ed9b69326c8d8d02735b40fa4d61cbad560f73bc72cab47255a986a743ce8` | identical |

Original b1ab native whole relay remains **NON-GREEN**: 22 tests/1523 assertions/2 failures/0 errors/skips, actual exit1. Failures at old test365/394 differed only in JSON object-key order. The other20 passed, including the native case/accent/PAD SPACE case with104 assertions. Focused repair at f694 separately passes29+28=57 assertions versus old27+22=49 before failure; all8 formerly-unreached later assertions now execute. There was no whole22-case f694 rerun.

## Actual results and exact selected scope

Every invocation below ran once. PHPUnit counts are tests/assertions; unittest counts are discovered/executed/skipped. All raw stdout, JUnit and synthetic proof-bearing failures remain private outside Git. Elapsed times are observations under root-approved distinct-server correctness overlap; they establish no performance/headroom acceptance.

| Private phase under `/workspace/defops-evidence-20261102/` | Source | Actual result / exit | Scope and limit |
| --- | --- | --- | --- |
| `commands-execution` | b1ab |40/1709;0 failures/errors/skips;exit0;122.7348s wall | Full relay22/1463 and evidence18/246 SQLite files |
| `cache-prefix-execution` | b1ab |4/27;0 failures/errors/skips;exit0;2.8940s | Only configured/empty/a-cache-/vaseyaudio-cache- lock-prefix datasets |
| `native-relay-execution` | b1ab |22/1523;2 failures;0 errors/skips;exit1;3105.2176s | Original whole native file NON-GREEN; preserved unchanged |
| `native-relay-repaired-execution` | f694 |2/57;0 failures/errors/skips;exit0;276.6489s | Only listing/privacy and print/usable-link methods with repaired context assertions |
| `sqlite-relay-repaired-execution` | f694 |2/57;0 failures/errors/skips;exit0;6.2199s | Same two methods only |
| `native-commerce-selected-execution` | f694 |3/162;0 failures/errors/skips;exit0;432.6378s | Only minimized reference snapshots, statement guard/callback lifetime, write refusal/lifetime |
| `php-control-pure-positive` | f694 |1/13;0 failures/errors/skips;exit0;0.3170s | Existing pure predicate method through initialized external PHPUnit wrapper; no Laravel/DB |
| `ops-execution` | b1ab |76 discovered/72 executed/4 skipped;0 failures/errors;exit0;23.0832s | uid1000; four existing root/custody-only skips explicitly retained |
| `ops-root-execution` | b1ab |4 executed/0 skipped;0 failures/errors;exit0;0.1341s | uid0 ONLY those4; combined76 DISTINCT executed cases, not an unskipped single run |
| `ops-mutant-size-digits` | b1ab + exact mutant |1 test/4 subtest failures;0 errors/skips;exit1;0.9378s | Both digit checks removed: four malformed equal sizes incorrectly admitted as already shipped |
| `ops-mutant-stat-guards` | b1ab + exact mutant |1 test/3 subtest failures;0 errors/skips;exit1;0.9584s | Failed/empty/malformed stat lacked required warning; later no-record assertions not reached |
| `php-control-relay-old` | f694 tests + old relay |1/40;1 failure;0 errors/skips;exit1;86.0409s | Uppercase completed; accent DB match passed, list withholding failed; trailing-space old control not reached |
| `php-control-comment-old-pure` | f694 tests + old evidence |1/1;1 failure;0 errors/skips;exit1;0.3292s | First `for/*c*/update` returned true; later forms not reached; initialized formatter works |
| `php-control-comment-old-native` | f694 tests + old evidence |1/19;1 failure;0 errors/skips;exit1;75.7059s | FIRST `FOR/**/SHARE` callback produced status0 snapshot versus expected refusal1; later forms not reached |

The two old PHP sources were reconstructed byte-exact from baseline `2ab0c41e946e30cc402afc8ec107a3670e399abd`; each file differs from candidate only in its narrow fix. Native callback first-arm failure was **not masked by READ ONLY** in this observation. This does not independently kill every comment marker, regex operand or READ ONLY layer. Single size-digit operand mutants were not run: no independent kills or observed equivalent-survivor result is invented. All mutants/old-source substitutions passed syntax checks and finally restored candidate/test hashes with clean Git; actual failure classifications are assertion reds, not setup/errors/no-tests/skips/timeouts.

## Exact commands, sanitized

These are the actual argument sequences retained in each phase's `command.json`/`command.sh`, shell-quoted below. Only inline `APP_KEY` material is redacted; that test key stays private. Native credentials were supplied only via the named external mode0600 env file; its contents are not published. The external pure wrapper (SHA256 `4e358294b534265ac76815833cf6585e11b6bd72a30fa3276617f1e6f0dac463`) calls the existing13-assertion method without Laravel setup under an initialized PHPUnit entry point. References identify this private execution environment, not portable artifacts.

`commands-execution`:

```sh
docker exec --user 1000:1000 --workdir /workspace/VA-Studio-defops-followup va-pcc-runtime-20261101 env APP_ENV=testing APP_DEBUG=false 'APP_KEY=[PRIVATE_SYNTHETIC_TEST_KEY]' DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning --log-junit /workspace/defops-evidence-20261102/commands-execution/junit.xml tests/Feature/TestIdentityLinkRelayCommandTest.php tests/Feature/TestCommerceEvidenceCommandTest.php
```

`cache-prefix-execution`:

```sh
docker exec --user 1000:1000 --workdir /workspace/VA-Studio-defops-followup va-pcc-runtime-20261101 env APP_ENV=testing APP_DEBUG=false 'APP_KEY=[PRIVATE_SYNTHETIC_TEST_KEY]' DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning --log-junit /workspace/defops-evidence-20261102/cache-prefix-execution/junit.xml tests/Feature/PruneExpiredCacheRowsTest.php --filter test_expired_locks_go_and_held_locks_stay_held
```

`native-relay-execution`:

```sh
docker exec --user 1000:1000 --env-file /workspace/defops-evidence-20261102/native-test.env --workdir /workspace/VA-Studio-defops-followup va-pcc-runtime-20261101 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning --log-junit /workspace/defops-evidence-20261102/native-relay-execution/junit.xml tests/Feature/TestIdentityLinkRelayCommandTest.php
```

`native-relay-repaired-execution`:

```sh
docker exec --user 1000:1000 --env-file /workspace/defops-evidence-20261102/native-test.env --workdir /workspace/VA-Studio-defops-followup va-pcc-runtime-20261101 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning --log-junit /workspace/defops-evidence-20261102/native-relay-repaired-execution/junit.xml tests/Feature/TestIdentityLinkRelayCommandTest.php --filter '^(.*::)?(test_the_list_shows_only_verified_synthetic_messages_without_paths_raw_json_or_links|test_print_relays_one_selected_pending_link_without_consuming_or_changing_anything)$'
```

`sqlite-relay-repaired-execution`:

```sh
docker exec --user 1000:1000 --workdir /workspace/VA-Studio-defops-followup va-pcc-runtime-20261101 env APP_ENV=testing APP_DEBUG=false 'APP_KEY=[PRIVATE_SYNTHETIC_TEST_KEY]' DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning --log-junit /workspace/defops-evidence-20261102/sqlite-relay-repaired-execution/junit.xml tests/Feature/TestIdentityLinkRelayCommandTest.php --filter '^(.*::)?(test_the_list_shows_only_verified_synthetic_messages_without_paths_raw_json_or_links|test_print_relays_one_selected_pending_link_without_consuming_or_changing_anything)$'
```

`native-commerce-selected-execution`:

```sh
docker exec --user 1000:1000 --env-file /workspace/defops-evidence-20261102/native-test.env --workdir /workspace/VA-Studio-defops-followup va-pcc-runtime-20261101 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning --log-junit /workspace/defops-evidence-20261102/native-commerce-selected-execution/junit.xml tests/Feature/TestCommerceEvidenceCommandTest.php --filter '^(.*::)?(test_every_reference_form_gives_the_same_minimized_snapshot_using_only_select_statements|test_the_statement_guard_refuses_locking_and_into_clauses_and_stays_a_single_inert_callback|test_a_write_attempted_while_the_snapshot_reads_is_refused_and_the_guard_ends_with_the_command)$'
```

`php-control-pure-positive`:

```sh
docker exec --user 1000:1000 --workdir /workspace/VA-Studio-defops-followup va-pcc-runtime-20261101 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning --log-junit /workspace/defops-evidence-20261102/php-control-pure-positive/junit.xml --no-configuration --bootstrap /workspace/VA-Studio-defops-followup/vendor/autoload.php /workspace/defops-evidence-20261102/PureExistingCommentGuardTest.php
```

`ops-execution`:

```sh
docker exec --user 1000:1000 --workdir /workspace/VA-Studio-defops-followup va-pcc-runtime-20261101 env TMPDIR=/workspace/defops-evidence-20261102/ops-execution/tmp python3 -m unittest discover -s tests/ops -p test_staging_backup_offhost.py -v
```

`ops-root-execution`:

```sh
docker exec --user 0 --workdir /workspace/VA-Studio-defops-followup va-pcc-runtime-20261101 env TMPDIR=/workspace/defops-evidence-20261102/ops-root-execution/tmp PYTHONPATH=/workspace/VA-Studio-defops-followup/tests/ops python3 -m unittest -v test_staging_backup_offhost.ConfigFileCustody.test_absent_file_changes_nothing test_staging_backup_offhost.ConfigFileCustody.test_a_trusted_file_is_sourced test_staging_backup_offhost.ConfigFileCustody.test_writable_linked_or_foreign_files_are_refused_and_never_sourced test_staging_backup_offhost.ConfigurationValidation.test_recipients_file_owned_by_another_user_is_refused
```

`ops-mutant-size-digits`:

```sh
docker exec --user 1000:1000 --workdir /workspace/VA-Studio-defops-followup va-pcc-runtime-20261101 env TMPDIR=/workspace/defops-evidence-20261102/ops-mutant-size-digits/tmp PYTHONPATH=/workspace/VA-Studio-defops-followup/tests/ops python3 -m unittest -v test_staging_backup_offhost.RcloneRemote.test_equal_malformed_record_and_remote_sizes_are_not_trusted
```

`ops-mutant-stat-guards`:

```sh
docker exec --user 1000:1000 --workdir /workspace/VA-Studio-defops-followup va-pcc-runtime-20261101 env TMPDIR=/workspace/defops-evidence-20261102/ops-mutant-stat-guards/tmp PYTHONPATH=/workspace/VA-Studio-defops-followup/tests/ops python3 -m unittest -v test_staging_backup_offhost.RcloneRemote.test_failed_or_malformed_ciphertext_stat_never_leaves_a_shipped_record
```

`php-control-relay-old`:

```sh
docker exec --user 1000:1000 --env-file /workspace/defops-evidence-20261102/native-test.env --workdir /workspace/VA-Studio-defops-followup va-pcc-runtime-20261101 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning --log-junit /workspace/defops-evidence-20261102/php-control-relay-old/junit.xml tests/Feature/TestIdentityLinkRelayCommandTest.php --filter '^.*::test_staff_recipient_exclusion_uses_database_case_and_collation_matching$'
```

`php-control-comment-old-pure`:

```sh
docker exec --user 1000:1000 --workdir /workspace/VA-Studio-defops-followup va-pcc-runtime-20261101 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning --log-junit /workspace/defops-evidence-20261102/php-control-comment-old-pure/junit.xml --no-configuration --bootstrap /workspace/VA-Studio-defops-followup/vendor/autoload.php /workspace/defops-evidence-20261102/PureExistingCommentGuardTest.php
```

`php-control-comment-old-native`:

```sh
docker exec --user 1000:1000 --env-file /workspace/defops-evidence-20261102/native-test.env --workdir /workspace/VA-Studio-defops-followup va-pcc-runtime-20261101 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning --log-junit /workspace/defops-evidence-20261102/php-control-comment-old-native/junit.xml tests/Feature/TestCommerceEvidenceCommandTest.php --filter '^.*::test_the_statement_guard_refuses_locking_and_into_clauses_and_stays_a_single_inert_callback$'
```

## Native/runtime profile, restoration and cleanup

Own Oracle MySQL **8.4.11**, image ID `sha256:4aae07a2c195abb9b3e92449443b39b51212f555a35ff8f93da0e82683f45de8`, repository digest `mysql@sha256:0f51e63e537a1f176423fa03ef559aabe6fa4372a53160a44bc7e75da7d15364`; container `va-defops-mysql-20261102`, private datadir `/workspace/defops-evidence-20261102/mysql-data`, free loopback host port33090, own bridge IP172.17.0.6, disposable DB `va_defops_synthetic_test`. Limits:2GiB memory, equal memory-swap limit (no added swap),2CPU,128MiB buffer,64MiB redo, binlog disabled. Bounded readiness succeeded at attempt8. Actual framework disposable admission succeeded with CI marker1 and exact selected database; no shared server was admitted. This was a local plaintext/non-TLS bridge test profile, not a transport-security or target-host claim.

Both server and actual Laravel session recorded REPEATABLE-READ, FK1/unique1 and all six strict modes: ONLY_FULL_GROUP_BY, STRICT_TRANS_TABLES, NO_ZERO_IN_DATE, NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO, NO_ENGINE_SUBSTITUTION. PHP8.4.27/PHPUnit12.5.34; Python3.13.5; age/keygen1.2.1, rsync3.5.0, rclone1.60.1-DEV (Debian package1.60.1+dfsg-4). APT update exited0 but network refresh failed and reused existing indexes; install exited0 reporting already newest/0 upgrades/0 newly installed. No fresh-index/new-install claim or retry. Verified available binaries were used for actual local behavior.

Contained vendor:160 exact locked packages,35698 independently copied files/1083694612bytes (before own metadata regeneration), every copied SHA256 matched A5 origin; vendored .git omitted. Previous linked metadata preserved privately. Own no-scripts/no-plugins autoload generation and non-bootstrap reflection resolved own classes/TestsTestCase and contained fonts/renderers. No renderer execution is inferred. Own .env copied from .env.example; build absent; no dependencies updated. Earlier warnings remain in private Composer log.

Final cleanup `native-cleanup-controls-final.json` at02:05:45 UTC proved0 other clients, transactions, data locks, pending MDL and schema tables on ONLY the owned server; earlier boundaries also record zeroes. `owned-processes-final.json` records no DEFOPS PHP; unrelated account PHP was identified as outside scope and untouched. Ops TMPDIRs were empty after cleanup. These are recorded-boundary facts, not permanent server-cleanliness guarantees.

Restoration hashes: relay candidate `7c25e3442a9b839f493648560abe10c4a9d2c0fc42a5f04ad1488c29ed6e204a`, old `2f6800cbd98a1cfee44574fdd07f3f16b258bee0079dd3a75e97a9234756d4ac`; evidence candidate `14b7d7bb4d6119b1b96dc1d85b3d7ab07bc4c76c05f938e4f5530e5f73ce5341`, old `c0774b0e73c0bacee2b885d6bf34d335364125cc3aea738ff39d8559dc2636ff`. Backup candidate `6ab527b0ebf4b32a7e2cbc3e553fce334938a775857370634e2430a47b06d8f0`; size mutant `98340ce2693a6f41dbfd9269cd4a344d55b22c903573b559aeab12b02929fd83`; stat mutant `bf70107d1f0738dc900f0b9c790d5e1dde3d90b4cd4699d4ff20167ddfe63d95`. Every receipt preserves exact command, original/executed/restored hashes, source SHA, UTC start/end, elapsed and actual test exit; raw logs/JUnit/source snapshots are private.

## Remaining conditions

No full f694 native22 relay pass, full native18 commerce pass, unselected commerce-method acceptance, full matrix/Foundation, browser/build/renderer, real provider, production/target-host, concurrency, TLS, performance or headroom acceptance is claimed. No additional tests/native/container actions accompanied this docs-only receipt. Exact final independent review approves bounded DEVELOPMENT integration; root retains cheap preflight/protected review and exact integrated-SHA duties. Keep default-off/no-live/no-launch boundaries and ordered integration.
