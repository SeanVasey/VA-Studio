# Independent re-review: lane REP fixes, head `622a4920`

Reviewer: Claude Code independent review subagent, 2026-10-29. Review only: nothing was pushed, no PR was opened or changed, no CI was dispatched, and `/home/user/wt/rep` was not modified.

- Branch `harness/reporting-rep-20261024`, HEAD `622a4920`.
- Tested code is `d5f29a1c`. `git diff d5f29a1c 622a4920 -- app resources config routes database` is empty; after it come `535ee73d` (a test-only fix of the lock probe) and docs.
- Delta reviewed: `git diff ebdc9ca4..622a4920` (21 files).
- Previous record: `rep-review-ebdc9ca4.md` (APPROVE WITH CONDITIONS).

## Verdict: APPROVE

Both P2s are fixed and the evidence holds up, including on native MySQL with A5 writers. Conditions C1 and C2 from the first review are discharged:
- **C1:** production identity accounts are excluded from segments, and the false claims are corrected.
- **C2:** the report takes no row locks, and the CSV now carries the unreadable/unverified/unavailable rows with accurate wording.

The P3s are fixed or documented. I have no new P0, P1 or P2 findings. Two P3 observations remain (R-1, R-2), and neither blocks the merge.

## (1) P2-2: no row locks in the sales report

### Code
- `RefundDisputeCase::load(..., bool $forUpdate = true)` applies `lockForUpdate()` through `$lock` on exactly the five statements that had it before: objects, latest observation, latest observed, allocations, active holds.
- Nothing else in `load()` changed; payment, grants, exclusive sales and lines were plain reads before and still are.

### Every writer still locks
The repo-wide grep of `RefundDisputeCase::load` / `reload()` finds:
- `RefundDisputeLedger.php:82`: default (true).
- `RefundDisputeActions.php:159`: default (true).
- `reload()` (`RefundDisputeCase.php:127`, used by `RefundDisputeLedger.php:118`, `RefundDisputeActions.php:49` and `RefundDisputeEffects.php:63`): calls `load()` with the default (true).
- The only `forUpdate: false` caller is `SalesReport.php:296`.

### The non-locking path cannot reach a writer
- The report takes `->refundedByLine()` (an array) and drops the case inside the closure.
- No writer accepts a case from outside.
- Even if a non-locking case were reloaded, `reload()` locks again.

### Snapshot transaction
- `DB::transaction` wraps each order's `load()`. Under InnoDB REPEATABLE READ (the default) the first SELECT fixes one consistent snapshot, and plain SELECTs take no row locks.
- `ReadOrder::verify` and `snapshot()` decryption issue no locking reads.

### Native MySQL 8.4.11 (own server)
- **Lock-free test:** `SalesReportLockFreeTest` gave OK (1 test, 11 assertions). It covers:
  - no locking SQL on any engine;
  - a second session holding FOR UPDATE on the order's rows in all five A5 tables, with the report not delayed (under 1.5 s with a 2 s lock timeout) and its figures unchanged;
  - zero `performance_schema.data_locks` for the report's thread, plus a positive control.
- **L1 mutant** (`$lock` always calls `lockForUpdate()`, i.e. the report locks again): **KILLED** natively. The first failing assertion lists `select * from refund_dispute_objects where order_id = ? order by id asc for update`.
  - On SQLite, L1 survives by construction: the SQLite grammar emits no FOR UPDATE.
- **A5 writers and races, unchanged locking:**

| Test | Result | Packet's earlier figures |
| --- | --- | --- |
| `RefundDisputeConcurrencyTest` (the four census native-only methods: two delivery races via the race worker, the exclusive reopening vs purchase race, writer latency) | OK (4 tests, 50 assertions), 1132 s | same counts as the A5 packet |
| `RefundDisputeLifecycleTest` filtered to the three writer cases the A5 packet ran natively (exact staff allocation with idempotent replay, partial refunds past paid / currency mismatch, dispute before refund) | OK (3 tests, 52 assertions), 888 s | |
| `RefundDisputeMigrationTest` | OK (2 tests, 63 assertions), 589 s | |

### CSV rows and wording
- **Unattributed note:** now reads "succeeded refund not attributed to a tier line: no line allocation recorded, or the order could not be read or verified (see the unreadable and unverified rows)". That is accurate: refunds of unverified orders are queued with an empty tier map, so they land in unattributed.
- **New rows:**
  - `tier_unverified_orders` and `tier_unreadable_refund_orders` (count in the `count` column) are always emitted when tiers are available.
  - `tiers_unavailable` carries the cap reason otherwise.
- Mutants removing either count row (R-C2, R-C3) are killed.
- The missing `refund_line_allocations(order_id)` index is now listed as a follow-up. It no longer affects the report; it affects only A5 writers' own locking reads.

## (2) P2-1: production identity accounts excluded

`CustomerSegments::syntheticAccounts()` adds `WHERE NOT EXISTS (production_identity_origins pio WHERE pio.account_id = ca.id)` to the cap count, the population and the purchase join.

**Is `production_identity_origins` the right discriminator?** Yes; it is sound and complete today:
- **Only two writers.** Exactly two code paths insert `customer_accounts` (repo-wide grep): `CustomerAccounts::provision` (synthetic fixtures, behind `CustomerAccessPolicy::requireEnabled`) and `CompleteIdentity.php:100`.
- **The origin is written atomically.** `CompleteIdentity` inserts the account and its origin row in the same `transaction()` (`:47`, `:100-106`). There is no committed window in which a production account has no origin.
- **No later linking.** Enrolment refuses an existing email (`$users !== []`: "Existing test/admin/user accounts are never adopted"). Recovery requires an existing origin. So an account created without an origin can never later become a production account.
- **Origins are permanent.** They are append-only: the update and delete guards (`IdentitySchema.php:119`) refuse every update and delete, and the migration's `down()` refuses while populated. An excluded account cannot become included again.
- **`Schema::hasTable` fallback.** When the table is absent the identity migration has not run, so no production account can exist.
- **Index.** `pio_account_unique` indexes `account_id`.

**Earlier probe re-run at `622a4920` (SQLite):**
- An account inserted with no origin row is still counted and exported as TEST. This is correct under the discriminator: no production path creates such an account.
- The author's `CustomerSegmentsEdgeTest` covers the case with an origin row (excluded from counts, consent distribution and every export).
- `CustomerSegmentsEdgeTest` on native MySQL: OK (2 tests, 14 assertions).
- Mutant R-P1 (exclusion disabled): KILLED.

## (3) Tests for M4, K4, K5, K6, K13 and K15, and the P3 changes

All re-run one at a time on SQLite in a separate scratch worktree:

| Mutant | Result |
| --- | --- |
| R-M4 retained model | KILLED by the new `test_export_authority_rereads_enrollment_...` (Authority: 4 tests, 89 assertions, 1 failure) |
| R-K4 paid exception as purchase | KILLED by `CustomerSegmentsEdgeTest::test_a_paid_exception_is_not_a_purchase` |
| R-K5 segment refunds ignored | KILLED (edge test: refunded segment, net, then reversal) |
| R-K6 inactive account in marketing | KILLED (account c8 added to `CustomerSegmentsTest`) |
| R-K13 resolved exception counted as awaiting | KILLED (`ProductionSeparationReportTest` now resolves through the real `ResolveRefundedTestException` flow) |
| R-K15 inclusive period end | KILLED (`SalesReportTest` now finalizes at exactly 00:00:00 and checks both adjacent periods) |
| R-P1 production exclusion disabled | KILLED |
| R-C1 CSV look-past-whitespace removed | KILLED (unit: 3 failures) |
| R-C2 / R-C3 CSV unreadable / unverified row removed | KILLED |
| R-T1 snapshot transaction removed (non-locking reads in autocommit) | SURVIVED. See R-2. |

The P3 changes:
- **P3-3:** the production funnel carries only `orders_prepared` (an order has no funds mode before its payment intent), labelled "PRODUCTION CHAIN (not split by funds mode)" with an accurate note; paid counts stay per mode. The test pins `['orders_prepared' => 4]`. This is fixed: no live and rehearsal money or paid counts are summed.
- **P3-4:** report notes, CSV notes and `docs/staff-reporting.md` state that lost disputes are not deducted and that refunded paid exceptions stay in exception gross. A test pins the latter through the real resolution flow (`gross 0, net 0, exception_orders 1, exception_gross = paid`).
- **P3-5:** the class doc, page notes, packet and staff doc now say the email is read only for the recipient binding and never exported.
- **P3-7:** `ReportingCsv::cell()` looks past leading spaces and LFs. My probe now gives `" =1+1" => "' =1+1"` and `"\n=1+1" => "'\n=1+1"`; full-width `＝` is documented as not neutralised.

## (4) Selection and safeguards

| Command (at `622a4920`) | Result |
| --- | --- |
| `python3 scripts/ci/test-phpunit-shards.py` | Ran 52, OK |
| `python3 scripts/ci/test-ci-scope.py` | Ran 27, OK |
| `python3 scripts/ci/test-workflow-cadence.py` | Ran 14, OK |
| `python3 scripts/ci/test-focused-tests.py` | Ran 53, OK |
| `composer validate --strict` | valid |
| `python3 scripts/ci/phpunit-shards.py --shards=24 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection` | "Proved 2593 selected MySQL-native tests in 257 files (of 9805 discovered tests in 726 files) across 24 nonempty shards". Generated files deleted. |

- The count comments in `.github/workflows/final-verification.yml:151` and `.gitlab-ci.yml:113` say 2593 of 9805 in 257.
- The selection adds `CustomerSegmentsEdgeTest` and `SalesReportLockFreeTest`.
- `database-sqlite-skips.json` is unchanged.

## Remaining observations (P3, non-blocking)

- **R-1: the discriminator works by exclusion.** Any future third writer of `customer_accounts`, or a production path that skipped the origin row, would fall into the TEST population. A positive test-account marker would fail closed instead: for example accounts provisioned by `CustomerAccounts::provision`, which audits `customer.test_account.provisioned`. Suggested for the U-13 follow-up; the flags stay off until then anyway.
- **R-2: the snapshot transaction has no test.** It is a consistency measure across the five reads (without it, autocommit reads could see an observation whose objects are not yet visible, and the order would be counted unreadable). Mutant R-T1 survives because no test interleaves a writer between the reads. The behaviour is sound by InnoDB semantics; I accept it as untested defence.
- **CSV edge (informational):** a space followed by a tab or CR before a trigger (`" \t=..."`) is not neutralised, because `ltrim` skips only spaces and LFs. Not exploitable in mainstream spreadsheets.
- **Coverage claim:** the author's statement that the A5 writer suites were not re-run natively is now covered by this review's native A5 runs above.

## Commands

**SQLite.** The direct-PHPUnit command (`rep-review/sqlite.sh`). The scratch worktrees were `git worktree add --detach` at `622a4920` (one for native runs, one for mutants), with `vendor` hard-linked, `.env` from `.env.example` and no `public/build`.

| Command | Result |
| --- | --- |
| `tests/Feature/Reporting tests/Unit/ReportingCsvTest.php` | OK (31 tests, 426 assertions). Matches the packet. |
| Earlier probes `ReviewProbeTest` | OK (3 tests, 5 assertions); outputs above |

**Native MySQL 8.4.11.**
- Server: own `/opt/mysql-8.4.11/bin/mysqld --no-defaults`, datadir under `<scratchpad>/rep-mysql/data`, port 3492 (checked free with `ss -ltnp`; 3491 was in use by another lane's server, which was not touched), socket `/tmp/rv92/m.sock`, `--mysqlx=OFF`.
- Databases `vaseyaudio_rv92a` (A5) and `vaseyaudio_rv92b` (reporting), with `VA_LOCAL_DISPOSABLE_MYSQL` set to match (`rep-review/mysql2.sh`). One file per run.

| Run | Result |
| --- | --- |
| `SalesReportLockFreeTest` | OK (1 test, 11 assertions) |
| L1 mutant on `SalesReportLockFreeTest` | KILLED |
| `CustomerSegmentsEdgeTest` | OK (2 tests, 14 assertions) |
| `RefundDisputeConcurrencyTest` | OK (4 tests, 50 assertions) |
| `RefundDisputeLifecycleTest` (three writer cases) | OK (3 tests, 52 assertions) |
| `RefundDisputeMigrationTest` | OK (2 tests, 63 assertions) |

## Not tested

- I did not re-run natively `ReportingAuthorityTest`, `SalesReportTest`, `CustomerSegmentsTest`, `RefundNettingReportTest`, `ProductionSeparationReportTest` or `OperationalHealthTest`. The author ran them at `d5f29a1c`, and I ran all but the authority test natively at `ebdc9ca4`.
- The full A5 lifecycle and administration suites on MySQL (SQLite and Foundation CI cover them).
- Browser specs.
- Behaviour at volume.

## Cleanup

Only the mysqld this reviewer started was stopped, and its datadir, socket directory and both scratch worktrees were removed (see PROGRESS.md).

## Resumption note (22:25 UTC, after a container restart)

- All six native runs listed above finished before the restart, at 17:53–18:22 UTC, on this reviewer's mysqld (port 3492). The results are in PROGRESS.md and `rep-review/rr-mysql-*.log`: LockFree, the L1 mutant, A5 concurrency, CustomerSegmentsEdge, the A5 lifecycle writers and the A5 migration.
- No native run was outstanding, so no mysqld was restarted.
- After the restart no mysqld was running. The stale PID file was not acted on.
- The datadir (`scratchpad/rep-mysql`), `/tmp/rv92` and both scratch worktrees (`scratchpad/rep-mut`, `scratchpad/rep-scratch`) were removed.
- `/home/user/wt/rep` was not modified.
