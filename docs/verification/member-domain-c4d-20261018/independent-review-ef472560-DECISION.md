# Independent review — C4d member domain at ef472560

- **Reviewed commit:** `ef472560cf75204a1f63412ba5a8b024d9bb5451` (branch `harness/member-domain-c4d-20261018`, worktree `/home/user/wt/c4`).
- **Base:** `origin/main` `e8dfe3cd4ebc21ca14925e616eb57f31320533c9`.
- **Tested code SHA:** `47aba91f`. I checked that `47aba91f..ef472560` touches only the following: `CHANGELOG.md`, `docs/architecture/decision-register.md`, the packet README, `scripts/ci/database-sqlite-skips.json`, and one count comment each in `.github/workflows/final-verification.yml` and `.gitlab-ci.yml`. No app or test file changed. The author's native-run commits `652ec430` and `b2509f4b` have app, tests and `composer.lock` byte-identical to `47aba91f`.
- **Reviewer:** an independent Claude reviewer. I did not write this change.
- **Rules kept:** no commit, push or GitHub comment. The worktree was left clean with HEAD unchanged; `git status --short` was empty at the end.
- **Date:** 2026-10-19.

## Decision

**APPROVE WITH CONDITIONS.** C4d may merge as a default-off, unmounted development change. Its missing native evidence must be recorded as open gates, and the conditions in the native-gap section must hold. No P0 or P1 finding is open.

The witness, the period addressing and the pre-bytes proof are sound in the code. On SQLite they are covered by tests that kill the mutants that matter. Natively, two things passed:

- The award witness passed again at the exact SHA.
- The native index lock evidence the author could not obtain also passed: owner, fence and period locks; the writer-held fence; and pre-bytes after a reserve. This ran in a scratch copy in which only the fixed 60 s budgets were raised.

That copy is not the reviewed source. It does show that the native gap is a latency problem in the existing writers, not a defect in C4d. I reproduced that latency on `main`.

## Findings

| ID | Sev | File:line | Finding | Evidence | Fix |
| --- | --- | --- | --- | --- | --- |
| F1 | P2 | packet "Native MySQL 8.4.11 results"; `MembershipNativeCustomerIndexTest`, `MembershipNativeCommitWitnessTest` (reservation), `MembershipPeriodAddressedOperationsTest` (positive) | Five native cases are still unproven on the exact source: index locks, writer-held fence, pre-bytes after reserve, reservation/release witness, and the period-addressed positive path. Every one needs a reserve that finishes inside 60 s. | Author chunks c01, c04–c09 and c13 hit the deadline; I checked the JUnit files. With the deadline raised to 300 s in a scratch copy, my run of `MembershipNativeCustomerIndexTest` passed: 3 tests, 56 assertions, 1017 s. I did not get the raised-copy reservation witness (N3b): the container restart killed it. | Obtain all five natively on an exact SHA, with no deadline raised, before C4e mounts routes or any member flag or approved hash is set. See the native-gap section. |
| F2 | P2 (pre-existing; a launch blocker for memberships, not for this merge) | `MembershipReservationOperations.php:83`, `MembershipReservationContext.php:786` | The existing native reserve takes longer than its fixed 60 s budget on this host, on `main` too. About 90k statements, dominated by repeated `IdentityMigrationOwnership::inspect` schema checks. Members' reserves fail closed under load. | I reproduced it on `main` `e8dfe3cd`: `MembershipReservationLifecycleTest::test_real_sources_reserve_once…` ERROR, `operation_deadline` at `MembershipReservationContext.php:786` in the committing proof (329 s wall). The author's measurement shows identical statement counts on base and head. | Open and track a separate, reviewed performance lane: schema-ownership proof once per owned frame. It must not raise caps. |
| F3 | P2 | `MembershipCustomerIndex.php:129` (`BillingAwardFence::lock`), `:323` (`FOR UPDATE` on up to 101 periods), `:71` (pre-bytes re-run) | A read the customer can trigger takes the **global** award fence: a single row, id=1, `FOR UPDATE` on MySQL. It holds the fence for the whole read: up to 101 complete audits and history captures, plus the terminal proof. Pre-bytes takes it a second time. Every award, reserve, release, expire and activation for every customer serializes behind it. No native latency was measured for N>1 periods. The writers' per-period cost suggests a large index will exceed its own 60 s budget and block writers meanwhile, up to `innodb_lock_wait_timeout`. The lock order is consistent, so there is no integrity risk. This is availability and DoS exposure. The packet calls it "a brief owner and fence lock". | Code reading; `BillingAwardFence::lock` (`WHERE id = 1 … FOR UPDATE`). Mutant I6, which removes the fence, survives every SQLite test. Only the native NOWAIT test sees it. | Before C4e: measure the native index at 1, 10 and 100 periods, then add rate limiting. Do one of two: (a) show that the current-owner lock already serializes every writer of that owner's periods, then drop the fence; or (b) lower the bound until the performance lane lands. Correct the packet wording. |
| F4 | P3 | `MembershipCustomerIndex.php:58,71-83` | The rule that the pre-bytes deadline is never renewed is not pinned by any test. | Mutant P3 (`'deadline' => PHP_INT_MAX`) survives the whole index test file. | Add a test that expires the captured deadline (for example a stubbed clock or a captured `hrtime` seam) and expects `operation_deadline`. |
| F5 | P3 | `MembershipCommitWitness.php:39-44` | No test exercises the witness guard conditions: connection name, level 1→0, idle PDO, exception mode. | Mutant W3, which records on any commit call, survives both witness files. In practice it is equivalent, because nothing commits between `arm()` and the owned commit. Mutant W2, a premature witness at `arm()`, is killed by existing committing-boundary tests (`MembershipReservationOwnershipTest`, `MembershipFirstAwardTest`). | Optional: a unit test of the manager with a nested level and a foreign connection name. |
| F6 | P3 | `MemberOriginalSourceCapture.php:322-326,444-451`; `MemberActivationReads.php:169-174` | `commit(); $committed = true;` is still used in three places. Two are read-only, so misclassifying them has no durable effect. The activation write path (`:444`) uses `$committed \|\| ! $primary->inTransaction()`. That is conservative, but the packet admits it can report committed-unknown for a pre-begin failure. | grep over `app/Domain/Memberships` and `app/Domain/Grants/Member`. No other write runner has the old shape. The three C4d runners now use the witness. | C4e, which owns activation: move `inspectActivation` to `MembershipCommitWitness` for an exact classification. |
| F7 | P3 | `MembershipReservationOperations.php:163-176` | A `PDO::commit()` that throws, such as a lost connection during COMMIT, never reaches the witness. It stays `operation_refused`, an outcome nobody knows. This is documented as an untested condition. | Code reading of `ManagesTransactions::commit` (vendor line 203). | C4e must not present `operation_refused` from a writer as "nothing happened". Exact retries converge, because the operations are idempotent. |
| F8 | P3 | `MembershipReservationOperations.php:215-230`; context refusals | The refusal reasons reveal whether a period exists: `paid_period_absent`, `invoice_owner_mismatch`, `reservation_absent`, `changed_period`. | Tests assert the distinct reasons. | C4e maps every reason to one not-found response, as the packet's C4e plan says. |
| F9 | P3 | `MembershipCustomerIndex.php:207-209,224-227`; `MembershipReservationOperations.php:159` | Three defense-in-depth checks are not pinned by any test: the per-period owner checks, the current-phase owner proof, and the period-match check alone. | Mutants I2c, I3b and A1 survive. Their layers do hold. I2a (no account filter) is killed by `foreign_period`. I2b (no filter, no owner checks) is killed by the `historicalInvoice` binding. A3 (wrong routing with no match check) is killed: it released another period's redemption. I3 (no terminal owner proof) is killed. | Optional tests: a forged in-account period with a foreign user; current-phase withdrawal. |
| F10 | P3 | `MembershipReservationOperations.php:222-226` | The non-locking routing join is the first consistent read inside the owned frame on MySQL REPEATABLE READ, so it fixes the read view earlier than before. Later locking reads see newer rows, and any disagreement fails closed. This is not exercised. | Code reading. `source_invoice_hash` is unique in both tables (`BillingSchema.php:287`, `MembershipSchema.php:190`). | Note it in the untested conditions. |
| F11 | P3 | packet README line 204 | The packet says `MembershipCustomerIndexTest` has 13 cases. It has 12; my run shows 12 tests and 191 assertions. | `grep -c 'public function test_'` | Fix in the deferred docs batch. |
| F12 | P3 | `scripts/ci/database-sqlite-skips.json`, both workflow comments, `CHANGELOG.md` | A merge with D1 (`12441893`) conflicts. | `git merge-tree` reports conflicts in `final-verification.yml`, `.gitlab-ci.yml`, `CHANGELOG.md` and `database-sqlite-skips.json`. D1 also edits `database-mysql-selection.json`. | Whichever lane merges second must: append both census blocks (C4d's six, D1's own) without touching the first 150; regenerate `phpunit-shards.py --mysql-native-selection`; update both count comments; and re-run the four safeguards. |

## Assessment by question

**a. Witness soundness.** `Connection::commit()` (vendor `ManagesTransactions.php:203-220`) calls `PDO::commit()` only at level 1. It then sets the level to 0 and only after that calls the manager's `commit()`, which runs the callbacks inside `parent::commit()`. The witness is recorded only under all of these conditions:

- the armed connection name;
- level 1→0;
- the same raw PDO;
- a PDO that is idle and still in exception mode.

It is recorded before `parent::commit()`. So the witness cannot be set unless `PDO::commit()` returned.

- **Commit throws:** the witness is never reached, so the result is a generic refusal (F7).
- **Nested levels and savepoint release:** levelBeingCommitted is not 1, so nothing is recorded.
- **Different connection:** the name and raw-PDO checks fail.
- **SILENT error mode:** refused at `arm()` and checked again.
- **SQLite and MySQL:** both report `inTransaction()` false after a commit.

A pre-commit failure cannot be misclassified as committed. Mutant W2 shows that the committing-boundary tests would catch this. A committed effect can still be reported as not committed only for an ambiguous COMMIT error.

The witness branch skips `observer->abort()`. That is safe, because there is nothing left to roll back. The swap is restored in every outcome: the `finally` in all three runners is unchanged and restores both the original dispatcher and the original manager. Each invocation gets its own new manager. Because the witness is a subclass, a nested owned writer started from an afterCommit callback is now refused (`changed_transaction`); before, it could run. That is stricter.

It matches existing patterns: `MembershipReservationObserver` and `MembershipAwardObserver` check manager identity, not class.

**b. Index.**

- **Owner isolation.** The window is keyed by `account_id`. Each row's account, user and origin must match. The historical invoice's original buyer must equal the current durable owner. A foreign owner gets an empty list. A withdrawn owner gets `owner_refused`, and withdrawal during commit is caught by the terminal proof. Mutants I2a, I2b and I3 are killed.
- **Sentinel.** The query uses `LIMIT 101`. The sentinel row is audited like any other, and `hasMore = count > 100`. Mutant I1 is killed, as was the author's M2.
- **Lock order.** The writers lock in this order: owner (`MembershipAwardContext:73` and `MembershipReservationContext:130`), then invoices to `MembershipBillingEvidence::lock`, which takes the fence first, then policies, plan and period. The index locks owner, then fence, then periods, then plans and definitions. Every holder of the fence is therefore serialized, so the index cannot form a cycle with any writer.
- **Readers that take no fence.** `MemberActivationHistoryInspection` takes the owner lock first, so the same owner is serialized. Staff authoring and other owners' activation reads share only immutable plan and definition rows. A deadlock there would refuse one side, and either side is a read or an uncommitted write. That is availability only, and it is not exercised.
- **Ledger audit.** An audit failure refuses the whole index (test `invalid_credit_balance`).
- **Output.** It carries period, plan-version, redemption, offer and definition ids, integer balances, dates and status. The test asserts that none of these appear: the billing invoice id, the provider reference, hashes or seals. No buyer data is present.
- **Availability.** See F3.

**c. Period addressing.** The join is `periods.source_invoice_hash = invoices.source_invoice_hash`, and the hash is unique on both sides. The query uses `LIMIT 2` and requires exactly one row. The context then locks and authenticates the invoice, owner, policy and period, and the projection must name the requested period.

- **Another customer's period:** refused with `invoice_owner_mismatch`. This passed natively (author c14) and on SQLite.
- **Unknown period:** refused with `paid_period_absent`.
- **Redemption of another period:** refused with `reservation_absent`.
- **Changing rows:** the rows are immutable. If the join result differed from what the locks see later, the projection check and the context's own `source_invoice_hash` lookup would refuse.

Mutant A3 shows that the match check prevents acting on a different period when routing is wrong. The non-locking read is safe inside the owned frame; F10 has the read-view note.

**d. Pre-bytes.** The proof is one-use: the state becomes `used` before any work runs, and mutant P1 is killed. It cannot be adopted (P4 killed). It uses the original deadline (F4: not pinned by a test). It re-runs the whole owned read, including the owner lock, fence, audits and committed proof. It requires a byte-identical projection and identical gate baselines (P2 killed). Serialization and cloning are refused.

Deferring the pre-bytes proof for `review` and `readActivated` to C4e is acceptable. Neither read is mounted or bound outside test fixtures today, and the packet says explicitly that the hook is needed. C4e must not mount those reads without it.

**e. Tests.** See the commands below. Every mutant that targets the main property is killed. The survivors are equivalent mutants or defense-in-depth layers (F4, F5, F9). The fence mutant I6 can be seen only natively.

**g. Census.**

- **Append-only:** the census went from 281 to 287 entries, with an identical prefix and no duplicates. The pinned first 150 entries, hashed in `test-focused-tests.py:139`, are unchanged.
- **Selection:** regenerated, it gives 2456 of 9418 cases in 235 of 688 files. That matches both workflow comments.
- **Safeguards:** all four are green.
- **D1:** see F12.

**h. Documentation.**

- **Decision register:** the entry states exactly the owner decision. `explicit_owner_confirmation_v1` is a retained technical confirmation, neither legal assent nor write authority. Wording, terms, retention and all hashes are unapproved, and every flag stays false.
- **CHANGELOG:** accurate, apart from the F3 wording ("same order as the writers" is true; the cost is not mentioned).
- **FPM finding:** reproduced.
  - `MemberOriginalRendererProcess.php:91` spawns `PHP_BINARY`, with `extension_dir` (`:80`) and the loader path (`:71`) taken from the parent process.
  - Its SHA-256 `f0470e91…` equals the pinned value in `resources/contracts/production-member-v1/profile-assets.json`.
  - `AppServiceProvider.php:49-54` binds the CLI factory only for the Free, Paid and PaidTax renderers.
  - `/usr/sbin/php-fpm8.4 -n -d memory_limit=128M scripts/render-member-original.php` exits 64.
  - The r2 render-profile recommendation is correct.
- **Secrets:** a grep of the diff and of the raw evidence `scratchpad/c4-ev/` found none.

**i. Scope.** The change touches only the member domain: three runners, three new classes, tests, docs, census and comments. It does not touch routes, `bootstrap/app.php`, `AppServiceProvider`, `composer.lock`, the Filament tests or configuration. Nothing is mounted or enabled.

## Native-gap recommendation (key decision)

**Severity: P2.** It does not block merging this default-off, unmounted domain lane. It **does** block C4e mounting member routes, and it blocks any member flag or approved hash being set.

Reasons:

1. **The gap is not C4d's, and it exists on main too.** I reproduced the overrun of the existing reserve on `main` (N2). The author's general logs show identical statement counts for base and head.
2. **The C4d logic that depends on the engine has native evidence in substance.**
   - The commit witness is one class, shared by all three runners. It passed natively for award and authoring at the final source, including my re-run at `ef472560` (N1). The reservation runner uses the same arm/commit/witness code.
   - With only the 60 s budgets lifted in a scratch copy, the three native index tests passed (N3a). Those tests cover lock holding with NOWAIT probes, waiting at a writer-held fence, and pre-bytes seeing a reserve.
   - The routing join itself ran natively (author c13), and the foreign-period refusal passed natively (c14).
3. **Every failure mode found fails closed.** The worst failures are refusals or a refused receipt, never a durable unrecorded effect or a data leak. AGENTS.md's CI cost policy allows a focused, reviewed development candidate to merge with untested conditions recorded honestly. It forbids describing focused checks as acceptance. It does not forbid merging.
4. **"SQLite does not prove MySQL concurrency" still applies.** C4d must not claim native index or reservation-witness evidence until that evidence exists on an exact SHA with unmodified deadlines. My raised-deadline copy is characterization only, not acceptance.

**Conditions for the merge:**

- **C1.** Merge as default-off and unmounted. The packet's untested conditions already list the five missing native cases as open gates; keep them. Add the global-fence availability note (F3) and fix the test count (F11). Both may go in the deferred follow-up batch under the freeze-and-batch rule.
- **C2.** Before C4e mounts any member route, and before any member flag or approved hash is set, obtain native evidence on an exact SHA with no deadline raised for all of the following:
  - the index locks;
  - the writer-held fence;
  - pre-bytes after a reserve;
  - the reservation and release witness;
  - the period-addressed positive path;
  - one native index latency measurement at about 100 periods (F3).
- **C3.** Open the performance lane for once-per-frame schema-ownership proof (F2) as a tracked release blocker. Native C2 evidence is expected after that lane, or on a faster host. Do not raise any cap.
- **C4.** The second of C4d and D1 to merge must re-do the census, selection and comments, then re-run the safeguards (F12).

## Commands and results

All runs used `nice`, at most two of my PHP processes at a time, and `public/build` was absent. The SQLite command is the one from the brief, wrapped in `scratchpad/c4-review/sq.sh` with `--log-junit`.

| Run | Source | Command (abridged) | Result |
| --- | --- | --- | --- |
| new-a | ef472560 | SQLite: `MembershipCommitWitnessTest`, `MemberGrantDefinitionsCommitWitnessTest`, `MembershipPeriodAddressedOperationsTest`, `MembershipNativeCommitWitnessTest`, `MembershipNativeCustomerIndexTest` | 16 tests, 172 assertions, 6 skipped (the six native cases), exit 0, 155 s |
| new-b | ef472560 | SQLite: `MembershipCustomerIndexTest` | 12 tests, 191 assertions, exit 0, 248 s |
| s-A | ef472560 | SQLite: `tests/Feature/ProductionMembershipBilling` | **398 tests, 3,451 assertions, 0 F/E, 35 skipped**, exit 0, 1,839 s. Matches the packet. |
| s-B | ef472560 | SQLite: `tests/Feature/ProductionMembership`, `ProductionMemberActivation`, `ProductionMemberGrants`, `ProductionMemberOriginals`, `tests/Unit/ProductionMemberGrants`, `tests/Unit/ProductionMemberOriginals` and the 20 `tests/Unit/{Billing,Member,Membership}*Test.php` files | **794 tests, 2,924 assertions, 0 F/E, 19 skipped**, exit 0, 1,142 s. Matches the packet. |
| safeguards | ef472560 | `python3 scripts/ci/test-{phpunit-shards,ci-scope,workflow-cadence,focused-tests}.py` | 52 OK, 27 OK, 14 OK, 53 OK; each exit 0 |
| selection | ef472560 content, in a scratch copy | `phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection` | exit 0; 2,456 selected of 9,418 in 235 of 688 files. Generated files deleted with the copy. |
| census | ef472560 vs e8dfe3cd | Python comparison of `methods` | 281 → 287 entries; prefix identical; no duplicates |
| merge-tree | ef472560 × D1 `12441893` | `git merge-tree --write-tree --name-only` (read-only) | 4 conflicting files (F12) |
| N1 | **ef472560**, native | private MySQL 8.4.11 (`/opt/mysql-8.4.11/bin/mysqld`, port 3361, own datadir, REPEATABLE-READ); `MembershipNativeCommitWitnessTest::test_native_throwing_after_commit_award_is_committed_on_innodb_and_replays` | **OK** (1 test, 22 assertions), 249 s |
| N2 | **main e8dfe3cd** (`git archive` + same vendor), native | `MembershipReservationLifecycleTest::test_real_sources_reserve_once_and_exact_retry_and_award_replay_preserve_the_retained_identity` | **ERROR**, `operation_deadline` at `MembershipReservationContext.php:786` (committing proof), 329 s. Reproduces the author's characterization on main. |
| N3a | scratch copy of ef472560 with `60_000_000_000` → `300_000_000_000` in 14 member files (characterization only, **not the reviewed source**) | `MembershipNativeCustomerIndexTest` (3 cases) | **OK** (3 tests, 56 assertions), 1,017 s |
| N3b, N3c | same scratch copy | reservation witness; period-addressed positive | Not obtained. The container restart at about 01:15 UTC killed N3b mid-run. Per the lead, they were not re-run. |
| FPM | — | `/usr/sbin/php-fpm8.4 -n -d memory_limit=128M scripts/render-member-original.php` | exit 64 |
| secrets | — | grep of the diff and `scratchpad/c4-ev/` for key, token and private-key patterns | none |

## Mutations

Each mutation was applied in a hard-link copy of the worktree by atomic file replacement, so the reviewed worktree was never written. Each was restored and compared byte-for-byte with the original; the final check `diff -rq app tests` reported the copy identical. Driver: `scratchpad/c4-review/mutate.py`. Specs: `mutations.json`.

| ID | Mutation | Tests run | Result |
| --- | --- | --- | --- |
| W2 | Witness recorded at `arm()`, before the physical commit | committing-boundary tests in `MembershipReservationOwnershipTest` and `MembershipFirstAwardTest`, plus the pre-commit control | **Killed**: 2 failures, `changed_policy` became `committed_receipt_refused` |
| W3 | Witness guard reduced to "armed and not yet committed" (any level, connection or PDO state) | both witness files | Survived. Equivalent in practice (F5). |
| I1 | `hasMore` uses `>=` | bound test | **Killed** |
| I2a | Window drops the `account_id` filter | foreign-owner test | **Killed** (`foreign_period`) |
| I2b | I2a plus both per-period owner checks removed | foreign-owner test | **Killed** (`historicalInvoice` binding refusal) |
| I2c | Per-period owner checks removed only | whole index file | Survived. Defense in depth (F9). |
| I3 | Terminal `proveCommitted` owner proof removed | withdrawal tests | **Killed** |
| I3b | Current-phase owner proof removed | whole index file | Survived. The terminal proof covers it (F9). |
| I6 | Award fence removed from the index | whole index file | Survived on SQLite. Only the native NOWAIT test sees it (F1, F3). |
| P1 | Pre-bytes no longer marks the read used | pre-bytes test | **Killed** |
| P2 | Pre-bytes skips the projection comparison | ledger-change test | **Killed** |
| P3 | Pre-bytes deadline effectively unbounded | whole index file | Survived (F4) |
| P4 | Read adoption allowed | pre-bytes test | **Killed** |
| A1 | Projection period-match check removed | whole period file | Survived. Unreachable with correct routing (F9). |
| A2 | Routing join resolves `p.id <> ?` | whole period file | **Killed**: 3 tests |
| A3 | A2 plus A1 | redemption-of-another-period test | **Killed**: release of another period's redemption succeeded |

The author's M1 (witness removed), M2 (sentinel dropped), M3a/M3b (owner lock and check) and M4 (pre-bytes skipped) are recorded in the packet. My W2, I1, I3, P1 and P2 overlap them independently, with the same outcome.

## Untested conditions

- **Native, exact SHA, deadline unmodified:**
  - index lock holding;
  - writer-held fence;
  - pre-bytes after a reserve;
  - reservation and release witness;
  - period-addressed positive path.

  The index tests passed only in a raised-deadline copy. The raised-deadline reservation witness was interrupted. (F1)
- Native index latency for N>1 periods, and how long it holds the global fence against concurrent writers (F3).
- No independent-process race between the index and the writers. The evidence is lock holding and structural lock order only.
- Deadlocks between the index and fence-free readers or staff authoring on shared immutable plan and definition rows.
- A failed or ambiguous `PDO::commit()` (lost connection) in the three writers (F7).
- Read-view timing caused by the non-locking routing read on MySQL (F10).
- No HTTP, browser, FPM pool, host, provider or production evidence, and no Foundation run. The FPM finding is static analysis plus a child-launch reproduction only.
- My SQLite runs and the author's both used one host. Load from another lane's processes was present throughout.

## Cleanup

- My mysqld on port 3361 died in the container restart and no mysqld is running.
- Removed: its datadir `scratchpad/c4-review-mysql`, the socket `scratchpad/c4r.sock`, and the mutation, base and raised-deadline copies under `scratchpad/c4-review/`.
- Lane D1's server on port 3341 was never touched.
- Logs and JUnit files are kept in `scratchpad/c4-review/runs/`. Progress is in `scratchpad/c4-review/PROGRESS.md`.
