# Independent review: lane REP (staff reporting), head `ebdc9ca4`

Reviewer: Claude Code independent review subagent, 2026-10-29. Review only: nothing was pushed, no PR was opened or changed, no CI was dispatched, and `/home/user/wt/rep` was not modified.

- Branch `harness/reporting-rep-20261024`, HEAD `ebdc9ca4`. That commit is docs only on top of the tested merged head `2162703c`, which merges main `a8f17e5c`.
- PR diff: `git diff a8f17e5c...ebdc9ca4` (37 files, +2890/-3).
- Scope: FP-028 (four widgets), FP-089 (sales report and CSV), FP-090 (customer segments and CSV, default-off).

## Verdict: APPROVE WITH CONDITIONS

There is no P0 or P1. I found no route by which a guest, a customer, a non-staff user, a staff user without the MFA the panel requires, or a staff user whose authority was removed can read a figure or get a CSV. Money is integer minor units grouped by currency, and refunds are netted status-aware through the A5 ledger. Native MySQL runs confirm that the grouped queries are safe under ONLY_FULL_GROUP_BY. The packet's test counts reproduce.

Under the freeze-and-batch rule the PR can merge at the exact reviewed SHA, with the two P2s deferred to the REP follow-up batch on two conditions:

1. **C1 (P2-1).** All three `REPORTING_CUSTOMER_*` flags stay off in every environment until the segment population is limited to synthetic test accounts (or labelled truthfully). The two false claims ("Production identity accounts are not covered" and "No email address … is read") get corrected in the same follow-up.
2. **C2 (P2-2).** The follow-up removes or bounds the locking reads in the tier netting. At minimum it discloses the wait and the full-table lock scope, and it puts the unreadable and unverified counts into the CSV.

I do not agree with one of the three "surviving mutant" equivalence claims (M4, see P3-1).

## Findings

### P2-1: Customer segments include every `customer_accounts` row, including production identity accounts, and label them TEST

**Where:**
- `app/Domain/Reporting/CustomerSegments.php:118-119`: `population()` reads `DB::table('customer_accounts')` with no scope.
- `:31`: `ENVIRONMENT = 'TEST (synthetic accounts and orders)'`.
- `:107`: the audit context records `'environment' => 'test_synthetic'`.

**Contradicting claims:**
- `docs/staff-reporting.md:57`: "Production identity accounts are not covered".
- `CustomerSegments.php:52`.
- Packet design decision 5.

**Failure path:**
1. `app/Domain/Customers/ProductionIdentity/CompleteIdentity.php:100` inserts production customers into the same `customer_accounts` table, linked through `production_identity_origins.account_id`.
2. Once the production identity path and the segment flags are both on (staging or production), those real accounts are read.
3. They are counted in `accounts`, the consent table, `no_purchase` and (if eligible) `marketing_email_eligible`.
4. They are exported with their `public_id` under the label `TEST (synthetic accounts and orders)`, and the audit row says `test_synthetic`.

Personal data would be mislabelled as synthetic, and the audit trail would be false.

**Evidence:** reviewer probe `test_probe_segments_include_any_customer_account_labelled_test`, in the scratch copy only. It inserts a `customer_accounts` row the same way `CompleteIdentity` does (not via `CustomerAccounts::provision`). Result:
- `overview.accounts=1` with environment TEST.
- The export contains `6bb0e209-…,"TEST (synthetic accounts and orders)",no_purchase,1,unknown,0,,,,0,0,0`.

**Mitigation today:** the feature is default-off (all three flags false; the `=== true` check is verified).
- In production, `LocalConsentRuntime::grantsEnabled()` requires `admitsTestCommerce()`, so no grant verifies and the marketing segment stays empty.
- Purchase segments are unavailable there too.
- The `no_purchase` export, however, works with test commerce admitted (staging).

**Fix:** exclude accounts that have a `production_identity_origins` row (or include only accounts provisioned by `CustomerAccounts::provision`, audited `customer.test_account.provisioned`). Correct the docs either way.

### P2-2: The "read-only" sales report takes locking reads that wait on A5 writers, lock the whole allocation table, and on timeout silently re-label refunds as "not allocated"

**Where:**
- `app/Domain/Reporting/SalesReport.php:281-290` calls `RefundDisputeCase::load()` outside any transaction, for up to 200 refunded orders per render. The page renders again on every Livewire request, and an export renders the report twice.
- `RefundDisputeCase.php:74,77,79,82,86`: five `lockForUpdate()` statements per order.
- `refund_line_allocations` (migration `2026_10_24_264100_refund_dispute_lifecycle.php`) has no index on `order_id`.

**Native MySQL 8.4.11 evidence** (reviewer probe `ReviewLockProbeTest`, own mysqld):
- `EXPLAIN SELECT * FROM refund_line_allocations WHERE order_id=? FOR UPDATE` gives `type=ALL key=NULL`. Under REPEATABLE READ that statement locks every allocation row in the table (all orders) for the statement, and it waits on any order's in-flight allocation transaction.
- A second connection held `FOR UPDATE` on the order's allocation row, and the reader ran with `innodb_lock_wait_timeout=2`. Result: `LOCKED build took 2.07s tiers refunded=0 unattributed={"USD":122975} unreadable=1`. The baseline was `tiers refunded=122975 unattributed=[]`.
- A plain consistent read with the same lock held took 0.000 s.
- With the default 50 s timeout, a staff page load can hang 50 s per contended order. Each report statement also briefly blocks A5 allocation inserts for every order.

**Disclosure:** the packet and `docs/staff-reporting.md:71` disclose "momentary FOR UPDATE locks on refunded orders". They do not disclose the wait, the whole-table scope, or the mislabelling.

**Mislabelling:**
- On the page, the unreadable count is shown (`sales-report.blade.php:81-82`).
- In the CSV, it is not. The refund appears as an `unattributed_refund` row with the note "succeeded refund not allocated to a tier line" (`SalesReport.php:124-127`), which is false for an allocated refund that merely could not be read.
- Likewise, `unverified_orders` (paid orders missing from the tier table) is absent from the CSV.

Order-level totals stay correct; only the tier attribution is wrong.

**Fix options:**
- a non-locking read model for line refunds in A5 (its own reviewed change), or
- at least a `refund_line_allocations(order_id)` index, a disclosed lock wait, and `unreadable_refund_orders`/`unverified_orders` rows in the CSV with an accurate note.

### P3-1: Mutant M4 is not equivalent; exporter() trusts a retained model's enrollment when panel MFA is optional

`StaffReportingAuthority.php:35-38`: `exporter()` calls `$provider->isEnabled($current)` on the user returned by `staff()`.

Under M4 (`$current = $actor`, no re-read):
- The gate and `AdminMultiFactor` still re-read the row.
- But when MFA is not required (local/testing), `AdminMultiFactor` returns true without checking.
- `exporter()` then reads the enrollment from the stale model.

Reviewer probe `test_probe_exporter_rereads_enrollment_with_optional_mfa` passes at `ebdc9ca4` and **fails under M4**, while the whole `ReportingAuthorityTest` survives M4 (OK, 3 tests, 87 assertions).

The shipped code is correct, and the real entry points pass a per-request user from `Filament::auth()`, so the practical impact is nil. The claim "M4 equivalent" is wrong, though, and the regression test is missing. Add the probe as a test and correct the packet.

I agree with the other two:
- **M16:** the row-level consent assertion repeats the `members()` filter, and M15 kills the filter.
- **M23/M24:** confirmed surviving. With the widget `canView` or the page `boot`/`canAccess` removed, the read model's `staff()` still throws `AuthorizationException` from `getViewData()` (pages catch only `ReportingException`) and from the export, so every request is still 403 or refused.

### P3-2: Adversarial cases not covered by tests (surviving reviewer mutants; the code is correct)

| Mutant | Change | Result |
| --- | --- | --- |
| K4 | `CustomerSegments.php:126`: drop `f.outcome = 'paid'` (paid exceptions counted as purchases) | SURVIVED |
| K5 | `CustomerSegments.php:128`: refunds ignored (`refunded_purchasers` and `refunded_minor` untested; the fixture has no refund) | SURVIVED |
| K6 | `CustomerSegments.php:156`: inactive accounts admitted to the marketing segment | SURVIVED |
| K13 | `OperationalHealth.php:139`: exceptions already resolved by RefundResolution still counted as awaiting review | SURVIVED |
| K15 | `SalesReport.php:354`: `< end` to `<= end` (midnight boundary double count across adjacent periods) | SURVIVED |
| K7, K9 | consent `affirmative` and `event_account` checks removed | SURVIVED. Defence in depth: the consent write path and insert guards make these unreachable; acceptable. |

### P3-3: The production funnel sums live and rehearsal counts

`SalesReport.php:149-151,397-398`:
- `orders_prepared` counts every `production_checkout_orders` row.
- `payments_on_time` and `payments_after_expiry` sum across funds modes.

The CSV row is labelled `PRODUCTION CHAIN (all funds modes)`, and `ProductionSeparationReportTest` asserts exactly this (`orders_prepared` 4, `payments_on_time` 3, mixing live and test funds). These are counts, not money, and they are labelled. Still, they contradict "Test and production figures are never summed" (`docs/staff-reporting.md:40`, `SalesReport.php:23,42`). Split the funnel by funds mode before any live payment.

### P3-4: Net excludes dispute losses, and refunded paid exceptions stay in exception gross

- **Lost disputes:** net is gross minus succeeded refunds. Lost disputes (chargebacks) are shown on the dashboard but are not deducted from net and are not mentioned in the report notes. Nothing is double-counted.
- **Refunded paid exceptions:** a paid exception fully refunded through `RefundResolution` stays in `exception_gross_minor` with the note "payment received". Document both, or show them.

### P3-5: Doc and code-comment accuracy

- `CustomerConsentStatus.php:214` reads `users.email` to check the recipient binding. It is never exported, which is correct, but the packet line 52 ("No email address … is read for the export"), `CustomerSegments.php:13-14,53` and the page note claim nothing is read.
- The A5 FOR UPDATE disclosure is incomplete; see P2-2.

### P3-6: Query cost

Every render (and every Livewire request) runs full scans on unindexed columns:
- `order_finalizations.finalized_at` (acknowledged);
- `quotes.created_at` and `orders.created_at` (test funnel; quotes include anonymous visitors);
- `customer_inquiries.state` (dashboard widget).

The segments page also recomputes the whole population (up to 20,000 accounts plus the consent and purchase joins) on every `wire:model.live` segment change.

All of this is acceptable at current synthetic volume. Measure it before real data arrives.

### P3-7: CSV neutralisation edge cases (informational)

The leading characters `=`, `+`, `-`, `@`, tab and CR are neutralised, and integers pass through. The following are not neutralised:
- a leading space or LF before `=` (`" =1+1"`, `"\n=1+1"`);
- full-width `＝`.

The only free-text cell is the staff-entered license template name, and mainstream spreadsheets do not evaluate these forms, so no action is needed beyond noting it.

## Areas checked with no finding

**Authorization:**
- Every widget runs Filament's `bootCanAuthorizeAccess` on every request; its `hasAuthorizedAccess` is protected, so it is not persisted by Livewire. The widget then calls `staff()` in the read model.
- Pages run `boot()`, `canAccess` and `staff()` in the read model. Exports call `exporter()`, which needs an enrolled provider in every environment.
- Widgets have no polling. The sales page's `from`/`to`/`granularity` and the segments page's `segment` are client-settable but strictly validated. Actions have no other side effects.
- The tests cover guest, customer, unenrolled staff, authority removed after mount (`$refresh`), retained and phantom models, and HTTP routes.

**Money:**
- One finalization and one verified payment per order (unique keys), so nothing is double-counted.
- Order-level refunds come from the latest `observed` observation's `succeeded_refund_minor`, which `RefundDisputeState::evaluate` computes per snapshot from refunds whose status is `succeeded`; `attention` rows store null and are skipped.
- Tier refunds come from `refundedByLine()`, which counts only allocations whose latest status is `succeeded`.
- `RefundNettingReportTest` drives the real A5 flow through partial refund, allocation, a succeeded-then-failed reversal, a second refund, an open dispute, a lost dispute and a rejected observation.
- Currency is grouped everywhere. The production schema has no currency column and is USD by schema.
- `funds_mode` other than `live` falls into the rehearsal side (fails safe).

**Consent:**
- Unknown stays `unknown`, and any grant that fails a check is `granted_unverified` and excluded.
- The checks are stricter than or equal to the customer-facing read: notice and policy hash, version, recipient HMAC, grants runtime, and any suppression target for the account.
- The flag is off by default, and with it off nothing is read (the test asserts no `customer_|orders|order_finalizations` SQL).
- Every export writes its audit row before returning, and a refusal writes nothing.

**SQL:**
- Every grouped query selects only grouped columns or aggregates, period buckets are grouped by alias, and no Filament table is used.
- The only writes are `AuditEvent::recordAttributed` rows. `ReadOrder::verify`, `ConsentPolicy::configured` and `LocalConsentRuntime` do not write.
- The only locking reads are those in P2-2.

## Commands run and results

Scratch copy: `git worktree add --detach <scratchpad>/rep-scratch ebdc9ca4`, with `vendor` hard-linked (`cp -al`) from `/home/user/wt/rep`, `.env` copied from `.env.example`, and no `public/build`.

**SQLite.** The packet's direct-PHPUnit command (`APP_ENV=testing … DB_CONNECTION=sqlite DB_DATABASE=:memory: … PAO_DISABLE=1 php -d memory_limit=512M -r '…phpunit…' -- --colors=never --fail-on-phpunit-warning`), wrapped as `rep-review/sqlite.sh`.

| Command | Result |
| --- | --- |
| `tests/Feature/Reporting tests/Unit/ReportingCsvTest.php` at `ebdc9ca4` | OK (23 tests, 393 assertions), 2m03s. Matches the packet. |
| Reviewer probes `ReviewProbeTest` (P1 exporter re-read, P2 segment population, P3 CSV edges) | OK (3 tests); P2 output quoted above |

**Mutants**, one at a time with `rep-review/mutant.sh` (perl edit in the scratch copy, run, `git checkout`):

| Mutant | Result |
| --- | --- |
| M4 retained model vs `ReportingAuthorityTest` | SURVIVED (3 tests, 87 assertions) |
| M4 vs reviewer probe P1 | KILLED |
| M23 widget `canView` returns true | SURVIVED (Authority + OperationalHealth: 5 tests, 168 assertions) |
| M24 SalesReportPage `canAccess` true and `boot` removed | SURVIVED (4 tests, 133 assertions) |
| K1 latest observation of any outcome | KILLED |
| K2 test account scope removed | KILLED |
| K4, K5, K6, K7, K9 | SURVIVED (CustomerSegmentsTest: 2 tests, 109 assertions) |
| K8 grants runtime ignored | KILLED |
| K13 resolved exceptions counted | SURVIVED |
| K14 production tiers include paid exceptions | KILLED |
| K15 inclusive period end | SURVIVED |
| K16 marketing flag ignored on export | KILLED |
| K17 unattributed remainder dropped | KILLED |

**Safeguards:**

| Command | Result |
| --- | --- |
| `python3 scripts/ci/test-phpunit-shards.py` | Ran 52, OK |
| `python3 scripts/ci/test-ci-scope.py` | Ran 27, OK |
| `python3 scripts/ci/test-workflow-cadence.py` | Ran 14, OK |
| `python3 scripts/ci/test-focused-tests.py` | Ran 53, OK |
| `composer validate --strict` | valid |
| `python3 scripts/ci/phpunit-shards.py --shards=24 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection` | "Proved 2589 selected MySQL-native tests in 255 files (of 9797 discovered tests in 724 files)". Matches the packet. Generated files deleted. |

**Native MySQL 8.4.11.**
- Server: own `/opt/mysql-8.4.11/bin/mysqld --no-defaults --initialize-insecure`, datadir under `<scratchpad>/rep-mysql/data`, port 3491 (checked free with `ss -ltnp`), socket `/tmp/rv91/m.sock`, `--mysqlx=OFF`. sql_mode includes ONLY_FULL_GROUP_BY.
- Test environment: `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3491 DB_DATABASE=vaseyaudio_rv91 VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_rv91` (`rep-review/mysql.sh`). One file per run. The other lanes' mysqld processes (ports 3437, 3467) were not touched.

| File | Result |
| --- | --- |
| Reviewer `ReviewLockProbeTest` | OK (1 test, 3 assertions), 7m40s; output in P2-2 |
| SalesReportTest | OK (1 test, 46 assertions), 323 s |
| ProductionSeparationReportTest | OK (1 test, 26 assertions), 370 s |
| RefundNettingReportTest | OK (1 test, 31 assertions), 259 s |
| CustomerSegmentsTest | OK (2 tests, 109 assertions), 612 s |
| OperationalHealthTest | OK (2 tests, 81 assertions), 617 s |

The assertion counts match the packet's native table for these five files.

## What I could not test

- The full native run of `ReportingAuthorityTest` (10.9 min in the packet). It has no grouped SQL beyond what the other files exercise, so I skipped it for time.
- Browser and Playwright rendering of the dashboard and pages.
- Real data volume or query plans on large tables. Only the EXPLAIN in P2-2 was taken.
- The reverse lock direction (the report's autocommit FOR UPDATE stalling an A5 writer) was inferred from the full-scan plan, not measured.
- Real production identity enrollment: the P2-1 probe inserts the row shape directly, because building the identity schema was out of scope.
- Multi-currency beyond USD, since none can occur today.
- The MySQL 1055 mutant claimed in the packet was not re-run; the native passes above cover the shipped queries.

## Cleanup

The scratch worktree and the mysqld datadir were removed at the end, and only the mysqld PID this reviewer started was stopped (see PROGRESS.md).
