# Independent re-review: lane A5 after the REJECT at 470a79f7

- Worktree `/home/user/wt/a5`, branch `harness/refund-dispute-a5-20261024`.
- New tested SHA `0e9520aa3397fa1ce4bcd001074aefb072d85f6c`. HEAD `e7712b6d` changes only `docs/verification/refund-dispute-a5-20261024/README.md` after it (`git diff --stat 0e9520aa HEAD`).
- Prior record: `scratchpad/a5-review-470a79f7.md`.
- I did not push, commit, comment or dispatch anything. Mutations ran in the scratch copy `scratchpad/a5-mut`, which I synced to HEAD with rsync (`composer.lock` unchanged since `f30ae1ae`). At the end, `git status --short` is empty, `public/build` is absent, and my mysqld is stopped with its datadir removed.

## Verdict: APPROVE WITH CONDITIONS

The P1 is fixed and proven on native MySQL. Every P2 and P3 from the first review is either fixed or documented as the record asked. No P0 or P1 is open. The remaining conditions do not block merge:
1. **Untested on MySQL:** Foundation (or a native run) remains the evidence for the full lifecycle and admin suites on MySQL. Both files are now in the MySQL selection. Natively I ran only the two P1-relevant admin tests (the author reports 5/5).
2. **Merge order:** whichever of A5, MIG or C4e merges second must union the SQLite census tail and regenerate the selection count comments. The migration prefix clash with MIG is resolved.
3. **Owner gates (no code change expected):** Sean's four policy values and U-08 stay unset or open. The documented operator limitations (stuck after `attention`, arbitrary per-refund split, test-chain-only hold coverage) are gates before any real use.

## Each claimed fix, verified

| Item | Claim | Verification | Status |
|---|---|---|---|
| P1 | Customers list sorts only by aggregates, Filament key sort off | Read `StaffCustomerResource` (see detail below). New test `test_the_grouped_customer_list_orders_only_by_aggregates_…` asserts that every ORDER BY entry is a raw `MAX/MIN(order_finalizations.…)`, both with no sort and after `sortTable('last_finalized_at')`. **Native MySQL 8.4.11 run: `test_staff_record_a_check…` + `test_the_grouped_customer_list…` OK, 2 tests, 58 assertions** (this test errored with 1055 at 470a79f7). Mutant R11 (remove `defaultKeySort(false)`) is killed on SQLite by the new test. | Fixed |
| P1 | Admin suite in the MySQL selection | `include_files` gains `tests/Feature/RefundDispute/StaffCommerceAdministrationTest.php`. I regenerated the selection in the scratch copy: "Proved 2530 selected MySQL-native tests in 243 files (of 9631 discovered tests in 705 files)". This matches both workflow comments. Generated `phpunit-ci-mysql-*` files deleted. | Fixed |
| P2-1 | Flag pinned on staging | `VASEY_TEST_REFUND_DISPUTE_ENABLED => refund-dispute.enabled` added to `CapabilityBoundary::CAPABILITY_FLAGS` (11). Stated `false` in `ops/staging/env.staging.example` and `ops/staging/test-commerce/env.test-commerce.example`. Docblocks and the validator comment say eleven. `StagingRuntimeValidatorTest` (11 / thirteen staged), `TestCommerceProfileValidatorTest` and `tests/ops/test_staging_capability_profile.py` were updated, the last also with an admission-source assertion on `RefundDisputePolicy`. All pass. | Fixed |
| P2-1 (extra) | Policy keys renamed `VASEY_TEST_REFUND_DISPUTE_*` | `config/refund-dispute.php` reads the four new names. The `.env.example`, production and staging templates, `CHANGELOG.md` and `docs/refund-dispute-lifecycle.md` all use the new names. `grep -rn VASEY_REFUND_DISPUTE` finds only the packet's history row. `ops/staging/forge-deploy.sh:64`'s `VASEY_TEST_*` arm now scrubs the keys from the inherited deploy shell; `tests/ops/test_staging_code_drift.py` passes (10). | Fixed |
| P2-1 (extra) | Production refusal unaffected by the rename | Refusal is still `RefundDisputePolicy::account()`, i.e. `TestEnvironment::admitsTestCommerce()` plus Stripe test mode, ahead of the flag and the values. The production template states `VASEY_TEST_REFUND_DISPUTE_ENABLED=false` and leaves the four values commented. `test_production_kit.py` (50) and `test_production_runtime_validator.py` (27) are OK. | Fixed |
| P2-2 | Tests for R1/R2/R6/R7/R10 | Five new lifecycle tests. All five previously surviving mutants are now killed (table below). | Fixed |
| P2-3 | Migration renamed to `2026_10_24_264100_refund_dispute_lifecycle.php` | It is the only `2026_10_24` file in A5. Only a vitest JSON number still contains `264000`. The A5 migration test and the seven roundtrip tests `require` the 264100 path (11 files reference it in all). The file content is unchanged (git rename at 100%). MIG keeps 264000, so the clash is gone. | Fixed |
| P2-3 | Merge with main | `3afcea0d` has parents `ad5b7cb9` + `707649a6`. It changed exactly main's file set (`git diff --name-only ad5b7cb9 3afcea0d` = `git diff --name-only e5d0d3f2 707649a6`). Every main file is byte-identical to `707649a6` except `CHANGELOG.md`, where the only difference is the A5 entry. The workflow comment took main's line, and `6ab0c98f` then regenerated it. | Clean |
| P2-3 | Census | `database-sqlite-skips.json` is unchanged since `f30ae1ae`: 295 methods, identical to the reviewed list (the first 291, so also the first 150, match main). | OK |
| P3 | Stray `PROGRESS.md` | Removed. | Fixed |
| P3 | Masked work count | `test_unset_policy_is_reported_and_writes_nothing` now asserts all seven tables at 0, unmasked. | Fixed |
| P3 | Docs count | Docs now say "a read-only order evidence action plus six commands", naming each. | Fixed |
| P3 | `order()` | Now private. New `orderFor(id, actor)` re-runs `staff()` (gate + MFA) and the account-scope existence check (`not_found`). `StaffOrderResource::detail()` uses it, and the new admin test covers staff / non-staff / out-of-scope. | Fixed |
| P3 | Modal outside test commerce | `fillForm` catches `RefundDisputeException`, sends a notification and calls `$action->cancel()`, which throws Filament's `Cancel`, so the undefined `$sequence` is never returned. Covered by the new admin test (live mode → "…is unavailable", no provider call). | Fixed |
| P3 | Limitations | New "Known limitations" section in `docs/refund-dispute-lifecycle.md`: stuck-after-attention (both directions), automatic multi-refund split, test-chain-only coverage and the hold gate for the other delivery paths. | Documented |
| P3 | Non-locking gate read | Unchanged. Acceptable, as stated in the first review: the User row is already locked in the same transaction. | Accepted |

Detail on the P1 code in `StaffCustomerResource` and why column sorts can't bring 1055 back:
- `defaultSort(closure, 'desc')`, where the closure is `orderByRaw('MAX(order_finalizations.finalized_at) …')->orderByRaw('MIN(order_finalizations.id) …')`.
- `->defaultKeySort(false)`, so Filament no longer appends a plain-column key sort.
- The only sortable column, `last_finalized_at`, uses `sortable(query: …)` with the same aggregate closure.
- No column is searchable and there are no filters.
- When a user sorts the column, Filament's `applySortingToTableQuery` evaluates the default-sort closure as well, which appends more aggregate orders. Everything is still aggregate-only, which `ONLY_FULL_GROUP_BY` accepts.
- The ORDER BY can only be widened by making another column sortable without an aggregate query, or by turning the key sort back on. Mutant R11 shows the new test catches the key-sort case. A future sortable plain column would also fail the regex assertion only if exercised through `sortTable`, so treat that as a review point for future edits to this table.

## Commands and results

| Run | Source | Result |
|---|---|---|
| SQLite (direct-PHPUnit command from the brief): `tests/Feature/RefundDispute`, `tests/Unit/RefundDisputeStateTest.php`, `ProductionEnvironmentTemplateTest`, `StagingRuntimeValidatorTest`, `TestCommerceProfileValidatorTest` | worktree HEAD (= 0e9520aa code) | OK, 185 tests, 3,260 assertions, 4 skipped (census natives), 5 min 20 s |
| Native MySQL 8.4.11: private `/opt/mysql-8.4.11/bin/mysqld` via `scripts/dev/with-mysql-test-server.sh`, port 3613 (free per `ss -ltnp`), short `TMPDIR=/tmp/a5rv`, TCP only, `VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_test`. Ran `StaffCommerceAdministrationTest --filter 'test_staff_record\|test_the_grouped_customer_list'`. | HEAD | **OK, 2 tests, 58 assertions**, 11 min 07 s. The wrapper stopped the server and removed its datadir; `/tmp/a5rv` removed; port 3613 free. I touched no other lane's server (3407 and 3497 were left alone). |
| Five previously surviving mutants plus R11 (scratch copy, one at a time, restored; `diff -rq app` clean afterwards) | scratch = HEAD | All killed (table below) |
| Native selection regeneration (`phpunit-shards.py --shards=8 … --mysql-native-selection`, scratch copy) | scratch = HEAD | 2530 of 9631 in 243 files (matches both workflow comments) |
| `tests/ops`: staging_capability_profile (17), staging_code_drift (10), production_kit (50), production_runtime_validator (27) | HEAD | all OK |
| Safeguards: phpunit-shards (52), ci-scope (27), workflow-cadence (14), focused-tests (53), database-receipts (61) | HEAD | all OK |
| `composer validate --strict` | HEAD | valid |

## Mutations

| # | Mutation | Killing test (new) |
|---|---|---|
| R1 | Drop the `administer-catalog` gate in `RefundDisputeAuthority::lock()` | `test_an_mfa_enrolled_non_admin_is_refused_by_every_writer_before_any_provider_call` |
| R2 | Cap a staff allocation by the original line total instead of the remaining total | `test_a_second_allocation_is_capped_by_the_line_s_remaining_total_not_its_original_total` |
| R6 | Allow the exclusive reopening decision while a dispute is open | `test_an_exclusive_reopening_decision_refuses_while_a_dispute_is_open_even_for_a_refunded_held_line` |
| R7 | Remove the order-wide hold check in `ReadTestOwnerDelivery` | `test_the_owner_download_projection_reports_an_order_wide_hold_and_its_release` |
| R10 | Drop the policy-hash recheck at observation commit | `test_a_policy_change_during_the_provider_read_makes_the_commit_stale_and_writes_nothing` |
| R11 | Remove `->defaultKeySort(false)` from the customers table (reintroduces the 1055 sort) | `test_the_grouped_customer_list_orders_only_by_aggregates_so_mysql_only_full_group_by_accepts_it` |

## Untested conditions
- The full `StaffCommerceAdministrationTest` (5) and the full lifecycle suite on MySQL. I ran 2 admin tests natively; the author reports 5/5. Foundation will run both files.
- Native races: not re-run, because the domain code did not change since 470a79f7, where I ran 2/2 order-row races. The exclusive-decision race and the latency test rely on the packet.
- Touched existing suites other than the A5 and validator files: I did not re-run them after the merge with main. Main's T36 files are byte-identical to `707649a6`.
- Real Stripe, Filament browser rendering, a staging deploy of the renamed keys.
