# Independent review — D1 production free mount at 12441893

| | |
| --- | --- |
| Reviewed commit | `12441893c1d648cb4f286c07a830cbd05db3ca5a` (branch `harness/production-free-mount-20261018`, worktree `/home/user/wt/d1`) |
| Base | `origin/main` `e8dfe3cd4ebc21ca14925e616eb57f31320533c9` (also the merge-base) |
| Tested code SHA | `07e4b0d5`. Verified: `git diff 07e4b0d5 12441893` touches only `.md` files (CHANGELOG, CLAUDE-PLAN, RESUMPTION, ops runtime page, staging runbook, evidence packet) |
| Reviewer | Independent adversarial reviewer (Claude). Did not write the change. No commit, push or GitHub comment |
| Date | 2026-10-18/19 |

## Decision: APPROVE WITH CONDITIONS

There is no P0 or P1. The invariants hold on every path I could reach:

- No byte leaves after a revocation committed after the redemption. The first-byte proof catches it, as shown by the native race and by mutation.
- A redemption cannot be replayed. The DB has `UNIQUE(authorization_id)` on redemptions, `begin()` is one-shot and the committed read is one-use.
- The mount stays off for every non-literal value and outside `local`/`testing`.
- The identity capsule is strictly stronger than ff0's.

Two P2s need follow-up:

- **F1:** an ordinary committing listener that issues a raw or implicit commit makes the Free write durable before the terminal re-proof, and the result is reported as a refusal. The docs say this case is `outcome_unknown`, never a refusal.
- **F2:** the new accept and redemption-commit graph re-proofs have no failing test at all.

Conditions:

1. **Before merge (docs only):** correct the overclaims.
   - `docs/ops/production-free-http-runtime.md:56`, packet section 5 (line 49) and the CHANGELOG say "refuses a raw commit" and "a failure after the physical commit is `outcome_unknown`, never a refusal". The observer detects a callback's raw or implicit commit; it cannot prevent it, and today it reports `changed_transaction`.
   - Packet line 169 claims "every new protection has a failing control". F2 and F4 show it does not.
   - Fix the duplicated parenthetical on packet line 26.
2. **May defer, per the freeze-and-batch rule:** add the F1 code change and the F2 tests to the D1 follow-up batch.

## Findings

| ID | Sev | File:line | Finding | Evidence | Fix |
| --- | --- | --- | --- | --- | --- |
| F1 | P2 | `app/Domain/Grants/ProductionFree/ProductionFreeGrantTransactions.php:92-106`; `ProductionFreeGrantCommitObserver.php:65-91`; docs as in condition 1 | A raw PDO commit, or a MySQL implicit commit such as DDL, issued by an ordinary `TransactionCommitting` listener physically commits the family-256 write before the terminal re-proof. The observer only notices afterwards, when the savepoint marker is gone. `run()` then throws `changed_transaction`, which maps to 503 `PRODUCTION_FREE_GRANT_UNAVAILABLE`, not `outcome_unknown`. The docs say a failure after the physical commit is always `outcome_unknown`. Impact is bounded: a replay with the same request key is idempotent, an orphan authorization row is one-use and expiring, and an orphan redemption sends no byte. Membership's observer has the same detection-only property. | Reviewer experiment (untracked test, now deleted; copy at `d1-review/ReviewD1ExperimentTest.php.txt`). E1: `$pdo->commit()` in a committing listener during `accept` gives `reason=changed_transaction`, `origins=1`, level 0, not in a transaction. Same on SQLite and on native MySQL 8.4.11. E1b (commit, then reopen): `changed_transaction`, `origins=1`. | When `abort()` finds the frame not owned (marker lost) or the primary no longer in a transaction, throw `outcome_unknown`, never a refusal. Correct the docs to say "detected, not preventable". |
| F2 | P2 | `ProductionFreeGrants.php:99` (accept graph); `ProductionFreeGrantDownloads.php:126-134` (redemption-commit graph) | Two of the new terminal-graph re-proofs have no failing control. Both closures were replaced by `fn => []` at once, and the whole Free directory still passes. Nothing tests a revocation written by a committing listener during the redemption commit (only `authorize` has that test). For the redemption, the first-byte proof is the downstream backstop, so no bytes leak. But the graph's own rollback, where no redemption row survives, is unproven. | MG (redemption graph to `[]`): Observer, FirstByte, Http and Binding tests 90/90 OK. MH (accept graph to `[]`): Observer and Http tests 54/54 OK. MG+MH combined on the full `tests/Feature/ProductionFreeGrants`: 294 tests, 2810 assertions, 9 skipped, exit 0 (survived). | Add (a) a revocation inserted by a committing listener during redemption commit 2: expect a refusal, 0 redemption rows and no transfer; (b) an accept-graph case, for example an origin column or work row changed by a listener. |
| F3 | P3 | `ProductionFreeGrants.php:99` | The accept graph re-projects the new origin only. It does not re-prove admissibility: the definition still open at the same availability, and `count < max_origins`. A `closed` availability event written by a committing listener is admitted. The outcome equals the serial order accept then close, so no invariant breaks. The cap is not DB-enforced; `UNIQUE(account_id, definition_id)` is. | E2: closed-availability insert inside the listener gives `reason=none`, `origins=1`, `availability=2`. | Either include `open`, the current availability id and the cap count in the accept graph, or document the choice. |
| F4 | P3 | `ProductionFreeGrantCommittedRows.php:110` | The first-byte column-for-column equality of the four rows has no failing control. Retention triggers plus the schema proof make it defence in depth, so this is a test gap only. | MD (equality reduced to `count === 1`): FirstByte and Http tests 48/48 OK. | Add a control, or label it defence in depth in the packet. |
| F5 | P3 | `routes/production-free-grants.php:110-121` | All six routes share the throttle prefix `production-free-grants` but have limits of 60, 30 and 20. Customers are not the default (`web`) guard user, so the key is prefix plus `sha1(domain\|ip)`: one combined per-IP counter. About 20 library GETs therefore exhaust accept, authorize and redeem for a minute, shared across a NAT. Tax uses a separate prefix per tier. | Vendor `ThrottleRequests::resolveRequestSignature`; compare `routes/production-tax-checkout.php`. | Use per-tier prefixes, or a named limiter keyed on the customer guard. |
| F6 | P3 | `ProductionFreeGrantTransfer.php:27` | `public readonly PreparedDeliveryStream $stream` exposes the public `writeTo()` and `stream()`, so in-process code can stream bytes without `begin()` and its committed proof. The `$committed` constructor argument is optional. HTTP cannot reach this: the controller only reads `sizeBytes` and `sha256`. | Code reading. | Expose size and sha only; require the committed read for transfers built by `redeem`. |
| F7 | P3 | `app/Http/ProductionFree/ProductionFreeController.php:305-323` | JSON (author-flagged) is released after the controller's capsule re-proof, not at native send time; B4's epoch release is not mirrored. The window is middleware unwinding only. The data is the owner's own committed state. The bearer token returned by `authorize` is useless without a current owner session, because `redeem` re-proves the owner inside both commits and before the first byte. Not a real gap for any endpoint. | Code reading; HTTP and first-byte tests. | Optional: mirror the epoch release for JSON. |
| F8 | P3 | `ProductionFreeGrantTransactions.php:72` | `run()` checks only the default connection for depth 0. ff0's dropped `ProductionFreeGrantBoundary` and Membership's `runOwned` require every connection to be idle. | Code comparison (`git show codex-ff0-ref:…/ProductionFreeGrantBoundary.php`; `MembershipReservationOperations.php:83-99`). | Add the all-connections idle check, or justify dropping it in the packet's ff0 table. |
| F9 | P3 | `ProductionFreeGrantTransactions.php:77-79` | The frame swaps in a fresh `DatabaseTransactionsManager`. The queue's and dispatcher's `afterCommit` handling uses the container's `db.transactions` instead. A job dispatched with `afterCommit` (or a `ShouldHandleEventsAfterCommit` listener) by ordinary code during the frame therefore runs immediately, before the commit. Family 256 dispatches nothing, and Membership has the same pattern. | Vendor `Queue.php:374-377`; grep of family 256. | Note it as a residual. |
| F10 | P3 | packet `README.md:26`, `:169` | Line 26 has a duplicated parenthetical. Line 169's "every new protection has a failing control" is contradicted by F2 and F4. | — | Edit. |

### Assessment by area

**a. Observer.**

- Ordering is correct: delegate listeners, then `admit`, the terminal proof, `assertOwned` (TEMPORARY shadows included) and `admit`, then `proved`, then the physical commit. Nothing runs between `proved` and `getPdo()->commit()`.
- These are refused before the commit: nested `DB::transaction` (the beginning event at depth > 1), an exception in a listener (it propagates and `abort()` rolls back the owned frame), a dispatcher swap (`source()`), a manager swap, a raw reopen, and a Free command inside a Free frame.
- Gap: a raw or implicit commit by a listener (F1).
- The dispatcher and manager are restored in `finally` in every outcome. Tests assert this for success, refusal and replacement.
- The swap is limited to the admitted default connection. `RefreshDatabase`-style outer transactions are refused (`transaction_forbidden`) by design.
- `outcome_unknown` is a `ProductionFreeGrantException`, so `run()` rethrows it unchanged. It is never mapped to `contention` and never retried.
- Consistent with `MembershipReservationObserver`, and stronger: it adds the savepoint marker, metadata admission and the F-4 floor.

**b. First byte.**

- `ProductionFreeDownloadResponse` settles in `sendHeaders()` and `sendContent()`. On refusal it swaps status and headers to the fixed error before anything is emitted.
- HEAD (405), Range and If-Range (422) are refused at the root seam. HTTP/1.0 adds nothing.
- The deadline is fixed once after the redemption commit and never renewed. The read is one-use and `begin()` is one-shot.
- The frame uses `SET TRANSACTION ISOLATION LEVEL READ COMMITTED, READ ONLY` (MySQL) or `query_only` (SQLite), with plain SELECTs. The native test proved no lock wait, and `performance_schema` showed READ COMMITTED / READ ONLY.
- A revocation committed after the redemption is detected: race (i) and mutation MC.
- `Transfer::writeTo` calls `begin()` and then requires `admitted`. The PR #129 progress allowance is unchanged. A second `writeTo` fails on the closed stream.

**c. HTTP.**

- Default-off confirmed: see the commands below.
- CSRF: the D1 test overrides `runningUnitTests()` so the real check runs: 419 for JSON and for the form. `Sec-Fetch-Site: same-origin` passing without a token is the Laravel 13 framework default.
- The token appears only in bodies. `Referrer-Policy: no-referrer`; `dontFlash` includes `token`; reports log only the exception class.
- Bodies are capped at 4 KiB, including chunked bodies through `stream_get_contents(max + 1)`. Query strings are rejected.
- Foreign owner: 404. Withdrawn account or forged marker: 403.
- Staff are separated: only the `customer` session guard is used, with exact `SessionGuard`.
- Errors use one fixed shape with an allowlisted header filter.
- For `contention`, 503 with `Retry-After: 1` is the right choice over 409: it is transient and the same request is the safe retry, while 409 tells the client to change its request. Mutation coverage of this mapping is the author's M4.
- JSON release: F7.

**d. ff0 composition.**

- `diff` against `codex-ff0-ref:app/Domain/Grants/Free/Production/ProductionFreeGrantHttpBinding.php`: the binding is identical between `a4f9e63f` and `ff0f7a85`. The adaptation only strengthens it: exact class checks, a `loggedOut === false` check, `is_array` guards before offsets, and `getRawValue`.
- Nothing security-relevant was lost from the binding.
- The dropped `IdentityPolicy`, `Identity` and `Lineage` were old-family adapters. Their gates are covered by `ProductionFreeGrantPolicy` and `ProductionCustomerAccess`.
- The dropped `Boundary` carried the all-connections idle check (F8).

**e. API change.**

Callers of `customerCommand`:

| Caller | Graph | Verdict |
| --- | --- | --- |
| `review`, library `index`/`show`, redeem inspect | The work itself | Pure read; fine |
| `accept` | `project(originGraph(id))` | Pure, but narrow (F3) and untested (F2) |
| `authorize` | Origin graph, `entitled`, the exact authorization row | Pure; killed by mutation MF |
| Redemption commit | Origin graph, `entitled`, exactly one redemption | Pure; untested (F2) |
| `ReviewFree256IdentityRotationTest` | Account id | Pure |

The `staffCommand` and documents terminals are listed in the packet's table and match the code.

**f. Tests.** See the commands below. The race uses independent OS processes (Symfony `Process` with `PHP_BINARY`, file barriers). Its three orderings assert the right outcomes: (i) `outcome_unknown`, 0 bytes, redemption retained; (ii) delivered with the correct SHA-256 while the revocation is uncommitted; (iii) `revoked` with no redemption, after a real lock wait.

**g. Docs and ledger.**

- PR #158 row verified: `e8dfe3cd` has parents `7cd95b73` and `0a8ccb3e349a1abef00c33021e36add285b0a6fb`.
- No `base64:` keys or secrets in the diff (the only hit is an empty `DB_PASSWORD=` for a disposable local DB).
- `git diff --check` is clean.

**h. Scope.**

- The ledger and CHANGELOG edits belong to the lane.
- The renderer binding is justified and inert: tests show it is bound but never resolved.
- The `ApprovalTest` and `CliRendererTest` premise changes are deliberate, not weakening.
- Missing: the F2 tests.

## Commands and results

All runs used PHP 8.4.26, PHPUnit 12.5.34 and vendor matching the merged lock (`composer.lock` identical to main), with `public/build` absent.

| # | Command | Result |
| --- | --- | --- |
| 1 | `git diff --stat 07e4b0d5 12441893` | 6 files, all `.md` |
| 2 | `git log -1 --format='%H %P' e8dfe3cd` | parents `7cd95b73…`, `0a8ccb3e349a1abef00c33021e36add285b0a6fb` |
| 3 | SQLite command (brief) on `tests/Feature/ProductionFreeGrants` | 294 tests, 2810 assertions, 9 skipped, exit 0, 16:43 (matches packet) |
| 4 | Native MySQL 8.4.11 (`/opt/mysql-8.4.11/bin/mysqld`, port 3341, author datadir, server defaults REPEATABLE-READ): `--filter '/(test_native_\|test_revocation_inside_a_committing_listener\|test_e1_raw_pdo_commit)/'` over Observer, FirstByte and the experiment | 6/6 OK, 58 assertions, 0 skipped, 22:59. Observer revocation-in-listener 272 s; TEMPORARY parent shadow 249 s; TEMPORARY owned shadow 214 s; first-byte READ COMMITTED/READ ONLY no-lock 163 s; independent-process race (3 orderings) 257 s; E1 on MySQL: `changed_transaction`, origins=1 |
| 5 | Tax `ProductionTaxCommittedReadReceiptTest::test_refused_original_commit_cleans_probe_and_deferred_callbacks_then_allows_fresh_retry`, 5× isolated on the branch | 5/5 OK (4 tests, 44 assertions each) |
| 6 | Same, 5× isolated on main `e8dfe3cd` (`git archive` extract plus a hardlinked vendor; verified that main's classes load) | 5/5 OK |
| 7 | Same, under 4 CPU busy loops plus a parallel PHP run, 2× on the branch and 2× on main | branch: run 1 FAIL `original-budget ('expiry')` at line 259, run 2 OK. main: run 1 FAIL, identical data set and line 259, run 2 OK. **Pre-existing timing sensitivity, not a D1 regression.** The 500 ms budget expires before the commit, so the frame is already rolled back and `assertTrue($pdo->inTransaction())` fails. D1 touches no class the test reaches. |
| 8 | `scripts/ci/test-phpunit-shards.py`, `test-ci-scope.py`, `test-workflow-cadence.py`, `test-focused-tests.py` (Node 24.22.0) | 52 OK, 27 OK, 14 OK, 53 OK; all exit 0 |
| 9 | `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection` | "Proved 2476 selected MySQL-native tests in 235 files (of 9489 discovered tests in 687 files)", matching the workflow comments. Generated files deleted. |
| 10 | Census diff | `database-sqlite-skips.json` is append-only (4 entries after the last; the pinned 150-entry hash is asserted by `test-focused-tests` OK). Selection adds the race worker to `branching_helper_files`. MySQL skips unchanged. |
| 11 | `php artisan route:list --json`, shipped config | testing 166 routes / 0 Free; production 167/0; local 168/0; testing with `PRODUCTION_FREE_GRANTS_HTTP_MOUNTED=true` and a variant env var set: 166/0 (config is literal, no `env()`) |
| 12 | Temporary config edits (restored, `cmp` identical to HEAD): `'true'`, `1`, `[true]` | 0 Free routes in testing, production, local and staging |
| 13 | Literal `true` (positive control) | testing 6, local 6, production 0, staging 0 |
| 14 | `config:cache` with `true` | testing 6 / production 0; caches cleared afterwards |
| 15 | `git diff --check origin/main...HEAD`; secret grep of the diff | clean; no keys |

## Mutations

Each mutation was a temporary edit to a tracked file, restored from a byte copy and checked with `cmp`.

| ID | Mutation | Tests run | Result |
| --- | --- | --- | --- |
| MA | Observer: drop `($this->terminal)()` | CommitObserverTest | **Killed**: 5 failures (revocation, policy, owner, session marker, staff policy) |
| MC | CommittedRows: drop the no-revocation check | FirstByteTest | **Killed**: 2 failures (revocation data set, refused begin) |
| MD | CommittedRows: equality reduced to `count === 1` | FirstByte and Http | **Survived** (48 OK); F4 |
| ME | Mount: drop `self::environment()` | Http default-off and runtime | **Killed**: 1 failure (cold production case). The runtime production case still gives 404 through the policy's `environment` check, so the defence is layered. |
| MF | `authorize` graph returns `[]` | CommitObserverTest | **Killed**: 1 failure (revocation in listener) |
| MG | Redemption-commit graph returns `[]` | Observer, FirstByte, Http, Binding | **Survived** (90 OK); F2 |
| MH | `accept` graph returns `[]` | Observer and Http | **Survived** (54 OK); F2 |
| MG+MH | Both, on the full Free directory | 294 tests | **Survived** (294 tests, 2810 assertions, 9 skipped) |
| MI | Download: drop the capsule `proveCurrent()` before `begin()` | Http post-commit withdrawals | **Killed**: 1 failure (marker) |
| MJ | Observer `source()`: drop the dispatcher-identity check | CommitObserverTest | **Killed**: 1 failure (replaced dispatcher) |

Also confirmed: the author's M1 to M5 class of mutation, re-done here as MA and ME.

## Untested conditions

- **Native MySQL:** only the 6 selected cases ran natively. The 12 ordinary Observer cases, 8 ordinary FirstByte cases and the Schema file were not re-run by me; the author reports them.
- **Hosts:** no hosted CI, real web server, PHP-FPM, `connection_status()` abort or send timeout.
- **Implicit commit:** a DDL-triggered implicit commit on MySQL was not exercised. It is the same class as E1 (raw commit, run natively).
- **F2:** no test was written for the redemption-commit revocation rollback. I reasoned that first-byte catches it: zero bytes, `outcome_unknown`, consistent with race (i).
- **Tax flake under load:** 2 load runs per tree, not a statistical sample. The identical failure on main at the same line is the basis for "pre-existing".
- **Affected-file set:** the 53 affected files outside the Free directory were not re-run by me.
- **Cleanup:**
  - The `vaseyaudio_d1_free` schema on the D1 datadir holds data from my native runs. Tests re-migrate.
  - The D1 mysqld was shut down normally.
  - I never touched the C4d mysqld on 3351; it was no longer running when I finished.
  - The worktree is clean at HEAD `12441893c1d648cb4f286c07a830cbd05db3ca5a`.
