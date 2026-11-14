# Independent review: lane DEF-OPS, tested SHA faceb5f8

- Worktree `/home/user/wt/defops`, branch `harness/deferred-ops-20261024`, base `origin/main` 707649a6, tested `faceb5f8b74ed2520c6cd52ab9719f07c64151cc`, HEAD `b6126bae`. The faceb5f8..b6126bae change is the packet README plus one test line (`TestCommerceEvidenceCommandTest`); every run below used HEAD b6126bae, so the code under test is faceb5f8 plus that test line.
- Reviewer: independent subagent, 2026-10-24. Nothing was pushed, committed, commented or dispatched. Mutations ran only in a scratch copy (`git archive HEAD` with a hardlinked `vendor/`, deleted afterwards). At the end `git status --short` printed nothing and `public/build` was absent. I started one private `mysqld` (PID 20396, `/opt/mysql-8.4.11`, port 3497, socket `/tmp/dfr-my/s`, datadir in the scratchpad), stopped it with `mysqladmin shutdown` and touched no other process.

## Verdict: APPROVE WITH CONDITIONS

There is no P0, P1 or P2. Each of the seven focus areas holds its safety property. A boundary file that is missing, linked, malformed or missing a key makes the production hold fail closed. The prune deletes only rows with `expiration <= cutoff`. The evidence read on MySQL is enforced as read-only by the server itself. The C1 equality checks are exact. The browser-stage assertions are unchanged.

The condition is about the record, not the code. The packet says the listing "kept every check of the old `verified()` ... in the same order of strictness". On MySQL that is not exactly true (P3-1). Correct that sentence, or apply the small fix below, and put P3-1 to P3-3 in the per-lane follow-up batch under the freeze-and-batch rule. None of them blocks the merge.

## Findings

### P3-1: the staff-address exclusion is narrower than before on MySQL (TestIdentityLinkRelay::verifiedBatch)

- **Old code:** `User::whereRaw('LOWER(email) = ?', [$recipient])->where('is_admin', true)->exists()`. The comparison ran entirely in MySQL, under the `users.email` collation (`utf8mb4_unicode_ci` by default). That collation ignores accents and trailing spaces (PAD SPACE).
- **New code:** `LOWER(email) IN (?, ...)` returns the raw `email` values. They are then matched in PHP with `$staff[strtolower($email)]` against the exact recipient. PHP `strtolower` changes only ASCII letters, so a staff row that MySQL counts as equal can miss in PHP.
- **Failure path, reproduced on native MySQL 8.4.11.** I wrote a scratch probe test (not committed) that creates staff users `ädmin@example.test` and `Admin2@example.test ` (trailing space) and test captures for `admin@example.test` and `admin2@example.test`. Its output was `old-query-accent=true old-query-padded=true` and `accent-listed=true padded-listed=true`. The old query would have withheld both captures; the new code lists both. On SQLite there is no difference, because both versions compare ASCII-only.
- **Impact: low.** It needs an admin-created staff address that differs only by accent or trailing space from a synthetic test address. It is reachable only in local, testing or staging, and only by an MFA-enrolled operator with a hidden password and `--attest`. The link it exposes signs into the customer test account, not the staff account. Nothing else widened:
  - `is_admin` is still required (mutation M4a fails 2 tests);
  - the query is parameterized with placeholders and chunked at 500;
  - the recipient is the normalized lowercase printable-ASCII synthetic address from `CustomerIdentityPolicy::email`;
  - list output and audit rows carry counts only (`not_examined` included), never addresses or links.
- **Test gap:** mutation M4b, which drops `LOWER()` so the lookup becomes case-sensitive, survives all 21 tests. No test stores a staff address in mixed case.
- **Suggested fix.** Let the database decide membership. For example, select the recipient back through a join, `SELECT r.addr FROM (VALUES ROW(?),...) r(addr) JOIN users u ON LOWER(u.email) = r.addr AND u.is_admin`. Or, when any returned staff email's `strtolower` is not in the recipient set, fall back to the old per-recipient `exists()` for that chunk. Add a mixed-case staff-email test. An accented-email case only shows the difference on MySQL.

### P3-2: SQL comments can get a locking clause past the evidence statement guard; on MySQL the server still enforces read-only

- **Bypass:** `isPlainSelect('select 1 for /* c */ update')`, `'select 1 FOR/**/SHARE'`, `'select 1 lock/**/in share mode'` and `"select 1 for -- x\nupdate"` all return `true`. Case and whitespace (tab, newline) variants are caught.
- **Leading comment:** `/* lead */ select 1` is refused, because the guard needs `select` at the start. That fails closed.
- **MySQL behaviour:**
  - The server refuses `SELECT ... FOR UPDATE` inside `START TRANSACTION READ ONLY` (checked: ERROR 1792). It accepts `FOR SHARE`, `LOCK IN SHARE MODE` and `SELECT ... INTO @v`.
  - A commented `FOR SHARE` could therefore take S locks for the length of the snapshot. That is not a data change.
  - `INTO` is caught however it is spaced, because `\binto\b` does not depend on surrounding whitespace.
- **Impact: very low.** Every statement comes from the command's own Eloquent queries. Laravel emits `for update` and `lock in share mode` exactly, so the realistic regression, someone adding `lockForUpdate()`, is caught. Optional hardening: strip `/* */`, `-- ` and `#` comments before matching.

### P3-3: the new backup.sh guards are correct but are not each pinned by a test

- **M5 survived:** removing the two `=~ ^[0-9]+$` checks, so both empty sizes compare equal again, still passes all 74 tests.
- **M5b survived:** restoring the old order, where the record is written even when `stat` fails, still passes all 74 tests.
- **Why the new tests still fail the old script:** with `set -euo pipefail`, the `remote=$(remote_size ...)` assignment in the `&&` chain now carries a failed listing's status. The old `[ "$(...)" = "$(...)" ]` dropped it. That one change is what makes the new tests fail against the old script.
- **What is never tested:** a listing that succeeds but prints no size, together with a record that has none, and a failed `stat`.
- **The code is right as written:** both sizes must be digits and equal, the size is read before the record is written, and a failed write removes `.new`. Retry convergence is unchanged: the retry directory is still removed only after a verified transfer, and a missing or invalid record makes the next run hit the existing "remove that object first" refusal instead of "already shipped". Optional follow-up: an rclone wrapper whose `lsf --format sp` exits 0 with empty output.

### Notes (no action needed for this lane)

- **N1, packet accuracy:**
  - "every `tests/ops/*.py` rc 0, 28 files": there are 20 files (28 is the validator's test count).
  - The timing-fallback warning now lists 77 files in total; the packet says the new entry joins "77 other files".
  - The P3-1 equivalence sentence is inaccurate, as above.
- **N2, pre-existing test fragility (not introduced here):** `TestCommerceEvidenceCommandTest::database()` iterates `Schema::getTables()`, which on Laravel 13 / MySQL returns every non-system schema on the server. My first native run errored (`Table 'vaseyaudio_dfr.u' doesn't exist`) because I had created a probe schema on the same private server. After I dropped it the test passed. CI's single-schema service database is unaffected, but a native run on a server shared with another lane's schema would fail the same way. Passing the schema, or filtering on `DB::getDatabaseName()`, would harden it.
- **N3, malformed boundary:** if the boundary file triggers a non-catchable compile fatal (for example a redeclared class), the production validator exits 255 without printing a `FAIL` line. Every `Throwable` case I tried (missing, linked, a directory in its place, syntax error, missing key, non-array `stagedFlags()`) printed `FAIL runtime.unavailable` with rc 1. The deploy checks the exit status, so a 255 still refuses.

## Focus-area verification

1. **Production hold (O3 R6).** Confirmed.
   - The boundary maps the four keys to exactly the old config keys: `free-grants.operative_enabled`, `inquiries.operator_notifications_enabled`, `discovery-sitemap.enabled`, `customer-listening.v2_promotion_enabled`.
   - The env-file layer still loops over the four names with the same `false` / `(false)` rule. The config layer uses `config($key) === false` over `$holdKeys`.
   - Behavioural fail-closed checks on a scratch root (validator copy plus linked vendor):
     - valid boundary with bad arguments: `FAIL runtime.arguments_shape` (the boundary loaded);
     - boundary missing, linked, replaced by a directory, syntax error, `DISCOVERY_SITEMAP_ENABLED` removed, or `stagedFlags()` returning a string: `FAIL runtime.unavailable`, rc 1.
   - Shipping: production releases are a fresh full `git checkout` of the SHA (`forge-deploy.sh` step 1, sealed and diff-checked). There is no `.gitattributes` or `export-ignore`, and no sparse checkout or file removal, so the tracked `scripts/ops/capability-boundary.php` is always present. The staging validator already requires it. `build-kit.py --check`: `build-kit: ok, 13 files`.
   - M2 (drop one hold key from `PRE_LAUNCH_HOLDS`): 14 failures in `test_production_runtime_validator.py`.
2. **PruneExpiredCacheRows (O4).** Confirmed.
   - Both statements keep `expiration <= $cutoff`, and the cutoff is read once.
   - A TOTP replay record (`forever` = now + 315360000) and a held lock (`DatabaseLock` expiration is now + seconds, or now + `lock_timeout`, default 86400) are never at or below the cutoff.
   - Separate connections follow Laravel: `CacheManager` uses `lock_connection ?? connection ?? null`, `lock_table ?? 'cache_locks'`. Pairs are de-duplicated by connection and table, and failover cycles terminate.
   - Stores that are not database stores are skipped. An incomplete database store fails before any delete.
   - `filament.cache_store` defaults to null, which is the default store (`NotificationMailbox`).
   - Runs: SQLite 26/26 and native MySQL 26/26 (134 assertions).
   - M1 (delete live rows in non-default stores): `test_a_database_store_used_only_by_the_limiter_or_by_filament_is_pruned_too` fails.
3. **TestIdentityLinkRelay.** Admission is unchanged: capture profile, local/testing/staging only, an MFA-enrolled named staff operator, hidden password, `--attest`. `--limit` is validated (1 to 200, default 50) before any read. Ordering is newest mtime first (ties by id), and symlinks and junk entries are counted as withheld. Print still verifies one id through the same checks. The challenge lookup and the staff lookup are each one query per listing. The one difference is P3-1. M4a fails 2 tests; M4b survives.
4. **SyntheticRehearsalBuilder and backup.sh.**
   - Rights: `provenance_reference` and `sample_disclosure` must both strictly equal `SyntheticRehearsalCatalog::rights()`.
   - Media: an interrupted source must match the regenerated SHA-256 (`hash_equals`), and so must a ready derivative's parent.
   - No production admission (the policy is unchanged; the command refuses production).
   - The GD/zlib limitation is acceptable: it fails closed, and reset is the documented remedy.
   - The rerun and interrupted-resume tests still pass with real ffmpeg and GD generation.
   - M6 (drop the derivative check and the disclosure check): 2 tests fail, 4 failures in all.
   - backup.sh: see P3-3.
5. **TestCommerceEvidence (K4).**
   - MySQL framing is `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ`, `START TRANSACTION READ ONLY`, the reads, then `ROLLBACK`. It is skipped inside a caller's transaction.
   - The guard switches on only after the framing and off before `ROLLBACK`. It is registered once per `Connection` (a `WeakMap`), holds only its switch, and goes inert in `finally`.
   - M8 (never switch it off): 2 tests error.
   - M3 (case-sensitive locking regex): 1 test fails.
   - Native MySQL, all passed: `test_the_statement_guard...`, `test_a_write_attempted...`, `test_the_snapshot_loads_only...`, and the reference-forms test on rerun (124 assertions).
   - M7 (`START TRANSACTION` without `READ ONLY`) fails natively.
   - M9 (guard disabled, framing kept) still passes the write-attempt test natively: MySQL refuses the write on its own.
   - Output stays read-only. JSON is written with `OUTPUT_RAW`; text values pass through `OutputFormatter::escape`.
6. **Browser stage.**
   - The diff changes only assertion messages, adds helpers and adds two helper tests. Every assertion condition is unchanged and nothing is skipped.
   - `safe_diagnosis` echoes only a `{phase, exception_class}` line whose phase is in the same 16 phases as `tests/browser/bootstrap.php` and whose class name is valid; anything else is dropped.
   - Local result without `public/build`: 14 run, 4 fail (`installation_diagnostics` / RuntimeException, manifest missing, ClamAV prints no signature date). That matches the packet's diagnosis.
   - I did not reproduce the build-present 12/14 result (it needs `npm run build`).
7. **Census.**
   - `include_files` gains `tests/Feature/TestCommerceEvidenceCommandTest.php` (still sorted).
   - Regenerated in the scratch copy: "Proved 2516 selected MySQL-native tests in 240 files (of 9616 discovered tests in 700 files)".
   - The `.github/workflows/final-verification.yml` and `.gitlab-ci.yml` comments match.
   - `database-sqlite-skips.json` and `database-mysql-skips.json` are untouched. `test-phpunit-shards.py`: 52 OK.
   - Dependabot: `tecnickcom/tc-lib-*` covers every `tc-lib` package in `composer.lock`. The only other name pinned in `profile-assets.json`, `tc-font-mirror`, is not a Composer package.

## Commands and results (HEAD b6126bae, PHP 8.4.26, PHPUnit 12.5.34)

| Command | Result |
| --- | --- |
| Direct PHPUnit on SQLite (brief's command): Prune, TestIdentityLinkRelay, TestCommerceEvidence, StagingSyntheticCatalog | OK, 89 tests, 2702 assertions (6m43s) |
| Same command on native MySQL 8.4.11: `PruneExpiredCacheRowsTest` | OK, 26 tests, 134 assertions |
| Native MySQL: TestCommerceEvidence `--filter` reference forms, guard, write attempt, columns | 3 OK. Reference forms errored on my probe schema (N2); rerun alone OK, 124 assertions |
| `for f in tests/ops/*.py` (20 files) | all OK (backup 74, validator 28, kit 50, capability 17, monitoring 44, …) |
| `scripts/ci/test-*.py` | all OK except `test-related-browser-stage.py`: 14 run, 4 failures, environmental as diagnosed |
| `python3 ops/production/build-kit.py --check` | `build-kit: ok, 13 files` |
| `phpunit-shards.py --mysql-native-selection` (scratch copy) | 2516 / 240 / 9616 / 700 |
| Native SQL probes | `FOR UPDATE` in a READ ONLY transaction gives ERROR 1792; `FOR SHARE` and `INTO @v` are allowed; `LOWER(email) = ?` matches `ädmin@` and `Admin2@... ` under utf8mb4_unicode_ci |

## Mutations (scratch copy only)

| # | Mutation | Outcome |
| --- | --- | --- |
| M1 | Prune non-default store targets with cutoff `PHP_INT_MAX` (deletes live rows) | killed (1 test) |
| M2 | Drop `DISCOVERY_SITEMAP_ENABLED` from `PRE_LAUNCH_HOLDS` | killed (14 failures) |
| M3 | Evidence guard locking regex made case-sensitive (lowercase `for update` accepted) | killed (1 test) |
| M4a | Staff lookup without `is_admin` (widened) | killed (2 tests) |
| M4b | Staff lookup without `LOWER()` | **survived** (P3-1 gap) |
| M5 | backup.sh digit checks removed | **survived** (P3-3) |
| M5b | backup.sh record written even when `stat` fails | **survived** (P3-3) |
| M6 | Drop ready-derivative hash check and `sample_disclosure` equality | killed (2 tests) |
| M7 | `START TRANSACTION` without `READ ONLY` (native MySQL) | killed |
| M8 | Guard never switched off | killed (2 tests) |
| M9 | Guard disabled, framing kept (native MySQL) | test still passes: the server refuses the write (defence in depth confirmed) |

## Untested conditions

- The browser stage with `npm run build` and fresh ClamAV signatures, and the Chromium/WebKit journeys. These need the hosted runner.
- The rest of `TestIdentityLinkRelayCommandTest` and `TestCommerceEvidenceCommandTest` on native MySQL, other than the cases named above.
- `StagingSyntheticCatalogTest` on MySQL.
- A real Forge production deploy, and `validate-runtime.php` against a full production environment file (the existing Python suite covers that through the scratch environment).
- A real rclone remote.
- The `fstat` inode race (no seam, as the packet states).
