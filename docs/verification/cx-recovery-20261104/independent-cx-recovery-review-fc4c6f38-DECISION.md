# Independent review: CX queued recovery and durable scan cursor

- Source: `fc4c6f38d99e98fff8457bad8a973a8f0de30d98` (worktree `/home/user/wt/cx`, branch `claude/cx-recovery-review-20261104`)
- Base: `d8984c2747352d5579fc386481c1dd160eb805bf` (accepted CX mail follow-up)
- Change: 11 files (10 PHP plus the work packet `docs/verification/cx-recovery-20261104/README.md`)
- Reviewer: independent Claude subagent, 2026-11-04. I made no edits or commits in the worktree, and `git status` was clean at the end.

## Decision: REQUEST CHANGES

The cursor design holds up under adversarial reading:

- A short CAS-guarded reservation runs before any adapter work.
- A per-cycle high-water mark bounds each pass.
- Wraparound makes every pending row come up again on every pass.
- No clock lease is used.
- The queued-only wake-up keeps the original claim as the only send authority.

I found no permanent-skip path, no double reservation, no reopening of terminal or submitting deliveries, and no automatic terminal retry.

The batch cannot merge yet, for three reasons:

- The test for the main recovery claim errors on both of its cases (P1-1).
- The new native tests have no routing into the skip census or the MySQL selection (P1-2).
- No MySQL execution exists for a migration that fails closed and is not atomic if any normalization detail is wrong (P2-1).

## Findings

### P1-1: the recovery test for a failed post-commit push errors on both cases

- **Where:** `tests/Feature/CustomerComms/TransactionalMailRecoveryTest.php:51-52`.
- **What fails:** `Queue::swap(new QueueManager(app()))` builds a manager with no connectors registered; `QueueServiceProvider` registers them only on the `queue` singleton. The adapter's dispatch at `TransactionalMailAdapter.php:60` then throws `InvalidArgumentException: No connector for [database]`. The synthetic `JobQueueing`/`JobQueued` fault is never reached.
- **Actual result:** `Tests: 6, Assertions: 20, Errors: 2, Skipped: 2` for RecoveryTest plus NativeTest on SQLite.
- **Impact:** the batch's main claim has no passing evidence. That claim is that an after-commit dispatch failure, before or after the broker push, leaves a `queued` row that `vasey:transactional-mail-wake-queued` recovers with exactly one transport.
- **Suggested fix:** `$m = new QueueManager(app()); (new \Illuminate\Queue\QueueServiceProvider(app()))->registerConnectors($m); Queue::swap($m);`. Alternatively, skip `Queue::fake()` in `setUp()` for this case.
- **Probe:** a scratch copy with exactly this change, at `/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad/cxprobe/ProbeRecoveryTest.php`, ran outside the worktree and passed 2/2 with 42 assertions. The remaining test logic is sound; the defect is in the test only. Commit the fix and re-run it on the exact source.

### P1-2: native tests are not routed into the census or the MySQL selection

- **Where:** `tests/Feature/CustomerComms/TransactionalMailRecoveryNativeTest.php:28-30`, `scripts/ci/database-sqlite-skips.json`, `scripts/ci/database-mysql-selection.json`.
- **Census gap:** both native methods call `markTestSkipped` on SQLite in `setUp()`, but neither is in the append-only SQLite skip census. The census must match the actual skips exactly, so the SQLite shard census check fails.
- **Selection gap:** none of the three new test files is in the MySQL selection. The `file_pattern` covers only `*Migration*Test.php`, and `include_files` does not list them. The MySQL shards would therefore never run either of the following:
  - the native concurrency tests, which are the only evidence for FOR UPDATE serialization and duplicate-job single transport;
  - the schema and guard tests under MySQL.
- The packet admits this as its gate 6. It is still a merge blocker.
- **Suggested fix:** append the two `[class, method]` entries after the existing entries, keeping the first 150 and main-first merge order. Add all three files to `include_files`, so the schema and recovery tests also run on MySQL. Regenerate the selection and the workflow count comments with `phpunit-shards.py`.

### P2-1: MySQL schema exactness is unexecuted, and a mismatch would block migration

- **Where:**
  - `database/migrations/2026_11_03_268400_transactional_mail_scan_progress.php:20-28`
  - `app/Domain/Notifications/Mail/TransactionalMailScanSchema.php:120-143`
  - `TransactionalMailScanProgress.php:124`
- **How it fails:** `up()` creates the table, three triggers and two rows over autocommit DDL, then ends with `assertOwned()`. That call compares tokens of MySQL's `SHOW CREATE TABLE` and `information_schema.TRIGGERS.ACTION_STATEMENT` with `definition()`/`guards()`. Any token difference has two effects:
  - `migrate`/`migrate:fresh` throws after the DDL. Every MySQL CI shard fails at setup, and a production or staging migrate leaves a residue that `assertAbsent()` then refuses forever, so a DBA must repair it by hand.
  - Every reservation also calls `assertOwned()`, so both commands would fail on every run.
- **My reading:** MySQL 8.4 prints `varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`, `bigint unsigned NOT NULL`, `PRIMARY KEY (`scan_key`)` and `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, and it stores trigger bodies verbatim. On that reading the tokens should match. I have not executed it.
- **Dependencies:** the guards depend permanently only on MySQL features the sibling migrations already rely on: `BINARY`, triggers with `SIGNAL`, `information_schema` and `SHOW CREATE TABLE`. The migration uses no REGEXP_LIKE and no CHECK. SQLite parity holds: the same guard predicates, `typeof(...) = 'integer'` and the same signed-64 ceiling (`MAX = 2^63-2`).
- **Suggested fix:**
  - Run migrate, both commands and the native tests on a private MySQL 8.4 before merge.
  - Add a runbook note for partial-install residue: table without triggers, or triggers without rows.
  - Consider checking the triggers and the empty table for ownership before the seed inserts. That way a normalization mismatch fails with a recognisable residue, not a seeded cursor.

### P2-2: the documentation and CHANGELOG now describe the old behaviour

- **Where:** `docs/transactional-mail.md:9-11`. CHANGELOG is not touched.
- **What is wrong:**
  - The docs still say "each run starts again from the oldest pending intent … That case needs an operator (or a stored cursor, a recorded follow-up)". After this change that claim is false.
  - The new operator command `vasey:transactional-mail-wake-queued` and its semantics are not documented. Its semantics are: queued rows only, duplicate jobs are harmless, the broker acknowledgment is not a receipt, and it is manual with no schedule.
  - The new durable table is not documented either.
- **Suggested fix:** in the same PR, update `docs/transactional-mail.md`, the CHANGELOG and the FUB-1 F3 ledger row, which currently says "stored cursor stays a deferred follow-up". Cover the pass, wraparound and high-water semantics, the crash-after-reservation delay of one pass, and the wake command.

### P3-1: cleanup can leave the physical transaction open

- **Where:** `TransactionalMailScanProgress.php:140-146`.
- `ROLLBACK TO SAVEPOINT` and `rollBack()` share one `try`. If the first throws on a live connection, for example because the anchor is missing, `rollBack()` is skipped. The PDO then stays inside a transaction that this function began, still holding the progress-row `FOR UPDATE` lock on MySQL.
- In a short artisan process the connection closes and releases the lock. In a long-lived process (queue worker, tests), later `owned()` calls refuse through `idle()`, and peers wait up to `innodb_lock_wait_timeout`.
- In practice this path is hard to reach, because nothing else uses the raw PDO in between.
- **Suggested fix:** when the connection is alive and `$pdo->inTransaction()`, call `rollBack()` in its own `try` even if the savepoint rollback failed. The frame belongs to this function, so no peer work can be rolled back.

### P3-2: on MySQL, the read view is fixed before the cursor lock is taken

- **Where:** `TransactionalMailScanSchema.php:144` runs before `TransactionalMailScanProgress.php:60`.
- Under REPEATABLE READ, the `SELECT scan_key …` in `assertOwned()` is the frame's first consistent read, so the read view is created before the `FOR UPDATE` wait on a peer. After the wait:
  - `start()` computes `MAX(id)` from the older snapshot (line 24);
  - `reserve()` runs its candidate query from the older snapshot (lines 42-50).
- The effect: rows committed while the worker waited, with id ≤ high, can be stepped over in this pass.
- This is not a permanent skip, because the next pass revisits them (the late-lower-id test covers the same mechanism). It adds latency under contention, and the docs should state it.
- **Suggested fix:** take the row lock before any consistent read, either by moving the purposes check after `row()` or by checking it inside `row()`. Alternatively, set READ COMMITTED for this frame. Do not switch the purposes read to `FOR SHARE`: two workers would hold S and both request X, which deadlocks.

### P3-3: the native tests do not fully witness the races they name

- **Where:** `TransactionalMailRecoveryNativeTest.php:83-110` and `tests/Support/transactional-mail-recovery-worker.php:76`.
- **Duplicate-job test:** it starts two real processes but never forces both to contend for the delivery row. The loser may simply run after the winner's claim has committed, so lock serialization of the claim is not witnessed. Fix: the parent holds `FOR UPDATE` on the delivery row and witnesses both workers in `data_lock_waits`, as the first native test does.
- **"Stale" check:** it calls `reserve(cycle - 1)` after the pass has finished, which is trivially null. It does not race a peer opening cycle+1 while a worker still holds the old cycle. The SQLite test at `TransactionalMailScanProgressTest.php:80-82` covers this case single-process.
- **What holds:** the first native test is genuinely concurrent: separate PIDs and connection ids, both waits witnessed on the PRIMARY record lock, and an exact `version = 3` check. Teardown stops the workers and deletes their directories, and the parent's lock transaction is committed in `finally`.

### P3-4: every reservation repeats the full ownership checks

- **Where:** `TransactionalMailScanProgress.php:124` and `TransactionalMailScanSchema.php:102, 150-172`.
- Each frame runs four `SHOW CREATE TABLE`, one `information_schema.TRIGGERS` query and one `SELECT`, plus temp-schema checks on SQLite. This happens up to about 401 times per run (4×100 reservations plus start), partly while the progress row lock is held.
- **Suggested fix:** check ownership once per invocation, or once per start, and keep only the cheap per-frame idle and driver checks. The repo already did this for "schema ownership once per frame" in lane G1.

### P3-5: the wake command has no age threshold

- **Where:** `WakeQueuedTransactionalMail.php:40-56` and `TransactionalMailAdapter.php:67-82`.
- Every pass dispatches a second job for any `queued` row whose original job is merely still waiting in the queue. The atomic claim makes this harmless; at most one transport was observed. It does double queue load if operators run wake routinely.
- The design avoids a clock lease on purpose, so this is acceptable. Document wake as an operator step after the stuck listing shows retained queued rows, not as a scheduled job.

### P3-6: on SQLite the install is not atomic, though it could be

- **Where:** migration line 9 (`$withinTransaction = false`).
- SQLite supports transactional DDL. A failure partway leaves residue that `assertAbsent()` refuses forever. The migration could wrap a raw transaction on SQLite only.
- On MySQL, DDL commits implicitly in any case; the sibling migrations have the same exposure.

### P3-7: smaller notes

- **Concurrent SQLite processes:** PDO's deferred `BEGIN` makes concurrent SQLite processes (local only) fail with SQLITE_BUSY on the lock upgrade. The result is a bounded command error, not a double reservation, because the CAS `version` check backs it up.
- **A run can end with no work:** if another process completes the pass and opens a new cycle between this run's `start()` and its first `reserve()`, the run returns with nothing done. This is bounded and harmless.
- **Size of the fix:** F3 was graded P3, and the fix adds a table, three triggers and a raw-PDO transaction frame. Reviewers of later changes should weigh this weight.
- **Superseded exclusion:** no test exercises it through the cursor specifically. The reservation SQL keeps it (`TransactionalMailScanProgress.php:45`), and the base superseded tests still pass.

## Invariant and base-semantics check

- **No reopen of terminal or submitting deliveries:** `wakeQueued()` reads only `state = 'queued'`, writes nothing and dispatches the unchanged job. `deliver()` still claims only from `queued` under `lockForUpdate`. Covered by `test_only_queued_rows_are_woken_and_terminal_or_submitting_rows_are_never_reopened` (passes): the job is handled twice, one mail is sent, and the non-queued rows stay byte-identical.
- **No automatic terminal retry and no clock lease:** none is introduced. Progress has no timestamps. Wraparound reconsiders only rows that are still pending or queued, and terminal rows never match the reservation predicates.
- **Superseded intents:** still excluded (`a.access_version <= n.access_version`). The `superseded=` count is unchanged.
- **`authority_withdrawn` mapping:** unchanged in the adapter. The queue path counts it under `skipped`, and the job path ends in `failed/authority_withdrawn`. Both are covered by passing tests.
- **Skipped counts and output format:** the same as the base. The base's `TransactionalMailTest` passes 35/35.
- **Default-off wake command:**
  - refuses `mail_not_configured` unless enabled;
  - refuses `mail_not_faked` in local/testing unless `Mail` is a `MailFake`, which a subclass satisfies;
  - re-checks the policy for every row;
  - refuses inside any open transaction;
  - touches no private storage.
  - The native worker points `local` at the parent's private root and checks environment, driver, database and the 0700 directory before running.
- **Cursor safety:**
  - Two workers cannot both advance: on MySQL, `FOR UPDATE` serializes, and on both engines the CAS checks `version`/`cycle`/`after`/`high`.
  - No permanent skip: crash after reservation, an uncertain commit and a rollback each delay at most one pass.
  - Passes are bounded: at most 4×limit examined rows plus one final advance per run.
  - Conflicts are bounded: one attempt, and the command ends on the first frame error.

## What I ran (PHP 8.4.26/8.4.27 CLI, SQLite `:memory:`, direct PHPUnit with `public/build` absent)

| Command target | Result |
|---|---|
| `TransactionalMailScanProgressTest.php` | OK, 5 tests, 39 assertions |
| `TransactionalMailRecoveryTest.php` + `TransactionalMailRecoveryNativeTest.php` | 6 tests, 20 assertions, **2 errors** (P1-1), 2 skipped (native, SQLite) |
| Scratch copy of RecoveryTest with connectors registered, `--filter push_failure` | OK, 2 tests, 42 assertions (probe only; not the reviewed source) |
| `TransactionalMailTest.php` (base regressions) | OK, 35 tests, 398 assertions |
| `TransactionalNotificationMigrationTest.php` (rollback interplay) | OK, 20 tests, 270 assertions |
| `CustomerAccountMigrationTest.php` (rollback interplay) | OK, 7 tests, 561 assertions |
| `TestPaymentEvidenceMigrationTest.php` (drops and restores the deliveries fixture) | OK, 20 tests, 205 assertions |
| `CustomerDataRightsTest.php` | OK, 25 tests, 271 assertions |

The background wrapper exited 1 only because its final `tail` over a scratch glob failed. Each PHPUnit log above reads OK.
| `php -l` on the 10 PHP files; `vendor/bin/pint --test` on them | clean; `{"result":"passed"}` |

Each run used `env APP_ENV=testing … DB_CONNECTION=sqlite DB_DATABASE=:memory: … PAO_DISABLE=1 php -d memory_limit=1024M -r '…require "vendor/phpunit/phpunit/phpunit";' -- --colors=never <file>`.

## What I did not check

- Anything on MySQL. The main session owns the only server. Not executed:
  - the migration;
  - SHOW CREATE TABLE and trigger normalization;
  - FOR UPDATE serialization;
  - both native tests, including `performance_schema` access and worker admission.
- The CI census, shard and selection tooling (`phpunit-shards.py`, `database-receipts.py`).
- The full PHP suite, or any other test families beyond those listed.
- A real queue worker, the real database broker under failure, or real SMTP. `Mail` was faked throughout.
- Mutation testing of the new code.
- Behaviour under PHP-FPM, Octane or long-lived workers. Only the reasoning in P3-1 covers it.
