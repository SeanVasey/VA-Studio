# Independent review addendum: read-interval ordering (CP-1) and cross-invoice guard lookups (Codex P2) on PR #54

- **Range under review:** `6184118d..59e8f8e2` (`harness/membership-operative-2`). It was extended from `2e08af42` while the review was running.

| Commit | Kind | Content |
| --- | --- | --- |
| `046372b9` | docs | `ADDENDUM-CODEX-P1.md`. Byte-identical to the reviewer's untracked copy (`diff`, no output). |
| `cbd5e5c5` | code | The read-interval fix for CP-1 (Codex P1 at `BillingReconciliation.php:42`). |
| `4cb4cba8` | docs | Evidence in `../hardening/codex-p1b-interval/`. |
| `2e08af42` | merge | Main at `d3e1c39a` (PRs #58 and #59). It changes no lane file. |
| `18f1606c` | code | Split guard lookups (Codex P2 4223685613, `BillingLedger.php:173`), new native `BillingNativeCrossInvoiceClaimTest`, census 182 to 183. |
| `59e8f8e2` | docs | Evidence in `../hardening/codex-p2-cross-invoice/`. |

- **Base records:**
  - `DECISION.md`, for L2-1;
  - `ADDENDUM-CODEX-P1.md`, for CP-1 to CP-9.
- **Reviewer:** an independent reviewer agent that authored no commit in this range and changed no product code. Its review files are untracked.
- **Date:** 2026-10-08 (UTC).
- **Commits:** none.

## Decision

**APPROVE WITH CONDITIONS for a development merge of `59e8f8e2`.** This is a development merge only, default-off and unregistered, on the terms of `DECISION.md`.

- **CP-1 is closed.** No interleaving found lets an older provider read become the tail after a newer one. An overlapping newer read is retried, never ended normally. Evidence:
  - the reviewer's original gateway-before-read probe;
  - a three-way Fiber interleaving in both orders;
  - all 1,680 three-retrieval schedules on SQLite (62 sampled on MySQL);
  - the lane's Fiber and two-process tests;
  - three mutations, all killed.
- **Codex P2 is closed.**
  - The split guards are exactly equivalent to the old ones.
  - With another invoice's identity row held `FOR UPDATE` for 8 s, an unrelated first claim now takes 1.26 to 1.30 s (8.67 s at lane 2).
  - In two 40-way concurrent storms (intakes, then claims), there were no deadlocks.
- **CP-4 is closed** by the lane's same-second test (lane mutation evidence).
- **CP-2 is reduced, not closed.** A reissued id still needs to collide only once (the start), but the harm is now transient: the holder of the reissued id is retried as `concurrent_retrieval`, not ended as superseded.
- **CP-3 is unchanged.**
- **Remaining conditions:**
  - P1B-1: cross-invoice deadlocks in the observations guard. These are pre-existing and absorbed by retry. They are part of L2-1 and must be addressed before activation.
  - P1B-2: a wall-clock assertion in the new native test.
  - Carried: CP-2, CP-3, CP-9, L2-1 (with R-5), L2-4, A1-4, R-4 and the release boundaries.

## Environment

- **Worktree:** `/home/user/VA-Studio-review-m54`, detached, first at `2e08af42` and then at `59e8f8e2`. `vendor/` is symlinked with its own autoload; `public/build` is absent.
- **Moved aside before checkout:** the round-1 untracked files, to `$scratchpad/r54p1/aside-6184118d/`, not deleted.
- **Review probe location:**
  - At `2e08af42` it was in `tests/`, and was added only after the directory run had discovered its files.
  - At `59e8f8e2` it was moved out of `tests/` before the directory run, the census self-tests and shard partitioning, and was run by path.
- **Mutations:** made in scratch copies (`r54p1b/mut`, `r54p1b/mut59`), never in the worktree. Both copies are deleted at the end.
- **Toolchain:** PHP 8.4.26, PHPUnit 12.5.34, SQLite `:memory:`.
- **Native MySQL:** a private `mysqld` 8.4.11.
  - `--no-defaults --user=root`, `--initialize-insecure`, listening on 127.0.0.1:3794, socket `/tmp/claude-0/r54p1b.sock`, `--mysqlx=OFF --skip-log-bin`. Datadir under the scratchpad.
  - `innodb_autoinc_lock_mode=2`, `auto_increment_increment=1`, `innodb_flush_log_at_trx_commit=1`, REPEATABLE-READ.
  - Databases: `r54b_lane`, `r54b_probe`, `r54b_storm`, `r54b_storm_mut` and `r54b_istorm`.
  - The other session's daemon (`p2ci`, pid 25012, stopped by its owner at 21:03Z per its lane record) and port 3306 were never touched.
  - Shut down and deleted at the end (`private-instance-lifecycle.txt`).

## CP-1: does it close?

**Mechanism** (`cbd5e5c5`, then `18f1606c` for the guard text):

- `BillingReconciliation::retrieve` commits a start position before the first provider read (`BillingReconciliation.php:42`).
- It commits an end position after the last read, or after the provider failure that ended the reads (`:64`). This is done through `BillingLedger::endRetrieval` (`BillingLedger.php:111`), always before the policy re-check, the identity claim and the append. `snapshots()` performs every provider read, and no provider call follows `:64`.
- `append()` admits a read only when its start is above the tail's end (`BillingLedger.php:177`). Otherwise:
  - `superseded_retrieval` when its end is below the tail's start;
  - `concurrent_retrieval` in every other case.
- The observations insert guard repeats the admission rule (`BillingSchema.php:293`). It also requires both positions to be unused `retrieval` positions with end > start.
- The job treats `superseded_retrieval` as a normal end and releases `concurrent_retrieval` with 60 s / 600 s backoff; the third attempt rethrows (`RetrieveMembershipInvoice.php:63-66`).
- Coverage still uses the start position (`BillingHintRecovery.php:88`).

**Why it is sound.** One counter on one server orders all allocations.

- `start(N) > end(T)` puts every read of N after every read of T.
- `end(N) < start(T)` puts every read of N before every read of T.
- Any other relation is an overlap whose read order is unknown, and it is refused for retry.
- Admitted rows are therefore ordered by read time along the whole chain.

**Evidence** (review probes; "both" means SQLite and MySQL):

- **The original CP-1 probe, through the real job (both).**
  1. A takes start 1 and stalls before reading.
  2. B takes [2, 3], reads settled and appends.
  3. A reads the reversal and is refused `concurrent_retrieval`, so the job is released with the 60 s backoff.
  4. The retry records the reversal: the chain is `[settled 2-3, reversed 5-6]`, and `currentSettled()` is null.

  At `6184118d`, the same probe left `settled` current with no retry.
- **Three-way Fiber interleaving (both, both resume orders).**
  - A stalls before its first read (reversal graph), B stalls after its last read (settled), and C runs to completion (settled).
  - A and B are both refused `concurrent_retrieval`, and the chain stays ordered.
  - A's retry records the reversal.
- **Exhaustive ledger schedules.** Three retrievals X, Y, Z, each with steps Start, End and Append, in all 1,680 interleavings, each on its own invoice identity. SQLite ran all of them; MySQL ran 62 (every 40th three-retrieval schedule plus all 20 two-retrieval ones).
  - Each append was classified exactly as predicted: admitted iff start > tail end; `superseded_retrieval` iff end < tail start; otherwise `concurrent_retrieval`.
  - SQLite: 2,604 admitted, 408 superseded, 2,028 concurrent. MySQL: 87 / 18 / 61.
  - Every chain satisfied `start(k) > end(k-1)`.
- **Mutations** (scratch copy, SQLite; `mutations-sqlite.txt`):

  | Mutation | Lane tests that kill it | Review probes |
  | --- | --- | --- |
  | Ma: end allocated right after the start, before the reads | 15 (interval ordering 5, job 4, overlapping 2, clock skew 4) | 4 |
  | Mb: coverage on the end position | 3 | — |
  | Mc: superseded when end < tail **end** (turns some overlaps into normal ends) | 1 (`…reversal_appended_first…`) | the 1,680-schedule probe |

  The lane also recorded its own CP-1 normal-end mutation and a trigger-only mutation.

**Can any interleaving still make a stale `settled` the tail?** Not while the counter is single-writer and never reissued (CP-2 covers the case where it is). In addition:

- **The superseded case is safe.** A definitive read that wholly precedes an `unknown` tail ends as superseded (carried I-2), and nothing settled is current, because the tail is `unknown` (probe).
- **A useful invariant now holds.** Admission orders reads, and coverage needs a definitive observation that started after the hint. So if any definitive observation started after a hint, every later tail did too. A tail that predates a provider change therefore always leaves that change's retained hint uncovered for redelivery or the sweep.
- **Inherent window, Info.** A legitimately admitted, older `settled` stays the tail until a refused newer read retries. The first backoff is 60 s, within the 600 s freshness window that consumers must already tolerate.

## Liveness under repeated overlap

**Starvation is impossible.** A `concurrent_retrieval` refusal happens only because a different, overlapping retrieval was admitted as the new tail. The same tail cannot refuse the retry: the retry starts after the refusal, and the refusal happens after that tail's end was committed. So every refusal is accompanied by a fresh admitted observation, and the invoice keeps getting observations.

**A failed job leaves a fresh-enough tail.** With `$tries = 3`, a job refused on all three attempts leaves a tail W3, where:

`start(W3) > end(W2) ≥ start(N2) > end(N1)`

That is, the tail began after the job's first read ended.

- The probe's adversarial schedule shows the bound is tight: W2 started before N1 ended (6 < 7), and W3 started after it (10 > 7).
- With two attempts, a failed job could leave a tail that predates the reversal its first read saw. The lane's job test pins three attempts (its "second attempt released 600 s" case fails at `$tries = 2`).
- The job then fails visibly: it rethrows on the third attempt (lane job test). Nothing is left silently current beyond the above.

## A failed end allocation, or a crash between end and append

Both were probed on SQLite and MySQL.

- **(a) The end allocation fails** (a transaction is open when it runs). The retrieval throws `transaction_open`, appends nothing, and leaves one anonymous start position.
- **(b) The end is committed but the append never happens.** This was modelled by withdrawing the configuration during the reads (`changed_policy`). It leaves two anonymous positions.

In both cases the pending hint stays uncovered and is scheduled again on redelivery, and the next retrieval is admitted. Anonymous positions are never read by coverage or admission (`retrieval_end_position` appears only in the schema, the ledger and tests), and no path reuses one.

## Test-expectation changes

- **Only the reason string changed** (superseded to concurrent) in:
  - `BillingOverlappingRetrievalTest`, 2 cases;
  - `BillingClockSkewOrderingTest`, 3 cases;
  - `BillingNativeStaleRetrievalRaceTest`, 2 cases.

  Every chain, `currentSettled` and no-award assertion is kept, and `BillingOverlappingRetrievalTest` also gains a convergence assertion (`reversed`, sequence 3).
- **One method is renamed.** It is not in the census, and no old name remains anywhere.
- **`BillingNativeDedupRaceTest` now accepts either refusal.** Both are non-appending, and the invariants are kept: one identity, and one contiguous chain of exactly the saved rows.
- **The "overtaken" job test** now runs the newer retrieval after the stale end commit, so it still tests a genuinely wholly-earlier read. It is not weakened.

## Census and merge

- **Census exactness.**

  | Head | Census pairs | Billing pairs | SQLite skipped billing cases | Equal |
  | --- | --- | --- | --- | --- |
  | `2e08af42` | 182 | 4 | 4 of 179 | yes |
  | `59e8f8e2` | 183 | 5 | 5 of 180 | yes |

  Files: `census-exactness*.txt`. The census-reading self-tests pass at both heads (`census-*.txt`), and main did not change the census.
- **The merge of main `d3e1c39a`** changes no lane path (`merge-and-range-checks.txt`). Its seven non-doc test files mention no billing table. Run on SQLite on the merged tree, they all pass (`sqlite-03-merged-main-tests.txt`).

## CP-2 under the interval design

**Does a reissued id now need to collide twice? No, once.**

- In the probe on both engines, the stale in-flight retrieval R holds start 4, whose row is lost, and the counter reissues 4 to a newer retrieval R2.
- R's end (5) is a fresh allocation, so R appends `settled` [4, 5] and covers hint 3, which arrived after R's reads.
- What changed: R2 is now refused as `concurrent_retrieval` (5 ≥ 4) instead of ending as superseded, so its job retries. The retry records `reversed` [7, 8], and nothing settled is current.

So the harm is transient: a stale tail and a wrongly covered hint persist only until the holder's retry, unless that retry is also lost. The recommendation is unchanged: a per-retrieval token on the position row, or a recorded single-writer, durable-commit requirement. It now applies to both positions.

## Codex P2: split guard lookups (`18f1606c`)

**Equivalence: exact.**

- `NOT EXISTS (… WHERE a = x OR b = y)` is the same predicate as `NOT EXISTS (… WHERE a = x) AND NOT EXISTS (… WHERE b = y)`.
- Likewise, `c IN (x, y)` is `c = x OR c = y`.
- All the columns are NOT NULL, and each carries a UNIQUE index. So the guard refuses exactly what it refused before, and the UNIQUE indexes remain the backstop.
- The regenerated trigger text passes the owned-schema check on both engines (`BillingSchemaPreparationTest`). There is no uniqueness or ownership hole.

**Other locking paths that reach across invoices.** These are point lookups under REPEATABLE READ: a lookup of a key that doesn't exist takes a gap lock.

- **Unrelated first claim** (the original review's split probe, re-run twice at `59e8f8e2`): 1.26 s and 1.30 s while invoice A was locked for 8 s. Before this commit it was 8.67 s at lane 2, and 4.26 s with a refusal on the lane's red run. The fix works.
- **Retrievals of four different invoices** (the original review's 4×5 storm, `storm-head-1/2.txt`):
  - `lock_deadlocks` rose by 1 and by 3, and all 20 retrievals saved in each run.
  - The latest deadlock is two observation INSERTs holding S gap locks: one on `observations_u0` `(invoice_id, sequence)`, another on `observations_u1` `(retrieval_position)` (`storm-nolock-1`).
  - The position indexes grow monotonically, so every appender's "not used" lookup lands on the same supremum gap. This predates the range (lane 2 measured 2 in 20). It is P1B-1.
- **Concurrent webhook intakes of distinct events, then concurrent first claims of distinct invoices** (new review probe, 4 workers × 10 each, run twice alone; `istorm-head-1/2.txt`): no deadlocks in either phase. All 40 intakes committed and all 40 claims succeeded.

  The hint guard's `hint_position` lookup has the same monotonic-supremum shape, but it produced no deadlock in 80 intakes. The subscriptions guard only takes S locks on plan, account and user rows, which nothing in billing X-locks, and it was not exercised concurrently. The positions guard has no subquery.

## Is `lockIdentity()` load-bearing?

**For correctness: no.**

- The per-invoice serializer is the UNIQUE `(invoice_id, sequence)` index together with the append retry.
  - Two appenders that read the same tail both compute sequence n+1, and one gets a duplicate-key error.
  - Its retry starts a new transaction, re-reads the new tail and re-applies the interval rule.
  - The trigger is an independent backstop (the lane's trigger-only mutation).
- With the lock removed (scratch copy `mut59`, two storm runs), all 20 of 20 retrievals saved in each run, and the split probe was unchanged (1.28 s).
- The original review's ordering mutation also survived.

**For deadlocks: no measurable effect.**

| Storm run | Deadlock increase |
| --- | --- |
| Head, run 1 | +1 |
| No lock, run 1 | +2 |
| Head, run 2 | +3 |
| No lock, run 2 | +2 |
| Lane 2, with the lock | +2 |
| Lane 2, without the lock | +2 |

That is +4 against +4 in this review, and +2 against +2 at lane 2.

- Those storms were cross-invoice, so they say nothing about same-invoice deadlocks.
- The P2 record's sentence "the review's storm data shows it reduces same-invoice deadlocks" is not supported by that data and should be corrected.

**Recommendation: keep it.** Its plausible benefit is that same-invoice appenders queue instead of colliding on `(invoice_id, sequence)`, which preserves the three-attempt append budget. That was not measured. Since `18f1606c` it no longer delays unrelated claims, and its remaining cost (it holds `observations()`'s schema proof and seal checks, about 2.5 s per append here) falls only on the same invoice. That cost is R-5, before activation.

## Findings

| ID | Severity | Where | Finding | Recommendation |
| --- | --- | --- | --- | --- |
| P1B-1 | Low (liveness; before activation; part of L2-1) | `BillingSchema.php:282-287` (observations guard lookups) | Concurrent appends to **different** invoices still deadlock on S gap locks taken by the guard's point lookups. `observations_u0` `(invoice_id, sequence)` and `observations_u1` `(retrieval_position)`; the position indexes are monotonic, so all appenders contend for one supremum gap. 1 to 3 deadlocks per 20-retrieval storm, with or without `lockIdentity()`; the retry absorbed all of them. Higher concurrency can exhaust the 3-attempt append retry (`observation_contention`, a job failure). | Before activation, with R-5: run the append transaction at READ COMMITTED, or otherwise avoid gap locks in the guard reads, then re-measure with more workers. Otherwise, record the contention bound. |
| P1B-2 | Low (test robustness) | `BillingNativeCrossInvoiceClaimTest.php:54` | The test asserts the unrelated claim took under 3 s of wall clock. On this review's shared instance it took 3.444 s and **succeeded** (`claimed`), so no lock wait reached the 3 s `innodb_lock_wait_timeout`. The time is the ownership proof under load (`mysql-011`). The same test passed when re-run alone (`mysql-011b`: claims of 1.283 s and 1.31 s, OK, run twice alone; other agents' processes still shared the host CPU, load about 3.7). A wall-clock bound can fail on a slow CI host without any lock wait. | Assert the outcome under the short lock-wait timeout (it already proves no long wait), or measure lock waits directly (for example, the connection's `Innodb_row_lock_waits` delta), not elapsed time. |
| P1B-3 | Info (record accuracy) | `../hardening/codex-p2-cross-invoice/README.md` ("Fix", last bullet) | "The review's storm data shows it reduces same-invoice deadlocks" is not what the data shows (see above). | Reword: kept as a same-invoice serializer; no measured deadlock effect. |
| P1B-4 | Info | `RetrieveMembershipInvoice.php:33` | Three attempts is the minimum at which a job exhausted by overlapping winners leaves a tail that began after its first read (probe). | Note why 3 is the floor next to `$tries`; the lane's job test already fails at 2. |
| P1B-5 | Info (inherent) | `BillingLedger.php:177` | A legitimately admitted older `settled` stays the tail until a refused overlapping newer read retries (first backoff 60 s, within the 600 s freshness window). | Consumers (C3/C2) treat `currentSettled` as evidence up to the freshness window old, as before. |
| CP-2 | Low (carried; reduced) | `BillingSchema.php` positions | One reissued start still admits a stale read and covers a hint, but the holder is now retried (`concurrent_retrieval`), so the harm is transient. | Unchanged: a per-retrieval token or a durability/topology requirement, now for both positions. |
| CP-3 | Low (carried) | `BillingLedger.php` `created_at` / freshness | Unchanged (probe still shows a `clock` refusal and the skew-extended freshness). | Unchanged. |

CP-1 and CP-4 are closed, and so is Codex P2. No finding is Medium or above.

## Runs

Raw outputs are in `review-evidence/codex-p1b/`, and each ends with `rc=`. PHPUnit was invoked as `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never <path>`.

**At `2e08af42`:**

| File | Engine | Result | rc |
| --- | --- | --- | --- |
| `sqlite-01-ProductionMembershipBilling.txt` (+ `.xml`) | SQLite | OK, 179 tests, 886 assertions, 4 skipped (the native-only census methods) | 0 |
| `sqlite-02-ReviewCodexP1BIntervalAdversarialTest.txt` | SQLite | OK, 10 tests, 9,546 assertions (review probes) | 0 |
| `sqlite-03-merged-main-tests.txt` | SQLite | The seven merged main test files: 9/42, 16/215, 28/126, 23/606, 1 skipped, 33/195, 3/19 (tests/assertions), all OK | 0 each |
| `mysql-01-BillingNativeIntervalRetrievalRaceTest.txt` | MySQL | OK, 2 tests, 28 assertions | 0 |
| `mysql-02-BillingNativeStaleRetrievalRaceTest.txt` | MySQL | OK, 2 tests, 27 assertions | 0 |
| `mysql-03-BillingNativeDedupRaceTest.txt` | MySQL | OK, 1 test, 15 assertions | 0 |
| `mysql-04-BillingRetrievalIntervalOrderingTest.txt` | MySQL | OK, 11 tests, 66 assertions | 0 |
| `mysql-05-BillingSchemaPreparationTest.txt` | MySQL | OK, 13 tests, 77 assertions | 0 |
| `mysql-06-ReviewCodexP1BIntervalAdversarialTest.txt` | MySQL | OK, 10 tests, 357 assertions (review probes; 62 sampled schedules) | 0 |
| `mutations-sqlite.txt` | SQLite (scratch) | Ma, Mb and Mc killed, as tabulated above | n/a |
| `pint.txt` | — | `pint --test` on the 19 PHP files of `cbd5e5c5`: passed | 0 |
| `census-test-*.txt`, `census-exactness.txt` | — | Census self-tests OK; census equals the skipped set | 0 |
| `merge-and-range-checks.txt` | — | The merge changes no lane path; main left the census unchanged | 0 |

**At `59e8f8e2`:**

| File | Engine | Result | rc |
| --- | --- | --- | --- |
| `sqlite-04-ProductionMembershipBilling-59e8f8e2.txt` (+ `.xml`) | SQLite | OK, 180 tests, 886 assertions, 5 skipped | 0 |
| `sqlite-05-ReviewCodexP1BIntervalAdversarialTest-59e8f8e2.txt` | SQLite | OK, 10 tests, 9,546 assertions | 0 |
| `mysql-011-BillingNativeCrossInvoiceClaimTest.txt` | MySQL, shared load | 1 test, 11 assertions, 1 failure: claim 3.444 s, succeeded (P1B-2) | 1 |
| `mysql-011b-BillingNativeCrossInvoiceClaimTest-alone.txt` | MySQL, alone | Run twice: OK, 1 test, 15 assertions each; claims of 1.283 s and 1.31 s; first retrieval of C 3.50 s and 3.42 s, both while A was locked | 0 / 0 |
| `mysql-012-BillingSchemaPreparationTest.txt` | MySQL | OK, 13 tests, 77 assertions (the regenerated split-guard triggers pass the owned-schema check) | 0 |
| `mysql-013-BillingNativeIntervalRetrievalRaceTest.txt` | MySQL | OK, 2 tests, 28 assertions | 0 |
| `mysql-014-BillingRetrievalIntervalOrderingTest.txt` | MySQL | OK, 11 tests, 66 assertions | 0 |
| `mysql-015-BillingNativeStaleRetrievalRaceTest.txt` | MySQL | OK, 2 tests, 27 assertions | 0 |
| `mysql-016-BillingNativeDedupRaceTest.txt` | MySQL | OK, 1 test, 15 assertions | 0 |
| `mysql-017-ReviewCodexP1BIntervalAdversarialTest.txt` | MySQL | OK, 10 tests, 357 assertions (review probes, run by path; the same observations as at `2e08af42`) | 0 |
| `storm-head-1.txt`, `storm-head-2.txt` | MySQL, alone | Split: claim 1.30 s / 1.26 s. Storm: deadlocks +1 / +3, 20/20 saved each | 0 / 0 |
| `storm-nolock-1.txt`, `storm-nolock-2.txt` | MySQL, alone, scratch copy without `lockIdentity()` | Split: claim 1.28 s / 1.28 s. Storm: deadlocks +2 / +2, 20/20 saved each | 0 / 0 |
| `istorm-head-1.txt`, `istorm-head-2.txt` | MySQL, alone | Intakes and claims, 4×10 each: 0 deadlocks; 40/40 committed, 40/40 claimed | 0 / 0 |
| `pint-18f1606c.txt` | — | `pint --test` on the 2 PHP files of `18f1606c`: passed | 0 |
| `census-test-*-59e8f8e2.txt`, `census-exactness-59e8f8e2.txt` | — | Self-tests OK; 183 pairs; census equals the skipped set (5) | 0 |

**Review sources** (untracked; not executable copies):

- `ReviewCodexP1BIntervalAdversarialTest.php.txt` (sha256 prefix `f47bef97d75ffe58`);
- `ReviewIntakeClaimStormTest.php.txt` and `review-intake-storm-worker.php.txt`;
- runners `run-mysql.sh` and `run-storm.sh`.

The storm probes are the original review's committed `review-evidence/probes/Lane2ReviewProbeTest.php` and its workers, unchanged.

## Conditions

1. **P1B-2, before relying on Foundation CI for this test:** replace the wall-clock bound.
2. **P1B-1 (part of L2-1), before activation, with R-5:** remove the guard gap-lock deadlocks (or bound and record them), then re-measure natively.
3. **P1B-3:** correct the P2 record's deadlock sentence.
4. **Carried:**
   - CP-2 (token or topology/durability requirement, now for both positions);
   - CP-3 (skew bound or database-clock times);
   - CP-9;
   - L2-4 and A1-4 before C3/C2;
   - R-4 before C4;
   - the release boundaries in `DECISION.md`: `enabled` and `provider_io_enabled` false, no gateway or route, no live mode.

## Not tested

- The full billing directory on MySQL. Only focused files ran there.
- Foundation CI.
- A real provider, and live mode.
- A real queue worker re-running a released job. The release was asserted with fake queue interactions.
- A real failover. CP-2 remains a simulation.
- Higher concurrency than 4 workers.
- Same-invoice concurrent appends without `lockIdentity()` under load.
- The subscriptions guard under concurrency.
- All 1,680 schedules on MySQL (62 sampled).
