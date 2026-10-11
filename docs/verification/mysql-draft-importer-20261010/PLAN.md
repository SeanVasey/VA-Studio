# MySQL-capable catalog draft importer — plan (lane `importer`, 2026-10-10)

Branch `harness/mysql-draft-importer` from main `f57e7256891d0d3c117e151a36f7fb967c724ab7`.
Work package: `docs/handoff/2026-10-08/MONDAY-PLAN.md` section 6 item 4, "a MySQL-capable draft
importer". This plan is written before any runtime source changes (AGENTS.md work protocol, step 1).

## Files owned

- `app/Domain/Migration/CatalogOnboarding/CatalogDraftImporter.php`
- `app/Domain/Migration/CatalogOnboarding/CatalogDatabaseEvidence.php`
- `app/Domain/Migration/CatalogOnboarding/NormalizedSourceSnapshot.php` (no change expected; public
  contract kept)
- `scripts/migration/persistent-catalog.*` (no change expected, see "What the brief gets wrong" below)
- `tests/Feature/PersistentCatalogDraftImportTest.php`
- `tests/Support/NormalizedCatalogFixtures.php` (no change expected)
- `scripts/ci/database-mysql-selection.json` (register the test file in `include_files`)
- `scripts/ci/database-sqlite-skips.json` is **not** owned; if a MySQL-only method is added, it is
  recorded as a blocker instead of edited (see "Tests to add").
- `docs/content-onboarding-readiness.md` (one section on MySQL support)
- `docs/verification/mysql-draft-importer-20261010/**` (this plan and the evidence)

Not touched: `CHANGELOG.md`, `README.md`, `CLAUDE.md`, `AGENTS.md`, `resources/contracts/`, `.env*`,
`vendor/`, lockfiles, `.github/`, `.gitlab-ci.yml`, `scripts/dev/*`, the migration
`database/migrations/2026_10_06_237000_catalog_import_progress.php`.

## What the SQLite path proves today (guarantees to preserve)

Read from `CatalogDraftImporter`, `CatalogDatabaseEvidence`, `PrivateSourceFiles`,
`NormalizedSourceSnapshot`, the migration, the CLI wrapper, the test and
`docs/verification/persistent-catalog-draft-import.md`:

1. **Dry run before apply.** `review()` reads source bytes, the live owned schema and the current
   target rows inside one transaction and writes nothing (`database_writes = 0`); the review digest
   binds source, actor, release, schema digest and target snapshot. `apply()` requires that exact
   digest, the same source identity and release, zero conflicts and a limit of 1–25.
2. **Immutable evidence hashes.** Batch and mapping rows are encrypted canonical JSON; the live
   triggers refuse UPDATE and DELETE; unique indexes and (SQLite only) BEFORE INSERT replacement
   guards refuse identity rewrites; the migration's `down()` refuses to drop populated tables.
3. **Refusal on schema drift.** `schema()` compares the exact `sqlite_master` rows of the two owned
   tables (table SQL, unique indexes, immutable triggers) with the reviewed migration grammar and
   requires `foreign_keys=1`, `writable_schema=0`, `ignore_check_constraints=0`; its digest is part of
   the review binding and is re-proved after every callback in `apply()`.
4. **Refusal on data drift.** The target snapshot (id + per-row sha256 of attributes and storage
   classes for `tracks`, `catalog_import_mappings`, `audit_events`) must equal the reviewed snapshot,
   minus the exact rows this batch wrote; actor row and MFA requirement must be unchanged; the final
   proof uses direct PDO reads that fire no `QueryExecuted` callback.
5. **Idempotent re-run.** Retained mappings form an exact prefix of the review; a replay creates no
   draft, mapping or audit; a fresh identical review records `skip`.
6. **No partial writes.** Each segment is one transaction; any refusal or exception rolls back track,
   batch, mapping and audits together; a nested transaction is refused.
7. **Standalone admission.** `DB::transactionLevel() === 0` and the driver must be SQLite; anything
   else is refused before the source lease is taken.

### Every driver assumption found

| Site | Assumption |
| --- | --- |
| `CatalogDraftImporter::standalone()` line 425 | `DB::getDriverName() === 'sqlite'` |
| `CatalogDraftImporter` line 25 docblock | "Trusted private SQLite staging" |
| `CatalogDatabaseEvidence::schema()` | `PDO::ATTR_DRIVER_NAME`, `PRAGMA foreign_keys`, `PRAGMA writable_schema`, `PRAGMA ignore_check_constraints`, `sqlite_master` rows and SQLite DDL grammar in `expectedSchema()` |
| `CatalogDatabaseEvidence::rows()` | driver check, `PRAGMA table_info`, `typeof()` storage classes, double-quoted identifiers |
| Transaction and locking | SQLite serialises the whole database for a writer, so plain reads inside the transaction are stable until commit; the only explicit locks are `lockForUpdate()` on the actor, the batch and all mappings |
| `database/migrations/2026_10_06_237000_catalog_import_progress.php` | already InnoDB-aware: `SIGNAL` triggers on MySQL, replacement triggers on SQLite only (not owned, unchanged) |
| `scripts/migration/persistent-catalog.php` and `.mjs` | bound to `scripts/dev/persistent-content-bootstrap.php`, which pins `DB_CONNECTION=sqlite` and the installation's `database.sqlite` (lines 134, 207, 298 of a file this lane does not own) |
| `tests/Feature/PersistentCatalogDraftImportTest.php` | forces a named in-memory SQLite connection, SQLite DDL and PRAGMAs in `changeSchema()`, `INSERT OR REPLACE`, `PRAGMA recursive_triggers`, SQLite quoting in a `QueryExecuted` listener |

## Design

`CatalogDatabaseEvidence` gains a MySQL branch next to the unchanged SQLite branch; every other
driver is refused with the same `catalog_target_schema_invalid` / `catalog_target_invalid` errors.
`CatalogDraftImporter::standalone()` admits `sqlite` or `mysql`. The SQLite branch's statements,
expected rows and hashes are not changed, so SQLite outputs stay byte-for-byte identical.

MySQL equivalents (information_schema and InnoDB):

| SQLite proof | MySQL proof |
| --- | --- |
| `PRAGMA foreign_keys = 1` | `@@session.foreign_key_checks = 1` |
| `PRAGMA writable_schema = 0` | `@@session.unique_checks = 1` (the InnoDB switch that lets duplicates through) |
| `PRAGMA ignore_check_constraints = 0` | `@@session.sql_mode` contains `STRICT_TRANS_TABLES` or `STRICT_ALL_TABLES` |
| implicit single-writer serialisation | `@@session.transaction_isolation = REPEATABLE-READ`, `@@session.autocommit = 1`, and every evidence read inside the transaction is `SELECT ... FOR SHARE`, whose next-key locks block concurrent inserts and updates of the read tables until commit |
| `sqlite_master` rows of the owned tables | `information_schema` TABLES (base table, InnoDB, collation), COLUMNS (type, nullability, default, extra, key, collation), STATISTICS (index name, uniqueness, columns), REFERENTIAL_CONSTRAINTS and KEY_COLUMN_USAGE (referenced table/column, delete and update rules) and TRIGGERS (timing, event, orientation, statement), compared with the rows the reviewed migration grammar produces for the connection's configured charset and collation |
| `PRAGMA table_info` + `typeof()` | `information_schema.COLUMNS` in ordinal order; the per-row "storage class" is the declared `DATA_TYPE`, or `null` for SQL NULL (strict mode fixes the stored type) |
| double-quoted identifiers | backtick-quoted identifiers |

Known engine differences that cannot be made identical and are documented instead:

- MySQL DDL commits implicitly. DDL from another session waits on the metadata lock held by the
  open import transaction, so it cannot interleave; DDL issued from inside the importer's own session
  (a callback) commits what was already written before the post-callback proof refuses. The test for
  "schema change inside a callback" therefore asserts refusal plus the committed state on MySQL, and
  the rollback only on SQLite.
- `TRUNCATE` fires no trigger on MySQL; it is DDL that needs the DROP privilege and is refused by the
  foreign key on `catalog_import_batches`. A least-privilege application user must not hold DROP.
- `information_schema` does not list TEMPORARY tables, and `INNODB_TEMP_TABLE_INFO` needs the PROCESS
  privilege, so a same-session temporary shadow of an owned table is not detected on MySQL.

The CLI wrapper stays bound to the SQLite persistent installation because its bootstrap is not owned
here; the domain service is what becomes MySQL-capable. A staging (MySQL) entry point is recorded as
remaining work.

## Assumptions

- The private disposable MySQL in this container is Oracle MySQL 8.0.46; CI uses 8.4.11. The
  information_schema columns used exist in both; `EXTRA = DEFAULT_GENERATED` has been reported since
  8.0.13. The 8.4 run is CI's to prove.
- The test suite may rebuild the whole database per case on MySQL, as `SupportAttachmentsTest` and
  `FreeGrantSchemaRecoveryTest` already do, admitted by `Tests\Support\DisposableNativeDatabase`
  (dedicated schema `vaseyaudio_catalog_import` locally, the CI job's disposable database in CI).
- Synthetic fixtures only (`NormalizedCatalogFixtures`, `SYNTHETIC` titles, `synthetic_fixture`
  acquisition method). No real artists, prices, rights, accounts or credentials.

## Acceptance criteria

1. The existing 47 cases pass unchanged on SQLite with the same statements and outputs (the SQLite
   branches of `CatalogDatabaseEvidence` are textually unchanged; the suite's SQLite path is unchanged).
2. The same suite plus the new cases passes on the private MySQL 8.0.46 with saved evidence.
3. Red before green: the new suite against the unchanged importer fails on MySQL with the former
   refusal (`catalog_target_schema_invalid` from `schema()` and the `standalone()` refusal); the trimmed
   output is saved before the fix.
4. `python3 scripts/ci/test-phpunit-shards.py` passes with the test file registered in
   `include_files` (it branches on the driver), and the sharder's `--mysql-native-selection` dry run
   accepts the policy.
5. `vendor/bin/pint --test` passes on every changed PHP file.
6. Evidence under `docs/verification/mysql-draft-importer-20261010/` with trimmed logs, no absolute
   `/home` or `/tmp` paths, no secrets.

## Tests to add (both engines unless stated)

- Drift: MySQL equivalents of every `schemaDrift` case (`DROP TRIGGER`, a no-op foreign trigger,
  `DROP INDEX`, `ADD COLUMN`, `foreign_key_checks = 0`, `unique_checks = 0`, non-strict `sql_mode`,
  `ON DELETE CASCADE` on the actor foreign key, nullable `actor_id`), plus a MySQL-only engine case
  if `ALTER TABLE ... ENGINE` is admitted with the foreign keys present.
- Duplicate apply: the same reviewed segment applied again after a committed segment, after a
  rolled-back segment and with an overlapping limit creates exactly one draft, mapping and audit set
  per record and leaves the evidence identical.
- Partial failure mid-apply: a failure raised while the second mapping of a segment is being created
  rolls back the first draft, its audits, the batch and the first mapping (no rows), and the next
  apply of the same review succeeds with exactly one row set per record.
- MySQL cross-session metadata lock: DDL from a second connection against an owned table while the
  import transaction is open waits (lock wait timeout) instead of interleaving. This can only run on
  MySQL; on SQLite it would need `markTestSkipped`, which requires a `database-sqlite-skips.json`
  census entry that this lane does not own. It is therefore written as a MySQL-only method only if
  the census edit is confirmed in scope; otherwise it is left in "remaining".

## What cannot be proven in this container

- MySQL 8.4 (CI's version): only 8.0.46 is installed.
- Hosted Foundation CI: no dispatch; the policy self-tests and the sharder dry run are the CI
  evidence here.
- The browser specs and the native `persistent-catalog.test.mjs` suite need `public/build`; it is
  kept absent so the seven HTTP tests do not 409, and the `.mjs` files are unchanged.
- Real source data, staging's actual MySQL host, privileges or network: the importer is proved on a
  disposable server with the CI environment variables only.

## Outcome notes (added after execution)

- The cross-session lock proof was not written as a MySQL-only skipped method: registering the skip in
  `scripts/ci/database-sqlite-skips.json` makes `scripts/ci/test-focused-tests.py` fail, because that
  self-test pins the census count of the `store-foundations` focused suite, and editing it is outside
  this lane. The proof is instead a MySQL-only branch of a both-engine case
  (`test_nested_importers_and_foreign_sessions_cannot_interleave_while_a_segment_is_open`), whose
  SQLite half proves that a second importer in the same process is refused mid-segment. No case skips
  on either engine, so the censuses are unchanged.
- `docs/content-onboarding-readiness.md` was left untouched: it describes the content pack and the
  operator sequence, not the importer. The importer's own record,
  `docs/verification/persistent-catalog-draft-import.md`, is outside this lane and still says the
  command rejects non-SQLite targets; see the integrator notes.
- The MySQL `ALTER TABLE ... ENGINE` drift case was not added: MySQL itself refuses an engine change
  on a table that participates in a foreign key (error 3776, probe in README.md).

## Second-session notes (resumed after a usage-limit cut-off)

- The first session left the domain change, the test suite, the selection entry, the plan, the
  evidence README, the SQLite runs and the MySQL red run; its MySQL green run had been started as four
  shards and was cut off before any shard produced a result. The second session kept all of that work,
  re-verified it (SQLite blocks unchanged by string comparison, Pint, both policy self-tests, the
  sharder dry run, the SQLite suite, a first-hand base-commit baseline, a first-hand red re-run) and
  completed the MySQL green run; see README.md for each result and which session produced it.
- The second session's first MySQL attempt (four shards as one background job) was terminated by the
  container after 972 s: all four PHP processes received SIGTERM at once while the runner shell and
  `mysqld` survived, and no shard had written a result. The run was repeated as four detached queues in
  which every test method is its own PHPUnit invocation with its own JUnit file, so a repeated
  termination could lose at most one group. The queues finished: 19 invocations, 50 tests, 356
  assertions, no errors, failures or skips, on both `DisposableNativeDatabase` admission paths
  (README.md, "MySQL 8.0.46: green after the fix").
- Acceptance criteria 1–6 are met for this container: SQLite 50/288 with the 47 original cases
  identical to the base-commit baseline (47/232, reproduced first-hand); MySQL 8.0.46 50/356; red
  before green reproduced first-hand at the three former refusal sites; policy self-tests and the
  sharder dry run pass with the file in `include_files`; Pint passes; evidence carries no absolute
  paths or secrets. MySQL 8.4 remains CI's to prove.
