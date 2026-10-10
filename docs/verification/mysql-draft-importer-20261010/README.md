# MySQL-capable catalog draft importer — evidence (2026-10-10)

Branch `harness/mysql-draft-importer` from main `f57e7256891d0d3c117e151a36f7fb967c724ab7`.
Plan: [PLAN.md](PLAN.md). Work package: `docs/handoff/2026-10-08/MONDAY-PLAN.md` section 6 item 4.

These are focused checks on a private disposable MySQL 8.0.46 and on in-memory SQLite in a cloud
container. They are not Foundation acceptance, not a hosted MySQL 8.4 run and not a staging import.
The lane ran in two sessions (the first was cut off by a usage limit); every result below names the
session that produced it, and the second session re-ran what it relies on.

## What changed

| File | Change |
| --- | --- |
| `app/Domain/Migration/CatalogOnboarding/CatalogDatabaseEvidence.php` | Adds a MySQL branch beside the unchanged SQLite branch: `schema()` proves the session switches and the exact owned objects from `information_schema`; `rows()` reads every evidence table with `SELECT ... FOR SHARE` and records the declared `DATA_TYPE` (or `null`) as the storage class. Any other driver is still refused (`catalog_target_schema_invalid`, `catalog_target_invalid`). |
| `app/Domain/Migration/CatalogOnboarding/CatalogDraftImporter.php` | `standalone()` admits `sqlite` or `mysql`; the docblock names both engines. Nothing else in the importer changed. |
| `tests/Feature/PersistentCatalogDraftImportTest.php` | Runs on the suite's MySQL connection when the suite selects MySQL (admitted by `DisposableNativeDatabase`), otherwise on the same named in-memory SQLite connection as before. Adds InnoDB equivalents of every schema-drift DDL, the `REPLACE INTO` identity attack, engine-aware callback-DDL assertions, and three new cases: partial failure then exact resume, overlapping duplicate applies, and a nested-importer refusal mid-segment that on MySQL also proves cross-session lock waits. |
| `scripts/ci/database-mysql-selection.json` | Registers the test file in `include_files` (it branches on the driver). No census entry: no case skips on either engine. |

The SQLite statements, expected rows and hashes in `CatalogDatabaseEvidence` are textually identical
to main: the `schema()` driver check through `expectedSchema()`, the SQLite body of `rows()`, and
`target()` plus `childless()` were extracted from `f57e725` and from the new file and compared as
strings (three `True` results, second session).

## Guarantees and their InnoDB proofs

| Guarantee | SQLite proof (unchanged) | MySQL proof (new) |
| --- | --- | --- |
| Dry run before apply | `review()` writes nothing; the digest binds source, actor, release, schema and target | Same code path; `rows()` locking reads release at the end of the read-only transaction |
| Immutable evidence | UPDATE/DELETE triggers, unique indexes, BEFORE INSERT replacement guards | `SIGNAL` triggers on UPDATE and DELETE; `REPLACE` and `INSERT ... ON DUPLICATE KEY UPDATE` delete or update first, so the same triggers refuse them (probe below) |
| Refusal on schema drift | exact `sqlite_master` rows, `foreign_keys=1`, `writable_schema=0`, `ignore_check_constraints=0` | exact `information_schema` TABLES, COLUMNS, STATISTICS, REFERENTIAL_CONSTRAINTS+KEY_COLUMN_USAGE, CHECK_CONSTRAINTS and TRIGGERS rows for the owned tables; `foreign_key_checks=1`, `unique_checks=1`, `autocommit=1`, strict `sql_mode`, `REPEATABLE-READ`, `DATABASE()` equal to the configured database, native PDO fetches |
| Refusal on data drift | target snapshot of `tracks`, `catalog_import_mappings`, `audit_events` with per-row sha256 of attributes and `typeof()` classes | same snapshot with the declared `DATA_TYPE` as the class; reads are `FOR SHARE`, so no other session can insert into or update a read table until commit |
| Idempotent re-run | retained mappings form an exact prefix; replay creates nothing | same code path, proved on MySQL by the resume and overlapping-apply cases |
| No partial writes | one transaction per segment; any exception rolls back | same, proved by the audit-failure and mapping-failure cases; see the DDL difference below |

### Engine differences that are documented, not hidden

- **Same-session DDL commits implicitly on MySQL.** The importer never issues DDL. DDL from another
  session waits on the metadata lock of the open transaction (probe and test below). DDL issued from
  inside the importer's own session (a callback, as the suite simulates) commits the rows already
  written; the post-callback schema proof still refuses, the committed rows are an exact retained
  prefix, and a replay creates nothing. On SQLite the same DDL rolls back with the transaction. The two
  callback-DDL tests assert each engine's actual behaviour.
- **`TRUNCATE` fires no trigger on MySQL.** It is DDL that needs the DROP privilege; the foreign key from
  `catalog_import_mappings` refuses it on `catalog_import_batches`. A least-privilege application user
  must not hold DROP on the owned tables.
- **Temporary shadows are invisible to `information_schema` on MySQL**, and `INNODB_TEMP_TABLE_INFO`
  needs the PROCESS privilege, so a same-session `CREATE TEMPORARY TABLE` over an owned table is not
  detected. SQLite's `sqlite_temp_master` was not checked before this change either.
- **Storage class.** SQLite records the stored value's class per row (`typeof`). MySQL strict mode fixes
  the stored type to the column's declared type, so the class is `DATA_TYPE` or `null`.
- **Rolled-back auto-increment values are not reused on InnoDB.** After a refused segment the next
  draft's id continues from the consumed counter. Nothing in the importer or its evidence depends on
  contiguous ids; the suite asserts counts and per-row digests, not id values.

## Environment

- PHP 8.4.26, PHPUnit 12.5.34, direct PHPUnit with this worktree's `vendor/autoload.php`,
  `public/build` absent, Node not involved.
- SQLite: `DB_CONNECTION=sqlite DB_DATABASE=:memory:` (the suite's own named in-memory connection).
- MySQL: Oracle MySQL `8.0.46-0ubuntu0.24.04.4`, a private disposable `mysqld` initialised and started for
  this lane on `127.0.0.1:33112` with its own socket, data directory and pid file, shut down afterwards.
  CI uses MySQL 8.4.11, which this container does not have. The server reported
  `sql_mode=ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`,
  `transaction_isolation=REPEATABLE-READ`, `foreign_key_checks=1`, `unique_checks=1`, `autocommit=1`.
- A full `migrate:fresh` on this MySQL takes 75–180 s because the repository's migrations issue about
  40,000 statements (mostly `information_schema` admission reads); the suite rebuilds the database for
  every case, as `SupportAttachmentsTest` does, so the MySQL run was split into four parallel shards on
  four disposable databases. Shard 1 used the dedicated synthetic schema `vaseyaudio_catalog_import`
  with no CI markers; shards 2–4 used `importer_ci_shard_{2,3,4}` admitted through the CI markers
  (`CI=true`, `VA_CI_DISPOSABLE_MYSQL=1`), so both `DisposableNativeDatabase` admission paths ran.
  The machine has four cores, so the shards contended with each other and with `mysqld`.

## InnoDB behaviour probes (scratch scripts, not committed; first session)

Run against a freshly migrated database on the private server before the MySQL branch was written.

| Probe | Result |
| --- | --- |
| `information_schema` rows for the two owned tables | InnoDB, `utf8mb4_unicode_ci`, `bigint unsigned` ids with `auto_increment`, `char(64)`/`char(40)`/`varchar(80)`/`longtext` columns with the table collation, `timestamp` `created_at` with `CURRENT_TIMESTAMP` default and `EXTRA = DEFAULT_GENERATED`; indexes `PRIMARY`, `*_unique` and one `*_foreign` index per foreign key except `track_id`, whose foreign key reuses the unique index; four foreign keys, `DELETE_RULE = RESTRICT`, `UPDATE_RULE = NO ACTION`; four `BEFORE` `ROW` triggers whose statement is the `SIGNAL`; no CHECK constraints; no views |
| PDO fetch types (`query()` and prepared) | ints as `int`, strings as `string`, `NULL` as null; `ATTR_STRINGIFY_FETCHES=false`, `ATTR_EMULATE_PREPARES=false` |
| `REPLACE INTO` same id, `REPLACE INTO` same digest, `INSERT ... ON DUPLICATE KEY UPDATE`, `UPDATE`, `DELETE` on a batch row | all refused: `SQLSTATE[45000] 1644 Catalog import evidence is immutable` |
| `TRUNCATE TABLE catalog_import_batches` | refused: `1701 Cannot truncate a table referenced in a foreign key constraint` |
| Session B `DROP TRIGGER`, `ALTER TABLE ... ADD COLUMN`, `INSERT INTO tracks`, `UPDATE users` while session A holds an open transaction after `FOR SHARE` reads | each waits and times out: `1205 Lock wait timeout exceeded` (2 s timeout); a plain `SELECT` is admitted |
| Session A `DROP TRIGGER` inside its own transaction after an insert | admitted; `rollBack()` reports no active transaction; the inserted row is committed |
| `SET SESSION foreign_key_checks = 0, unique_checks = 0` | `SELECT @@session...` returns `int(0)`, `int(0)` |
| `CREATE TEMPORARY TABLE catalog_import_mappings` | `information_schema.TABLES` still lists only the base table; the shadowed name resolves to the temporary table |
| `ALTER TABLE ... ENGINE=MyISAM` on either owned table | refused: `3776 Cannot change table's storage engine because the table participates in a foreign key constraint` |
| `UPDATE tracks ... WHERE id = (SELECT min(id) FROM tracks)` | refused on MySQL: `1093`; `UPDATE ... ORDER BY id LIMIT 1` admitted (the suite branches here) |
| Seeded tables after a fresh migration | `catalog_discovery_epoch`, `discovery_sitemap_current`, `site_publications`, `migrations`: a truncate-based reset would break the epoch triggers, so the suite keeps `migrate:fresh` per case |

## Test runs

All PHPUnit runs used this form (environment variables as listed under "Environment"; `--filter` and
`--log-junit` as noted; JUnit files in `local-evidence/` have the worktree prefix stripped from `file=`):

```
php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' \
  -- --no-progress tests/Feature/PersistentCatalogDraftImportTest.php
```

### SQLite: unchanged suite at the base commit, then the new suite

| Run | Session | Result |
| --- | --- | --- |
| Base commit `f57e725` (original suite and importer) in a scratch `git archive` checkout of that commit with its own Composer autoloader (the test file's sha256 equals `git show f57e725:tests/Feature/PersistentCatalogDraftImportTest.php`), SQLite, `--log-junit` (`local-evidence/sqlite-baseline-original-suite.junit.xml`, `local-evidence/sqlite-baseline-original-suite.txt`) | second (the first session's run of the same thing also reported 47/232) | rc 0: OK (47 tests, 232 assertions), 1m20s |
| This branch, final suite, SQLite (`local-evidence/sqlite-new-suite.junit.xml`, `local-evidence/sqlite-new-suite.txt`) | second | rc 0: OK (50 tests, 288 assertions), no skips, 1m40s |

Per-case comparison of the two JUnit files (second session, `xml.etree` over `testcase` name, `assertions`
and child status; output in `local-evidence/sqlite-junit-comparison.txt`): base 47 cases, new 50 cases;
no base case missing; no difference in the 47 original cases; the added cases are
`test_partial_failure_while_the_second_mapping_is_created_leaves_no_rows_and_the_next_apply_resumes_exactly` (19 assertions),
`test_overlapping_duplicate_applies_create_exactly_one_draft_mapping_and_audit_set_per_record` (24) and
`test_nested_importers_and_foreign_sessions_cannot_interleave_while_a_segment_is_open` (13).

### MySQL 8.0.46: red before the fix

`local-evidence/mysql80-red-before-fix.txt` (first session). The new test file ran against the base
commit's `app/` files on the dedicated local schema `vaseyaudio_catalog_import`, which also proves the
dedicated-schema admission path of `DisposableNativeDatabase`. Three cases ran (the fourth filter
alternative matched no data set); every one errored at a former refusal site before any import read or
write:

| Case | Former refusal |
| --- | --- |
| `test_dry_run_is_read_only_and_atomic_segments_resume_without_duplicate_rows_or_audits` | `RuntimeException: catalog_target_invalid` from the driver check in `CatalogDatabaseEvidence::rows()` (old line 80) |
| `test_partial_failure_while_the_second_mapping_is_created_leaves_no_rows_and_the_next_apply_resumes_exactly` | `ValidationException` from `CatalogDraftImporter::standalone()` (old line 425, `DB::getDriverName() === 'sqlite'`) |
| the lock case (then named `test_mysql_metadata_and_next_key_locks_hold_foreign_session_ddl_and_writes_while_a_segment_is_open`) | `RuntimeException: catalog_target_schema_invalid` from `CatalogDatabaseEvidence::schema()` (old line 25) |

rc 2, "Tests: 3, Assertions: 6, Errors: 3", 7m17s (three `migrate:fresh` rebuilds).

Second-session re-run (`local-evidence/mysql80-red-before-fix-rerun.txt`): the final test file was copied
into a scratch `git archive` checkout of `f57e725` with its own Composer autoloader (the checkout's
`CatalogDatabaseEvidence.php` hashes identically to `git show f57e725:...`), and the same three cases
ran on the CI-marker-admitted disposable database `importer_red_rerun`: rc 2, "Tests: 3, Assertions: 6,
Errors: 3", 22m04s under heavy contention. The three refusal sites are the same as above
(`CatalogDatabaseEvidence.php:80` `catalog_target_invalid`, `CatalogDraftImporter.php:425` via `:432`
`ValidationException`, `CatalogDatabaseEvidence.php:25` `catalog_target_schema_invalid`); the third case
now carries its final name.

### MySQL 8.0.46: green after the fix (second session)

`local-evidence/mysql80-green-after-fix.txt` (the 19 PHPUnit outputs, each followed by its rc and elapsed
line) and `local-evidence/mysql80-green-after-fix.junit.xml` (the 19 JUnit suites merged into one
document). The final suite (commit `b7860a1`, importer `1996fb8`) ran as four detached queues on the
private server, one PHPUnit invocation per test method (`--filter <method prefix>`, all data sets of the
method), so that a process termination could lose at most one group. A first attempt as four shards in
one background job had been terminated by the container after 972 s with no result (all four PHP
processes got SIGTERM at once; `mysqld` and the runner shell survived); nothing from it is cited.

Aggregate of the 19 JUnit files (`xml.etree`): **50 tests, 356 assertions, 0 errors, 0 failures,
0 skipped; 50 distinct case names**, i.e. every case of the suite. Every invocation returned rc 0.
Wall times include the per-case `migrate:fresh` under heavy contention (three lanes' `mysqld` instances
and their PHP processes shared the four cores; load average about 10), so they are not timings.

| Group (`--filter`) | Queue / database | Tests | Assertions | Errors | Failures | Skipped | rc | Wall time |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `test_audit_failure_after` | 1 / `vaseyaudio_catalog_import` | 1 | 4 | 0 | 0 | 0 | 0 | 02:35.960 |
| `test_batch_evidence_is` | 4 / `importer_ci_queue_4` | 1 | 4 | 0 | 0 | 0 | 0 | 03:33.892 |
| `test_conflicts_have_explicit` | 3 / `importer_ci_queue_3` | 6 | 24 | 0 | 0 | 0 | 0 | 38:42.342 |
| `test_current_revoked_authority` | 3 / `importer_ci_queue_3` | 1 | 5 | 0 | 0 | 0 | 0 | 04:55.493 |
| `test_declared_media` | 4 / `importer_ci_queue_4` | 1 | 15 | 0 | 0 | 0 | 0 | 03:24.076 |
| `test_dry_run_is_read_only` | 1 / `vaseyaudio_catalog_import` | 1 | 36 | 0 | 0 | 0 | 0 | 04:53.868 |
| `test_exact_review_source_and_current_target_binding` | 1 / `vaseyaudio_catalog_import` | 11 | 55 | 0 | 0 | 0 | 0 | 58:43.621 |
| `test_final_direct_current_proof` | 3 / `importer_ci_queue_3` | 5 | 20 | 0 | 0 | 0 | 0 | 20:30.886 |
| `test_final_direct_schema_proof` | 2 / `importer_ci_queue_2` | 1 | 17 | 0 | 0 | 0 | 0 | 03:25.394 |
| `test_foreign_or_incomplete_live_owned_schema` | 2 / `importer_ci_queue_2` | 9 | 54 | 0 | 0 | 0 | 0 | 52:00.910 |
| `test_fresh_review_of` | 4 / `importer_ci_queue_4` | 1 | 9 | 0 | 0 | 0 | 0 | 06:06.663 |
| `test_mappings_are_immutable` | 4 / `importer_ci_queue_4` | 1 | 4 | 0 | 0 | 0 | 0 | 04:38.536 |
| `test_nested_importers_and` | 2 / `importer_ci_queue_2` | 1 | 14 | 0 | 0 | 0 | 0 | 04:56.353 |
| `test_nested_transaction` | 4 / `importer_ci_queue_4` | 1 | 4 | 0 | 0 | 0 | 0 | 03:42.569 |
| `test_overlapping_duplicate` | 4 / `importer_ci_queue_4` | 1 | 25 | 0 | 0 | 0 | 0 | 02:05.767 |
| `test_partial_failure_while` | 4 / `importer_ci_queue_4` | 1 | 20 | 0 | 0 | 0 | 0 | 04:49.640 |
| `test_raw_replace_cannot` | 4 / `importer_ci_queue_4` | 5 | 20 | 0 | 0 | 0 | 0 | 34:04.558 |
| `test_schema_changes_after_review` | 2 / `importer_ci_queue_2` | 1 | 20 | 0 | 0 | 0 | 0 | 04:03.541 |
| `test_source_os_lease` | 4 / `importer_ci_queue_4` | 1 | 6 | 0 | 0 | 0 | 0 | 04:04.443 |

What the MySQL run proves beyond the SQLite run, by case:

- `test_foreign_or_incomplete_live_owned_schema` (9 data sets): each InnoDB drift (`DROP TRIGGER`, a
  no-op foreign trigger, `DROP INDEX`, `ADD COLUMN`, `foreign_key_checks = 0`, `unique_checks = 0`,
  non-strict `sql_mode`, `ON DELETE CASCADE` on the actor foreign key, nullable `actor_id`) is refused by
  both `review()` and `apply()` with `catalog_target_schema_invalid` and leaves the evidence unchanged.
- `test_raw_replace_cannot` (5 data sets): `REPLACE INTO` on either owned table, by primary key or by
  any unique identity, is refused with `Catalog import evidence is immutable` by the `SIGNAL` triggers.
- `test_schema_changes_after_review` and `test_final_direct_schema_proof`: DDL from a callback inside
  the importer's own session commits implicitly; the drift is still refused, the committed rows are an
  exact retained prefix, and a replay creates nothing (the engine difference above).
- `test_nested_importers_and`: with a segment open, a nested importer in the same process is refused,
  and a second session's `DROP TRIGGER`, `ALTER TABLE ... ADD COLUMN`, `INSERT INTO tracks` and
  `UPDATE users` each time out with error 1205 on the open transaction's metadata and next-key locks.
- `test_partial_failure_while` and `test_overlapping_duplicate`: a failure while the second mapping is
  created rolls back every row of the segment on InnoDB; overlapping and repeated applies create exactly
  one draft, mapping and audit set per record.
- `test_final_direct_current_proof` (5 data sets): the MySQL `UPDATE ... ORDER BY id LIMIT 1` late
  mutation is refused by the final direct proof like the SQLite subquery form.

## Policy self-tests (second session)

| Command | Result |
| --- | --- |
| `python3 scripts/ci/test-phpunit-shards.py` | rc 0, "Ran 52 tests", OK (`local-evidence/policy-selftests.txt`) |
| `python3 scripts/ci/test-focused-tests.py` | rc 0, "Ran 50 tests", OK (the focused-suite census is untouched) |
| `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection` | rc 0: "Proved 1815 selected MySQL-native tests in 164 files (of 7645 discovered tests in 557 files) across 8 nonempty shards" (main: 1,765 in 163 of 7,642); the suite is in `phpunit-ci-mysql-8.xml`; the generated `phpunit-ci-mysql-*` files and manifest were deleted (`git status` clean of them) |

The selection grows by exactly this file's 50 cases. The sharder warns that the file has no entry in
`phpunit-timings-mysql.json` and uses the fallback weight; at CI's measured 35 s per `migrate:fresh` case
the file adds about 30 minutes to one MySQL shard. Fresh timings from the first hosted run should replace
the weight.

## Not verified

- MySQL 8.4 (CI's version); only 8.0.46 exists here. The `information_schema` columns used are present
  in both, and `EXTRA = DEFAULT_GENERATED` has been reported since 8.0.13, but the 8.4 run is CI's.
- No hosted Foundation CI dispatch; the policy self-tests and the sharder dry run are the CI evidence.
- `scripts/migration/persistent-catalog.test.mjs` (needs `public/build`) and the browser specs were not
  run; the `.mjs` files are unchanged.
- The CLI wrapper still targets the SQLite persistent installation, because
  `scripts/dev/persistent-content-bootstrap.php` pins `DB_CONNECTION=sqlite` and is outside this lane.
  A staging (MySQL) entry point is remaining work.
- Real source data, staging's host, privileges and network were not exercised.
- Concurrency between two importer processes on MySQL was proved only for the lock waits a second
  session experiences while one segment is open (DDL, insert and update all time out); two importers
  racing for the same batch were not run as separate processes.
