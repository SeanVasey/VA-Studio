# F9: member customer index latency (lane F9)

Lane F9, branch `harness/member-index-f9-20261024`, from the C4e head `ad1647ff`. It fixes finding F9 of the C4e
packet (`docs/verification/member-http-c4e-20261024/README.md`, "Finding F9"): one customer index call plus its
pre-bytes proof took about 20 s at one paid period on native MySQL 8.4.11, reached the fixed 60 s budget at about five
periods and always refused at 10 and 100 (the page size is 100).

This is a local, unreviewed development candidate. Nothing is pushed, no PR is open, no workflow was dispatched. The
member HTTP mount stays default-off and is not mounted anywhere new. **The work stopped at a handoff checkpoint; read
[Status at handoff](#status-at-handoff-2026-10-26) first.**

## Cause

The index ran outside any owned frame, so every schema guard proved completely on every call. Per period and pass it
re-ran the identity ownership proof about 12 times (inside the membership guard and `IdentityRows`), the member-grant
schema proof about 8 times, and verified the same retained buyer binding three times (capture, current proof, committed
proof), each with two complete identity proofs. Pre-bytes repeats the whole read. Writers had G1's once-per-frame proof
(`SchemaProofFrame`, PR #168); the index did not.

## Change (application code)

| File | Change |
| --- | --- |
| `app/Domain/Memberships/Production/MembershipCustomerIndex.php` | Right after `lockOwnerFirst` the frame opens `new MembershipAwardTransaction($connection, $primary, memberGrantProofs: true)` and builds `MembershipRows` on it, so the identity and membership guards prove completely once per frame token (G1). All periods share one member-grant schema snapshot. Each distinct retained buyer binding is verified once per frame (`$state['historical']`, `bindings()`); every period still checks its own binding equals it and the origin. `$owned->beginTerminalProof()` runs before `$grants->proveCurrent` and `$access->proveCurrent`, so from there to the commit every guard proves completely, as before. The raw phases use `currentRawAll`, `committedCallbacksAll` and `committedRawAll` |
| `app/Domain/Memberships/Production/MembershipAwardTransaction.php` | Optional `memberGrantProofs` constructor flag (default `false`) and `memoizesMemberGrantProofs()`. Writers construct it unchanged |
| `app/Domain/Grants/Member/MemberGrantCurrentSchema.php` | `pin`/`assertCurrent` route the inspection through `SchemaProofFrame::prove` (guard `member_grant:<plan hash>`, member tables pinned) only when the owned transaction opted in; every writer and every committed-frame proof (`assertCommitted`) inspects in full on each call, as before |
| `app/Domain/Grants/Member/MemberActivationHistoryReader.php` | `capturePeriod(..., ?MemberGrantSchemaSnapshot $schema = null)` re-proves a caller-pinned snapshot instead of a new pin (writers keep the default). New `currentRawAll`, `committedCallbacksAll`, `committedRawAll`: one complete schema proof before (and, for the raw phases, after) the re-reads of all periods that share one snapshot, instead of per period. The per-period methods are unchanged for writers |

Why it keeps the C4e security properties (for review):

- **Locks unchanged.** No new locking statement; the frame's first statement is still the owner `users` lock (R1). The
  savepoint and the `SELECT ... LIMIT 0` pins that the memo adds run after it and create no read view and no row lock.
- **Memo soundness is G1's argument.** A remembered proof is reused only while the frame token (marker witness,
  session inputs, `Com_create_table`/`Com_drop_table`, SQLite catalogs) is unchanged and the inspected tables are
  pinned by metadata locks; every MySQL reuse repeats the identity dependents scan. A schema or session change refuses
  (fail-closed). The member-grant inspection only checks owned tables, their triggers and the namespace inventory, so
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
- **Pre-bytes** still re-runs the complete owned read and the committed proof (it is not made cheaper); it benefits
  from the same frame proofs. A cheaper currentness comparison was not needed.

## Measurements (private MySQL 8.4.11, one PHPUnit process)

Scratch harness `evidence/F9IndexLatencyProbe.php.txt` (the C4e latency harness, parametrized): `memberActivationFixture()`
plus synthetic older sealed periods in the retained format; one call is `MembershipCustomerRead::begin()`, `index()` and
`proveBeforeBytes()` under the one 60 s budget. Statements per call from the session's `Questions` counter. The host
was **not idle**: other lanes ran PHPUnit and mysqld concurrently, load average about 4 to 5 on 4 cores.

| Periods | Before (`ad1647ff`) total s | Before statements | Before outcome | After total s | After statements | After outcome |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 19.0 to 19.6 | 9,416 | completed | 8.5 to 9.6 | 5,088 | completed |
| 3 | 35.0 to 35.7 | 17,480 | completed | 8.9 to 9.6 | 5,640 | completed |
| 5 | 52.3; 60.0 | 25,544; 25,471 | completed; refused (`index_refused` in pre-bytes) | 9.2 to 10.6 | 6,192 | completed |
| 10 | 61.4; 60.4 | 27,975; 28,895 (cut off) | refused (`operation_deadline`) both | 10.8 | 7,572 | completed |
| 100 | 60.9; 60.9 | 29,043 (cut off) | refused (`operation_deadline`, in the index) both | 33.3 to 34.9 | 32,412 | completed (3 of 3) |

- `SHOW CREATE TABLE` per call: before 5,354 at one period and growing; after 2,324 at every size (1 to 100).
- Statements per extra period and call: before about 4,032; after 276.
- At 100 periods after the fix, 58 % of wall time is the identity dependents scan that G1 repeats on every memo reuse
  (`information_schema` KEY_COLUMN_USAGE, TRIGGERS, VIEWS, ROUTINES; about 16 ms per reuse, about 7 reuses per period
  and pass): `evidence/glog-time-after-100-periods.txt`. Phase breakdowns: `evidence/glog-phases-*.txt`. Raw JSONL:
  `evidence/result-scaling-*.jsonl`.
- The "before" run's process had loaded every affected class before the working tree was edited (its counts equal the
  C4e phase B numbers exactly: 9,416 and 17,480).

## Tests added or changed

| Test | Engines | What it pins |
| --- | --- | --- |
| `MembershipNativeCustomerIndexTest::test_native_index_schema_proofs_per_call_do_not_grow_with_the_number_of_periods` | MySQL only (appended to the SQLite skip census, entry 303) | Session counters, not timing: `Com_show_create_table` and `Com_show_create_trigger` per call are equal at 1 and 3 periods and nonzero; all statements per extra period and call < 500 |
| `MembershipCustomerIndexAvailabilityTest::test_index_proves_schema_once_per_owned_frame_and_completely_in_its_terminal_phases` | both | Source census: owned frame with `memberGrantProofs: true` after the owner lock, terminal phase before the current identity and grant proofs and the raw phase, batched raw/committed calls, one binding verification site, writers construct the transaction without the flag; plus a behaviour run |
| `MembershipCustomerIndexAvailabilityTest::test_index_terminal_phase_proves_in_full_after_remembered_proofs` | both | As G1's `MemberObserverTerminalProofTest`: while the index frame remembers identity, membership and `member_grant:` proofs, a table appears under a reserved identity index name (another session on MySQL); the index refuses before its commit, and on MySQL the frame is terminal |
| `MembershipCustomerIndexAvailabilityTest::test_index_locks_only_the_owner_and_reads_everything_else_without_row_locks` | both | Census literal updated to the new `capturePeriod(..., schema: $schema)` call (no assertion removed) |

SQLite cannot count raw PDO statements (no trace hook in PHP 8.4 `Pdo\Sqlite`, and `MembershipRows` refuses
application functions), so the statement-count regression is native-only; on SQLite the terminal test's frame-state
assertions catch a missing frame or a missing `member_grant:` memo.

## Status at handoff (2026-10-26)

**Done (on this branch, one WIP commit on `ad1647ff`):** the application change above, the three tests, the census
entry, this packet and its evidence summaries.

**Verified so far** (`evidence/test-runs.txt`; application code equal to the commit):

- SQLite `MembershipCustomerIndexAvailabilityTest`: 7 tests, 180 assertions, OK.
- Native MySQL 8.4.11 `MembershipCustomerIndexAvailabilityTest`: 7 tests, 180 assertions, OK (includes the terminal
  test against another session). The later hardening of the census test's position checks (`$at` helper, after
  3629a135's lesson) has not been re-run.
- Native `test_native_index_schema_proofs_per_call_do_not_grow_with_the_number_of_periods`: OK, 18 assertions.
- Latency table above.

**Not verified / not done:**

1. Merge `origin/main` (now includes PR #174 as `dd587603`) into this branch. Expect a small conflict in
   `MembershipCustomerIndexAvailabilityTest.php` (3629a135 hardened the owner-lock order check next to the edited
   `capturePeriod` literal; keep both) and in `scripts/ci/database-sqlite-skips.json` (union: main's entries first and
   in main's order, then this branch's one appended entry; first-150 hash `d93da4e7…` must not change).
2. Codex P2 from PR #174 (thread r4369151991) is **not in**: `app/Http/Membership/MembershipController.php` `reserve()`
   validates `array_keys($body) === ['offerId', 'expectedRevision', 'requestKey']`, so a valid reordered body is a 422.
   Fix: compare the key set regardless of order (for example sort a copy of `array_keys($body)` and compare with the
   sorted expected list, or `count($body) === 3` plus `array_key_exists` for each), leaving every value check as is.
   Leave the order-strict checks at about lines 135 and 161 (server-built structures). Add to
   `tests/Feature/ProductionMembershipHttp/MembershipHttpTest.php` (or the mount test) a case that a reordered valid
   body reaches the domain and cases that an extra or a missing key is still 422. Record it as fixed in the deferred
   ledger.
3. Regenerate the native selection after the merge and update both count comments (`.github/workflows/final-verification.yml`
   about line 151, `.gitlab-ci.yml` about line 113; main base 2579 of 9774 in 249 files, plus this branch's new native
   method): `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`,
   then `rm -f phpunit-ci-mysql-*`. Run `python3 scripts/ci/test-phpunit-shards.py`, `test-ci-scope.py`,
   `test-workflow-cadence.py`, `test-focused-tests.py`, `test-database-receipts.py`.
4. Native MySQL re-runs on the merged head (one process per method or file, private 8.4.11, `VA_LOCAL_DISPOSABLE_MYSQL`):
   all of `MembershipNativeCustomerIndexTest` (the 8 C4e cases including R1
   `test_native_index_waiting_behind_a_same_owner_writer_reads_that_writers_commit` and R4
   `test_native_index_waiting_behind_a_same_owner_release_reads_the_released_history`, plus the new one),
   `MembershipNativeCommitWitnessTest`, `MembershipCommitWitnessTest`, `MembershipPeriodAddressedOperationsTest`,
   `MemberActivationCommitWitnessTest`, `MembershipHttpTest`, and, because `MembershipAwardTransaction`,
   `MemberGrantCurrentSchema` and the history reader changed, G1's `MembershipSchemaProofFrameTest` and
   `MemberObserverTerminalProofTest`.
5. SQLite on the merged head: `tests/Feature/ProductionMembershipHttp`, `ProductionMemberGrants`,
   `ProductionMemberActivation`, `ProductionMembership`, `ProductionMembershipBilling`, `ProductionMemberOriginals`,
   `ProductionIdentity`, `StagingEnvironmentAdmissionTest` (the C4e phase B mA/mB/mC sets; roughly three hours of CPU).
   Direct PHPUnit command from the C4e packet, `public/build` absent.
6. Latency after the merge at 1, 5, 10 and 100 periods with the harness copied into `tests/` temporarily (do not commit
   it), and the 100-period case with another customer's real award running concurrently
   (`test_concurrent_award_at_100` in the harness), showing the award never appears in
   `performance_schema.data_lock_waits` blocked by the index. Not run after the fix yet.
7. Mutations (driver `evidence/mutate.py`, run in a hard-link copy, never the worktree): P1 index without the owned
   frame, P2 `memberGrantProofs: false`, P3 binding dedup removed, P4 per-period committed callbacks: expected killed by
   the native growth test (P1, P2 also by the SQLite terminal test). T1 `beginTerminalProof()` removed: expected killed
   natively by the terminal test (the index would commit before the committed frame refuses). T2 to T4 (committed-frame
   proofs removed): expected killed by existing tests such as
   `MembershipCustomerIndexTest::test_owner_withdrawal_while_the_index_commits_refuses_the_terminal_proof`. None run.
8. CHANGELOG entry and the C4e packet / deferred-ledger F9 entries: mark F9 "fixed pending review" only after 1 to 6.

**Further margin, if wanted (not done):** 100 periods takes about 33 s on a loaded host. Most of the rest is G1's
per-reuse dependents scan, which under REPEATABLE READ returns the frame's dictionary snapshot; skipping it there, or
removing per-period guard calls that sit next to each other with no callback between, would cut it further but changes
G1 or C4d guard density and needs its own review. Paging or a smaller page was not needed.

## Untested conditions

Everything in "Not verified" above, plus: one fixture shape (one plan, one binding, one reservation); a binding change
between periods (owner recovery) only by code reading; no HTTP, PHP-FPM or production evidence; no Foundation run.
