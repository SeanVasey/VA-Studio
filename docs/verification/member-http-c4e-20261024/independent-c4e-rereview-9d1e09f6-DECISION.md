# Independent re-review: C4e phase A after the N1/N2 redesign, at 9d1e09f6

- **Reviewed:** tested source `9d1e09f6` on `harness/member-http-c4e-20261024` (worktree `/home/user/wt/c4e`).
  - HEAD is `c163d951`. I checked that `9d1e09f6..c163d951` changes only `CHANGELOG.md`, `docs/handoff/2026-10-17/RESUMPTION.md`, the runtime doc and the packet README.
  - Commits since my first review (`be33167f`): `bde867f6`, `a9147db8`, `9d1e09f6`.
- **Earlier record:** `scratchpad/c4e-review-b3deef84.md`.
- **Reviewer:** independent Claude reviewer; I am not the author.
- **Rules kept:**
  - No commit, push, PR, GitHub comment or dispatch.
  - Probes and mutations ran only in scratch copies. Before deleting the copies I confirmed their `app/` matched the worktree.
  - The worktree `git status --short` was empty at the end, and HEAD was unchanged.
- **Date:** 2026-10-24.

## Decision

**APPROVE**, as a default-off, unmerged phase A candidate. No P0, P1 or P2 is open. The four new findings (R1–R4) are P3, for the deferred batch under the freeze-and-batch rule. Native evidence on an exact post-G1 SHA is still the phase B gate (C4d C2). It is not a finding here.

**N1 and N2 are resolved.** I checked this three ways:
- **Statement log.** A native MySQL general log of a real index transaction shows `FOR UPDATE` only on the owner's T23 tables (`users`, `customer_accounts`, `production_identity_origins`, `production_identity_verifications`, `production_identity_challenges`). There is no lock on the fence, plan, definition, profile, period, history or redemption rows. There is no `FOR SHARE` or `GET_LOCK`, and no locking read after commit.
- **My native mutants.** Making the definition read locking, or the current-phase plan read in `raw()` locking, is killed by the author's NOWAIT test (definition 3572; plan 3572). The first one closes the native gap the author listed for SM3.
- **Writers.** They keep the locking default at every call site.

**Correctness holds, and I verified the stale-snapshot case natively.**
- The REPEATABLE READ read view is **always** created **before** the owner lock. The packet calls this possible but untested (R1).
- When a same-owner writer commits during the owner-lock wait, the index commits on a stale window and the terminal proof refuses it with `changed_period_window`. Staleness can only refuse.

**Other items:**
- **N3 and N6:** fixed.
- **Census and selection:** correct.
- **Immutability:** plan versions, profiles and definitions are immutable at the schema level, on both engines.

## Findings

| ID | Sev | Where | Failure path | Evidence | Required |
| --- | --- | --- | --- | --- | --- |
| R1 | P3 | `MembershipCustomerIndex::collectOwned` → `new MembershipRows` → `assertCurrent` → `MembershipSchema::assertOwned` → `IdentityMigrationOwnership::inspect:224`, which runs `SELECT id FROM <identity table> LIMIT 1`. This happens inside the index transaction and before `ProductionCustomerAccess::lock`. | These consistent reads of InnoDB tables create the transaction's read view before the owner lock, every time and in the pre-bytes re-run too. So any index that waits at the owner lock behind a same-owner writer that changes something it reads will audit the older snapshot, commit, and then be refused by the terminal proof: the customer gets the one not-found response. It fails closed, but it happens every time in that situation, not occasionally. Same-owner writers include reserve, release, expire, activation, and an award for that owner. The window is wider before G1, while writers run long. The packet's "if the read view was created before … schema checks may touch table metadata" understates this. The reads are row reads of identity tables, and they always precede the lock. | **T0 (native general log):** the first consistent read on a user table is at statement 304 of the index transaction (`SELECT id FROM production_identity_addresses LIMIT 1`, then the other identity tables and `SELECT * FROM production_membership_* LIMIT 0`). The first `FOR UPDATE` (`users`) is at statement 1203. **T1b (native race):** a separate process held the owner's `users` row; `INNODB_TRX` showed the index in `LOCK WAIT` on that row. The process then inserted a later, trigger-valid period for the owner and committed. Result: the index transaction committed once, then refused with `changed_period_window`, the terminal committed-frame check. | Correct the packet's untested-condition text and the class docblock: the read view always precedes the owner lock, and why. In phase B, measure the refusal rate with a real same-owner writer. Optionally, retry once within the original deadline when the owner lock had to wait, or begin the frame's first consistent read after the owner lock. |
| R2 | P3 | Packet ("After the owner lock it requests no further lock"); `ProductionCustomerAccess::verifyHistoricalBinding` and `proveHistoricalBindingCurrent` (`ProductionCustomerAccess.php:150-177`, through `IdentityRows::rows` with `FOR UPDATE`) | After the owner lock, the index issues more `FOR UPDATE` reads. On a mostly re-entrant basis it re-locks the same owner rows (the current and historical proofs), and per period `verifyHistoricalBinding` locks the challenge row of every verification entry up to the binding's observation. After a recovery, that includes older challenge rows that are not in the owner-lock set. All of these rows belong to the owner, so no cross-customer cycle is possible. Every membership writer has the same exposure, so it is not new. The claim is still literally false, and same-owner identity operations (recovery) are where a cycle would have to form. | T0: 12 `FOR UPDATE` statements on each of the five owner tables in one index transaction (prepare and execute, six passes); the fixture has no recovery, so the extra historical challenges were not exercised. Code: `ProductionCustomerAccess.php:177`. | Rephrase it as: "further locks only on the same owner's identity rows (re-proofs, historical challenges)". Deferred batch. |
| R3 | P3 | `MemberActivationHistoryReader::capturePeriod(..., bool $consistentRead = false)`; `MemberGrantRows::definitionByHash(..., bool $committed = false)` | Nothing pins that writers keep the locking default. Mutant W1 changes the default to `true`, which makes every writer's history capture unlocked (`MembershipAwardContext:104`, `MembershipReservationContext:474,678`, `MemberActivationHistoryInspection:82`). It survives the availability source census, the only SQLite guard of the opt-in. | `sq-W1`: `MembershipCustomerIndexAvailabilityTest::test_index_locks_only…` OK (1 test, 40 assertions). Today's code is correct: a grep shows every writer call site uses the default. | Add census assertions for the two defaults and for the writer call sites (no flag argument), or a native writer NOWAIT check. Deferred batch. |
| R4 | P3 | Terminal history proof under a stale snapshot | Only the window difference was shown natively. A history difference (for example a redemption or event committed during the owner-lock wait) is covered by `committedCallbacks`/`committedRaw`, which re-read the complete captured scope with exact equality. That comes from code reading only. | T2 could not run before G1: the reserve fixture overran its 60 s budget (`MembershipReservationContext.php:786`). | Phase B: add the same-owner wait race with a reserve to the native plan, next to the existing pre-bytes-after-reserve case. |

Carried over unchanged, and still recorded in the packet:
- N4: F6's fallback for a `PDO::commit()` that itself throws is unpinned.
- N5: a non-membership writer failure is a 503.
- N7: rate limits are per IP; a per-customer limiter is needed before any non-local mount.

## Assessment by question

1. **When the read view is created.** See R1.
   - MySQL creates the RR read view at the first consistent read, not at `START TRANSACTION`, and the index issues such reads before the owner lock. The general log shows these, all inside the index transaction:
     - ownership-proof row reads `SELECT id FROM production_identity_{addresses,challenges,origins,verifications,notices,attempts,outcomes} LIMIT 1`;
     - `SELECT * FROM production_membership_* LIMIT 0`, repeated for each `MembershipRows`/`assertCurrent` before `ProductionCustomerAccess::lock`.
   - So the stale-snapshot case exists, and T1b shows it is handled.
   - **The terminal proof covers every unlocked read:**
     - window: `raw()` with `committed=true`;
     - complete per-period history, including activation support rows: `committedCallbacks`/`committedRaw`, exact equality;
     - the per-period plan and the definition, profile and plan: `raw()`;
     - identity: locking reads, then `proveCommitted`.
   - The rows are append-only (UPDATE and DELETE triggers are `0 = 1`), so a difference is always an addition and cannot be hidden by a later write. `IdentityCommittedFrame::begin` opens a new transaction after the index commits, so its snapshot follows that commit; T1b confirms this.
   - The only blind spot is a period inserted older than the 101st, which sits outside the window and is never shown. That is harmless.
   - Under READ COMMITTED (not configured; the server default is RR) the post-lock reads would be fresh, and the owner lock alone keeps them stable.
2. **Writers and immutability.**
   - **Writers of the tables now read unlocked:**
     - periods: award (`MembershipAwardContext:98`);
     - events and redemptions: award and reservation context (`:610-663`);
     - member origins, artifacts, activations and consume events: `MemberActivationInspection:226-231`, inside the reservation context's `inspect`.

     Every one takes `ProductionCustomerAccess::lock($principal)` first and holds it to commit, with the principal bound to the invoice buyer (`sameOwner`, `match()`). No other code writes these tables: no job, sweep, import or migration. Billing tables are not read by the index.
   - **Plan versions, profiles and definitions:**
     - Only staff inserts write them (`MembershipPolicyVersions:34`, `MemberGrantRows:71-86`).
     - UPDATE and DELETE triggers are `0 = 1` on MySQL (`SIGNAL`) and SQLite (`RAISE(ABORT)`): `MembershipSchema.php:273`, `MemberGrantSchema.php:288`. The guarded schema reads re-prove them.
     - Their columns hold no status, retirement or current-pointer field (plan: id, policy_hash, original_terms_hash, provenance, payload_ciphertext, seal, created_at; profiles and definitions per `MemberGrantRows::insert`). Hashes are unique.
     - Currentness lives in the approved configuration hashes, which are captured and re-proved.
     - The author's on-schema test refuses UPDATE and DELETE on all three tables; it ran green on SQLite.
3. **N1 and N2 are gone.**
   - T0 shows no locking statement on any shared row or membership row. The only further locks are on the owner's own identity rows (R2).
   - The opt-in flags are used only in `MembershipCustomerIndex` (one `capturePeriod(..., consistentRead: true)`; the `MemberGrantRows` reads with `true`). Every writer call site keeps the locking default (grep), but nothing pins that (R3).
   - The history reader keeps the flag inside its own issued state, so the current and raw phases of the same snapshot stay unlocked, while writers' snapshots (a separate reader instance) stay locked.
4. **Tests.**
   - **N3:** fixed. Unmounted refusals assert there is no session cookie; mounted requests assert there is one.
   - **N6:** fixed. `indexBody` enforces an exact key allowlist at every level and refuses anything else as `tampered` (the one not-found). The mount test covers five tamper shapes.
   - **Native coverage:**
     - My n1 baseline in the no-reserve scratch fixture passed (1 test, 27 assertions).
     - MD (definition lock) and MR (current-phase plan lock in `raw()`) were both killed.
     - The author's n1–n5c and NM1–NM4b are recorded in the packet; I did not re-run them.
5. **Census and selection.**
   - 294 entries.
   - The first 150 hash `d93da4e7` is unchanged (`test-focused-tests` OK).
   - Against base `a08e79ca` (291 entries): positions 284 and 285 are renamed in place, and three entries are appended. Against my previous review: the tail entry was renamed and two were appended. There are no duplicates.
   - Keeping old names is still impossible, because undiscovered methods are refused.
   - Selection regenerated: "Proved 2490 selected MySQL-native tests in 237 files (of 9573 …)", which equals both workflow comments. Generated files deleted.
   - The four safeguards pass: 52, 27, 14, 53.
6. **What the redesign introduces.**
   - The same-owner freshness refusal (R1).
   - The doc precision point (R2) and the default pin (R3).
   - Next-key and gap locks from the owner lock's range reads can briefly delay another customer's identity insert. They cannot form a cycle; this predates C4e and the packet acknowledges it.
   - Lock order against identity operations is unchanged from C4d and every writer.
   - No new cross-customer wait or cycle was found.

## Commands and results

- **SQLite runs:** the brief's direct PHPUnit command on in-memory SQLite, from the worktree at `c163d951`. App, routes, bootstrap, config and tests are identical to `9d1e09f6`.
- **Native runs:** my private `/opt/mysql-8.4.11/bin/mysqld` on port 3427 with its own datadir, `VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_c4err`, REPEATABLE READ, **pre-G1, no deadline raised**, run from scratch copies.

| Run | Selection | Result |
| --- | --- | --- |
| sq-mount | `MembershipHttpMountTest` | OK, 34 tests, 1092 assertions |
| sq-avail | `MembershipCustomerIndexAvailabilityTest` | OK, 5 tests, 109 assertions |
| sq-http | `MembershipHttpTest` | OK, 5 tests, 416 assertions |
| census | Python comparison against `a08e79ca` | 291 → 294; first 150 hash `d93da4e7`; renamed at 284, 285; 3 appended; 0 duplicates |
| selection | `phpunit-shards.py --shards=8 … --mysql-native-selection` | 2490 of 9573 in 237 files; generated files deleted |
| safeguards | four `scripts/ci/test-*.py` | 52, 27, 14, 53 OK |
| T0 (native) | Scratch `ReviewerRereviewSnapshotProbeTest::test_t0` with the general log on around one index call | Index transaction of 11,405 logged statements. `FOR UPDATE` only on the five owner tables (12 each). First user-table consistent read at statement 304, first `FOR UPDATE` at 1203. Nothing locked after commit. Analysis in `evidence/t0-analysis.txt`. |
| T1 (native), run 1 | Same-owner stand-in writer process | Invalid: the writer's own detection of the index's lock wait failed, so it held the lock until the index timed out (1205) |
| T1b (native) | Same, with the writer sleeping a fixed 12 s | Writer saw the index in `LOCK WAIT` on `users`, inserted a period and committed. The index committed once, then refused `changed_period_window`. |
| T2 (native) | History variant (needs the reserve fixture) | Not obtained: the fixture's reserve hit `operation_deadline` (pre-G1 latency) |
| n1-base2 (native) | `MembershipNativeCustomerIndexTest::test_native_index_locks_only_the_owner_rows…`, scratch no-reserve `indexFixture` and award receipt accepted (as the author's probe did) | OK, 1 test, 27 assertions, 256 s (a first attempt failed in the fixture on the pre-G1 award receipt) |

## Mutations (mine; scratch copies; each reverted and compared)

| ID | Mutation | Run | Result |
| --- | --- | --- | --- |
| MD | `definition()` reads `definitionByHash` with a lock | native n1 | **Killed** (`definition` 3572); SQLite census also kills it |
| MR | `raw()` per-period plan read locking in the current phase (`$committed`) | native n1 | **Killed** (`plan` 3572) |
| W1 | History reader default `consistentRead = true` (opt-in leaks to writers) | SQLite availability census | **Survived** (R3) |

The author's SM1–SM7 and NM1–NM4b are recorded in the packet; I did not re-run them.

## Incident during this review (disclosed)

- My first `mysqld` start chose port 3417, which turned out to be the G1 lane's running server. My bind failed, and my start script's `CREATE DATABASE IF NOT EXISTS vaseyaudio_c4err` then ran against **G1's server**.
- I noticed at once, confirmed the database was empty (0 tables, created seconds earlier) and dropped it. Nothing else was executed against that server.
- All later work used port 3427, after confirming it was free and checking the datadir; the start script now refuses a busy port.
- The G1 owner may want to know that this empty database briefly existed (15:20 UTC).

## Untested conditions

- No native evidence on an exact post-G1 SHA (phase B). All native results above are pre-G1 characterization on scratch probes or scratch fixtures, not acceptance.
- The stale-snapshot history case (R4), the activation-specific history support reads, and periods with a post-recovery historical binding (extra challenge locks, R2) were not exercised natively.
- The R1 refusal rate under real concurrent same-owner writers was not measured.
- Victim and wait behaviour against same-owner identity operations (sign-in, recovery) was not exercised.
- PHP-FPM, web server, proxy and browser behaviour, and per-IP limits under load: not exercised.
- main `5b0a6606` and the G1 head were not reviewed; the author reports `git merge-tree` conflicts only in comments and docs.

## Evidence and cleanup

All paths below are under `/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad/c4e-rr/`:
- `evidence/` holds the probe test, writer stand-in, mutation driver, scratch fixture variants, gzipped general log and T0 analysis.
- `runs/` holds the logs.
- `PROGRESS.md` is the progress log.

The server was shut down and its datadir and copies were removed. The worktree is clean at `c163d951`.
