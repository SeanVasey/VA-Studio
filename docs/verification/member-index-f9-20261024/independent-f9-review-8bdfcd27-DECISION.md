# Independent review: lane F9 (member customer index latency) at `8bdfcd27`

- **Reviewed:** `harness/member-index-f9-20261024`, HEAD `8bdfcd27c85dd7dc69dc5afde4e77a2d3141faea`, worktree `/home/user/wt/f9`.
  - PR diff: `git diff 76fca3d7...8bdfcd27`, 32 files.
  - Main `76fca3d7` is an ancestor, and the two-dot and three-dot stats are identical.
- **Packet:** `docs/verification/member-index-f9-20261024/README.md` with `evidence/`.
- **Reviewer:** independent Claude reviewer; I am not the author. I also did the C4e final review (`ad1647ff`, merged as PR #174).
- **Dates:** 2026-10-30 to 2026-10-31 UTC.
  - The review was interrupted by a usage limit and a container restart, then resumed.
  - Progress log: `/home/user/notes/f9-review/PROGRESS.md`. Run logs:
    - 2026-10-30: in the old session scratchpad, `…/scratchpad/f9-review/runs/`;
    - after the restart: `/home/user/notes/f9-review/runs/`.
- **Rules kept:**
  - No commit, push, PR or dispatch, and `/home/user/wt/f9` was never written (`git status` empty).
  - Probes and mutants ran in detached scratch worktrees with vendor hard-linked. All 160 vendor package versions equal `composer.lock`.
  - Mutants were applied with the author's own `evidence/mutate.py` definitions, pointed at the copies.
  - Native runs used my own `/opt/mysql-8.4.11/bin/mysqld`: port 3457 (checked free with `ss -ltnp`), socket `/tmp/f9r-my/s`, REPEATABLE-READ, `performance_schema` on, binary log off.
    - 2026-10-30: pid 1666.
    - After the restart: pid 19258, datadir `/home/user/notes/f9-review/mysql`.
  - Other lanes' servers were never touched: CX's mysqlds on 3492, 3493 and 3467, and PCC's.
  - I stopped only my own chain scripts.

## Decision

**APPROVE WITH CONDITIONS.** There is no P0 or P1.

- **Design.** The design is sound.
  - Memo reuse for the member-grant guard is fail-closed, which I checked natively with two new probes.
  - Every C4e property I was asked to check still holds, by native tests and mutants.
  - Binding dedup is sound. I probed it with a real owner recovery between periods, and an over-merging mutant fails closed.
- **Condition (F9-R1, P2): do not record F9 as resolved for mounting.**
  - On this host under the load other lanes put on it (load average 5 to 8 on 4 cores), the 100-period call took 53.9 s, **60.1 s (refused)** and 59.8 s. The 100-period call with a concurrent award refused at 60.3 s.
  - The statement count was identical to the author's (32,412 per call), so the code under test is the same; only CPU headroom differs. The author measured 27 to 30 s at load 4 to 5.
  - Merging the unmounted code is safe, because every refusal fails closed and nothing is released.
  - But the CHANGELOG, RESUMPTION, deferred-ledger and C4e-packet wording ("100 periods complete in 27 to 30 s", "F9 fixed pending review") overstates the margin.
  - The 100-period page should stay a mount gate (with N7) until it has measured headroom on the target host under realistic concurrency. Options: page size, fewer per-reuse identity dependents scans (58 % of the remaining time per the author's own profile), or a production-hardware measurement.
  - Under freeze-and-batch, this P2 does not block the merge if the ledger wording is corrected, or explicitly deferred, at merge time.

## Findings

| ID | Sev | Where | Failure path | Evidence | Required |
| --- | --- | --- | --- | --- | --- |
| F9-R1 | P2 | Latency claim in `CHANGELOG.md`, `docs/handoff/2026-10-17/RESUMPTION.md`, `docs/handoff/2026-10-07/codex-deferred-20261011.md`, `docs/verification/member-http-c4e-20261024/README.md` and the F9 packet; `MembershipCustomerIndex::LIMIT` = 100 with the fixed 60 s operation budget | Statements per 100-period call stay at about 32k (G1's per-reuse dependents scans dominate). The wall time therefore scales with host CPU, and on a busy 4-core host one index plus pre-bytes call reaches the 60 s budget. The result is a permanent fail-closed refusal (the one not-found) for members with many periods whenever the server is busy, which is the original F9 symptom, now load-dependent rather than certain | Reviewer harness run (`F9_SIZES=1,100 F9_CALLS=3`):<br>1 period: 11.8 / 12.5 / 13.5 s.<br>100 periods: 53.9 s, 60.1 s refused (`owner_refused`, deadline hit in pre-bytes), 59.8 s.<br>Both sizes: statements 5,088 / 32,412, identical to the author's.<br>Concurrent-award case: index `operation_deadline` at 60.3 s; the award never waited on the index (its own receipt timed out, `committed_receipt_refused`).<br>Load 5.2 → 7.1 during the scaling run (`runs/load-before-latency.txt`) | Record F9 as "much improved; 100-period page fits only with spare CPU". Keep the mount gate until the page has headroom on the target host under concurrency. Fix by reducing the page or the per-reuse scans, or by measuring on production hardware |
| F9-R2 | P3 | `MembershipCustomerIndex.php` committed phase (`committedRawAll`, mutant T3), around lines 200–216 | Without `committedRawAll`, nothing proves the member-grant schema again in the committed frame after `committedCallbacksAll` and the `audit()` callbacks. The equality comparisons against the owned-frame capture still refuse any differing data, so this is defence in depth rather than an acceptance path. The committed frame is READ ONLY: a same-name temporary shadow cannot be made with `LIKE` (MySQL 1066) or `ALTER … RENAME` (1792); it needs an explicit `CREATE TEMPORARY TABLE` (see the T3 probe below). T3 was equally unpinned before F9 (per-period `committedRaw`) | Native probe v1–v3, base and T3 mutant: the hook fired in `audit()` inside the committed frame; every shadow attempt was refused (1066, then 1792 READ ONLY), and the index completed on both base and mutant, so the probe cannot distinguish them | Accept census-only. Optionally pin the READ ONLY committed frame (temporary-table creation refused) as a test |
| F9-R3 | P3 | `MembershipCustomerIndex::bindings` / `$state['historical']` | Dedup cost scales with distinct retained bindings: each distinct binding still runs a complete committed identity proof (`proveHistoricalBindingCommitted`). Many recoveries across periods (a binding per observation, up to 128) would push latency back toward the old per-period cost. It fails closed, but it is unmeasured | Code reading; my binding probe used 2 distinct bindings | Note it in the packet's untested conditions; optionally measure with several recoveries |
| F9-R4 | P3 | Packet "Untested conditions" ("periods whose bindings differ … code reading only") | Now covered by the reviewer probe. Adding it as a test would pin it | Probe below | Optional: commit the probe as a both-driver test |
| F9-R5 | P3 | `docs/verification/member-index-f9-20261024/evidence/glog.py:30` | `git diff --check 76fca3d7...8bdfcd27` reports trailing whitespace | Command output | Strip it |

## Assessment by question

### 1. Memo reuse for the member-grant guard

- **Code.** `MemberGrantCurrentSchema::inspectOwned` routes to `SchemaProofFrame::prove` only when the owned transaction opted in (`memberGrantProofs: true`, the index only).
  - `context()` and the savepoint marker checks still run on every call; only `inspect()` is memoized.
  - The memo is keyed by the plan hash, and the pins are the five member tables.
  - Committed-frame proofs (`assertCommitted`) always call `inspect()` directly.
  - Writers construct `MembershipAwardTransaction` without the flag. The census pins the five observer constructors and exactly one `memberGrantProofs: true` under `app/Domain/{Memberships,Grants}`.
- **Reuse soundness.** A reuse needs an unchanged frame token (marker witness, session inputs, `Com_create_table`/`Com_drop_table`, the SQLite catalogs), and on MySQL it repeats the identity dependents scan. DDL on a pinned member table, including a new trigger, needs an exclusive metadata lock and waits behind the frame.
- **Native probe P-MDL** (`test_probe_member_table_trigger_waits_for_the_frame_metadata_lock`).
  - Setup: during the memo phase, with `member_grant:…` remembered and the frame not terminal, another session runs `CREATE TRIGGER … ON production_member_definitions`.
  - Result: the other session got `1205 Lock wait timeout exceeded` (`lock_wait_timeout=3`), and the index completed normally.
- **Namespace-only collision.** A new table whose name collides with a member guard trigger takes no lock on the pinned tables and moves no token, so reuse does not see it.
  - It cannot change what the index reads: triggers and tables are separate namespaces, and the index reads only the owned tables.
  - The complete inspection in the owned terminal phase sees it (`SHOW CREATE TABLE` on the trigger name).
- **Native probe P-NS** (`test_probe_member_grant_namespace_collision_in_memo_phase_is_refused_before_commit`).
  - Setup: another session creates `production_member_profiles_update` during the memo phase.
  - Result: the index refused (`index_refused` ← `MemberGrantException`) with **0 commits**, and the frame was terminal afterwards. The refusal came from the terminal phase, before commit.
  - So the collision is seen in the terminal phase, before commit. That is acceptable.
- **SQLite.** The token includes the complete main and temp catalogs, so any same-connection DDL forces a complete proof.

### 2. C4e's pinned properties

- **Owner `users` lock first (R1); no new locking statement.**
  - `lockOwnerFirst` is still the first statement in the frame. `MembershipAwardTransaction::__construct` runs only `SELECT DATABASE()` and `SAVEPOINT` after it.
  - The pins are `SELECT … LIMIT 0`, with no row locks. The diff adds no `FOR UPDATE`, `FOR SHARE` or `GET_LOCK`.
  - The census pins the order: `assertIsInt` is used on every position, so the strpos-false hole from my C4e P3-1 is closed.
  - Behaviour, native, at `8bdfcd27`:
    - R1 same-owner writer: OK, 15 assertions.
    - R4 genuine release: OK, 16.
    - Owner-only locks: OK, 29.
  - My general-log confirmation run failed in its fixture under host load and is not counted. R1 is the behavioural pin.
- **The terminal phase proves every guard completely before commit.**
  - `beginTerminalProof()` runs before `$grants->proveCurrent`, `$access->proveCurrent` (identity, complete), `currentRawAll` (member-grant, complete before and after) and `$rows->assertCurrent()` (membership, complete).
  - Mutant **T1** (terminal call removed), native `test_index_terminal_phase_proves_in_full_after_remembered_proofs`: **killed**, with "Failed asserting that 1 is identical to 0": the index committed before the refusal.
- **The committed frame proves completely before release.**
  - `assertCommitted` and `IdentityRows::committed` are always complete.
  - The order is `committedCallbacksAll` (complete proof first), then audits, then a complete committed identity proof per distinct binding, `proveCommitted`, `committedRawAll` (complete before and after), `raw(true)`, and finally `finish()`.
  - Mutants T2 and T4 were killed by the author; T3 is discussed under question 4.
- **Pre-bytes is still a full pass under the original deadline.** `MembershipCustomerRead::proveBeforeBytes` is unchanged and re-runs `collect()` with a new owned frame and committed proof. The packet's T2/T4 and the HTTP pre-bytes test cover it.
  - Native `MembershipHttpTest::test_the_index_body_is_withheld_when_the_pre_bytes_re_read_refuses`: OK, 59.
- **Other customers' awards are never blocked.**
  - Native `test_native_same_plan_award_for_another_customer_runs_while_the_index_is_open`: OK, 21.
  - In my concurrent latency run the award never appeared blocked by the index (`award_waited_on_index=false`, `award_held_plan_while_index_open=true`).
- **Growth.**
  - Native `test_native_index_schema_proofs_per_call_do_not_grow_with_the_number_of_periods`: OK, 18 assertions.
  - **P1** (no owned frame): **killed** natively, `Com_show_create_table` 6982 vs 5354.
  - **P2** (`memberGrantProofs: false`): **killed** natively, 2702 vs 2450.

### 3. Binding dedup

- **Code.** The key is the canonical JSON of the full retained binding, including `verification_observation_id`/`_hash`.
  - The rows a verification reads are the owner's T23-locked identity rows plus immutable history.
  - `bindings()` refuses differing evidence for one key (`changed_history`). Distinct keys are each proved, both current and committed.
- **Probe** (`test_probe_periods_with_bindings_from_before_and_after_a_recovery`).
  - Setup:
    - the fixture's period, plus a copy with the original binding;
    - a real recovery (`requestIdentity('recover')` and `completeIdentity`), giving 2 verification observations and a new principal on the same origin;
    - a newer period retaining the post-recovery binding, and one more older period with the old binding.
  - The index with the new principal listed all **4 periods (2 distinct bindings)** and pre-bytes passed.
    - SQLite: OK, 20 assertions.
    - Native: OK.
- **Over-merge mutant.** I keyed the dedup by `origin_id` only, so the post-recovery period reuses the pre-recovery evidence. The probe then **failed closed**: refused with `owner_refused`, because the per-binding proof compares exact evidence. Wrong dedup can only refuse, never accept stale evidence.

### 4. T3 (committed raw phase removed)

- **What the probe did.** I wrote a native probe, `test_probe_committed_phase_callback_shadow_is_refused_before_release`.
  - It hooks the first decrypt after the index commits that runs outside `committedRaw*` and the committed frame's start. That hook fired inside `MembershipCustomerIndex::audit` (`MembershipLedgerAudit::verify` → `periodPayload`). This is the window between `committedCallbacksAll` and `committedRawAll` that T3 leaves unproved.
  - From there it tries to give `production_member_activations` a same-name temporary shadow.
- **Results.** The committed frame is a READ ONLY transaction, and every attempt was refused:
  - v1, `CREATE TEMPORARY TABLE x LIKE x`: 1066.
  - v2, `CREATE TEMPORARY TABLE … LIKE` plus `ALTER … RENAME`: 1792 "Cannot execute statement in a READ ONLY transaction".
  - v3, an explicit `CREATE TEMPORARY TABLE` built from `SHOW CREATE TABLE`: also 1792, on both the base and the T3 mutant.
- **Consequences in this window:**
  - A callback cannot create a shadowing relation.
  - Any change to a session input that alters reads (for example `sql_select_limit`) changes what the committed reads return. Those reads already compared byte for byte with the owned-frame capture inside `committedCallbacksAll`.
  - `raw(true)` and the complete committed identity proofs follow anyway.
- **Judgement.** T3 is not behaviourally reachable from within the index's committed frame. Census-only coverage is acceptable, and this is F9-R2, P3. `committedRawAll` stays as defence in depth, as before F9.
- **Recommendation.** If a test is wanted, pin the READ ONLY committed frame itself, with a temporary-table creation refused with 1792, rather than T3.

### 5. Key-order fix

- `reserve()` compares the sorted key set to `['expectedRevision', 'offerId', 'requestKey']`, with value checks unchanged.
- PHP arrays cannot hold duplicate keys, and `MembershipPrivacy` already refuses duplicate JSON keys.
- A list body yields integer keys, which never equal the string set. A missing, extra or replaced key changes the set.
- No malformed body is newly accepted.
- Tests:
  - SQLite `tests/Feature/ProductionMembershipHttp`: 40 tests, 1,608 assertions, OK.
  - Native key-order test: OK, 100 assertions.
  - The author's K1/K2 mutants are killed.

### 6. Merge correctness

- **Census** `scripts/ci/database-sqlite-skips.json`: main's 302 entries are an identical prefix, and one method is appended (303). No duplicates; the first-150 hash is `d93da4e7…`.
- **Selection.** `phpunit-shards.py --shards=8 … --mysql-native-selection` printed "Proved 2601 selected MySQL-native tests in 258 files (of 9809 …)", matching both count comments. Generated files deleted.
  - `MembershipCustomerIndexAvailabilityTest.php` is added to `include_files` in `database-mysql-selection.json`.
- **Safeguards:** `test-phpunit-shards` 52, `test-ci-scope` 27, `test-workflow-cadence` 14, `test-focused-tests` 53, `test-database-receipts` 61, all OK.
- **Composer:** `composer audit --locked`: no advisories. `composer validate --strict`: valid.
- **Hygiene:** `git diff --check` shows one trailing-whitespace line (F9-R5). A secret-pattern grep of the diff found nothing.

## Commands and results

SQLite runs used the direct PHPUnit command from the brief (in-memory SQLite, `public/build` absent).

| Run | Source | Result |
| --- | --- | --- |
| SQLite `MembershipCustomerIndexAvailabilityTest` + `MembershipCustomerIndexTest` | `8bdfcd27` | OK, 19 tests, 387 assertions |
| SQLite `tests/Feature/ProductionMembershipHttp` | `8bdfcd27` | OK, 40 tests, 1,608 assertions |
| SQLite binding probe | probe copy | OK, 1 test, 20 assertions |
| SQLite binding probe, over-merge mutant | mutant | refused (fail closed); reverted and compared |
| Native R1 | `8bdfcd27` | OK, 15 |
| Native R4 | `8bdfcd27` | OK, 16 |
| Native growth test | `8bdfcd27` | OK, 18 |
| Native owner-only locks | `8bdfcd27` | OK, 29 |
| Native same-plan award for another customer | `8bdfcd27` | OK, 21 |
| Native `MembershipHttpTest`, one process per method | `8bdfcd27` | journey 129, key order 100, one not-found 148, outcome-unknown 34: OK on 2026-10-30. Committed-write-submitted 46 and withheld-on-pre-bytes 59: OK on 2026-10-31 reruns (their first attempts failed inside the fixture's award at `MembershipAwards.php:103` under host load, which is not lane code) |
| Native G1 `MembershipSchemaProofFrameTest` | `8bdfcd27` | OK, 8 tests, 66 assertions (4,357 s under load) |
| Native P1 / P2 growth | mutants | killed (6982 vs 5354; 2702 vs 2450) |
| Native T1 terminal test | mutant | killed (1 commit vs 0) |
| Native probes P-NS, P-MDL, bindings | probe copy | as above |
| Native `MembershipCustomerIndexAvailabilityTest` | `8bdfcd27` | OK, 7 tests, 196 assertions (3,606 s), including the terminal test baseline |
| Native T3 probe v1–v3 | base and T3 mutant | every shadow refused (1066 / 1792 READ ONLY); not distinguishing (F9-R2) |
| Latency run 1, 2026-10-31 07:25 (load 5→7) | probe copy, author's harness | 1 period 11.8 / 12.5 / 13.5 s; 100 periods 53.9 s, **60.1 s refused**, 59.8 s; statements 5,088 / 32,412 |
| Latency, 100 periods with a concurrent award | same | index refused `operation_deadline` at 60.3 s; the award never waited on the index (its own receipt timed out) |
| Latency run 2, 2026-10-31 15:18 (load 5.5–9) | same | 1 period 15.9 / 16.5 / 17.5 s; 100 periods **60.0 s refused**, 59.2 s, **60.4 s refused** (`owner_refused`, deadline in pre-bytes) |
| Native G1 `MemberObserverTerminalProofTest`, run 1 | `8bdfcd27` | 4 tests: 1 OK; 2 errors and 1 failure, all inside writer fixtures or writer receipts (`MembershipReservationOperations:210`, an `IdentityCommittedFrame` deadline, `committed_receipt_refused`) under load about 8. None is in index code. Rerun: see below |

## Not tested

- **G1 `MemberObserverTerminalProofTest`**, a writer-side G1 test. F9 touches writers only through the default-false constructor flag, which the census pins.
  - Rerun: 3 of 4 methods OK, 84 assertions.
  - `test_the_activation_read_terminal_phase_proves_in_full_after_remembered_proofs` errored inside the fixture's activation, an `IdentityCommittedFrame` original-deadline check at line 84, under load 8 to 10. A single-method retry failed earlier, in the fixture's reserve (`MembershipReservationOperations:181`), under load 10. I never got a clean native result for this one method; the author's packet records 4 tests and 92 assertions OK.
- Distinct-binding scaling (F9-R3). PHP-FPM, web server or proxy behaviour. Foundation and hosted CI.
- I could not get an idle-host latency measurement. The host was shared with other lanes in every run (load 5 to 9 on 4 cores), and I could not measure the target production hardware. F9-R1 is therefore stated for a busy host. The author's 27 to 30 s figure is plausible with more spare CPU.
- My general-log confirmation of the R1 statement order failed inside its fixture under load and was discarded. R1 rests on the native R1/R4 behaviour tests and the source census.

## Cleanup

- My `mysqld` (port 3457, last pid 1277) was shut down with `mysqladmin -S /tmp/f9r-my/s shutdown`. Its datadir and socket directory were removed.
- The scratch worktrees `wt`, `m-P1`, `m-P2`, `m-T1`, `m-T3` and `m-probe` were removed with `git worktree remove --force`, then `git worktree prune`; `git worktree list` shows no `f9-review` entry.
- `/home/user/wt/f9` is clean at `8bdfcd27`.
- Other lanes' servers (PCC on 3517, CX on 3494) were left running and untouched.
- Kept for reference:
  - the probe source, `/home/user/notes/f9-review/F9ReviewerProbeTest.php`;
  - run logs and JUnit files, in `/home/user/notes/f9-review/runs/` and the old scratchpad's `f9-review/runs/`;
  - latency JSONL, in `/home/user/notes/f9-review/latency/`.
