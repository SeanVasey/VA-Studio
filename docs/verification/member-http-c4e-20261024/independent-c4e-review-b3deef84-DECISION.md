# Independent review: C4e phase A (default-off member HTTP) at b3deef84

- **Reviewed commit:** `b3deef84148fa742b19799bfcff815a44a1084e7` (tested source), on branch `harness/member-http-c4e-20261024` in worktree `/home/user/wt/c4e`.
- **HEAD:** `be33167f4f47f17c4c0888a3b5a06f53a8097fca`. I checked that `b3deef84..be33167f` changes only the packet README and one count comment in `.github/workflows/final-verification.yml`. I also checked that `7892227f..b3deef84` leaves `app/`, `routes/`, `bootstrap/` and `config/` unchanged.
- **Base:** `a08e79ca` (main at branch time). Main has since moved to `5b0a6606`; that change is unrelated and I did not review it.
- **Reviewer:** an independent Claude reviewer. I did not write this change.
- **Rules kept:**
  - No commit, push, PR, GitHub comment or workflow dispatch.
  - Mutations and probes ran only in scratch copies, which I compared byte for byte with the worktree and then deleted.
  - The worktree `git status --short` was empty at the end, and HEAD was unchanged.
- **Date:** 2026-10-24.

## Decision

**APPROVE WITH CONDITIONS**, as a default-off, unmerged phase A candidate. No P0 or P1 finding is open.

**What holds:**

- **HTTP layer.** It is sound:
  - Mount gate.
  - One not-found for every refusal (F8).
  - Writer outcome mapping (F7).
  - Capsule 403 before any domain call.
  - Pre-bytes proof before any body (C2).
  - Receipt allowlist.
  - Owner binding of the period-addressed writers.
- **Tests.** The tests kill the mutants that matter.
- **F4 and F6.** Both are correct.
- **Census.** The rename-in-place is acceptable (the rules force it), and the counts match a regenerated selection.

**What does not hold:** the F3 resolution, as argued.

- Dropping `BillingAwardFence` is safe for consistency. No torn or stale state can be observed: every read is a locking read, and the current and committed proofs fail closed.
- It does **not** remove cross-customer serialization. The index still takes `FOR UPDATE` on rows that every customer shares, and every same-plan writer locks those rows too (N1).
- Without the fence, those shared rows are now locked in inconsistent orders. I reproduced an InnoDB deadlock natively between a member's index and a definition-first locker, which is what a zero-period customer's index does (N2).
- The packet, CHANGELOG, class docblock and runtime doc state the opposite, and the phase B plan as written would not detect either problem.

Native MySQL evidence on an exact SHA remains the known phase B gate (C4d C2). It is not a phase A finding.

## Findings

| ID | Sev | Where | Failure path | Evidence | Required |
| --- | --- | --- | --- | --- | --- |
| N1 | P2 | `MembershipCustomerIndex.php:218` (`$rows->one(MembershipRecords::TABLE …)` per period); `:251-257` (`definition()`: `definitionByHash`, `profile`, `plan`); `:310-315` (`raw()`, with `committed=false`); `MembershipRows::rows` → `CurrentRows::rows:47-48` and `MemberGrantRows::read:100-102` both add `FOR UPDATE` on MySQL. Writers: `MembershipAwardContext.php:82` and `MembershipReservationContext.php:145` lock the same plan row `FOR UPDATE`, after the fence. | The index still exclusively locks the **shared** `production_membership_plan_versions` row of every listed period. Through `definition()` it also locks the approved member definition, profile and plan, and it holds all of these until commit (twice, with pre-bytes). Every award, reserve, release, expire and activation of every customer on that plan takes the same plan row `FOR UPDATE`. Index A therefore still blocks, and is blocked by, every same-plan writer of every customer, and same-plan indexes serialize with each other. With one current plan, that is effectively global. Even a verified customer with **zero** periods takes the current plan, definition and profile rows exclusively through `definition()`. Any signed-in non-member can therefore contend the row every member writer needs, limited only per IP. The F3 availability goal is not met. The claims "It takes no global lock", "a customer read no longer serializes every customer's writers" (CHANGELOG) and "The fence serialized the index against every customer's writers" (packet) are inaccurate. Phase B step 5 ("show neither waits for the other") would fail for a same-plan writer. `test_native_index_completes_while_another_customers_writer_holds_the_global_fence` holds only the fence, not the plan row a real writer holds right after it, so it cannot see this. | Native probe (pre-G1, deadlines unmodified, 307 s, private 8.4.11). NOWAIT from an independent session inside the index transaction gave: buyer index with 1 period `{fence 0, plan 3572, definition 3572, profile 3572, buyer_user 3572}`; another customer's index with 0 periods `{fence 0, plan 3572, definition 3572, profile 3572}`. With an independent session holding the plan row `FOR UPDATE` (as a writer does after its fence), the zero-period index failed with `index_refused` and driver code **1205** at a 1 s wait bound. | **Before merge:** correct the F3 analysis and claims in the packet, `CHANGELOG.md`, the `MembershipCustomerIndex` docblock and lock comment, `docs/ops/production-membership-http-runtime.md` and the native test docblock. **Before phase B is counted, and before any mount beyond local/testing:** choose a design. Options include: reading the trigger-guarded, append-only plan, definition and profile rows without locks and keeping the byte comparisons; keeping the fence until G1 latency is measured; or option (b). Then add NOWAIT probes for the plan, definition and profile rows, plus a real same-plan writer from another customer running concurrently, to `MembershipNativeCustomerIndexTest` and to the phase B latency plan. |
| N2 | P2 | Same files. Lock orders: the member index takes plan `Pd` in its period phase, then definition `D` and profile in `definition()`. A zero-period index, or one whose periods are on other plans, takes `D` → profile → `Pd`. Activation (`MemberActivationInspection.php:89-93`) takes `Pd` in the reservation context, then `D` in `facts->capture`. An index over periods on two plan versions takes the plan rows in period-recency order. | The fence used to order every index and every activation before any shared row. Without it these orders form cycles. Example: member A's index holds `Pd` and is auditing; customer C's index takes `D` and waits for `Pd`; A then requests `D`. InnoDB detects the deadlock and rolls back the lighter transaction (1213). That customer gets the one not-found response for their own index, or an activation refuses. It fails closed, with no integrity effect, but it is a customer-visible availability regression that this lane introduces. Index against activation, and index against index across two plan versions in opposite order, form the same cycle. | Native probe (pre-G1, deadlines unmodified, 370 s). Through a namespaced `hrtime` hook, I fired once inside the member index transaction after it held the plan row. An async `mysqli` session (standing in for a zero-period index's `definition()` order) took the definition `FOR UPDATE`, then requested the plan row. The member index then requested the definition. Result: member index `completed periods=1`; the other session got **1213 Deadlock found**. Run 2 showed the same 1213; run 1 was aborted by an external mysqld restart. Activation and multi-plan cycles are from code reading only. | Resolve together with N1. Any design must give the index and the writers one global order over the shared rows, or not lock them at all. Add a native deadlock-order test to phase B. |
| N3 | P3 | `MembershipPrivacy::handle` (the seam's `MembershipHttpMount::admitted()` check) | Removing the seam's own mount check (mutant R11) passes every test: the controller's check still returns not-found before identity, and no query runs. However, an unmounted or stale request then runs the session middleware and **issues a session cookie**. That breaks the documented "before any session" guarantee unnoticed. | R11 survived `MembershipHttpMountTest` (33/1070). Adding `assertCookieMissing(config('session.cookie'))` to the runtime-withdrawal case passes on the candidate (7 tests, 210 assertions) and kills R11 (7 failures). | Add the cookie-absence assertion (deferred batch is fine). |
| N4 | P3 | `MemberOriginalSourceCapture.php:457` (`$committing && ! $primary->inTransaction()`) | The fallback for a `PDO::commit()` that itself throws is not pinned (mutant R9 survives `MemberActivationCommitWitnessTest`). Activation is not mounted, and the packet already lists this as an untested condition. | R9: 3 tests, 50 assertions, OK. | Keep it in the untested conditions. Pin it when activation is mounted. |
| N5 | P3 | `MembershipPrivacy::refusal($e, true)`, `MembershipReservationOperations.php:181`, `SealedMembershipPaidInvoice::lock` | A writer failure before commit that is not a `MembershipException` (for example a billing decrypt error) becomes `operation_refused`, which is a 503, not the 404. This is the correct conservative F7 choice. For a *known* foreign period id, though, the evidence is read (taking the global fence and the foreign invoice's billing rows `FOR UPDATE`) **before** the owner comparison (`sameOwner`), so a state-dependent 503 can differ from an unknown id's 404. Period ids are random v4 UUIDs that appear only in the owner's own index, so the oracle value is negligible. The read-before-owner-check behavior predates this lane (C4d). | Code reading. Mutant R2 (owner comparison in `sameOwner` removed) is still refused by `match()` (`conflicting_invoice`), so the IDOR stays closed. | Note only. Optionally route by owner before reading evidence, in a later domain lane. |
| N6 | P3 | `MembershipController::index` | The index body is the domain projection passed through whole, with no HTTP allowlist as the writer receipt has. It includes `provenance`, plan-version, definition and offer-revision ids; C4d reviewed these as non-private. The HTTP test asserts equality with the domain output, so a future domain field would reach customers silently. | Code reading; `MembershipHttpTest` journey. | Optionally pin the index key set in the HTTP test. |
| N7 | P3 | `routes/production-memberships.php` (throttle by IP, same as D1) | Customers behind one NAT share one 10-a-minute budget. An attacker with many addresses is not bounded per account. Because of N1 the index still contends shared rows, so a per-customer key matters more than the packet suggests. | Code reading of `ThrottleRequests::resolveRequestSignature` (default guard, not `customer`). | Per-customer limiter before any non-local mount (already a listed follow-up). |

## Assessment by question

1. **F3 (writers and the owner lock).**
   - **Writers.** I enumerated every write to the tables the index reads:
     - Period and award event: `MembershipAwardContext:98-99`.
     - Redemption and reserve/release/expire events: `MembershipReservationContext:610-663`.
     - Activation rows: `MemberActivationInspection:230`, inside the reservation context's `inspect`.
     - Plan versions (staff): `MembershipPolicyVersions:34`.
     - Member profiles and definitions (staff): `MemberGrantRows:71-86`.
     - Identity rows: identity domain.
     - Billing rows (`BillingLedger`, `BillingWebhookIntake`, `BillingSubscriptionApprovals`, `BillingRetrievalAttempts`, `RetrieveMembershipInvoice`): the index does not read them.
     - No sweep, expiry job, import or row migration writes membership rows. `expire` is triggered by the owner, and retention triggers forbid UPDATE and DELETE.
   - **Owner lock.** Award and reservation take `ProductionCustomerAccess::lock($principal…)` first and hold it to commit. They bind the principal to the invoice's original buyer (`SealedMembershipPaidInvoice::sameOwner`, `MembershipReservationContext::match`). The period's `account_id` comes from that principal's durable binding, so there is no second owner identity for the same period. Staff plan and definition rows are append-only and are locked directly by the index.
   - **Consistency.** It holds without the fence. Every index read is `FOR UPDATE` (next-key locks under RR) or is compared byte for byte again in the current and committed proofs, so a torn read fails closed.
   - **Availability and lock order.** These do not hold as claimed (N1, N2).
2. **F8/F7/C2.**
   - **Not-found.** Every `MembershipException` other than `identity_refused`, `committed_receipt_refused` and `operation_refused` maps to the one byte-identical `404 {"code":"MEMBERSHIP_NOT_FOUND"}`. So do an unknown route, a pattern miss, the unmounted family and a body withheld at pre-bytes. The respond hook maps any rendered 404 to the same body.
   - **Capsule.** It runs before any domain call (`write()`/`index()` first lines after the mount check), and its refusal is 403.
   - **Writer classification.** It is complete (`MembershipReservationOperations::run/runOwned`): a witnessed commit, or any failure after commit, becomes `committed_receipt_refused` (202); an unclassified failure becomes `operation_refused` (503, retry the same request). Nothing that may be durable is presented as "nothing happened".
   - **Index tail.** The index body is a built `JsonResponse` that is returned only after the capsule proof, `proveBeforeBytes()`, the mount check and the capsule proof again. Nothing streams.
   - **Exception paths.** These go through the respond hook. The report hook logs only the exception class, and debug mode cannot leak a reason under the prefix.
3. **Mount.**
   - `MembershipHttpMount` is byte-identical to D1's `ProductionFreeHttpMount` apart from names.
   - It admits only the literal `true` together with a cached `local` or `testing` environment.
   - The config file holds the literal `false` with no env switch.
   - `scripts/ops/capability-boundary.php` (`PRODUCTION_LANE_PATTERN`, `mounted\z`) refuses `production-memberships.http_mounted` true on staging without changes.
   - The `StagingEnvironmentAdmissionTest` census classifies the mount as local/testing only (15/231 OK).
   - Data cases cover the string `"true"`, `1`, `null`, an array, `staging` and `production`. Mutant R4 (`==`) is killed.
4. **Authorization/IDOR.**
   - Another customer's period gets the one not-found for reserve, release and expire, and an empty index (HTTP test).
   - With the owner comparison removed (R2), the context's `match()` still refuses (defense in depth).
   - CSRF is real (419 test), Origin and `Sec-Fetch-Site` are checked, the session is blocked with `block(20, 5)`, and the limiter prefixes are separate (author mutants M6 and M7 killed).
   - Abuse and DoS: see N1 and N7.
5. **F4/F6.**
   - **F4.** It reuses the namespaced `hrtime` of `MembershipAwardMonotonicClock`; there is no production seam. A cached global resolution would make the test fail, not pass silently. The re-run test requires no commit.
   - **F6.** The witness is installed as the connection's transaction manager and armed right before the owned commit. The classification `committed || witnessed || (committing && idle)` is correct. A callback that reopens a transaction after a witnessed commit stays committed (author M15b killed). Only the commit-throws fallback is unpinned (N4).
6. **Receipt/bodies.**
   - The writer receipt is allowlisted to `periodId`, `status`, `balances` and the optional `redemptionId`, `creditAmount` and `honorDeadline`, with exact keys asserted.
   - No invoice id or provider reference appears in any body.
   - Error bodies carry only fixed codes. The only headers that survive are `Allow`, `Retry-After` and `X-RateLimit-*`, each value-checked.
   - The index body is the domain projection (N6).
7. **Census.**
   - **Rename-in-place.** It is acceptable, and keeping the old entries is not possible: `phpunit-shards.py` and `database-receipts.py:442` refuse a census method that is not discovered, and the receipts require each engine's skips to match its census exactly.
   - **Prefix and append.** The two renamed entries (positions 284 and 285) lie outside the hash-pinned first 150 (`test-focused-tests.py:139`, `d93da4e7…`, OK), and one entry was appended (291 → 292).
   - **Safeguards and selection.** The four safeguards pass. The regenerated selection is 2488 of 9569 cases in 237 files, which equals both workflow comments. `database-mysql-selection.json` needs no change.
8. **Mutations:** see below.

## Commands and results

All commands ran from `/home/user/wt/c4e` at `be33167f` (app, routes, bootstrap and config identical to `b3deef84`) or from a scratch copy of it. PHPUnit runs used the brief's direct command on SQLite in memory with `--fail-on-phpunit-warning` and `public/build` absent.

| Run | Selection | Result |
| --- | --- | --- |
| base-mount | `tests/Feature/ProductionMembershipHttp/MembershipHttpMountTest.php` | OK, 33 tests, 1070 assertions |
| base-http | `tests/Feature/ProductionMembershipHttp/MembershipHttpTest.php` | OK, 5 tests, 416 assertions, 159 s |
| base-avail | `MembershipCustomerIndexAvailabilityTest.php` | OK, 4 tests, 69 assertions |
| base-f6 | `MemberActivationCommitWitnessTest.php` | OK, 3 tests, 50 assertions |
| base-staging | `StagingEnvironmentAdmissionTest.php` | OK, 15 tests, 231 assertions |
| base-d1-config / base-d1-binding | D1 `ProductionFreeGrantHttpConfigurationAdmissionTest`, `ProductionFreeGrantHttpBindingTest` (bootstrap ordering regression) | OK, 9/47 and 26/144 |
| ops | `python3 -m unittest tests/ops/test_staging_capability_profile.py` | OK |
| selection | `phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection` | exit 0, "Proved 2488 selected MySQL-native tests in 237 files (of 9569 discovered tests in 697 files)"; generated `phpunit-ci-mysql-*` deleted |
| safeguards | `scripts/ci/test-{phpunit-shards,ci-scope,workflow-cadence,focused-tests}.py` | 52, 27, 14, 53 OK |
| diff | `git diff --check a08e79ca..be33167f` | clean |
| native-probe1 | Scratch `ReviewerNativePlanLockProbeTest` on private `/opt/mysql-8.4.11/bin/mysqld`, port 3393, own datadir, `VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_c4erev`, REPEATABLE-READ, **pre-G1, no deadline raised** | OK, 307 s; observations under N1 |
| native-deadlock3 | Scratch `ReviewerNativeIndexDeadlockProbeTest` (same server) | OK, 370 s; member index completed, other session 1213 (N2). Run 2: 1213 (reaped as an exception). Run 1: aborted by an external mysqld shutdown at 13:05. |

The probe sources and driver scripts are kept in `scratchpad/c4e-review/evidence/`, the logs in `scratchpad/c4e-review/runs/`, and the progress log in `scratchpad/c4e-review/PROGRESS.md`. The server was shut down and its datadir removed.

## Mutations (mine, scratch copy, each reverted and compared)

| ID | Mutation | Run | Result |
| --- | --- | --- | --- |
| R1 | F8: `invoice_owner_mismatch` → 403 (foreign-period oracle) | HTTP every-refusal | **Killed** |
| R2 | Domain owner comparison `sameOwner` removed | HTTP every-refusal | Survived, **equivalent**: `match()` refuses `conflicting_invoice` → 404 |
| R3 | Index tail swallows a `proveBeforeBytes()` failure | HTTP pre-bytes withholding | **Killed** |
| R4 | Mount accepts `== true` (`1`, `"true"`) | Mount test | **Killed** (3 failures) |
| R5 | Writer `operation_refused` → 202 submitted | HTTP generic writer refusal | **Killed** |
| R9 | F6 commit-throws fallback dropped | F6 test | **Survived** (N4) |
| R10 | Index body returned before the tail proofs | HTTP pre-bytes withholding | **Killed** |
| R11 | Seam's own mount check removed | Mount test | **Survived** (N3); killed by the proposed cookie-absence assertion |

The author's mutations M1–M16 are recorded in the packet. I did not re-run them.

## Conditions

1. **Before merge.** Correct the F3 claims (N1) in the packet, CHANGELOG, `MembershipCustomerIndex` docblock and lock comment, runtime doc and native test docblock. State that the index still serializes with same-plan writers and indexes through the plan, definition and profile row locks, and that dropping the fence introduces lock-order cycles (N2). Alternatively, restore the fence (C4d's reviewed behaviour) until a design is chosen.
2. **Before phase B evidence is counted, and before any mount beyond local/testing.** Resolve N1 and N2 with one design. Extend `MembershipNativeCustomerIndexTest` and the phase B plan with:
   - plan, definition and profile NOWAIT probes;
   - a concurrent real same-plan writer from another customer;
   - a deadlock-order case;
   - the 1/10/100-period latency (C4d C2).

   The remaining C4d C2 native items stay open gates, unchanged.
3. **Deferred batch.** N3 cookie-absence assertion; N6 index key-set pin; N7 per-customer limiter before any non-local mount; N4 kept as an untested condition.

## Untested conditions

- **Native evidence.** None on the exact SHA with G1 merged. My native probes are pre-G1 characterization on scratch probe tests: they show lock holding and a cycle, not latency, and they are not acceptance.
- **N2 coverage.**
  - Shown natively with a raw-SQL stand-in for the definition-first index, not with two real index processes.
  - The activation and multi-plan cycles come from code reading.
  - Victim selection (InnoDB weight) was observed once in each direction-relevant run, not characterized.
- **Writers waiting at the index's plan lock.** Not executed natively; the lock order comes from code reading (`MembershipAwardContext:82`, `MembershipReservationContext:145`).
- **Runtime and environment.**
  - No PHP-FPM, web server, proxy (`getSchemeAndHttpHost` before trusted proxies) or browser evidence.
  - No slow-client test.
  - Rate limits were not exercised across addresses.
- **Main.** I did not review main `5b0a6606` or a merge with it.
