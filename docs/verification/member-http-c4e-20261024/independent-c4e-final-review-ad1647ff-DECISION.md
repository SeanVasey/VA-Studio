# Independent final review: C4e member HTTP at `ad1647ff`

- **Reviewed:** `harness/member-http-c4e-20261024`, HEAD `ad1647ff3e468cbe02b9e8889299acd475019061`, worktree `/home/user/wt/c4e`.
  - PR diff: `git diff 8f166c5a...ad1647ff`, 27 files.
  - Focus: the changes since the APPROVE at `9d1e09f6`. These are `aafafabb` (R1 to R3), `c5475656`, `8cddd8b4`, the main merges `34fd070c` and `3f3d8672`, and the ledger commits.
- **Earlier records:** `scratchpad/c4e-review-b3deef84.md` and `scratchpad/c4e-rereview-9d1e09f6.md`.
- **Reviewer:** independent Claude reviewer; I am not the author. Date: 2026-10-24/25 UTC.
- **Rules kept:**
  - No commit, push, PR, comment or dispatch.
  - The worktree under review was never written. At the end, `git status --short` in `/home/user/wt/c4e` was empty and HEAD was unchanged.
  - Mutants ran only in a detached scratch worktree. Its `app/`, `tests/` and `routes/` were compared equal to the reviewed worktree before removal; the mutant copy differed in exactly the one mutated line.
  - Native runs used my own `/opt/mysql-8.4.11/bin/mysqld` (pid 3522, port 3447, which I checked free with `ss -ltnp`; socket `/tmp/c4f-my/s`; datadir under the scratchpad).
  - Other lanes' servers (pids 3215, 25703, 26620) were never touched.
  - Mine was stopped with `mysqladmin -S /tmp/c4f-my/s shutdown`. The datadir, socket directory and both scratch worktrees were removed (`git worktree prune`).

## Decision

**APPROVE WITH CONDITIONS.**
- No P0, P1 or P2 is open.
- Merging this branch with F9 open is safe as a fail-closed, unmounted state.
- **Conditions** (not merge blockers for the unmounted state; they go to the deferred batch under freeze-and-batch):
  1. F9 and N7 (a per-customer limiter) stay recorded as blockers for **any** mount, including local demos used as evidence.
  2. The three P3 findings below are deferred with the lane.

## Assessment by question

### 1. R1 fix (`aafafabb`): the snapshot now follows the owner lock

- **Code.** Inside `DB::transaction`, the first call is `lockOwnerFirst($primary, $principal->userId)` (`app/Domain/Memberships/Production/MembershipCustomerIndex.php:138-141`, method at `:268-285`). It runs `SELECT id FROM \`db\`.\`users\` WHERE id = ? FOR UPDATE` on the captured primary.
  - `plainPrimary` and `getDatabaseName()` issue no SQL.
  - `outsideTransactions()` runs twice before the frame, so the transaction cannot be nested inside one whose read view already exists.
  - An InnoDB locking read does not create a REPEATABLE READ read view.
- **Native statement order.** I turned the general log on around `test_native_index_locks_only_the_owner_rows_until_commit_then_releases_them` at `ad1647ff`: OK, 1 test, 29 assertions.
  - On the index thread, the only statement between `START TRANSACTION` and the lock is that statement's own `Prepare`. The lock is the frame's first executed statement.
  - The first re-lock by `ProductionCustomerAccess::lock` (`users ... FOR UPDATE`) is statement 405 after it.
  - Analysis: `c4e-final-review/runs/glog-analysis.txt`; the log is `runs/general-locks.log.gz`.
- **Same-owner writers.** Can a same-owner writer still commit a period the index misses? No, for every writer that goes through `ProductionCustomerAccess::lock`:
  - That lock's first locking read is `users` by actor id (`ProductionCustomerAccess.php:227`, through `IdentityRows::rows` with `FOR UPDATE`).
  - `match()` binds the actor to the principal, and the writer holds the lock to commit.
  - Either the index takes `users` first, and the writer cannot commit until the index commits; or the index waits, and its read view is created after the writer's commit.
  - The writers enumerated in the earlier reviews (award, reserve, release, expire, activation) all take this lock first. The residual case, a writer that locks owner identity rows without `users`, is honestly listed as untested in the packet (README line 650). The terminal committed-frame proof still refuses in that case, so it fails closed.
- **Behaviour (native), baseline at `ad1647ff`:**
  - `test_native_index_waiting_behind_a_same_owner_writer_reads_that_writers_commit`: OK, 15 assertions, 471 s.
  - `test_native_index_waiting_behind_a_same_owner_release_reads_the_released_history` (R4): OK, 16 assertions, 486 s.
- **R2.** The class docblock (`:28-36`) and the packet now say the further locks are re-locks of the same owner's identity rows plus the historical challenge rows. That is accurate.
  - The CHANGELOG says the index "cannot join a lock-order cycle with any membership writer, activation or index". That holds: all of them take the owner's `users` row first and hold it to commit, so they are fully serialized per owner.
  - Identity operations are not claimed, and the packet lists them as untested.
- **R3.** The census pins both defaults by reflection and the `capturePeriod` writer call sites (`MembershipCustomerIndexAvailabilityTest.php:102-115`). A grep of all call sites matches: three writer classes keep the locking default, and only the index passes the flags. See P3-2 for the one gap.

### 2. `8cddd8b4`: test only, and it does not weaken the R1/R4 proof

- **Test only.** `git show 8cddd8b4` touches only `tests/Feature/ProductionMembershipBilling/MembershipNativeCustomerIndexTest.php`: two `usleep(20000)` become `usleep(250000)`, plus comments.
- **Rationale.** It is correct: InnoDB refreshes the `INNODB_TRX` cache only when it has not been read for 0.1 s. The author's instrumented trace `c4e-ev/latency/r1-debug.txt` shows the stale view.
- **Assertions unchanged.** Both tests still assert that the index was observed in `LOCK WAIT` (`assertSame([$parent], waiters)`), and they assert the outcome (new period listed; reservation `released`).
- **Mutant M1** removes the `lockOwnerFirst(...)` call, which is the ordering `aafafabb` added; everything else is at `ad1647ff`, `8cddd8b4` included.

| Run | Result |
| --- | --- |
| native R1 on M1 | **Killed**: exit 2, `MembershipException`. Trace: `MembershipCustomerIndex.php:346` (`changed_period_window`) via `:205`, the terminal committed `raw(..., true)`. This is exactly the original R1 symptom. 434 s |
| native R4 on M1 | **Killed**: exit 2, `index_refused` caused by `MemberGrantException` from `MemberActivationHistoryReader.php:350` via `MembershipCustomerIndex.php:193` (`committedCallbacks`, the terminal history proof). 376 s. This also gives native evidence for the history side of R4 |
| SQLite `MembershipCustomerIndexAvailabilityTest` on M1 | **Survived**: OK, 5 tests, 123 assertions. See P3-1 |

### 3. Merge correctness (`34fd070c`, `3f3d8672`)

- **Census** `scripts/ci/database-sqlite-skips.json`:
  - main `8f166c5a` has 297 entries and the branch has 302.
  - Positions 0–296 are identical to main except 284 and 285. Those are the C4d methods renamed in place, already accepted twice, because the census must match existing methods exactly.
  - Five branch methods are appended. There are no duplicates.
  - The first-150 hash `d93da4e7a13a8c6aff8f97f363eb6d93dde884bad53c9ae50eeb0ed32514c71c` is unchanged (`test-focused-tests.py` OK).
- **Selection.** `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection` printed "Proved 2562 selected MySQL-native tests in 248 files (of 9753 discovered tests in 717 files)".
  - This equals `.github/workflows/final-verification.yml:151` and `.gitlab-ci.yml:113` ("2562 of 9753 cases in 248 files"). Main's comments read 2557 of 9701.
  - Generated `phpunit-ci-mysql-*` files were deleted, and `git status` was clean.
- **Safeguards:** `test-phpunit-shards.py` 52 OK, `test-ci-scope.py` 27 OK, `test-workflow-cadence.py` 14 OK, `test-focused-tests.py` 53 OK, `test-database-receipts.py` 61 OK.

### 4. F9 gate: unmounted and fail-closed

- `routes/web.php:93-97` requires `routes/production-memberships.php` only when `MembershipHttpMount::admitted()`. No other file includes the routes file (grep).
- `MembershipHttpMount` (`app/Http/Membership/MembershipHttpMount.php`) is a token-for-token copy of the reviewed `ProductionFreeHttpMount`; the diff shows only the docblock and the names. It admits only when:
  - the plain loaded config has `production-memberships.http_mounted === true`;
  - the container's cached `env`, or the literal captured by `offsetSet`, is `local` or `testing`.

  Anything else, including any exception, returns false.
- The shipped `config/production-memberships.php:13` is the literal `'http_mounted' => false`, not `env()`.
- `MembershipPrivacy` (prepended in `bootstrap/app.php:68`) re-checks `admitted()` on every `/production-memberships*` request, before any session or SQL. A stale route cache therefore gets the one not-found.
- `StagingEnvironmentAdmissionTest` lists `MembershipHttpMount.php` in the local/testing gate census (`:160`).
- **Tests:**
  - SQLite `MembershipHttpMountTest`: OK, 34 tests, 1092 assertions. It covers the default-off matrix, runtime withdrawal and the stale table.
  - SQLite `StagingEnvironmentAdmissionTest`: OK, 15 tests, 231 assertions.
- **Callers.** The only caller of `MembershipCustomerIndex`/`MembershipCustomerRead` outside the domain is `MembershipController`.
- **Conclusion.** Staging and production cannot mount. With F9 open, a call that is somehow made (local or testing only) refuses with `operation_deadline`, `owner_refused` or `index_refused`, which maps to the one not-found, and never releases bytes before pre-bytes passes. The author's latency JSONL confirms that every refused call threw before pre-bytes. Merging is safe as an unmounted, fail-closed state. The cost of a mounted local call is up to about 60 s of worker time per request under a 10-a-minute per-IP limit, local or testing only.

### 5. Ledger and doc spot-check against `scratchpad/c4e-ev/`

All checked claims match the evidence:

| Claim | Evidence |
| --- | --- |
| R1 at `8cddd8b4` OK, 15 assertions | `runs-b/idx-R1c.log` (head `8cddd8b4`) |
| Earlier R1 failures | `idx-test_native_index_waiting_behind_a_same_owner_writer…log` at `34fd070c`: the trace is the `$admit` deadline (`MembershipCustomerIndex.php:134` from the period loop `:160`), consistent with `operation_deadline`, though the reason is not printed. `idx-R1b.log` at `bd3172c6`: 1205 |
| R4 OK both times, 16 assertions | at `34fd070c` and `8cddd8b4` (`idx-R4c.log`) |
| Lock, wait, zero-period, reverse-order and same-plan award cases | 29, 23, 21, 15 and 21 assertions at `34fd070c` |
| Interleave case | 16 assertions |
| Commit witnesses and period path | `nwitness` 3/55; `witness` 5/94; `period` 4/69; `actwitness` 3/50 at `8cddd8b4` |
| HTTP | five per-method logs at `8cddd8b4`: 129 + 148 + 34 + 46 + 59 = 416 |
| Locks case on `3f3d8672` | `m2-idx-…locks_only…` head `3f3d8672`, OK, 29 assertions |
| Latency table | matches `latency/result-scaling.jsonl` (17 rows: 1, 2, 3, 5, 10 and 100 periods, the same totals and refusals) |
| 100 periods with a concurrent award | matches `latency/result-concurrent.jsonl` (61.4/61.9 s refusals; award finished at 46.1/44.3 s; never waited on the index) |
| "Affected SQLite 919 tests" in `CLAUDE-PLAN.md` | 161 + 416 + 342, matching the packet's mA, mB and mC |
| RESUMPTION, CLAUDE-PLAN checkpoint and deferred-ledger entries for PRs #168–#172 and C4e (N4, N5, N7, F9) | consistent with the packet and the earlier review records |

## Findings

| ID | Sev | Where | Failure path | Evidence | Required |
| --- | --- | --- | --- | --- | --- |
| P3-1 | P3 | `tests/Feature/ProductionMembershipBilling/MembershipCustomerIndexAvailabilityTest.php:90-91`; packet `docs/verification/member-http-c4e-20261024/README.md:168` and `:310` ("SQLite census pins the order") | The order pin is `assertLessThan(strpos($frame, 'new MembershipRows'), strpos($frame, '$this->lockOwnerFirst(...)'))`. If the call is deleted, `strpos` returns `false`, and PHP evaluates `false < int` as true, so the assertion passes. The other source checks (`substr_count('FOR UPDATE') === 1` and the SQL string) look at the still-present private method. So deleting the R1 fix passes every SQLite test. Only the two native-only methods kill it, and they run only in the manual native selection or Foundation. | Mutant M1 on SQLite: OK, 5 tests, 123 assertions (survived). Natively it is killed by R1 and R4 (above). | Pin the call: for example `assertSame(1, substr_count($frame, '$this->lockOwnerFirst($primary, $principal->userId);'))` and `assertIsInt` on both positions before comparing. Correct the packet wording to "SQLite census pins the order when present; the native R1/R4 tests pin its presence". Deferred batch. |
| P3-2 | P3 | Same test, `:107-114` (R3 census) | The census pins the `capturePeriod` call sites only in three named writer classes. The writer call site of `definitionByHash` (`app/Domain/Grants/Member/MemberGrantDefinitions.php:79`, staff authoring) and any future writer class are not pinned. A writer passing `true` there would silently read unlocked. Today's code is correct: all call sites were grepped, and only the index passes the flag. | grep of `capturePeriod(` and `definitionByHash(` across `app/` | Add `MemberGrantDefinitions` to the census, or assert that only `MembershipCustomerIndex` passes either flag anywhere under `app/`. Deferred batch. |
| P3-3 | P3 | Packet `README.md:447-465` ("Census and selection") | It still says "final state 294 entries" and gives the selection as 2490 of 9573. The current state is 302 entries and 2562 of 9753, which are stated correctly in the phase B sections at `:539-541` and `:621-623`. A reader can take the stale numbers as current. | Census and selection recomputed above | Label that section as the state at `9d1e09f6`, or point it to the phase B numbers. Deferred batch. |

Carried over unchanged, all recorded in the packet and the deferred ledger: F9 (blocks any mount), N4, N5, N7.

## Commands and results (my runs)

- **SQLite:** the brief's direct PHPUnit command (in-memory SQLite, `public/build` absent), wrapper `c4e-final-review/sq.sh`.
- **Native:** wrapper `c4e-final-review/my.sh`, my private server at port 3447, REPEATABLE-READ, `performance_schema` on, binary log off, `innodb_flush_log_at_trx_commit=2`, one database per run, `VA_LOCAL_DISPOSABLE_MYSQL` set, no deadline or lock-wait timeout raised.
- **Source:** a scratch worktree at `ad1647ff` with vendor hard-linked from `/home/user/wt/c4e`. All 160 vendor package versions equal `composer.lock`.

| Run | Source | Selection | Result |
| --- | --- | --- | --- |
| safeguards | `ad1647ff` | `scripts/ci/test-{phpunit-shards,ci-scope,workflow-cadence,focused-tests,database-receipts}.py` | 52, 27, 14, 53, 61 OK |
| selection | `ad1647ff` | `phpunit-shards.py --shards=8 … --mysql-native-selection` | 2562 of 9753 in 248 files; equals both comments; generated files deleted |
| census | `8f166c5a` vs `ad1647ff` | Python comparison | 297 → 302; main's order kept except the two accepted renames at 284 and 285; 5 appended; first-150 hash unchanged; 0 duplicates |
| base-R1 | `ad1647ff` | native `…same_owner_writer_reads_that_writers_commit` | OK, 1 test, 15 assertions, 471 s |
| mut-M1-R1 | M1 | same | Killed: `changed_period_window` at the terminal raw proof, 434 s |
| base-locks-glog | `ad1647ff` | native `…locks_only_the_owner_rows…` with general log | OK, 29 assertions, 328 s; the lock is the frame's first statement |
| mut-M1-R4 | M1 | native `…same_owner_release_reads_the_released_history` | Killed: terminal `committedCallbacks` refused, 376 s |
| base-R4 | `ad1647ff` | same | OK, 1 test, 16 assertions, 486 s |
| sq-mount | `ad1647ff` | `MembershipHttpMountTest` | OK, 34 tests, 1092 assertions |
| sq-staging | `ad1647ff` | `StagingEnvironmentAdmissionTest` | OK, 15 tests, 231 assertions |
| sq-avail | `ad1647ff` | `MembershipCustomerIndexAvailabilityTest` | OK, 5 tests, 123 assertions |
| sq-mut-avail | M1 | same | OK, 5 tests, 123 assertions: the mutant survived (P3-1) |

Logs and JUnit files are in `/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad/c4e-final-review/runs/`; the progress log is `…/c4e-final-review/PROGRESS.md`.

## Not tested

- I did not re-run the rest of phase B: the zero-period, reverse-order and same-plan award cases, the commit witnesses, period-addressed operations, native `MembershipHttpTest` and latency. The packet's runs at `34fd070c` and `8cddd8b4` were spot-checked against their logs instead. Application code at `ad1647ff` equals `8cddd8b4` apart from main's merged changes, which do not touch the index or HTTP files.
- The full affected SQLite suites (the packet's 919 tests) were not re-run; only the four focused files above.
- R1/R4 each ran once at `ad1647ff`, so flakiness was not measured. The R1 race depends on the index finishing two periods within 60 s, which is already tight before F9 (the author's first R1 run failed on the deadline under load).
- Identity operations (sign-in, recovery) against the index's owner lock; a writer locking owner rows without `users`; PHP-FPM, a web server or proxy behaviour; Foundation and hosted CI.
- F9 itself (being fixed in a separate lane).
