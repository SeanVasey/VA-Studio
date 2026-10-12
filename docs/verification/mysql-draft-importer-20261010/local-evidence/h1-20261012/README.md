# Importer H1 correction: local evidence, 2026-10-12

Candidate application/tests: `a4bc2bbc5ad260084028fe671790197a2c767bd5`.
Control: `d55d023ac8fada1fd0b6aad811c421addbb0d3d9`, which is
`2aba5bede538aacd8493b56585f0fbd3bcd20d0d` plus the new test file only.
`git diff --exit-code 2aba5be d55d023a -- app` passed.

| File | Control SHA-256 | Candidate SHA-256 |
| --- | --- | --- |
| `CatalogDatabaseEvidence.php` | `f1b1f589aa317389e9e13eff70a958d070c24047745750a55b1482dbb905d468` | `ce408e6a40f1dea59ca0f53ccaa3fadf922fe7191362942d4bb4171d13fca383` |
| `PersistentCatalogDraftImportTest.php` | `dd5678246c8355275fb1d58df71c6dce57a234c3f27bc3e1e2a10b4f2d5d6395` | same |

Runtime: PHP 8.4.26, PHPUnit 12.5.34, Oracle MySQL Community Server **8.4.11**,
`lower_case_table_names=0`, `REPEATABLE-READ`. A private `mysqld` used its own datadir,
socket and loopback port 33396, in the official `mysql:8.4` image with digest
`sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242`.
Tests connected through its private socket using a synthetic socket-local root account.
No shared server, host installation, provider or customer data was used.

`$CANDIDATE`, `$RED`, `$REVIEW`, `$BASE` and `$SCRATCH` replace absolute checkout/runtime
paths in retained outputs; ANSI color escapes were removed. Original counts and failures
are unchanged. Replace the two path placeholders in the independent probe before reuse.

The direct PHPUnit command, from the relevant worktree, is:

```sh
env APP_ENV=testing APP_DEBUG=false DB_URL= CACHE_STORE=array SESSION_DRIVER=array \
  QUEUE_CONNECTION=sync DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33396 \
  DB_USERNAME=root DB_PASSWORD= DB_DATABASE=vaseyaudio_catalog_import \
  DB_SOCKET="$SCRATCH/mysql-run/mysql.sock" TMPDIR="$PRIVATE_TMP" \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' \
  -- --no-progress tests/Feature/PersistentCatalogDraftImportTest.php \
  --filter '<selection>' --log-junit '<output.xml>'
```

The red selection is `test_engine_change_racing_the_pin_while_an_earlier_open_waits_is_refused`.
Both datasets reached the precise blocked `SELECT 1` after listing and verified the target
really became MyISAM. Only the expected refusal assertion failed: **2 tests, 18 assertions,
2 failures, 0 errors, 0 skips, exit 1** (`mysql-races-red.*`).

The new green selection is
`test_engine_change_racing_the_pin_while_an_earlier_open_waits_is_refused|test_a_pin_that_cannot_open_a_table_in_time|test_a_temporary_shadow_of_a_table_outside`.
Result: **4 tests, 32 assertions, 0 failures, 0 errors, 0 skips, exit 0**
(`mysql-new-cases-green.*`). Both H1 targets pass, as do the timeout/restoration and
temporary-shadow cases. The timeout case covers caller limits of 30 and one seconds,
and restoration after refusal and success.

The previous dry-run/resume, transaction-pin, whole-schema resumed-pin and nested-importer
cases passed: **4 tests, 63 assertions, 0 failures, 0 errors, 0 skips, exit 0**
(`mysql-prior-cases-green.*`). They ran concurrently with the new cases on the distinct
`vaseyaudio_catalog_import_regression` schema, using `CI=true VA_CI_DISPOSABLE_MYSQL=1`.
Their filter was
`test_foreign_or_incomplete_live_owned_schema_refuses_review_and_apply_without_repairs#(10|11|12)$|test_storage_changed_after_admission_but_before_the_first_write_is_refused_by_the_transaction_pin|test_storage_of_a_table_outside_the_evidence_set_changed_before_the_first_write_is_refused_by_the_transaction_pin|test_dry_run_is_read_only_and_atomic_segments_resume_without_duplicate_rows_or_audits|test_nested_importers_and_foreign_sessions_cannot_interleave_while_a_segment_is_open`.
That regex did **not** select the three schema-drift datasets; those require PHPUnit's
range syntax `test_foreign_or_incomplete_live_owned_schema_refuses_review_and_apply_without_repairs#10-12`
and a separate run. Only the four actual JUnit cases count for the first command.
The corrected schema-drift range passed: **3 tests, 23 assertions, 0 failures, 0 errors,
0 skips** (`mysql-schema-drift-green.*`, 333.791 seconds). Its shell session handle had
expired when collected; the complete PHPUnit log and JUnit remain, so the missing shell
exit status is not invented. The independent reviewer also executed the same three
datasets on a separate schema: **3 tests, 23 assertions, exit 0**, 259.611 seconds
(`reviewer-mysql-drift-green.*`). These are overlapping checks, not six distinct cases.
The required distinct focused MySQL selection is **11 tests / 118 assertions**, with
no failures, errors or skips; it does not establish the rest of the MySQL file.

For SQLite use `DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_SOCKET=` and omit the filter.
Final whole-file result: **60 tests, 336 assertions, 0 failures, 0 errors, 0 skips, exit 0**
(`sqlite-final.*`). No SQLite case is MySQL concurrency evidence.

Setup failures are retained. In the filesystem sandbox, `/tmp` reports UID 65534 instead
of root and a synthetic `/workspace/.git` appears, so the unchanged private-source guard
refuses fixtures (60 tests, 68 assertions, 56 errors in each of the first two runs).
Running with genuine filesystem metadata removed those systematic failures. A run with
workspace fixture storage still had two source-admission errors (60 tests, 328 assertions);
the exact two cases then passed in isolation, and a disposable fixture-only diagnostic
admitted 500 fixtures. Final fixture storage is a private directory under ordinary `/tmp`,
outside the workspace. No guard was disabled or changed.

Initial sharder discovery failed because the worktree's `vendor/bin` symlink loaded the
base checkout's autoloader. Copying the untracked Composer wrappers into the worktree
fixed discovery; no repository script changed. The corrected command
`python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`
reported **1,825 selected tests in 164 files, of 7,800 in 562 files**. Generated files were
deleted. This eight-shard discovery command did not execute tests or change the hosted
24-shard configuration. Policy self-tests: sharder 52, focused selection 50, both passed.
Changed-file Pint passed.

Independent reviewer probe: `pin-probe.php` / `pin-probe.txt`, **12/12 checks passed** on
the candidate and MySQL 8.4.11. This supplements the required PHPUnit runs; it does not
replace them. TLS infrastructure, alternative server routing, `lower_case_table_names=1/2`,
the rest of the MySQL file, Foundation CI, production host and the documented trigger/routine
residual remain untested here.
