# Review: lane `importer` (harness/mysql-draft-importer)

Reviewed head `fc67774b62a0ff99059b3c08a63681d1cffb5b5b` (range from `f57e7256`). Worktree untouched; my mysqld on port 33132 is shut down.

## Decision: APPROVE WITH CONDITIONS

## Scope: pass
All 17 files are owned; the selection JSON adds only this test. No protected files, secrets, absolute paths or real data.

## Findings
1. **MEDIUM: the `FOR SHARE` locking read is not tested** (`CatalogDatabaseEvidence.php:288`). Removing it (mutant m1) still passes `test_nested_importers_and_foreign_sessions...` (OK, 14 assertions). None of the test's four 1205 outcomes depends on the locking read:
   - DDL waits on the metadata lock.
   - `UPDATE users` waits on `actor()`'s `lockForUpdate`.
   - `INSERT INTO tracks` waits because trigger `cde_1_insert` updates `catalog_discovery_epoch` row 1, which the importer's own track insert has already X-locked.

   My probe made a foreign `INSERT INTO audit_events` (a table with no triggers) inside the callback:
   - Original code: the insert gets 1205.
   - Under m1: the insert is admitted **and `apply()` still commits**, because the REPEATABLE READ snapshot hides the row from the final proof.

   So the guard matters, but nothing tests it. The README's wording ("time out with error 1205 on the open transaction's metadata and next-key locks") misattributes the cause of these waits.
2. **MEDIUM: cross-schema foreign-key drift is admitted** (`CatalogDatabaseEvidence.php:133-136, 204-206`). The schema comparison never reads `REFERENCED_TABLE_SCHEMA` (or `UNIQUE_CONSTRAINT_SCHEMA`). My probe re-created `catalog_import_mappings_actor_id_foreign` to point at `reviewer_other_schema.users(id)`, and `schema()` admitted it. The README claims the owned objects are compared exactly; this drift is missed.
3. **LOW: some session guards have no test.** The isolation level, autocommit, `DATABASE()` and `ATTR_STRINGIFY_FETCHES` checks (`:102-111`) are never exercised. Mutant m6 (isolation check removed) survives by construction, since no case changes these settings. My probe shows the guards themselves work: READ COMMITTED and `autocommit=0` are both refused with `catalog_target_schema_invalid`.
4. **LOW:** `private const OWNED` (`:25`) is never used; the same list is repeated as a literal at `:125`.
5. **INFO:** Three points:
   - Same-session DDL commits on MySQL; this is documented and tested, the CLI verify closure (`persistent-catalog.php:81`) issues no DDL, and foreign DDL waits.
   - A segment's whole-table share locks block other writes to `users`, `tracks` and `audit_events` (fine for staging).
   - Outside this lane: shard 8 is under-weighted by about 30 minutes until timings are refreshed, and `persistent-catalog-draft-import.md` is stale.

## Conditions (owned files only)
1. In `tests/Feature/PersistentCatalogDraftImportTest.php`, add to the MySQL branch of the nested or lock test a foreign-session write that only the locking read can block, such as a trigger-free `INSERT INTO audit_events (...)`, and assert 1205. Confirm locally that the case fails with `FOR SHARE` removed. In the lane README, name the lock that produces each 1205 outcome.
2. In `CatalogDatabaseEvidence.php`, compare `k.REFERENCED_TABLE_SCHEMA` (expected to equal `DATABASE()`) in the foreign-key rows, and add a MySQL drift case that points a foreign key at another schema's `users`. Re-run the drift data sets on MySQL and record the result.
3. Remove `OWNED` or use it to build the `IN (...)` list.

Optional: add MySQL drift assertions for READ COMMITTED and `autocommit=0`.

## Verified by running
- SQLite: the direct runner in the worktree gave OK (50 tests, 288 assertions). JUnit matches the committed `sqlite-new-suite.junit.xml` case for case.
- MySQL 8.0.46 with CI-marker-admitted disposable databases, all OK with the lane's assertion counts:
  - drift: 9 tests, 54 assertions
  - REPLACE: 5 tests, 20 assertions
  - nested: 14 assertions
  - partial: 20 assertions
  - overlap: 25 assertions
  - schema callback: 20 assertions
  - final schema: 17 assertions
- Mutants, with the override confirmed live by a poison mutant:
  - m2 (triggers not compared), m3 (strict-mode check removed), m4 (`foreign_key_checks` check removed): **killed** by drift #0, #8 and #6.
  - m1 (no `FOR SHARE`): **survived** the suite; killed only by my probe.
  - m6 (isolation check removed): survives by construction.
- Probes: the cross-schema foreign key was admitted; READ COMMITTED and `autocommit=0` were refused.
- The committed MySQL JUnit recomputes to 50 tests, 356 assertions, 0 errors/failures/skips, 50 distinct cases.
- Pint passed. Self-tests: 52 OK and 50 OK. The sharder reports 1815 tests in 164 files, with this file in shard 8.

## AGENTS.md invariants
Drafts only; no money, grant, consent or download paths. Immutability holds on both engines; the MFA gate, source ids and audits are unchanged.
