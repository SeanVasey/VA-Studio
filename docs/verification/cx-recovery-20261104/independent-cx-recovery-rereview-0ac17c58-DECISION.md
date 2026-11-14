# Focused re-review: CX queued recovery and durable scan cursor (fixes only)

- Source: `0ac17c5852097358c970c45b6b3d042563f87b72` (worktree `/home/user/wt/cx`, branch `claude/cx-recovery-review-20261104`)
- Prior decision: REQUEST CHANGES at `fc4c6f38` (`/home/user/notes/cx-recovery-review-fc4c6f38.md`)
- Fix range: `fc4c6f38..0ac17c58`, six commits: `0689cd31`, `f00716f5`, `3152989b`, `d101ca72`, `8b4f31a4`, `0ac17c58`
- Reviewer: independent Claude subagent, 2026-11-04. I made no edits or commits in the worktree, and `git status --short` is empty at the end.
- One stray file: importing `phpunit-shards.py` once wrote a gitignored `scripts/ci/__pycache__/` into the worktree. I deleted it immediately; I had created it myself seconds earlier (timestamps checked). Later tooling checks ran on `git archive` copies in my scratchpad, with `PYTHONDONTWRITEBYTECODE=1`.

## Decision: APPROVE WITH CONDITIONS

Both P1s, P2-1 (by the reported native run) and P3-1 are fixed correctly. P2-2 is fixed in the operator docs and the CHANGELOG, but the ledger rows are not updated yet (N3). The two test-design fixes do not weaken what the tests prove.

One new merge condition remains: N1. The new native worker is a driver-branching helper but is not listed in `branching_helper_files`. As a result, the preflight step `python3 scripts/ci/test-phpunit-shards.py` fails, both on this branch and after integrating the C4 census commit `6d96042f`. The fix is a one-line policy addition.

Conditions:

1. **C1 (N1):** add `tests/Support/transactional-mail-recovery-worker.php` to `branching_helper_files`, in sorted position between `track-publication-manifest-race-worker.php` and `transactional-notification-worker.php`. Then show `python3 scripts/ci/test-phpunit-shards.py` green on the integrated candidate (this branch plus `6d96042f`).
2. **C2:** regenerate the stale count comments at integration, as the packet already says. The stale comment is at `.github/workflows/final-verification.yml:151`: "2709 of 10178 cases in 269 files".
3. **C3 (N3):** update the FUB-1 F3 ledger rows, and add the P3s that the packet says were "deferred to the ledger", before or at integration.

## Prior findings

### P1-1: the recovery test errored on both cases. Fixed.

- **Fix:** `TransactionalMailRecoveryTest.php:53-56` registers the framework connectors through `QueueServiceProvider::registerConnectors()` on a fresh `QueueManager` and swaps it in. This is the fix suggested in the prior review.
- **Run:** RecoveryTest alone gives OK, 4 tests, 62 assertions. That includes both push windows and the queued-only and no-reopen test.
- **Test logic is unchanged:**
  - It still asserts the fault fires exactly once, outside any transaction, with the row `queued`/`version=0`.
  - It still counts the jobs rows (1 after the push, 0 before it).
  - The wake command prints `requested=1 skipped=0`.
  - Every retained job is the original `DeliverTransactionalMail` with `tries=1` and `afterCommit`.
  - Handling every job yields exactly one `OrderReadyMail` and the state `accepted`.

### P1-2: the native tests were not routed. Fixed, with one gap (N1).

**SQLite census:**

- The append is append-only. All 336 prior entries are byte-identical as a prefix, and the two new pairs come last.
- The pinned prefix is preserved: the sha256 of `methods[:150]` (compact JSON) is `d93da4e7a13a8c6aff8f97f363eb6d93dde884bad53c9ae50eeb0ed32514c71c`, the value `scripts/ci/test-focused-tests.py:140` pins.
- There are no duplicates: 338 entries, 338 unique.
- `schema_version` and `purpose` are unchanged.
- Both method names match `TransactionalMailRecoveryNativeTest.php:48` and `:83` exactly. Both methods skip on SQLite only, in `setUp()` (`:28-30`).
- The census is append-ordered, not sorted (`read_census(..., require_sorted=False)`), so appending is correct.

**MySQL selection:**

- The three files are inserted into `include_files` in sorted position. The `include_files` list equals `sorted(set(...))`, as `read_selection()` requires.
- `pattern_files`, `residual_files` and `branching_helper_files` are unchanged, and each is still sorted.
- None of the three files skips on MySQL (no `markTestSkipped` and no driver branch in ScanProgress or Recovery), so `database-mysql-skips.json` needs no entry.

**The gap (N1):** the selection policy also pins every driver-branching helper under `tests/`, and the worker added in `fc4c6f38` is not listed. See N1.

### P2-1: MySQL schema exactness was unexecuted. Answered by the reported execution; I did not reproduce it.

- The packet reports a native run at `790aef20`: OK, 11 tests, 162 assertions.
  - `790aef20` is `8b4f31a4` plus `a2144d4a`.
  - `a2144d4a` touches only the identity `CatalogSocketBinding` and its test.
  - `8b4f31a4..0ac17c58` changes only two docs files.
  - So the native run covers this source's PHP exactly.
  - 11 tests = ScanProgress 5 + RecoveryNative 2 + Recovery 4, which is consistent.
- I accept it as the reported evidence. I did not run MySQL, as instructed.
- The optional runbook note on partial-install residue was not added. It stays a P3 suggestion, not a condition.

### P2-2: docs and CHANGELOG described the old behaviour. Fixed, apart from the ledger (N3) and one wording slip (N4).

I checked `docs/transactional-mail.md:11` and `:15` against the code:

- **Bounds.** `--limit` is 1 to 100 for both commands (`QueueTransactionalMail.php:35`, `WakeQueuedTransactionalMail.php:22`). The examine budget is `4 × --limit` (`:53` and `:41`). Accurate, except "plus one" (N4).
- **Wraparound.** `start()` opens cycle+1 with `after=0` and `high=MAX(id)` only when `after == high` (`TransactionalMailScanProgress.php:21-27`). Refused intents stay in the pending predicate and come up again in the next pass. Accurate.
- **At most one pass of delay** after a crash, an uncertain commit or a rollback. This matches the reservation-before-work design. Accurate.
- **One conflict attempt.** A CAS miss throws inside the frame. The queue command breaks out of its loop and exits with failure (`:65-69`). The wake command ends the invocation (`:61-65`). Nothing retries. "Answered once, without retry" is accurate but vague; see N4.
- **No age threshold** on wake. `wakeQueued()` selects `state='queued'` only, with no time predicate (`TransactionalMailAdapter.php:74`). Accurate. It is documented as an operator step after a broker incident, not a schedule, as P3-5 asked.
- **Refusals.** Wake refuses inside a transaction (`outside()`, `:69`). It refuses unless Mail is faked in local/testing (`TransactionalMailPolicy.php:36`, through `configuration()` at `:70`). It never reopens non-queued rows. Its scan position is separate (`queued_wakeups`). Accurate.
- **CHANGELOG** entry is present and accurate. "Nothing is sent" holds under the default-off policy.

### P3-1: cleanup could leave the physical transaction open. Fixed correctly.

`TransactionalMailScanProgress.php:140-151` now issues `rollBack()` in its own `try`, after the savepoint rollback, under the same guard as before:

- `$begun`
- `getRawPdo() === $pdo`
- `$pdo->inTransaction()`

**Can the second `rollBack()` roll back foreign or peer work?** Not on any reachable path:

- `idle()` (`:110`, `:156-167`) proves that no connection, and no raw PDO, is in a transaction before `beginTransaction()`. PDO also refuses a nested `beginTransaction()`.
- So an active transaction on this exact raw PDO is the one this frame began, unless something inside the frame ended it and issued a new raw `BEGIN`.
- The only code inside the frame is `assertOwned()` and the two closures. Grepping them shows no transaction control apart from this class's own SAVEPOINT, RELEASE, COMMIT and ROLLBACK TO.
- A reconnect changes `getRawPdo()`, so the guard skips.
- An implicit commit, deadlock rollback or confirmed commit clears `inTransaction()` on both drivers. Probed on PHP 8.4.27 with pdo_sqlite: `beginTransaction(); exec('COMMIT')` leads to `inTransaction() === false`. pdo_mysql reads the server status flag.
- A peer process cannot share the handle.

The comment above the block is now stale; see N2.

### Prior P3-2 to P3-7

The packet defers these "to the ledger". The ledger is not touched in this range (N3). None was re-examined beyond confirming the fixes did not change the code they describe.

## The two test-design fixes

### `d101ca72`: view teardown in `finally` (`TransactionalMailScanProgressTest.php:166-178`)

This does not weaken the test. The refusal assertion is unchanged inside the inner `try`:

- `up()` must throw `LogicException`;
- the foreign view must still return `sentinel = 1`.

`$this->fail()` raises `AssertionFailedError`, which is not a `LogicException`, so a wrongful adoption still fails the test. The outer `finally` only removes the view, which `migrate:fresh` (without `--drop-views`) and `db:wipe` would otherwise leave on MySQL.

A cosmetic nit, not graded: if `up()` ever replaced the view with a base table, MySQL's `DROP VIEW IF EXISTS` would raise its own "not VIEW" error. That error would replace the `fail()` message, but the test still fails.

### `8b4f31a4`: the worker receives the `customer`, `delivery`, `payments` and `transactional-notifications` subtrees

This does not mask an authority refusal.

- **Why the worker needs them:** the fixtures enable these default-off test gates in-process only (for example `TransactionalNotificationFixtures.php:18` and `:39`). A fresh worker process boots with the env defaults (`false`), so it refused as `authority_withdrawn`.
- **What still runs for real:** copying the parent's subtrees makes the worker re-verify under exactly the gates the parent used to create the delivery. Every database-backed authority input is still read live from the shared MySQL: account state, `access_version`, purchase and delivery rows.
- **Why a refusal cannot be hidden:** the test asserts the positive path, with the winner `accepted`, `sent=1`, and the loser `submitting` with `sent=0`. A real refusal ends `failed` with zero sends and fails the test.
- **What it never claimed:** this test never claimed to cover refusals. The in-process `TransactionalMailTest` covers them (35/35 green).
- **Option, not a finding:** copying only the specific enabled flags would be narrower, but it would not prove more.

The worker's other guards still hold:

- `:21-28` run before any override:
  - environment `testing`;
  - driver `mysql`;
  - the database equals `DB_DATABASE` and the disposable declaration;
  - mode whitelist;
  - the directory prefix under `storage/framework/testing/cx-mail-native-`, `realpath` equality, not a symlink, mode 0700.
- The new override (`:53-55`) runs only in `deliver` mode, after the guards, over a fixed list of four keys. None of those keys selects a database connection, a filesystem root, the environment or a mail transport.
- The JSON depth bump from 16 to 64 affects parsing only.
- `payments.secret_key` now travels to the child over stdin, never in argv or the environment, and is never echoed. That is the same trust boundary as inherited env in a testing-only worker.

## New findings

### N1 (P2, merge condition): the native worker is not in `branching_helper_files`, so the preflight shard safeguards fail

- **Where:**
  - `tests/Support/transactional-mail-recovery-worker.php:23` (`DB::getDriverName()` matches `DRIVER_BRANCHING`);
  - `scripts/ci/database-mysql-selection.json:210-310` (`branching_helper_files`);
  - check at `scripts/ci/phpunit-shards.py:225-239`;
  - test at `scripts/ci/test-phpunit-shards.py:265-269`;
  - run by `.github/workflows/preflight.yml:122-123`.
- **Failure path:** `test_committed_policy_covers_every_driver_branching_file_under_tests_exactly` asserts that `branching_coverage_errors()` is empty.
  - **At `0ac17c58`** it returns three errors:
    - two pre-existing at the base `d8984c27`: `LegacyHistoryDeclarationMigrationTest` and `TestIdentityLinkRelayCommandTest` are unselected;
    - one new: `driver-branching helper not in branching_helper_files: tests/Support/transactional-mail-recovery-worker.php`.
  - **After applying `6d96042f`** (the C4 census commit the packet relies on) to a scratch copy, the pre-existing two are gone and the CX worker error remains. Result: FAILED (failures=1).
  - So the cheap preflight still refuses the integrated candidate.
- **Fix:** insert the worker path in sorted position in `branching_helper_files`. A scratch copy with exactly that addition on top of `6d96042f` ran `test-phpunit-shards.py` OK, 52 tests.
- **Why P2 and not P1:** the check enforces itself and has no runtime effect, so it cannot merge silently. It is still a condition, not a deferral, because preflight is required.

### N2 (P3): the cleanup comment now contradicts the code

- **Where:** `TransactionalMailScanProgress.php:139`.
- **What is wrong:** the comment says "A missing anchor means the frame changed. Never roll back replacement or peer work." The code now calls `rollBack()` even when the anchor is missing.
- **Failure path:** a future change that issues transaction control inside the frame would make the second `rollBack()` end a replacement transaction. The comment would mislead whoever reviews that change.
- **Fix:** reword the comment to the actual invariant: the frame is the only transaction control on this raw PDO between `idle()` and the catch, so any active transaction there is this frame's. Alternatively, add an assertion-style test that the frame closures never issue BEGIN, COMMIT or ROLLBACK.

### N3 (P3): ledger rows not updated, although the packet says items were "deferred to the ledger"

- **Where:**
  - `docs/handoff/2026-10-07/codex-deferred-20261011.md:529` ("Still deferred: F3 … the cursor is a follow-up");
  - `docs/handoff/2026-10-17/RESUMPTION.md:65` and `docs/handoff/2026-11-01/CODEX-HANDOFF.md:144` (FUB-1 F3 still listed as deferred);
  - `docs/verification/cx-recovery-20261104/README.md:96-99` (claims P3-2 to P3-7 are deferred to the ledger).
- **Failure path:** a later lane reads F3 as open and duplicates the cursor work. Meanwhile the six deferred P3s exist only in this packet.
- **Fix:** mark FUB-1 F3 as addressed by this batch, and add one row per deferred P3 in the ledger, at integration.

### N4 (P3): wording in `docs/transactional-mail.md:11`

- **Where:** `docs/transactional-mail.md:11`, the phrases "a run examines at most `4 × --limit` intents plus one" and "a conflicting peer run is answered once, without retry".
- **What is wrong:**
  - The code never examines more than `4 × --limit` intents (`examined < $budget`). The "+1" is the closing reservation that advances to the high-water mark without examining an intent.
  - The conflict phrase does not tell operators what they will see.
- **Fix:** write "examines at most `4 × --limit` intents, and makes at most one more reservation to close the pass". For the conflict, say that a reservation conflicting with a peer is not retried: the run stops, reports `error`, and exits non-zero.

### N5 (P3, informational): the committed prior decision carries a local scratch path

- **Where:** `docs/verification/cx-recovery-20261104/independent-cx-recovery-review-fc4c6f38-DECISION.md`, which is byte-identical to my notes file.
- **What is wrong:**
  - It contains one `/tmp/claude-0/...` scratchpad path in the P1-1 probe line. That path is meaningless to repository readers.
  - One table row ("`php -l` … pint") sits after a paragraph, so it renders outside the table.
- **Fix:** optional. Replace the path with "a scratch copy outside the worktree" and move the row into the table.

## What I ran

All runs used PHP 8.4.27 CLI on SQLite `:memory:`, with direct PHPUnit, `public/build` absent and `--do-not-cache-result`, using the env line given in the brief.

| Target | Result |
|---|---|
| `TransactionalMailScanProgressTest.php` | OK, 5 tests, 39 assertions |
| `TransactionalMailRecoveryTest.php` | OK, 4 tests, 62 assertions |
| `TransactionalMailRecoveryNativeTest.php` | 2 tests, 0 assertions, 2 skipped (native-only, SQLite) |
| `TransactionalMailTest.php` (base regressions) | OK, 35 tests, 398 assertions |
| Sum | 46 tests, 499 assertions, 2 skipped: matches the packet's `8b4f31a4` report (the PHP is identical at `0ac17c58`) |
| `php -l` worker; `vendor/bin/pint --test` on the five changed PHP files | clean; `{"result":"passed"}` |
| Census checks (Python): prefix equality, `[:150]` sha256, uniqueness, method names vs source, `include_files` sorted | all pass |
| `test-phpunit-shards.py -k test_committed_policy` on scratch `git archive` copies | `0ac17c58`: FAILED; `0ac17c58` + `6d96042f`: FAILED (worker only); + N1 fix: full file OK, 52 tests |
| `branching_coverage_errors()` at the base `d8984c27` | the two pre-existing errors only (confirms N1 is new in CX) |
| PDO probe for the P3-1 reasoning | pdo_sqlite `inTransaction()` is false after a raw COMMIT; a replacement BEGIN shows "no such savepoint" on ROLLBACK TO |

## What I did not check

- **Anything on MySQL:**
  - the native run at `790aef20` (11 tests, 162 assertions) is accepted as reported, not reproduced;
  - so are MySQL `SHOW CREATE TABLE`/trigger normalization, FOR UPDATE serialization and `performance_schema` witnesses.
- **The full preflight workflow**, including `test-database-receipts.py` and the shard generation with `--mysql-native-selection` against real PHPUnit discovery.
  - I ran only the shard-safeguard unit file, the census hash test inputs and static policy checks.
  - `test-focused-tests.py` was not meaningful on the scratch copy, because it lacks `.github/`. Its six errors there were all `FileNotFoundError` for workflow files.
- **The integrated C4 branch itself.** I applied only `6d96042f`'s two JSON hunks to a scratch copy.
- **The full PHP suite, Foundation CI, other test families** (for example the migration-rollback interplay tests the prior review ran), a real queue worker or broker, and real SMTP.
- **The prior P3-2 to P3-7 items**, beyond confirming that the fixes did not touch their code.
- **The `a2144d4a` identity fix,** which is outside this review's scope.
