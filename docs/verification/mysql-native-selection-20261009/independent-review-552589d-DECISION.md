# Re-review: harness/mysql-native-selection @ 552589d vs main 596d2133

**DECISION: APPROVE WITH CONDITIONS.** No finding blocks the merge. The worktree was not modified, and `git status` is clean.

## Evidence

- **Self-tests:** rc 0 for all nine gate suites (27/55/50/30/12/7/5/48/14 tests). related-browser-stage: rc 1, the 4 known environmental failures.
- **Real discovery** (`--shards=8 --mysql-native-selection`): rc 0, 1,765 cases in 163 of 557 files. Hashes match the note. Generated files deleted.
- **`DisposableNativeDatabaseTest`** (SQLite, direct PHPUnit, no `public/build`): rc 0, 8 tests, 25 assertions.
- **Mutations, all killed:** 14 Python (sharder, verifier, both collectors, both workflows; scratch copy, hash-checked restores) and 5 on the PHP helper (one condition dropped each).

## Findings

1. **Gate integrity holds (Info).**
   - The census must be discovered, disjoint from the SQLite census, and inside the selection. Both the sharder (`phpunit-shards.py:266-276`) and the verifier (`database-receipts.py:520-528`) enforce this.
   - Each engine's skips must match its census exactly (`:484-488`).
   - Both collectors (`database-receipts.py:829-836`, `gitlab-database-receipts.py:375-381`) require that:
     - MySQL ran exactly the recomputed selection;
     - the MySQL skips equal the census;
     - every MySQL skip ran on SQLite;
     - every SQLite skip ran on MySQL.

   So no identity can go unexecuted on both engines. All three policies are hashed (`:49`, `:562-568`).

2. **The selection can shrink without a policy edit (Medium).**
   - Renaming a `*Migration*Test.php` out of the pattern silently drops it from MySQL. Nothing pins the selected set.
   - New driver-branching tests default to SQLite-only. My scan found 44 unselected files that call `getDriverName()`, consistent with the 46-file residual disclosed in the note (lines 70-130).
   - The residual is disclosed, but nothing enforces it.

3. **The census is correct (Info).** I checked all 14 sites, including `FreeGrantSchemaRecoveryTest:234` and `CustomerListeningFreshnessTest:135`. Each one:
   - skips unconditionally on `getDriverName() !== 'sqlite'`;
   - has a body that only works on SQLite (`ProductionFreeGrantSchemaTest` reaches its skip through `sqliteOnlyRecovery()` at `:297`);
   - has no env gate.

   The attachment env gate is correctly left out, so a skip there turns the run red.

4. **Option A is adequately safe (Low).**
   - `DisposableNativeDatabase.php:35-39` requires all of: the marker, `CI=true`, the testing environment, an exact `getenv('DB_DATABASE')` match, and a plain, non-prod name.
   - `:memory:` and a DB_URL that diverges both fail closed.
   - `dropAllTables` is scoped to the current schema. The 307 existing `FinalizationDatabaseMigrations` files already wipe whatever database they target, so the marginal risk is small.
   - Residual risk: copying the CI env onto a shared daemon would wipe its `DB_DATABASE`.
   - The `performance_schema` read is a read-only, filtered SELECT. That is fine on a per-job container.
   - The env vars appear only on the MySQL jobs (GitHub `:190,193`, GitLab `:139,142`), and they are pinned there.

5. **Timeouts are disclosed (Low).**
   - GitHub's 210 minutes is reasonable: the worst estimate is about 141 minutes plus cases that were not estimated, against GitHub's 6-hour cap.
   - GitLab's 4 shards are estimated at up to 211 minutes, which exceeds GitLab.com's documented 180-minute cap. This is disclosed in `.gitlab-ci.yml:113-116`, the CHANGELOG and the note, and it would fail loudly rather than pass.
   - The SQLite jobs are unchanged.

6. **The merge is correct (Info).** #70's rule is intact at `database-receipts.py:472-474`. It is engine-agnostic, so it also covers the 43 MySQL-census skips that carry setUp assertions. Its self-test is retained at `test-database-receipts.py:232-240`.

7. **Stale items (Low).**
   - `test_exact_reviewed_sqlite_skips_and_no_mysql_skip` is misnamed.
   - No MySQL fixture has a skip with assertions.
   - `gitlab-database-receipts.py:314` annotates a 2-tuple but returns 3 values.
   - `ci-database-receipts.md` still says the policy is "not inferred from filenames".
   - Line 3 of the note still names base `0f9b39c`.

8. **No overclaims (Info).** The docs consistently state four things:
   - no hosted MySQL run has happened;
   - the local evidence is MySQL 8.0.46, not 8.4;
   - the census is exact only after a MySQL run;
   - the attachment blocker closes only "once a hosted MySQL run passes".

## Conditions

- **C1:** Claim no MySQL-native coverage, census exactness or attachment-blocker closure until one exact-SHA Foundation run passes on hosted MySQL 8.4. Then regenerate `phpunit-timings-mysql.json`; 78 selected files are currently untimed.
- **C2 (follow-up CI PR, before the final integrated Foundation run):**
  - Add a self-test that fails when any `tests/` file using `getDriverName`, `ATTR_DRIVER_NAME` or `['driver']` is neither selected nor in a reviewed residual list. That list starts with the current 46 files.
  - Pin the selected migration-pattern files.
- **C3:** Before GitLab hosted results count as evidence, either confirm a runner timeout of at least 240 minutes or make a reviewed move to 8 GitLab MySQL shards. Record this as an open blocker.
- **C4:** In the handoff or runbook, state that `VA_CI_DISPOSABLE_MYSQL=1` is only for a private, disposable mysqld.
- **C5 (optional):** Fix the items in finding 7.
