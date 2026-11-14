# Independent review: lane A5, refund/dispute lifecycle and staff customer/order administration

- Branch `harness/refund-dispute-a5-20261024`, worktree `/home/user/wt/a5`, base `e5d0d3f2`.
- Tested SHA reviewed: `470a79f7bce978bb56f9527d8e964305f898e5a7`. HEAD `f30ae1ae` differs only in `docs/verification/refund-dispute-a5-20261024/README.md` (`git diff --stat 470a79f7 HEAD -- . ':!docs'` is empty), so runs at HEAD exercise the tested code.
- Reviewer: independent Claude subagent, 2026-10-24. I did not push, commit, comment or dispatch anything. Mutations and probes ran in a scratch copy (`scratchpad/a5-mut`) or a scratch test file. At the end, `git status --short` in the worktree is empty and `public/build` is absent.

## Verdict: REJECT (narrow; re-review needs only the P1 fix and its MySQL proof)

The domain design holds up under adversarial reading:
- Policy refuses before any lock or provider call.
- Provider facts come only from the authenticated GET path, outside transactions, under a leased claim.
- The state machine works on whole snapshots, and `attention` applies no effect.
- Line allocation is exact.
- Effects are holds only and never rewrite an original.
- Lock order is consistent, and MFA and the gate are re-read under the row lock.
- The ledger is append-only, encrypted and idempotent.
- The migration is additive and forward-only, with sound guards.

One concrete failure blocks the merge. Under Sean's freeze-and-batch rule, nothing merges while a P1 is open:

- **P1: the Test customers staff page fails on every render on MySQL 8.4, the production and CI engine.** Native evidence is below. The A5 MySQL selection does not include `StaffCommerceAdministrationTest`, so even a Foundation run would never catch it.

After that fix and its MySQL proof, I would expect APPROVE WITH CONDITIONS. The conditions are the P2 items below.

## Findings

### P1-1: `StaffCommerceDirectory::customers()` + Filament default key sort breaks under `ONLY_FULL_GROUP_BY` (MySQL)
- Failure path:
  1. `StaffCustomerResource::table()` sets `defaultSort('last_finalized_at')`, and Filament 5's `hasDefaultKeySort` defaults to true.
  2. `CanSortRecords::applySortingToTableQuery()` (vendor/filament/tables/src/Concerns/CanSortRecords.php:131-153) therefore appends `ORDER BY order_finalizations.id`.
  3. The query groups by `o.owner_key, ca.public_id`, and Laravel's MySQL `strict => true` enables `ONLY_FULL_GROUP_BY`.
  4. MySQL raises 1055 on every list render: "Expression #2 of ORDER BY clause is not in GROUP BY clause and contains nonaggregated column 'vaseyaudio_test.order_finalizations.id'…".
  5. SQLite accepts the query, which is why every packet run passed.
- Evidence: native MySQL 8.4.11 run 1 (below). `StaffCommerceAdministrationTest::test_staff_record_a_check_allocate_and_suspend_through_the_domain_and_read_customer_detail` errors at line 96 (`Livewire::test(ListStaffCustomers::class)`). The other four tests in that run pass, including the order list and the forbidden-access test.
- Impact: on staging (MySQL) the FP-040 "staff customer list/detail" deliverable is unusable (500). It fails closed, so there is no data exposure, but the packet's acceptance claim for staff customer administration does not hold on the production engine.
- Fix (verified, see run 2): `->defaultKeySort(false)` on the customers table, or a group-safe key sort.
- Required regression: add `tests/Feature/RefundDispute/StaffCommerceAdministrationTest.php` to `include_files` in `scripts/ci/database-mysql-selection.json`, regenerate the counts, and run it natively once.
- Also re-check the order list: it rendered on MySQL in run 1 and has no GROUP BY.

### P2-1: The staging capability boundary does not pin `VASEY_TEST_REFUND_DISPUTE_ENABLED`
- Today's state:
  - Staging admits test commerce (`TestEnvironment::admitsTestCommerce()`).
  - The flag is not in `CapabilityBoundary::CAPABILITY_FLAGS` (scripts/ops/capability-boundary.php).
  - `ops/staging/env.staging.example` leaves it commented/unset instead of stating `false`.
- This breaks the documented profile invariant that each staging-admitted capability flag is stated `false` in the templates and refused by the deploy gate.
- Defense in depth still holds: writes also need all four policy values set to supported values, plus staff MFA and Stripe test mode. A single mis-set env flag therefore cannot activate effects on its own, which is why this is not P1.
- Condition before or with the merge:
  - Add the flag to `CAPABILITY_FLAGS`.
  - State `VASEY_TEST_REFUND_DISPUTE_ENABLED=false` in both staging templates.
  - Update the "ten flags" docblock and `tests/ops/test_staging_capability_profile.py`.
- Alternatively, record an explicit owner-accepted gate.
- Production needs nothing more: the production template states `false`, and the policy refuses outside test-commerce environments. `ops/production/validate-runtime.php` pins no test flags by design.

### P2-2: Test gaps on guards that are correct in code (mutations survived)
- I confirmed each behavior with a separate probe against the unmodified code, so these are coverage gaps, not bugs.
- They should land with the P1 fix, since this lane touches access and money:
  - R1, gate under lock: removing `Gate::forUser($current)->allows('administer-catalog')` from `RefundDisputeAuthority::lock()` survives the full A5 suite. The only domain authority test uses a non-admin who also lacks MFA, so the MFA check masks the gate.
    - Probe P1: a verified non-admin with MFA enrolled is refused (`AuthorizationException`, no provider call).
    - Add that case.
  - R2, remaining-capacity cap: comparing a staff allocation with the line's original total instead of its remaining total survives. No test makes a second allocation to an already refunded line.
    - Probe P2 confirms that the second allocation is refused (`invalid`) and that an allocation to the other line succeeds.
  - R6, open-dispute bar on the exclusive decision: dropping `$dispute !== 'open'` survives.
    - Probe P3 confirms `not_qualified` for a fully refunded, held line while a dispute is open.
  - R7, owner projection: dropping the order-wide-hold check in `ReadTestOwnerDelivery` survives. No A5 test reads the projection.
    - Probe P5 confirms `unavailable` under a reconciling hold.
  - R10, policy recheck at commit: dropping the policy-hash recheck in the observation commit survives.
    - Probe P4 (policy changed during the provider read) confirms `stale` with nothing written.

### P2-3: Merge-order conflicts with MIG and main (mechanical, but they must be handled by whoever merges second)
- **Migration prefix.** MIG (`/home/user/wt/mig`, unmerged; not on local `origin/main` 707649a6) adds `2026_10_24_264000_legacy_history_archive.php`, which has the same prefix as A5's `2026_10_24_264000_refund_dispute_lifecycle.php`.
  - What I checked: no repo test or script requires unique or strictly increasing prefixes. The 17-character prefixes are unique today, and no guard parses the six-digit sequence. Laravel orders by full basename, so `legacy_…` would run before `refund_…`. The two migrations share no tables.
  - Both `down()` methods refuse. So `migrate:rollback --step 1` tests (e.g. `CustomerListeningMigrationTest`) still meet a forward-only migration whichever is last.
  - Result: there is no functional conflict, but this breaks the lane-numbering convention.
  - Recommendation: **whichever lane merges second renames to a later unused prefix** (e.g. `2026_10_24_265000_…`) and updates every literal path:
    - For A5: `RefundDisputeMigrationTest::MIGRATION`, the seven roundtrip tests that `require` the file, the docs, and the packet.
  - Neither migration has run on any shared environment, so renaming is safe.
- **SQLite census.** A5 and MIG both append at the same tail of `scripts/ci/database-sqlite-skips.json` (C4e reportedly too). The second to merge must union the entries, keeping the first 291 unchanged.
- **Workflow comments.** Main moved after `e5d0d3f2`: T36 changed the `final-verification.yml` comment to "2499 of 9595 … 239 files". A5's "2520 of 9581 in 242 files" conflicts on that line.
  - After rebasing, regenerate with `phpunit-shards.py --mysql-native-selection`. P1's added include file changes the count again.
  - Update both `final-verification.yml` and `.gitlab-ci.yml`. No safeguard checks these comments.

### P3 (record; no merge block)
1. **Stray root `PROGRESS.md`.** It was added in `a1abbe8d` and holds one line of lane-internal notes ("native1 4/4 OK … native2 started"). It does not exist on main. Remove it.
2. **Masked assertion.** `StaffCommerceAdministrationTest::test_unset_policy_is_reported_and_writes_nothing` hides the work-table count with `array_replace(R::counts(), ['refund_dispute_work' => 0])`. The policy refuses before `RefundDisputeWork::create`, so the true count is 0. Assert it unmasked.
3. **Permanently stuck cases.**
   - After an `attention` observation, every staff command refuses `not_observed` until a later accepted observation. If the provider keeps reporting the same drift (e.g. an amount change), the case and its holds stay frozen, with no staff override.
   - The reverse also applies: an open dispute that arrives together with an unrelated drift places no reconciling hold, because `attention` applies no effect.
   - Both follow the design ("attention applies no effect") and fail closed or open respectively. Record them as operator-runbook gates before any real use.
4. **Arbitrary automatic split.** With several unallocated refunds that together make a full refund, `greedy()` splits each refund across lines in an arbitrary order. The per-line totals are unique, but the per-refund split is not, so a later succeeded→failed reversal frees an arbitrarily chosen line. A review effect is recorded and holds are not auto-released. Document this.
5. **Command modals outside test-commerce environments.** `fillForm` calls `RefundDisputeLedger::review()`, whose `RefundDisputeException('disabled')` is not caught. In a non-test environment, opening a command modal errors instead of showing a notification. Reads elsewhere work.
6. **Docs say "seven commands".** There are six commands plus the read-only "Order evidence" action (seven actions).
7. **`StaffCommerceDirectory::order()`.** It is public and has no authority check of its own; all current callers check `staff()` first. Consider a private method or an internal check.
8. **Non-locking gate read.** `lock()` calls `Gate::forUser($current)->allows('administer-catalog')` without `[true]`, unlike other domain writers. This is safe here because the User row is already locked `FOR UPDATE` in the same transaction, but it differs stylistically.
9. **Other delivery paths and holds.** Paid-grant, Tax, free and member downloads do not check holds. I judge this acceptable for this default-off lane:
   - Those paths serve different order sources (`ProductionPaidOrderSourceV1` / `paid_order_origins` etc.), disjoint from the test-chain `orders` that A5 can hold.
   - So no hold can be bypassed today. The real gap is that A5 covers only the test chain.
   - Record it as a gate: any future refund/dispute coverage of the production/Tax chains must add hold checks to those paths.

## Assessment by area (what I verified)
1. **Policy refusal.**
   - `RefundDisputePolicy::current()` runs first inside `RefundDisputeAuthority::lock()`, before any row lock or provider call.
   - It requires `enabled === true` (the strings `'true'` and `'1'` refuse) and all four values as non-empty strings from `SUPPORTED`. Extra keys refuse, and so does the environment/mode/account admission.
   - Mutation R5 (drop the extra-key check) was killed.
   - Nothing encodes TERMS §10: no window, no "license ends", no revocation.
   - The env templates keep the flag off or unset. Staging pinning is P2-1.
2. **Provider truth.**
   - Observations come only from `VerifyTestPayment::inspect` → `StripeFinancialInspectionGateway::financialState` → `PaymentFinancialEvidence::capture`.
   - `outsideTransactions()` and the tests assert `transaction_level` 0 at provider calls.
   - The 120 s lease is checked again at commit, along with the token, actor, policy hash and clock.
   - An incomplete list becomes `incomplete`. Vanished objects, amount or currency changes, regressions, over-refunds and currency mismatches become `attention`, which applies no effect and releases no hold (R3 and R9 killed).
   - A succeeded→failed refund is accepted and flagged `refund_reversed_review_required`. Allocations and holds are retained.
3. **Money.**
   - Integer minor units, USD only, explicit currency at every layer (state, observation guard, allocation guard).
   - Line totals come from the verified original payload and must sum to the verified payment.
   - Automatic allocation happens only on a full refund or a single-line order.
   - Staff allocations must sum exactly and stay within each line's remaining capacity (probe P2). Negative, zero, oversize and non-int inputs refuse in both Filament parsing and the domain. Overflow is bounded (≤10 lines × 99,999,999).
4. **Effects and holds.**
   - Every write in `app/Domain/Commerce/RefundDispute/*` targets only the seven A5 tables plus audit. Grep: `::create`, `save`, and `DB::table('refund_dispute_holds')->update`.
   - `IssueTestDelivery` and `RedeemTestDelivery` call `RefundDisputeAccess::assertClear` after their Order row lock (R4 killed). The owner projection marks order-wide holds (probe P5).
   - Lock order: staff User → Order → work → case rows. Delivery takes customer User → Order → control → holds. There is no cycle.
   - MFA (`AdminMultiFactor::satisfiedBy(..., lockForUpdate: true)`) and the gate are re-read under lock.
   - Dispute holds follow the policy value.
   - The exclusive decision requires the line to be fully refunded or the dispute lost, no open dispute, and a held grant (probe P3). It refuses under `remain_sold`.
   - Native race 3 confirms that no exclusive sale or reservation changes.
5. **Ledger.**
   - Rows are append-only (update/delete triggers), encrypted through `CheckoutEvidence`, and hashed, with a unique `(order, request)` and `(order, sequence)`.
   - Request-UUID idempotency: a replay with a different actor or arguments conflicts, and a stale sequence refuses (R8 killed).
   - Object and status dedupe uses unique keys and triggers.
   - Audit actions are `commerce.refund_dispute.*`, and provider IDs appear only in ciphertext (asserted in tests).
6. **Filament.**
   - `ListTestCommerceRecords::boot()` re-checks the gate and MFA enrollment on every Livewire request.
   - Every action goes through the domain services, which check again.
   - A non-admin, even one with MFA, gets 403 from the page (probe P1) and `AuthorizationException` from the domain.
   - Hidden actions add no authority.
   - The customer list is broken on MySQL (P1-1).
7. **Migration.**
   - Additive: 7 tables and 21 triggers (3 per table), and `down()` throws before any query.
   - No existing object is altered.
   - Insert-guard parent checks replace FKs to orders, lines and grants. That is acceptable because the parents are immutable and undeletable under their own guards (finalization graph `*_immutable_delete`), and FKs remain to `users` and among the new tables.
   - The stated justification is weaker than written: the seven roundtrip tests still had to be edited.
   - Those edits follow the existing `transactional_notices` pattern. `CapabilityRollbackFixture::dropEmptyLeavesFirst` asserts each table is empty before dropping it, every retained-row comparison is kept, and A5 is re-upped afterwards. They do not weaken what the tests prove.
   - The prefix clash with MIG is P2-3.
8. **Census and selection.**
   - SQLite census: 291 → 295, and the first 291 entries are byte-identical (append-only).
   - Selection additions are sorted and validated (`test-database-receipts.py` OK).
   - I did not regenerate the 2520/9581/242 counts myself; I relied on the packet's command. They need regenerating after rebase anyway (P2-3).

## Commands and results (all with the direct-PHPUnit SQLite command from the brief, via `scratchpad/a5-review/phpunit.sh`)
| Run | Source | Result |
|---|---|---|
| A5 focused SQLite: `tests/Feature/RefundDispute` + `tests/Unit/RefundDisputeStateTest.php` + `tests/Unit/ProductionEnvironmentTemplateTest.php` | HEAD f30ae1ae (= 470a79f7 code) | OK, 33 tests, 2,153 assertions, 4 skipped (census natives), 4 min 08 s. Matches the packet. |
| Touched sample SQLite: TestOwnerDeliveryTest, TestOwnerDeliveryProjectionTest, TestOrderFinalizationMigrationTest, HostedCheckoutMigrationTest, PromotionMigrationTest, CommerceGuardBytesTest | same | OK, 108 tests, 979 assertions |
| Reviewer probes `scratchpad/a5-review/probe/A5ReviewProbeTest.php` (SQLite, against worktree code) | same | P1–P5 pass. P6 failed on my own wrong expectation: an idempotent replay is refused `already_issued` by the pre-transaction replay check and returns no token, which is correct behavior. |
| Safeguards `test-phpunit-shards.py` / `test-ci-scope.py` / `test-workflow-cadence.py` / `test-focused-tests.py` | same | OK, 52 / 27 / 14 / 53 tests |
| `test-database-receipts.py`, `composer validate --strict` | same | OK; valid |
| Native MySQL run 1: private `/opt/mysql-8.4.11/bin/mysqld` 8.4.11 via `scripts/dev/with-mysql-test-server.sh`, port 3611, datadir under `scratchpad/a5-review/mysqltmp`, `VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_test`, REPEATABLE-READ. Ran `RefundDisputeMigrationTest` + `StaffCommerceAdministrationTest`. | HEAD | 5 tests, 117 assertions, **1 error (P1-1)**, 29 min 42 s under heavy shared load (load average ≈10). Migration 2/2 OK, admin 2/3 OK. Server stopped, datadir removed. |
| Native MySQL run 2: admin `test_staff_record…` against the candidate fix `->defaultKeySort(false)` (scratch copy, port 3612) | scratch | OK, 1 test, 39 assertions, 4 min 35 s: the fix makes the customer list and detail render on MySQL. Server stopped, datadir removed. |
| Native MySQL run 3: `RefundDisputeConcurrencyTest` filtered to the two order-row races (port 3611) | HEAD | OK, 2 tests, 31 assertions, 6 min 56 s. Server stopped, datadir removed. |

The container restarted mid-review (coordinator notice). Afterwards I confirmed the worktree was clean at `f30ae1ae`, the scratch copy matched the worktree's `app/`, and no stale mysqld of mine was running. Runs 2 and 3 used fresh private servers on ports that `ss -ltnp` showed free. I touched no other lane's server.

## Mutations (scratch copy, one at a time, restored after each; the final `diff -rq` of `app/` against the worktree was clean)
| # | Mutation | Result |
|---|---|---|
| R1 | Drop the `administer-catalog` gate in `RefundDisputeAuthority::lock()` (MFA kept) | **survived** the full A5 suite (27 tests). Correct behavior confirmed by probe P1 → P2-2 |
| R2 | Cap a staff allocation by the line's original total instead of its remaining total | **survived** the full suite. Probe P2 → P2-2 |
| R3 | Release every active hold on an `attention` observation | killed (`test_impossible_provider_changes…`) |
| R4 | `assertClear` ignores per-grant holds (order-wide only) | killed (partial-refund suspension, redemption-after-hold) |
| R5 | Remove the extra policy key refusal | killed (`test_every_write_refuses…`) |
| R6 | Allow the exclusive reopening decision while a dispute is open | **survived** the full suite. Probe P3 → P2-2 |
| R7 | Remove the order-wide hold check in `ReadTestOwnerDelivery` | **survived** the full suite. Probe P5 → P2-2 |
| R8 | Remove the command `busy` refusal while a provider read is leased | killed (`test_stale_busy…`) |
| R9 | Treat a vanished prior object as acceptable | killed (lifecycle + unit) |
| R10 | Drop the policy-hash recheck at observation commit | **survived** the full suite. Probe P4 → P2-2 |

## Untested conditions
- Real Stripe test-account interoperability (all provider facts were synthetic).
- The full lifecycle suite on MySQL. It is in `include_files`, so Foundation will run all 15 cases on MySQL, but only 3 (packet) ran natively. Native race 3 (exclusive decision vs purchase) and the latency test were not re-run by me; I rely on the packet.
- Filament browser rendering.
- The full touched-suite sweep (I ran a 108-test sample).
- The regenerated native-selection counts.
- Behavior after rebasing onto current main (707649a6).
