# F9: member customer index latency (lane F9)

Lane F9, branch `harness/member-index-f9-20261024`, from the C4e head `ad1647ff`, merged with `origin/main` `a8f17e5c`
(through PR #177, which carries C4e as PR #174) and then `76fca3d7` (PRs #178 REP and #179, the symfony/mime security
update). It fixes finding F9 of the C4e packet
(`docs/verification/member-http-c4e-20261024/README.md`, "Finding F9"): one customer index call plus its pre-bytes
proof took about 20 s at one paid period on native MySQL 8.4.11, reached the fixed 60 s budget at about five periods and
always refused at 10 and 100 (the page size is 100). It also carries the Codex P2 on PR #174 (thread r4369151991):
`reserve()` refused a valid JSON body whose keys came in another order.

This is a local development candidate, pending independent review. Nothing is pushed by this lane, no PR is open, no
workflow was dispatched. The member HTTP mount stays default-off and is not mounted anywhere new; review N7 (per-client
rate limits) still blocks any non-local mount. The handoff status of 2026-10-26 is superseded by this packet.

## Cause

The index ran outside any owned frame, so every schema guard proved completely on every call. Per period and pass it
re-ran the identity ownership proof about 12 times (inside the membership guard and `IdentityRows`), the member-grant
schema proof about 8 times, and verified the same retained buyer binding three times (capture, current proof, committed
proof), each with two complete identity proofs. Pre-bytes repeats the whole read. Writers had G1's once-per-frame proof
(`SchemaProofFrame`, PR #168); the index did not.

## Change

| File | Change |
| --- | --- |
| `app/Domain/Memberships/Production/MembershipCustomerIndex.php` | Right after `lockOwnerFirst` the frame opens `new MembershipAwardTransaction($connection, $primary, memberGrantProofs: true)` and builds `MembershipRows` on it, so the identity and membership guards prove completely once per frame token (G1). All periods share one member-grant schema snapshot. Each distinct retained buyer binding is verified once per frame (`$state['historical']`, `bindings()`); every period still checks its own binding fields and origin. `$owned->beginTerminalProof()` runs before `$grants->proveCurrent` and `$access->proveCurrent`, so from there to the commit every guard proves completely, as before. The raw phases use `currentRawAll`, `committedCallbacksAll` and `committedRawAll` |
| `app/Domain/Memberships/Production/MembershipAwardTransaction.php` | Optional `memberGrantProofs` constructor flag (default `false`) and `memoizesMemberGrantProofs()`. Writers construct it unchanged |
| `app/Domain/Grants/Member/MemberGrantCurrentSchema.php` | `pin`/`assertCurrent` route the inspection through `SchemaProofFrame::prove` (guard `member_grant:<plan hash>`, member tables pinned) only when the owned transaction opted in; every writer and every committed-frame proof (`assertCommitted`) inspects in full on each call, as before |
| `app/Domain/Grants/Member/MemberActivationHistoryReader.php` | `capturePeriod(..., ?MemberGrantSchemaSnapshot $schema = null)` re-proves a caller-pinned snapshot instead of a new pin (writers keep the default). New `currentRawAll`, `committedCallbacksAll`, `committedRawAll`: one complete schema proof before (and, for the raw phases, after) the re-reads of all periods sharing one snapshot. The per-period methods are unchanged for writers |
| `app/Http/Membership/MembershipController.php` | `reserve()` compares the sorted key set (`expectedRevision`, `offerId`, `requestKey`): any order, none missing, none extra; value checks unchanged. The order-strict checks on server-built structures (index allowlist, receipt balances) are unchanged |

Why the C4e security properties hold (for review):

- **Locks unchanged.** No new locking statement; the frame's first statement is still the owner `users` lock (R1). The
  savepoint and the `SELECT ... LIMIT 0` pins the memo adds run after it and take no row lock. The native lock, wait,
  reverse-order, same-plan writer, R1 and R4 cases all pass (below).
- **Memo soundness is G1's argument.** A remembered proof is reused only while the frame token (marker witness,
  session inputs, `Com_create_table`/`Com_drop_table`, SQLite catalogs) is unchanged and the inspected tables are held
  by metadata locks; every MySQL reuse repeats the identity dependents scan. A schema or session change refuses
  (fail-closed). The member-grant inspection checks only owned tables, their triggers and the namespace inventory, so
  the same argument covers it; a namespace-only collision is seen by the complete terminal proofs.
- **Terminal re-proof.** The owned terminal phase (before the commit) proves every guard completely; the committed
  read-only frame keeps complete proofs (`IdentityRows::committed`, `assertCommitted`), now once around all periods
  plus once per distinct binding instead of once per period. Nothing is released before `IdentityCommittedFrame::finish`
  and pre-bytes.
- **Binding dedup.** The rows a binding verification reads are the owner's identity rows, locked by this frame (T23),
  plus immutable history; in one frame a repeat returns the same rows. Periods with the same binding share the result;
  differing evidence for one binding refuses (`changed_history`).
- **Batched raw phases.** No callback runs inside `currentRawAll`/`committedRawAll`; reads of all periods sit between
  two complete proofs in one transaction holding the pins. In `committedCallbacksAll` a callback between periods can
  only be refused later by the complete proofs of `committedRawAll`, before `finish()`.
- **Pre-bytes** still re-runs the complete owned read and the committed proof under the original deadline; it gets
  faster from the same frame proofs. A cheaper currentness comparison, a smaller page or paging was not needed.

## Measurements (private MySQL 8.4.11, one PHPUnit process)

Scratch harness `evidence/F9IndexLatencyProbe.php.txt` (the C4e latency harness, parametrized; not committed as a
test): `memberActivationFixture()` plus synthetic older sealed periods in the retained format; one call is
`MembershipCustomerRead::begin()`, `index()` and `proveBeforeBytes()` under the one 60 s budget. Statements per call
from the session's `Questions` counter. The host was **not idle**: other lanes ran PHPUnit and mysqld concurrently
(load average about 4 to 5 on 4 cores for both runs).

"After" is the merged source `56cf3ead` (application code equal to the final head); "before" is `ad1647ff`.

| Periods | Before total s | Before statements | Before outcome | After total s | After statements | After outcome |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 19.0 to 19.6 | 9,416 | completed | 7.6 to 8.6 | 5,088 | completed (4 of 4) |
| 3 | 35.0 to 35.7 | 17,480 | completed | 8.7 to 9.3 | 5,640 | completed (3 of 3) |
| 5 | 52.3; 60.0 | 25,544; 25,471 | completed; refused (`index_refused` in pre-bytes) | 8.4 to 9.1 | 6,192 | completed (3 of 3) |
| 10 | 61.4; 60.4 | 27,975; 28,895 (cut off) | refused (`operation_deadline`) both | 9.9 to 10.4 | 7,572 | completed (3 of 3) |
| 100 | 60.9; 60.9 | 29,043 (cut off) | refused (`operation_deadline`, in the index) both | 27.4 to 30.2 | 32,412 | completed (3 of 3) |

- `SHOW CREATE TABLE` per call: before 5,354 at one period and growing; after 2,324 at every size from 1 to 100.
- Statements per extra period and call: before about 4,032; after 276.
- **100 periods with another customer's real award running** (harness `test_concurrent_award_at_100`, 2 repetitions):
  the index completed in 33.4 s and 34.0 s (the harness also samples `performance_schema` every 300 ms from inside the
  index); the award, in a separate process started with the index transaction, committed about 19 to 21 s in while the
  index was still open, held the shared plan row during the index, and never appeared in
  `performance_schema.data_lock_waits` blocked by the index's connection. Before the fix (C4e phase B) the same case
  refused at 61.4 s and 61.9 s.
- At 100 periods, 58 % of wall time is the identity dependents scan G1 repeats on every memo reuse
  (`information_schema` KEY_COLUMN_USAGE, TRIGGERS, VIEWS, ROUTINES; about 16 ms per reuse, about 7 reuses per period
  and pass): `evidence/glog-time-after-100-periods.txt`.
- The "before" run's process had loaded every affected class before the working tree was edited (its counts equal the
  C4e phase B numbers exactly: 9,416 and 17,480).

Raw rows: `evidence/result-scaling-before.jsonl`, `result-scaling-final.jsonl`, `result-concurrent.jsonl` (and the
pre-merge `result-scaling-dev1.jsonl`, `result-scaling-prof1.jsonl`). Phase breakdowns: `evidence/glog-phases-*.txt`.

## Tests added or changed

| Test | Engines | What it pins |
| --- | --- | --- |
| `MembershipNativeCustomerIndexTest::test_native_index_schema_proofs_per_call_do_not_grow_with_the_number_of_periods` | MySQL only (SQLite skip census entry 303, appended) | Session counters, not timing: `Com_show_create_table` and `Com_show_create_trigger` per call are equal at 1 and 3 periods and nonzero; all statements per extra period and call < 500 |
| `MembershipCustomerIndexAvailabilityTest::test_index_proves_schema_once_per_owned_frame_and_completely_in_its_terminal_phases` | both | Source census (every position pinned as an int first): owned frame with `memberGrantProofs: true` after the owner lock, terminal phase before the current identity and grant proofs and the raw phase, batched raw and committed calls, one binding verification site, writers construct the transaction without the flag; plus a behaviour run |
| `MembershipCustomerIndexAvailabilityTest::test_index_terminal_phase_proves_in_full_after_remembered_proofs` | both | As G1's `MemberObserverTerminalProofTest`: while the index frame remembers identity, membership and `member_grant:` proofs, a table appears under a reserved identity index name (another session on MySQL); the index refuses before its commit, and on MySQL the frame is terminal |
| `MembershipCustomerIndexAvailabilityTest::test_index_locks_only_the_owner_and_reads_everything_else_without_row_locks` | both | Census literal updated to the new `capturePeriod(..., schema: $schema)` call (no assertion removed) |
| `MembershipHttpTest::test_reserve_accepts_its_three_keys_in_any_order_and_refuses_a_missing_or_extra_key` | both | A reordered valid body reaches the domain (reserve, then the original order replays it); missing, extra, replaced and wrong-typed reordered bodies are 422 with no write |

SQLite cannot count raw PDO statements (no trace hook in PHP 8.4 `Pdo\Sqlite`, and `MembershipRows` refuses
application functions), so the statement-count regression is native-only; on SQLite the terminal test's frame-state
assertions catch a missing frame or a missing `member_grant:` memo. Because the terminal test branches on the driver,
`MembershipCustomerIndexAvailabilityTest` joins the reviewed native include list
(`scripts/ci/database-mysql-selection.json`).

## Census and selection

- `scripts/ci/database-sqlite-skips.json`: main's 302 entries first and unchanged, this lane's one method appended (303);
  first-150 hash `d93da4e7…` unchanged. The MySQL skip census is unchanged.
- `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`:
  exit 0, 2,587 of 9,778 cases in 250 files on the first merge (main 2,579 of 9,774 in 249), and 2,601 of 9,809 cases
  in 258 files after the second merge (main 2,593 of 9,805 in 257); both count comments updated; generated files
  deleted.
- Safeguards on `56cf3ead` and again on the second merge `308a40ae`: `test-phpunit-shards.py` 52, `test-ci-scope.py` 27, `test-workflow-cadence.py` 14,
  `test-focused-tests.py` 53, `test-database-receipts.py` 61: all OK. (Before the include-list entry,
  `test-phpunit-shards.py` failed on the new driver-branching file, which is how it was found.)

## Test results

Direct PHPUnit (`--fail-on-phpunit-warning`), `public/build` absent, `APP_KEY` set, CI environment. Native: private
`/opt/mysql-8.4.11/bin/mysqld` 8.4.11 on its own datadir and port, `REPEATABLE-READ`, `performance_schema` on, binary
log off, `VA_LOCAL_DISPOSABLE_MYSQL`, one database per chain. The runs below are on `56cf3ead`;
the commits after it change documentation, plus the second merge (see below), which touches none of this lane's code.

| Run | Selection | Result |
| --- | --- | --- |
| SQLite sA | `ProductionMembershipHttp`, `ProductionMemberGrants`, `ProductionMemberActivation`, `ProductionMembership`, `StagingEnvironmentAdmissionTest` | 177 tests, 2,746 assertions, 10 skips, OK |
| SQLite sB | `ProductionMembershipBilling` | 419 tests, 3,700 assertions, 41 skips, OK |
| SQLite sC | `ProductionMemberOriginals`, `ProductionIdentity` | 276 tests, 1,494 assertions, 20 skips, OK |
| Native, one process per method | all 9 `MembershipNativeCustomerIndexTest` methods: owner-only locks (29 assertions), wait at a writer-held owner lock (23), fence/shared rows held elsewhere (21), reverse order (15), same-plan award for another customer (21), **R1** same-owner writer (15), **R4** genuine release (16), index then reserve then pre-bytes (16), new growth test (18) | each OK |
| Native, one process per method | all 6 `MembershipHttpTest` methods: journey (129), key order (100), one not-found (148), outcome unknown then converge (34), committed write with refused receipt is submitted (46), index body withheld when pre-bytes refuses (59) | each OK |
| Native | `MembershipNativeCommitWitnessTest` | 3 tests, 55 assertions, OK |
| Native | `MembershipCommitWitnessTest` | 5 tests, 94 assertions, OK |
| Native | `MembershipPeriodAddressedOperationsTest` | 4 tests, 69 assertions, OK |
| Native | `MemberActivationCommitWitnessTest` | 3 tests, 50 assertions, OK |
| Native | G1 `MembershipSchemaProofFrameTest` | 8 tests, 66 assertions, OK |
| Native | G1 `MemberObserverTerminalProofTest` | 4 tests, 92 assertions, OK |
| Native | `MembershipCustomerIndexAvailabilityTest` | 7 tests, 196 assertions, OK |
| Native (not in the native selection) | `MembershipCustomerIndexTest` | 12 tests, 187 assertions: 10 OK, including `test_bound_is_one_hundred_periods_and_the_sentinel_only_reports_more` (100 periods plus the sentinel, natively); 2 errors by construction: `test_sentinel_row_is_authenticated_like_every_listed_period` and `test_ledger_audit_failure_refuses_the_whole_index` reseal an event by editing `sqlite_master` (MySQL 1064), so they are SQLite-only and the file is not natively selected |

Every SQLite skip is in the SQLite census and no census method ran (checked from the JUnit files).

Three native index methods first errored in a run where two native processes and a SQLite mutation chain of this lane
ran beside other lanes (load average about 7): each failed inside the fixture's own reserve (a writer this lane does
not change), with `operation_refused` or `committed_receipt_refused`, the writer's 60 s budget under load. Re-run one
process at a time, all passed (the table above).

## Mutations

Each mutant was applied in a hard-link copy of the worktree by atomic file replacement (driver `evidence/mutate.py`),
restored and compared (`diff -rq app` empty after every restore). The worktree was never written.

| ID | Mutation | Tests | Result |
| --- | --- | --- | --- |
| P1 | No owned frame (`new MembershipRows`, no terminal call): per-period proving restored | native growth test (`Com_show_create_table` 5,354 at 1 period vs 6,982 at 3); SQLite terminal test (no frame) and census | **Killed** |
| P2 | `memberGrantProofs: false` | native growth test (`Com_show_create_table` 2,450 vs 2,702); SQLite terminal test (no `member_grant:` memo) and census | **Killed** |
| P3 | Binding dedup removed (verify and prove per period) | native growth test (2,324 vs 2,968); SQLite census | **Killed** |
| P4 | Committed callbacks per period again | native growth test (2,324 vs 2,408); SQLite census | **Killed** |
| T1 | `beginTerminalProof()` removed | native terminal test: the index committed (one `TransactionCommitted`) before the committed frame refused; SQLite census only (on SQLite same-connection DDL moves the catalog token, so the next proof is complete anyway: equivalent there) | **Killed** natively |
| T2 | Committed-frame `proveCommitted` removed | existing `MembershipCustomerIndexTest::test_owner_withdrawal_while_the_index_commits_refuses_the_terminal_proof` on SQLite and natively ("Expected an explicit membership refusal") | **Killed** |
| T3 | Committed raw phase (`committedRawAll`) removed | SQLite census; behaviour survives on SQLite (`MembershipCustomerIndexTest` 12/12) and natively (terminal test 21 assertions, owner-withdrawal-during-commit test 14) | Census only; see Untested conditions |
| T4 | Committed callbacks phase removed | every index call refuses (`history_snapshot`): SQLite availability 6 errors, `MembershipCustomerIndexTest` 8 errors; native terminal test (the index never answers) | **Killed** |
| K1 | `reserve()` back to the order-strict key list | new HTTP test (422 for the reordered body) | **Killed** |
| K2 | `reserve()` accepts any subset of the keys | new HTTP test (503 instead of 422 for a missing key) | **Killed** |

## Untested conditions

- One fixture shape (one plan, one buyer binding, one reservation); periods whose bindings differ (owner recovery
  between periods) are covered by code reading only.
- The host was shared and loaded for every timing; there is no idle-host latency. Timings are one server on one host.
- T3 has no behavioural kill: the committed raw phase re-reads what `committedCallbacksAll` just read in the same
  committed frame with no callback between, so only a change made by a callback in between would differ; as before F9
  (per-period `committedRaw`), nothing exercises that.
- Under REPEATABLE READ the per-reuse dependents scan sees the frame's snapshot; skipping it or merging adjacent guard
  calls would cut the remaining time further but changes G1 or C4d guard density and needs its own review.
- No HTTP evidence under PHP-FPM, a real web server, a proxy or a browser; no Foundation run, hosted CI, host, provider
  or production evidence. The mount stays default-off; N7 (per-customer rate limit) is still required before any
  non-local mount.

### After the second merge (`308a40ae`)

The second merge changes no member-domain, HTTP, route, bootstrap or configuration file of this lane (it adds lane
REP's reporting files and updates `symfony/mime` and `symfony/polyfill-mbstring`). After
`rm -rf vendor/symfony/mime vendor/symfony/polyfill-mbstring && composer install`: `composer audit` reports no advisories,
`composer validate --strict` passes. Focused checks: SQLite `MembershipCustomerIndexAvailabilityTest` plus
`MembershipHttpMountTest` 41 tests, 1,288 assertions, OK; SQLite key-order test 1 test, 100 assertions, OK; native growth
test 1 test, 18 assertions, OK. The full suites above ran on `56cf3ead`, whose member code equals this head.

## Cleanup

The private server was shut down with `mysqladmin shutdown` after the last run, and the hard-link mutation copy was
removed. Scratch logs and JUnit files stay in the session scratchpad, outside the repository; the summaries the packet
relies on are in `evidence/`.

## Independent review at `8bdfcd27`

Decision: **APPROVE WITH CONDITIONS** ([`independent-f9-review-8bdfcd27-DECISION.md`](independent-f9-review-8bdfcd27-DECISION.md)),
no P0 or P1. Memo reuse for the member-grant guard fails closed (a concurrent `CREATE TRIGGER` waited behind the frame's
metadata lock; a namespace-only collision was refused at the pre-commit terminal phase with no commit); C4e's pinned
properties hold natively (R1, R4, owner-only locks, the same-plan award for another customer; mutants P1, P2 and T1
killed); binding dedup is sound with a real owner recovery between periods (4 periods, 2 bindings, both engines); T3 is
acceptable as census-only because the committed frame is read-only; the key-order fix accepts no malformed body.

| ID | Finding | Resolution |
| --- | --- | --- |
| F9-R1 (P2) | At host load 5 to 9 the reviewer measured the same statement counts as the author (5,088 and 32,412 per call) but 100-period calls of 53.9 to 60.4 s, 3 of 6 refused at the 60 s deadline, and the concurrent-award case refused at 60.3 s | Condition accepted: the fix removes the per-period growth, but a full 100-period page has no proven headroom and stays a mount gate with N7. The CHANGELOG, the C4e packet, the resumption row and the deferred ledger no longer say "fixed". Options for the follow-up: a smaller page, fewer G1 identity dependents scans (58 % of the remaining time), or a measurement on the target host |
| F9-R2 (P3) | T3 (committed raw re-read removed) has census-only coverage | Deferred: add a test that the committed frame is read-only |
| F9-R3 (P3) | Each distinct binding costs one complete committed identity proof; many recoveries are slower (fails closed, unmeasured) | Deferred |
| F9-R4 (P3) | The reviewer's recovery probe could become a test | Deferred |
| F9-R5 (P3) | Trailing whitespace in `evidence/glog.py` | Fixed |

The reviewer could not measure an idle host or production hardware; G1's `MemberObserverTerminalProofTest` passed 3 of 4
methods under load 8 to 10 (the fourth failed twice inside its writer fixture's deadline, not in index code; the author's
run passed 4 of 4).
