# Lane REP: reporting and operations views (FP-028, FP-089, FP-090)

Work package statement, committed before implementation (2026-10-24). Branch `harness/reporting-rep-20261024` from
`origin/main` `8f166c5a`. Read-only against commerce, customer, queue and media data; the only writes are append-only
audit rows for exports. Nothing here touches live payments, DNS, customer data import or a provider.

This file is updated at the end of the lane with the exact commands, results and untested conditions
(see "Results" below once present).

## Scope

| Row | Deliverable | Default |
| --- | --- | --- |
| FP-028 | Dashboard widgets for staff: queue backlog/age and failed jobs, Stripe receipt work backlog, payment exceptions awaiting review, refund/dispute attention (A5 tables), pending inquiries, media pipeline failures and quarantine, and a plain "unavailable" list for evidence the app does not record (scheduler last run, backup/restore) | on, staff + MFA |
| FP-089 | Staff sales and conversion report page with period filter and a CSV export: orders, gross paid, status-aware refunds and net per currency, license tier breakdown, conversion funnel; test commerce and production checkout never mixed | on, staff + MFA |
| FP-090 | Staff customer segmentation page and audited CSV export (pseudonymous reference, segment, consent label, purchase aggregates; no contact data) | **off** (U-13) |
| FP-069 | Collaborator revenue accounting | skipped (scope decision pending, U-11) |

## Files owned

New: `app/Domain/Reporting/*`, `app/Filament/Widgets/*`, `app/Filament/Pages/*` (Reporting pages),
`resources/views/filament/reporting/*`, `config/reporting.php`, `tests/Feature/Reporting/*`, this directory.
Edited (append-only): `CHANGELOG.md`, `.env.example`, `README.md` environment table,
`scripts/ci/database-mysql-selection.json` and the generated counts in `.github/workflows/final-verification.yml` and
`.gitlab-ci.yml`, `scripts/ci/database-sqlite-skips.json` only if a native-only test method is added (append-only census).
Not touched: `routes/web.php`, `bootstrap/app.php`, membership code and config (lane C4e), site content schema, mail,
customer data export/deletion routes (lane CX).

## Design decisions

1. **Authority.** `StaffReportingAuthority::staff()` re-reads the user and requires the `administer-catalog` gate and the
   admin panel's MFA rule (`AdminMultiFactor::satisfiedBy`, a fresh database read) on every call. Widgets, pages and
   actions call it from Livewire `boot()` (every request, including component updates) and again inside each export.
   `exporter()` additionally requires an enrolled MFA provider in every environment, because a personal-data export is
   not something a panel with optional MFA may do. Hiding a navigation item or a widget is never the control.
2. **Money.** Integer minor units with an explicit currency, grouped by currency. Gross is `verified_payments.amount_minor`.
   Refunds come only from the A5 ledger and are status-aware: order totals use the latest *observed* observation's
   `succeeded_refund_minor`, so a succeeded refund that later failed stops counting; license-tier refunds go through
   `RefundDisputeCase::load(..., forUpdate: false)->refundedByLine()` in a snapshot transaction, with no row locks (never a raw sum
   of allocations, which would keep counting a reversed refund). Succeeded refunds with no line allocation are reported as "unallocated", not guessed into a tier.
3. **Test versus production.** Two sources, never summed (the production funnel counts only orders prepared, labelled unsplit): the retained test commerce chain (`order_finalizations.mode =
   'test'`, labelled TEST) and the production checkout chain (`production_checkout_*`), split by `funds_mode` (`live` is
   Production, `test` is a rehearsal with test funds, labelled). The A5 refund ledger exists for test orders only, so the
   production section shows gross only and says refunds are not recorded there. A section with no rows says so.
4. **Honest unavailability.** Each metric carries `ok` / `attention` / `unavailable`; an unreadable source (queue
   connection other than `database`, missing table, no recorded evidence) is shown as unavailable with the reason, never as zero.
5. **Customer segments.** First-party customer accounts and their test-chain orders only; accounts created by the production
   identity path (a `production_identity_origins` row) are excluded (U-07/U-13). A customer is in a marketing segment only when `email_marketing` consent is verified granted:
   latest event granted, bound to the currently configured notice, recipient unchanged, grants runtime enabled, no
   suppression record. Everything else is `unknown`, `withdrawn` or `granted_unverified` and is excluded. The mechanism is
   default-off (`REPORTING_CUSTOMER_SEGMENTS_ENABLED`, `REPORTING_CUSTOMER_EXPORT_ENABLED`,
   `REPORTING_MARKETING_SEGMENT_ENABLED`). No email address, name or order content is exported; an account's email is read only to verify a grant's recipient binding.
6. **MySQL.** Every grouped query selects only grouped columns and aggregates; Filament tables are not used for grouped
   data (no default key sort); period bucketing uses a driver-aware expression selected as an alias and grouped by it.
7. **Exports** record one append-only `audit_events` row (actor, filters, row count, SHA-256 of the file) before the file is
   returned; a refused request writes nothing. CSV cells beginning `=`, `+`, `-`, `@`, tab or CR are neutralised.
8. **Bounds.** Period at most 400 days (day buckets at most 92), tier breakdown at most 5,000 finalized orders,
   refund line netting at most 200 refunded orders, exports at most 20,000 sales rows / 10,000 customer rows; over a bound the
   page says so instead of truncating silently.

## Acceptance criteria

- Every widget, page and export refuses a guest, a non-staff user, a staff user without the MFA the panel requires, and a
  user whose authority was removed after mount, including direct Livewire calls.
- Sums per currency are exact in minor units; refunds net correctly including a succeeded then failed reversal.
- Test and production figures are separated and labelled; an empty store shows empty states, not zeros presented as fact.
- Consent filtering excludes unknown, withdrawn and stale grants from the marketing segment; export audit rows exist.
- All grouped queries run on native MySQL 8.4.11 with `ONLY_FULL_GROUP_BY`.

## Tests

`tests/Feature/Reporting/*` (SQLite for logic, the files listed in `database-mysql-selection.json` on native MySQL), plus the four
CI safeguards and `composer validate --strict`. Mutation tests for the authority gates, refund netting and consent filter.

## Final results (2026-10-29), as first finished at `2162703c`; see "Results after the review fixes" below for the current figures

Supersedes the 2026-10-26 handoff status (the Codex handoff was cancelled and the lane finished here). Branch
`harness/reporting-rep-20261024`, merged head `2162703c` (merges `origin/main` `a8f17e5c`, which is `dd587603` plus docs-only handoff
files; nothing outside `docs/` and `CLAUDE.md` changed, and the regenerated native selection is unchanged: **2589 cases in 255 files of
9797 discovered**, comments already match). No frontend file is touched by this lane, so tsc and vitest were not run.

### Delivered
FP-028 (four dashboard widgets), FP-089 (sales and conversion report page, audited CSV) and FP-090 (customer segments page and audited
CSV, default-off, no contact data). FP-069 skipped (U-11). Design and limits: `docs/staff-reporting.md`. Code: `app/Domain/Reporting/*`,
`app/Filament/{Widgets,Pages,Reporting}/*`, `resources/views/filament/reporting/*`, `config/reporting.php`, tests
`tests/Feature/Reporting/*` and `tests/Unit/ReportingCsvTest.php`. `docs/remaining-parity-coverage.csv` statuses were not edited.

### Native MySQL 8.4.11 at merged head `2162703c`
Private `mysqld` (own datadir, port 3417, short socket, `VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_*`, ONLY_FULL_GROUP_BY on), each file run on
its own; the server was stopped and its datadir removed afterwards.

| File | Result | Time |
| --- | --- | --- |
| ReportingAuthorityTest | OK, 3 tests, 87 assertions | 10.9 min |
| OperationalHealthTest | OK, 2 tests, 81 assertions | 6.0 min |
| SalesReportTest | OK, 1 test, 46 assertions | 2.8 min |
| RefundNettingReportTest | OK, 1 test, 31 assertions | 2.7 min |
| ProductionSeparationReportTest | OK, 1 test, 26 assertions | 3.5 min |
| CustomerSegmentsTest | OK, 2 tests, 109 assertions | 8.4 min |

Earlier native runs found two MySQL-only defects that SQLite hid (SQL aliases `delayed` and `lines` are reserved words; fixed in
`5c94a0e8`) and two fixture issues (`customer_inquiries.request_key` is `char(36)`; MySQL JSON reorders object keys). A MySQL mutant
that adds an ungrouped `ORDER BY f.id` to the period query fails with error 1055 (SQLite accepts it).

### SQLite and safeguards at `2162703c`
- `tests/Feature/Reporting` plus `tests/Unit/ReportingCsvTest.php`: 23 tests, 393 assertions, OK.
- Regression sample (FoundationTest, LicensingAdminTest, StaffCommerceAdministrationTest, TestCommerceOperationsTest, CustomerIdentityHttpTest): 66 tests, 660 assertions, OK.
- `scripts/ci/test-phpunit-shards.py` (52), `test-ci-scope.py` (27), `test-workflow-cadence.py` (14), `test-focused-tests.py` (53): all OK. `composer validate --strict`: valid.
- The SQLite census is untouched: no reporting test skips on either engine.

### Mutation testing (SQLite, 25 mutants, one at a time, run at `bac4e9f3`; app code unchanged since)
Killed (22): staff authority without MFA or without the gate; exporter without enrolment; sales and segment export without an audit row;
tier refunds from raw allocation sums; order refunds counting every observation or ignoring status; paid exceptions counted as revenue;
rehearsal funds merged into live; consent: any grant verified, suppression ignored, recipient change ignored, notice binding ignored;
marketing segment admitting unknown; export ignoring the disabled flag; export adding an email column; unreadable queue shown as zero;
widget and page entry points unauthorized together with the read model; unscoped test account.
Survived (3, by design; the review showed M4 was not equivalent and it is now killed, see the resolution below): M4 (retained user model), M16 (removes only
the redundant consent assertion on export rows; the primary filter remains and M15 kills it), M23/M24 (remove only the widget `canView`
or the page `boot`/`canAccess` layer; the read model still refuses, so these are defence in depth).

### Untested conditions
- Browser specs (Playwright) were not run; the admin dashboard now shows four more widgets.
- No provider, real data or real host; the production-section tests use hand-built rows with foreign keys off (shape-valid, not a real checkout journey).
- Multi-currency totals are grouped by currency but only USD can occur today, so only USD is exercised.
- Several period and dashboard scans are unindexed and unmeasured on a large table (see `docs/staff-reporting.md`, Known limits).
- The customer export is deliberately contact-free; marketing use needs U-13 (processor, unsubscribe path) and is not built.
- Customer segments stay off (`REPORTING_*` flags) until U-13; FP-069 waits on U-11. Independent review of authority, refund netting and
  consent filtering is still required before merge.

### Commands to reproduce
SQLite: `env APP_ENV=testing APP_DEBUG=false APP_KEY=base64:$(head -c32 /dev/zero | base64) DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning tests/Feature/Reporting tests/Unit/ReportingCsvTest.php`
(a real `vendor/` for this lock file, no `public/build`). Native: the same with `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=<free> DB_SOCKET= DB_DATABASE=vaseyaudio_x DB_USERNAME=root DB_PASSWORD=<private> VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_x`
against a private MySQL 8.4 on its own datadir, one file per run. Selection: `python3 scripts/ci/phpunit-shards.py --shards=24 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`, then delete `phpunit-ci-mysql-*`.

## Independent review and resolution (2026-10-29)

Review of `ebdc9ca4`: [independent-rep-review-ebdc9ca4-DECISION.md](independent-rep-review-ebdc9ca4-DECISION.md), APPROVE WITH CONDITIONS, no
P0 or P1. All findings were fixed or documented before the PR; the fix commits are on this branch after `ebdc9ca4`
(`d5f29a1c` code and tests, `535ee73d` lock-probe correction, plus the docs commit that records the final results).

| Finding | Resolution |
| --- | --- |
| P2-2 report took `FOR UPDATE` reads (whole-table lock scope on MySQL, wait, silent mislabel) | Fixed. `RefundDisputeCase::load()` gained `bool $forUpdate = true` (writers unchanged); the report calls it with `forUpdate: false` inside a snapshot transaction. `SalesReportLockFreeTest` (every engine: no locking statement in a report or export; native MySQL: a second session holding the order's A5 ledger locks neither delays the report (under 1.5 s with a 2 s lock timeout) nor changes its figures, and `performance_schema.data_locks` shows no lock for the report's thread, with a positive control). Mutant L1 (locking again) is killed on native MySQL (on SQLite the grammar drops `FOR UPDATE`, so there it survives by construction). The CSV now has `tier_unverified_orders`, `tier_unreadable_refund_orders` and `tiers_unavailable` rows and an accurate unattributed note. The missing `refund_line_allocations(order_id)` index is a follow-up (no migration in this lane). |
| P2-1 segments read production identity accounts and labelled them TEST | Fixed. Accounts with a `production_identity_origins` row are excluded from the population, the purchase join and the caps. `CustomerSegmentsEdgeTest` inserts an account with an origin row and asserts it is absent from counts, consent distribution and every export. Docs corrected. |
| P3-1 M4 not equivalent | Fixed. `test_export_authority_rereads_enrollment_from_the_database_even_when_panel_mfa_is_optional` kills M4; the earlier claim that M4 is equivalent was wrong and is withdrawn. |
| P3-2 K4, K5, K6, K13, K15 | Tests added, all five killed (see mutants). No code defect was exposed. K7 and K9 stay surviving by design (the consent write path and insert guards make them unreachable). |
| P3-3 production funnel summed funds modes | Fixed. The production funnel now carries only `orders_prepared`, labelled as not split (an order has no funds mode until its payment intent); paid counts live inside each funds-mode section. |
| P3-4 net excludes lost disputes; refunded paid exceptions stay in exception gross | Documented on the page, in the CSV notes and in `docs/staff-reporting.md`; a test pins the refunded-exception behaviour (resolved through the real exception resolution flow). |
| P3-5 claim that no email is read | Corrected in the docs, code comments and page notes: the account email is read only to verify a grant's recipient binding and is never exported. |
| P3-6 unindexed scans, segments recompute per change | Deferred, documented under Known limits in `docs/staff-reporting.md`. |
| P3-7 CSV leading whitespace and newline before a formula character | Fixed in `ReportingCsv::cell()` (look past leading spaces and line feeds) with unit cases. Full-width formula characters remain un-neutralised (documented). |

### Results after the review fixes (2026-10-29, tested code `d5f29a1c`, lock probe corrected in `535ee73d`)

Native MySQL 8.4.11 (private mysqld, own datadir, port 3417, `ONLY_FULL_GROUP_BY`), one file per run:

| File | Result |
| --- | --- |
| SalesReportLockFreeTest (new) | OK, 1 test, 11 assertions |
| ReportingAuthorityTest | OK, 4 tests, 90 assertions |
| SalesReportTest | OK, 1 test, 47 assertions |
| CustomerSegmentsEdgeTest (new) | OK, 2 tests, 14 assertions |
| CustomerSegmentsTest | OK, 2 tests, 111 assertions |
| RefundNettingReportTest | OK, 1 test, 31 assertions (tiers through the non-locking read) |
| ProductionSeparationReportTest | OK, 1 test, 29 assertions |

`OperationalHealthTest` was not re-run natively: nothing it exercises changed since the passing run at `2162703c`. The first run of
the new lock test passed every native assertion (no locking SQL, no wait, unchanged figures, zero locks for the report's thread) and
failed only its positive control, which probed by transaction id; it now probes by thread and passes.

SQLite at `d5f29a1c`: `tests/Feature/Reporting` plus `tests/Unit/ReportingCsvTest.php` 31 tests, 426 assertions; the A5 suites
(`tests/Feature/RefundDispute`, `RefundDisputeStateTest`) 34 tests, 495 assertions, 4 skipped native census methods as before;
regression sample (FoundationTest, LicensingAdminTest, TestCommerceOperationsTest, CustomerIdentityHttpTest, CustomerRefundResolutionTest)
75 tests, 755 assertions. Safeguards 52, 27, 14, 53 OK; `composer validate --strict` valid. Native selection regenerated:
**2593 cases in 257 files of 9805 discovered** (two files and four selected cases added: SalesReportLockFreeTest, CustomerSegmentsEdgeTest,
plus one new case in ReportingAuthorityTest); the count comments in both CI files are updated. No census skip was added.

Mutants added for the review findings, SQLite, killed: M4 (retained model), K4 (paid exceptions as purchases), K5 (segment refunds
ignored), K6 (inactive account in marketing), K13 (resolved exceptions counted as awaiting), K15 (inclusive period end), P1 (production
accounts not excluded), C1 (leading whitespace in CSV), C2 (CSV omits the unreadable count). L1 (the report locks rows again) is
killed on native MySQL by `SalesReportLockFreeTest` (assertion on the executed SQL, run at `535ee73d`); on SQLite it survives because
Laravel's SQLite grammar emits no `FOR UPDATE`, so the native run is the proof.

Not re-run natively: the A5 writer suites. `RefundDisputeCase::load()` keeps its locking behaviour for every existing caller (the new
parameter defaults to true), and the A5 suites pass on SQLite, but a native A5 pass belongs to the PR's final verification.

## Re-review of the fixes at `622a4920`

Decision: **APPROVE** ([`independent-rep-rereview-622a4920-DECISION.md`](independent-rep-rereview-622a4920-DECISION.md)),
no new P0 to P2; both conditions of the first review are met. Native MySQL 8.4.11 on the reviewer's own server:
`SalesReportLockFreeTest` OK (1 test, 11 assertions), a mutant that restores the report's row locks (L1) killed, the A5
`RefundDisputeConcurrencyTest` OK (4 tests, 50 assertions), the A5 lifecycle writer cases OK (3 tests, 52 assertions) and
`RefundDisputeMigrationTest` OK (2 tests, 63 assertions), so the A5 writers keep their locking default. Mutants M4, K4, K5,
K6, K13, K15, the production-account exclusion, the CSV whitespace case and both CSV count rows are killed. Non-blocking
P3s: R-1 (exclude production accounts positively by marking test accounts, for the U-13 follow-up), R-2 (the per-order
snapshot transaction is untested defence), and a space followed by a tab before `=` is not neutralized.

## Follow-up batch FUB-1 (2026-10-31)

Branch `harness/followups-rep-cx-20261031` from `origin/main` `8bb1ab24` (PRs #178 and #183 merged). Deferred findings from this
lane's PR and reviews, each fixed with a regression test that fails without the fix, or left deferred with a reason. Ledger:
`docs/handoff/2026-10-07/codex-deferred-20261011.md` (2026-10-31 FUB-1 entry).

| Finding | Resolution | Commit | Test |
| --- | --- | --- | --- |
| Codex P2 r4418610921: the tier netting read each order's succeeded total from the period aggregate, before the per-order snapshot, so a refund recorded in between left a negative `unattributed_refund_minor` | Fixed. The succeeded total is the case's latest accepted observation, read inside the same snapshot transaction as `refundedByLine()`. A case whose line refunds exceed its succeeded total is counted as unreadable (its earlier succeeded total is shown as unattributed); a negative remainder is never added. | `75b913f5` | `SalesReportRefundSnapshotTest`: a refund and then a reversal recorded between the two reads; the inconsistent-case guard |
| Review R-2: the per-order snapshot transaction was untested | Fixed with a native-only test: a second MySQL session commits an allocation after the snapshot read the order's accepted observation and before it reads the allocations; the report keeps the earlier consistent view, and the next report attributes it. Census entry appended (main's 309 entries first). | `75b913f5`, `ff79e2c2` | `test_native_the_order_snapshot_does_not_see_an_allocation_committed_while_it_reads` |
| Codex P2 r4418610931: the page hid the unverified and unreadable counts and unattributed refunds whenever the tier table had no rows | Fixed. The table stays conditional on rows; the warnings render whenever the tier section is available. | `fa4ec1c8` | `SalesReportViewTest` (every order excluded, rows present, section unavailable) |
| Codex P2 r4419244931: a `jobs` row reserved longer ago than `retry_after` counted as running/ok | Fixed. New `queue_stalled` attention metric ("Jobs stalled past the retry window") using `queue.connections.database.retry_after` (connector default 60) and the queue's own expiry rule `reserved_at <= now - retry_after`; `queue_reserved` counts only reservations inside the window. | `5d3fdc31` | `OperationalHealthTest::test_a_reservation_older_than_the_configured_retry_window_is_a_stalled_attention_metric_not_a_running_job` |
| P3: a space followed by a tab before a formula character was not neutralised | Fixed. `ReportingCsv::cell()` skips every ASCII whitespace character (space, tab, LF, CR, NUL, VT, FF) before checking for a trigger. | `5002d79c` | `ReportingCsvTest` (seven new cases) |
| P3-6: unindexed period scans and no `refund_line_allocations(order_id)` index | Still deferred: needs a migration. | | |
| R-1: exclude production accounts positively by marking test accounts | Still deferred: needs a migration and the U-13 policy decision. | | |

`docs/staff-reporting.md` describes the snapshot rule, the warnings, the stalled metric and the whitespace rule.

### Mutants (one at a time, each killed)

| Mutant | Engine | Result |
| --- | --- | --- |
| M1: the original code (aggregate succeeded total, any non-zero remainder added) | SQLite | killed (negative unattributed amount) |
| M2: aggregate succeeded total, negative remainder dropped | SQLite | killed (the reversal phase shows a stale unattributed amount) |
| M3: no inconsistent-case guard | SQLite | killed |
| R2: the per-order snapshot transaction removed | native | killed: the report attributed the allocation committed mid-read (unattributed `[]`, tier refund 122975, expected the reverse) |
| V1: the original view (warnings inside the rows branch) | SQLite | killed |
| H1: running counts every reservation | SQLite | killed |
| H2: stalled uses `<` instead of `<=` | SQLite | killed |
| H3: a fixed 90-second window instead of the configured one | SQLite | killed |
| C1: the original whitespace set (space and LF only) | SQLite | killed (5 cases fail) |

### Results

Native MySQL 8.4.11 (private `mysqld`, own datadir, port 3491, `ONLY_FULL_GROUP_BY`, REPEATABLE READ default; stopped by
`mysqladmin shutdown` and the datadir removed afterwards), one file per run: `SalesReportRefundSnapshotTest` OK (3 tests, 17 assertions, at
`68d840d8`; its native method again at `ff79e2c2`: OK, 1 test, 4 assertions), `OperationalHealthTest` OK (3, 90), `RefundNettingReportTest` OK (1, 31),
`SalesReportLockFreeTest` OK (1, 11), `SalesReportTest` OK (1, 47), `SalesReportViewTest` OK (1, 11).
SQLite: `tests/Feature/Reporting` plus `tests/Unit/ReportingCsvTest.php` 43 tests, 466 assertions, 1 skipped (the native-only method).
Census: 310 entries (main's 309 first, one appended). Native selection regenerated: **2671 cases in 264 files of 9931 discovered**
(was 2665 in 263 of 9916); both count comments updated. Safeguards `test-phpunit-shards` 52, `test-ci-scope` 27,
`test-workflow-cadence` 14, `test-focused-tests` 53, `test-database-receipts` 61: OK. All 20 `tests/ops` files OK. `composer audit`:
no advisories; `composer validate --strict`: valid.

Not tested: browser specs, the full native selection, Foundation CI, and a real queue worker dying (the stalled metric is tested on
hand-written `jobs` rows).
