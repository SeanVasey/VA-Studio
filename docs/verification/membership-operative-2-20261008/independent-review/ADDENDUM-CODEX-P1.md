# Independent review addendum: Codex P1 fixes on PR #54 (L2-2 sweep cursor, L2-3 database-issued order)

- **Range under review:** `83821fc0..6184118d` (`harness/membership-operative-2`).
  - `f341b725`: code. Sweep cursor (P1-A, L2-2); retrieval and hint positions (P1-B, L2-3).
  - `bd2fb261`: docs. Lane record `../hardening/codex-p1/README.md` and its evidence.
  - `0225c504`: merge of `main` at `72045620` (identity key rotation, PR #57).
    - It changes no lane file: `git diff --stat bd2fb261 0225c504 -- app/Domain/Memberships app/Console app/Jobs tests/Feature/ProductionMembershipBilling tests/Feature/ProductionMembership tests/Support/membership-billing-stale-race-worker.php` is empty.
    - `scripts/ci/database-sqlite-skips.json` is exactly the union of both parents (checked entry by entry), and the census self-test passes (`review-evidence/codex-p1/merge-and-range-checks.txt`, `receipts-self-test.txt`).
  - `6184118d`: docs only. `git diff --stat 0225c504 6184118d -- . ':!docs'` is empty.
  - Later head `3fed2392` adds only `tests/frontend/checkout-return.test.tsx`, a test timer fix. It was not run.
- **Base decision:** `DECISION.md` (APPROVE WITH CONDITIONS at `6219f283`). L2-2 and L2-3 are judged against the conditions as written there.
- **Reviewer:** an independent reviewer agent that authored no commit in this range and changed no app code, test, flag or registration. Its review files are untracked.
- **Date:** 2026-10-08 (UTC).
- **Commits:** none. The integration owner commits.

## Decision

**The two fixes: APPROVE WITH CONDITIONS. PR #54: HOLD (do not merge at `6184118d` or `3fed2392`).**

- **L2-2 is closed.** The sweep reaches every hint that committed before a fresh pass starts, and the cursor is sound. The tie-break that makes this true is not pinned by any lane test (CP-4).
- **L2-3 is closed for its two named comparisons.** Retrieval ordering and hint coverage no longer compare clocks across hosts. Two residuals keep part of the condition open for activation:
  - worker clocks still drive `created_at` and freshness (CP-3);
  - the positions are bare counter values, which a counter regression can reissue (CP-2).
- **The merge is held** because the coordinator's new Codex P1 at `3fed2392` (`BillingReconciliation.php:42`) is **confirmed and open** (CP-1, High).
  - A position taken at the start of a retrieval orders starts, not provider reads. A read that began earlier but finished later is dropped as `superseded_retrieval`, which the job treats as a normal end.
  - The pre-reversal `settled` read then stays the tail and stays current.
  - The defect is older than this range: R-6's clock rule also compared starts, and lane 1's Addendum 1, by this reviewer lineage, missed it. This range does not introduce it and does not close it.
  - It must be fixed and reviewed before PR #54 merges. Per the coordinator's instruction, it is recorded here, not fixed.

Release boundaries are unchanged:

- `enabled` and `provider_io_enabled` stay false.
- No gateway or route is bound.
- There is no live mode and no real provider I/O.
- No added line writes a grant, award, credit event or subscription state.

## Environment

- **Review worktree:** `/home/user/VA-Studio-review-m54`, detached at `6184118d`, with the main checkout's `vendor/` symlinked and its own Composer autoload. `public/build` is absent.
- **Mutations:** made only in a scratch copy under the session scratchpad (`r54p1/mut`), never in the review worktree. The copy is removed at the end.
- **Toolchain:** PHP 8.4.26, PHPUnit 12.5.34, SQLite `:memory:` (phpunit.xml default).
- **Native MySQL:** a private `mysqld` 8.4.11 instance.
  - Started with `--no-defaults --user=root`, initialized with `--initialize-insecure`, listening on 127.0.0.1:3793.
  - Flags: `--socket=/tmp/claude-0/r54p1.sock --mysqlx=OFF --skip-log-bin`. Datadir under the session scratchpad.
  - Databases: `r54p1_review` (lane files) and `r54p1_probe` (review probes).
  - Server variables: `innodb_autoinc_lock_mode=2`, `auto_increment_increment=1`, `auto_increment_offset=1`, `innodb_flush_log_at_trx_commit=1`, REPEATABLE-READ, strict `sql_mode`.
  - The other session's daemon on 3871 was never touched. After the runs, this instance was shut down with `mysqladmin` (rc 0) and its datadir deleted (`review-evidence/codex-p1/private-instance-lifecycle.txt`). The runner is `review-evidence/codex-p1/run-mysql.sh`.

## P1-B (L2-3): database-issued positions

What the fix does:

- `production_membership_billing_positions` (`BillingSchema::TABLES[4]`).
  - The id is the position: `BIGINT UNSIGNED AUTO_INCREMENT` on MySQL, `INTEGER PRIMARY KEY AUTOINCREMENT` on SQLite.
  - `kind` is `retrieval` or `hint`. Updates and deletes are refused.
  - The insert guard is `NEW.id < 1` (`BillingSchema.php:284`).
- `BillingLedger::startRetrieval()` (`BillingLedger.php:98`) commits a `retrieval` position in its own transaction, with no transaction open, before the first provider read (`BillingReconciliation.php:42`).
- The observation carries `retrieval_position`, which is UNIQUE, CHECK `> 0` and sealed.
- `append()` requires the tail's position to be strictly lower (`BillingLedger.php:159`), and the observations insert trigger repeats the rule (`BillingSchema.php:276`).
- The webhook intake allocates a `hint` position in the same transaction as the event row (`BillingWebhookIntake.php:85`).
- Coverage is `o.retrieval_position > hint_position` (`BillingHintRecovery.php:86`).

### Can positions be back-dated, reused or forged?

- **Back-dated: no.**
  - An explicit positive id is refused on both engines: the lane's `BillingSchemaPreparationTest` passes on SQLite and on MySQL (see "Runs").
  - `REPLACE` and `ON DUPLICATE KEY UPDATE` reach the insert or update guard. On MySQL, a session cannot disable triggers.
  - An explicit non-positive id is admitted (CP-6). The review probe shows this cannot back evidence:
    - the `> 0` CHECKs refuse it;
    - `append()` refuses it with `invalid_value`;
    - coverage refuses `0`, `-5`, `'0'`, `'-5'` and `'01'` as `tampered_ledger`;
    - the counter still only moves forward.
- **Reused: no, while the counter never reissues an id.**
  - The UNIQUE indexes and the trigger `NOT EXISTS` clauses stop a position backing two observations or two hints.
  - The `kind` checks stop a hint position backing an observation, and the reverse (lane test, both engines).
  - A counter that goes backwards breaks this (CP-2).
- **Forged: not by application code.**
  - A database writer with DDL rights can do anything, as before. The seals are unkeyed canonical-JSON hashes (`BillingValues.php:87`), so they detect corruption, not a privileged forger.
  - The coverage query reads observations without re-checking their seals (pre-existing; CP-8).

### Can an allocated-but-uncommitted position cover in the unsafe direction?

No. Coverage compares allocation order, not commit order.

- For a hint H and a retrieval R, `p_R > p_H` means R's id was allocated after H's.
  - Under InnoDB's autoinc mutex, a single-row insert takes the next value in allocation order in every lock mode. Mode 2 only interleaves multi-row and bulk inserts.
  - H's allocation runs inside intake, after the request arrived.
  - R's provider reads start only after its allocation returned.
  - So the chain is: H arrived < H allocated < R allocated < R read. This holds whatever either transaction's commit state is.
- An intake transaction that rolls back:
  - on MySQL, leaves a gap;
  - on SQLite, rolls the sequence back, but SQLite serializes writers and the rolled-back hint has no row to cover.
- Any `PDOException` in the intake transaction, including a failed position insert, ends in `event_identity` when no winner exists. That is fail closed.
- Rejecting `MAX(id)` as the hint position, as the lane did, is correct: it would have let an allocated-but-unread retrieval cover the hint.

### Is AUTO_INCREMENT monotonic enough on MySQL 8.4?

On one server, yes.

- `innodb_autoinc_lock_mode=2` (the 8.4 default) is safe for these single-row inserts.
- Since 8.0 the counter is persisted through the redo log, so a restart does not reissue a committed value.

The guarantee is single-writer and durable-commit only:

- **Multi-primary breaks it.** Group Replication multi-primary sets `auto_increment_increment`/`offset` per member, and circular replication does the same. Values are then not globally time-ordered.
- **Lost committed transactions break it**, followed by reissue:
  - asynchronous-replica failover;
  - an OS crash with `innodb_flush_log_at_trx_commit`≠1 or `sync_binlog`≠1;
  - a point-in-time restore while workers run.

  Hint positions are safe here: a hint position is lost together with its event row (same transaction). A retrieval position is committed on its own before provider I/O, so an in-flight retrieval can outlive the loss of its own row.
- The review probe models that loss: it deletes the "lost" rows behind a dropped and then byte-for-byte restored delete guard, and rewinds the counter.
  - The stale in-flight retrieval appends under an id the new primary reissued to a later retrieval, so its pre-hint read covers the hint and becomes the tail.
  - The genuinely newer retrieval is then refused as `superseded_retrieval` (equal position), which the job ends silently.
  - SQLite and MySQL give the same result: `{"stale_position":3,"hint_position":2,"fresh_position":3,"stale_appended_position":3,"stale_outcome":"settled"}`, and the fresh append is refused with `superseded_retrieval`.
  - On MySQL the rewind needs `ALTER TABLE ... AUTO_INCREMENT`. That ALTER re-rendered the bounds CHECK's literals with `_ascii` introducers, so the owned-schema check refused the table with `schema`.
    - The first MySQL attempt (`mysql-05`) therefore errored inside the simulation. That is fail closed and is not the probe's subject (CP-9).
    - The corrected simulation re-adds the CHECK through the application connection in the same ALTER (`mysql-06`).
  - This is CP-2.

### Is the kept `created_at` check still fail closed?

Yes, in the sense that it never appends.

- No lane or earlier test exercised the refusal path. The review probe does.
- A tail written by a worker whose clock runs 1000 s ahead makes a later, correctly clocked reversal throw `clock`. The job rethrows it, so the queue retries at 60 s and 600 s, and nothing is appended.
- The cost:
  - the newer evidence is lost after three attempts if the skew exceeds about 660 s;
  - `freshness_deadline` is `retrieved_at` + 600 s on the stale worker's clock, so the pre-reversal `settled` stays current for skew + 600 s.
- This is the "remaining condition" the lane names, but it is still part of L2-3's substance (CP-3).

### Migration 259000 changed in place

- 259000 has been on `main` since `e94417a8` (PR #51). This range changes `BillingSchema`, which the migration calls; the migration file itself is unchanged.
- The README's wording now matches original finding I-4: "not applied in production or any shared environment".
- `docs/architecture/decision-register.md` U-02 still lists no production host.
- The review probe rebuilds the old 259000 shape from `83821fc0`'s `BillingSchema` and checks that the new code refuses it, on both engines:
  - `assertOwned()` and `up()` refuse it with `schema`;
  - the positions table is not created (the prefix-recovery path does not adopt the old tables);
  - webhook intake refuses with a `BillingException`.
- Requiring `migrate:fresh` for local and CI databases is acceptable before any shared environment exists. Once one exists, this kind of change must become a new migration.

## P1-A (L2-2): sweep cursor

- **Keyset.** `scan()` pages with `received_at > ? OR (received_at = ? AND id > ?)` (`BillingHintSweep.php:53`). The order is `ORDER BY received_at, id`, and the comparison and the order use the same binary collation on both engines.
  - `scanAll()` stops after `MAX_SWEEP_HINTS` (10,000) and reports the cursor that continues.
  - No loop is possible: a page that returns `next` examined at least one row.
- **Ties.** Five hints in one second, with pages of 2, are each examined exactly once (three pages), and `--all --limit 1` finds all five (review probe).
  - With the tie-break mutated away (`received_at = ? AND 0 = 1 AND id > ?`), all 7 lane cursor tests still pass, while the review tie test fails (`mutations-sqlite.txt`, M1). This is CP-4.
  - `id >= ?` (M2) is caught by three lane tests.
- **Hints behind the cursor.** A hint committed after a page, whose intake clock places it behind the cursor, is not reached by continuing that pass. A fresh pass reaches it (review probe). This is the documented behaviour.
  - The property that holds: every hint committed before a fresh pass starts is examined by that pass, if the pass is followed to "Scan complete".
- **Above 10,000 retained hints.** Repeating `--all` from the start never reaches the newest hints; the operator must chain `--all --after`, as the command's warning says. Any future automation must loop until "Scan complete" (CP-7).
- **Forgery and replay.**
  - The cursor is `v1.<stamp>.<uuid>.<HMAC-SHA256>`, keyed with `APP_KEY` over a domain-separated string with the account and mode (`BillingHintSweep.php:146`).
  - It is opened, and the MAC checked with `hash_equals`, before any ledger read.
  - The policy check comes first.
  - The lane tests cover: shape, edited fields, a wrong MAC, another key and another account. Each is refused with nothing listed or dispatched.
  - Mode binding is in the MAC but cannot be exercised: the policy refuses `live` (`live_not_authorized`).
  - A cursor has no expiry and binds no pass (CP-7). Replaying it only skips hints, which is an operator's choice and never a coverage decision.
- **APP_KEY rotation.** The MAC uses `app.key` only, not `app.previous_keys`.
  - With the old key moved into `previous_keys`, an old cursor is refused (`Refused (cursor).`, exit 1, nothing listed or dispatched).
  - A fresh pass still works: the hint ciphertexts decrypt under the previous key, and three hints are dispatched. A cursor minted after the rotation is accepted (review probe).
  - This fails closed and is recoverable. Seals are unkeyed, so a rotation does not affect them.
- **`--dispatch` dedupe.** It is deduplicated by invoice across every page of one run (lane test, and the review tie probe dispatches 5 for 5 invoices). Separate `--after` runs are not deduplicated against each other; that is harmless, as before (A1-5).

## Coordinator's Codex P1 at `3fed2392` (`BillingReconciliation.php:42`): confirmed, open

Reproduced independently with the untracked review probe. It uses a gateway that fires before the first provider read, which the lane's `InterleavingBillingGateway` cannot do.

1. A takes position 1 and stalls before reading.
2. B takes position 2, reads the still-settled invoice and appends `settled`.
3. The provider reverses the payment.
4. A reads the reversal. Its append is refused as `superseded_retrieval` (1 < 2), and `RetrieveMembershipInvoice::handle` returns normally (`RetrieveMembershipInvoice.php:60`), so nothing retries.

The chain is `[["settled",2]]`, and `currentSettled()` returns the pre-reversal read after the reversal.

Codex's order, with A appending before B, ends the same way: B's 2 > 1 is admitted.

The coordinator's fix gives each read a start and an end position, admits an append only when `new.start > tail.end`, and refuses overlap as `concurrent_retrieval` with a retry. That addresses this shape. For the follow-up review:

- the fix must not end an overlap as a normal end;
- it should carry CP-2's token binding to both positions;
- hint coverage should keep comparing the start position.

## Findings

| ID | Severity | Where | Finding | Recommendation |
| --- | --- | --- | --- | --- |
| CP-1 | High; confirmed; open (blocks the PR #54 merge) | `BillingReconciliation.php:42`; `BillingLedger.php:159`; `BillingSchema.php:276`; `RetrieveMembershipInvoice.php:60` | Codex P1 at `3fed2392`. The start position orders retrieval starts, not provider reads. An earlier-started, later-reading retrieval is dropped as `superseded_retrieval` (a normal end), and a pre-reversal `settled` stays the current tail. Pre-existing in R-6; not closed here. | The coordinator's start/end-position fix, then a follow-up addendum with a regression for both append orders. |
| CP-2 | Low (forward; before activation) | `BillingSchema.php:284`; `BillingLedger.php:98,159` | Positions are bare counter values. After a counter regression (async failover, non-durable commit settings with an OS crash, PITR restore, or a multi-primary topology), an in-flight retrieval can append under a reissued id. Its pre-hint read then covers the hint and becomes the tail, and the newer retrieval is refused as `superseded_retrieval` and ends silently. Probe-demonstrated. | Bind each position to a random per-retrieval token stored in the positions row and required by the observation trigger, so a reissued id fails closed. Treat an equal tail position as `position_reused` (loud), never as superseded. And/or record the production requirement: single writer, `auto_increment_increment=1`, durable commits, and no worker carried across a failover or restore. |
| CP-3 | Low (forward; before activation; L2-3 residual) | `BillingLedger.php:162,169`; `BillingSchema.php:273` | `created_at` monotonicity and `freshness_deadline` still come from worker clocks. A clock-ahead tail refuses later appends with `clock` (fail closed, but the newer evidence is lost after about 660 s of skew), and the stale `settled` stays current for skew + 600 s. Probe-demonstrated. | Record a bounded-skew/NTP requirement for every worker host, or take `created_at`/`retrieved_at` from the database clock. Add a regression for the `clock` refusal. |
| CP-4 | Low (test gap) | `BillingHintSweep.php:53`; `BillingSweepHintsCursorTest.php` | No lane test pins the `(received_at = ? AND id > ?)` tie-break. Removing it, which would skip tied uncovered hints at every page boundary, leaves all lane tests green (M1). | Add a same-second, multi-page regression (the review tie probe is one). |
| CP-5 | Info | `BillingHintSweep.php:146-148` | The cursor MAC uses `app.key` only. After a rotation, old cursors are refused and a fresh pass works. | None; optionally say "start a new pass after a key rotation" in the command help. |
| CP-6 | Info | `BillingSchema.php:284` | The guard `NEW.id < 1` admits explicit non-positive ids. On SQLite, `0` and `-5` were stored as such. On MySQL, `0` was admitted and auto-assigned the next id, and `-5` was refused as out of range for `BIGINT UNSIGNED` (strict mode). The SQLite guard relies on an unassigned rowid reading -1 in a BEFORE INSERT trigger. No such id can back evidence. | Optionally guard exactly `NEW.id = -1` (SQLite) / `NEW.id = 0` (MySQL). |
| CP-7 | Info | `BillingHintSweep.php`; `SweepMembershipBillingHints.php` | Cursors never expire and bind no pass. Above 10,000 retained hints, a fresh `--all` needs `--after` chaining to finish. | Any scheduled use must loop until "Scan complete". |
| CP-9 | Info (operational; fail closed) | `BillingSchema.php:437` and the CHECK literal normalizer (`BillingSchema.php:445-451`) | On MySQL 8.4.11, `ALTER TABLE ... AUTO_INCREMENT = n` re-rendered the bounds CHECK's string literals with `_ascii` introducers (reproduced on a throwaway table). The normalizer accepts only `_utf8mb4`, so `assertOwned()` refuses with `schema` and billing stops. The same likely applies to every billing table and to other table-rebuilding operator commands (`ALTER ... FORCE`, `OPTIMIZE TABLE`), which were not tested. It is safe, but an operator can make billing unavailable without touching data. | Normalize `_ascii` introducers too, or record in the runbook that billing tables must not be altered or rebuilt. |
| CP-8 | Info (pre-existing) | `BillingHintRecovery.php:84-92` | Coverage reads observations without re-checking their (unkeyed) seals. A privileged database writer can hide a hint. Unchanged by this range. | None for development. |

No other finding is Medium or above. The original I-3 (same-second rule) is obsolete: the rule no longer exists.

## Runs

Every PHPUnit run uses `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never <path>`, run from the review worktree at `6184118d` with `public/build` absent. Raw outputs are in `review-evidence/codex-p1/`, and each ends with `rc=`.

| File | Engine | Result | rc |
| --- | --- | --- | --- |
| `sqlite-01-ProductionMembershipBilling.txt` (+ `.xml`) | SQLite | OK, 161 tests, 782 assertions, 2 skipped (the native-only `BillingNativeStaleRetrievalRaceTest` methods). Matches the lane record. | 0 |
| `sqlite-02-ReviewCodexP1AdversarialTest.txt` | SQLite | OK, 8 tests, 58 assertions (review probes; untracked file) | 0 |
| `mysql-01-BillingSchemaPreparationTest.txt` (+ `.xml`) | MySQL 8.4.11 | OK, 12 tests, 63 assertions (position guards, database-only ids, kind and uniqueness on MySQL triggers) | 0 |
| `mysql-02-BillingClockSkewOrderingTest.txt` (+ `.xml`) | MySQL 8.4.11 | OK, 8 tests, 26 assertions | 0 |
| `mysql-03-BillingSweepHintsCursorTest.txt` (+ `.xml`) | MySQL 8.4.11 | OK, 7 tests, 112 assertions | 0 |
| `mysql-04-BillingNativeStaleRetrievalRaceTest.txt` (+ `.xml`) | MySQL 8.4.11 | OK, 2 tests, 27 assertions (two processes, including the clock-ahead stale worker) | 0 |
| `mysql-05-ReviewCodexP1AdversarialTest.txt` (+ `.xml`) | MySQL 8.4.11 | 6 tests, 41 assertions, 1 error: the CP-2 simulation's own `ALTER TABLE` tripped the owned-schema check (`schema`, CP-9). The other five passed, including the tie, behind-cursor, key-rotation, explicit-id and previous-shape probes. | 2 |
| `mysql-06-ReviewCodexP1-added-probes.txt` | MySQL 8.4.11 | OK, 3 tests, 16 assertions: CP-2 (corrected simulation), CP-3 and CP-1 reproduced on MySQL | 0 |
| `mutations-sqlite.txt` | SQLite, scratch copy | M1 (tie-break removed) survives all 7 lane cursor tests and is killed by the review tie test. M2 (`id >= ?`) is killed by 3 lane tests and by the review tie test. | n/a |
| `pint.txt` | n/a | `pint --test` on the 16 PHP files `f341b725` adds or modifies: passed. The untracked review test has style findings only. | 0 (lane files) |
| `receipts-self-test.txt` | n/a | `python3 -I scripts/ci/test-database-receipts.py`: OK | 0 |
| `merge-and-range-checks.txt` | n/a | The merge changes no lane file; the skips JSON equals the union; `0225c504..6184118d` changes nothing outside docs; `3fed2392` touches one frontend test file. | 0 |

The review probes are in the untracked file `tests/Feature/ProductionMembershipBilling/ReviewCodexP1AdversarialTest.php` (sha256 prefix `1693d858e66cc8f4`), which is not committed. A non-executable copy is at `review-evidence/codex-p1/ReviewCodexP1AdversarialTest.php.txt`. The `mysql-05` run started before the CP-1 and CP-3 probes were added, so it holds six tests; those two probes were run on MySQL separately in `mysql-06`.

## Conditions

1. **CP-1, before PR #54 merges:** the start/end-position fix, with regressions for both append orders and a follow-up independent addendum on the tested commit.
2. **CP-4, with that fix:** a same-second, multi-page sweep regression.
3. **CP-2, before activation:** per-retrieval token binding (or the equivalent for the new start/end positions), or a recorded production topology and durability requirement.
4. **CP-3, before activation:** a recorded worker clock-skew bound or database-clock times for `created_at`/`retrieved_at`, plus a `clock` regression. Together with item 3, this is what closes L2-3 for activation.
5. **Carried, unchanged:**
   - L2-1 and R-5 before activation;
   - L2-4 and A1-4's durable attempt record before C3/C2;
   - R-4 before C4;
   - the release boundaries in `DECISION.md`.

## Not tested

- The full 161-test directory on MySQL. Only the four focused lane files and the review probes ran there.
- Foundation CI.
- A real provider, live mode (refused by policy) and the mode half of the cursor MAC.
- A real replica failover or crash. CP-2 is a simulation that deletes rows behind a dropped and restored guard and rewinds the counter.
- Group Replication or multi-primary.
- `innodb_autoinc_lock_mode` 0 and 1.
- The frontend test change in `3fed2392`.
- The coordinator's CP-1 fix, which has not landed.
