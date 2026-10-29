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
   `RefundDisputeCase::load(...)->refundedByLine()` (never a raw sum of allocations, which would keep counting a reversed
   refund). Succeeded refunds with no line allocation are reported as "unallocated", not guessed into a tier.
3. **Test versus production.** Two sources, never summed: the retained test commerce chain (`order_finalizations.mode =
   'test'`, labelled TEST) and the production checkout chain (`production_checkout_*`), split by `funds_mode` (`live` is
   Production, `test` is a rehearsal with test funds, labelled). The A5 refund ledger exists for test orders only, so the
   production section shows gross only and says refunds are not recorded there. A section with no rows says so.
4. **Honest unavailability.** Each metric carries `ok` / `attention` / `unavailable`; an unreadable source (queue
   connection other than `database`, missing table, no recorded evidence) is shown as unavailable with the reason, never as zero.
5. **Customer segments.** First-party customer accounts and their test-chain orders only; production identity accounts are
   not covered (U-07/U-13). A customer is in a marketing segment only when `email_marketing` consent is verified granted:
   latest event granted, bound to the currently configured notice, recipient unchanged, grants runtime enabled, no
   suppression record. Everything else is `unknown`, `withdrawn` or `granted_unverified` and is excluded. The mechanism is
   default-off (`REPORTING_CUSTOMER_SEGMENTS_ENABLED`, `REPORTING_CUSTOMER_EXPORT_ENABLED`,
   `REPORTING_MARKETING_SEGMENT_ENABLED`). No email address, name or order content is read for the export.
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

## Final results (2026-10-29)

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
Survived (3, by design): M4 (retained user model: the gate and MFA helper re-read the user themselves, so equivalent), M16 (removes only
the redundant consent assertion on export rows; the primary filter remains and M15 kills it), M23/M24 (remove only the widget `canView`
or the page `boot`/`canAccess` layer; the read model still refuses, so these are defence in depth).

### Untested conditions
- Browser specs (Playwright) were not run; the admin dashboard now shows four more widgets.
- No provider, real data or real host; the production-section tests use hand-built rows with foreign keys off (shape-valid, not a real checkout journey).
- Multi-currency totals are grouped by currency but only USD can occur today, so only USD is exercised.
- `finalized_at` has no index; the period scan is unmeasured on a large table. `RefundDisputeCase::load()` takes momentary `FOR UPDATE`
  locks on refunded orders (at most 200 per report); a non-locking variant would touch the A5 domain.
- The customer export is deliberately contact-free; marketing use needs U-13 (processor, unsubscribe path) and is not built.
- Customer segments stay off (`REPORTING_*` flags) until U-13; FP-069 waits on U-11. Independent review of authority, refund netting and
  consent filtering is still required before merge.

### Commands to reproduce
SQLite: `env APP_ENV=testing APP_DEBUG=false APP_KEY=base64:$(head -c32 /dev/zero | base64) DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning tests/Feature/Reporting tests/Unit/ReportingCsvTest.php`
(a real `vendor/` for this lock file, no `public/build`). Native: the same with `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=<free> DB_SOCKET= DB_DATABASE=vaseyaudio_x DB_USERNAME=root DB_PASSWORD=<private> VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_x`
against a private MySQL 8.4 on its own datadir, one file per run. Selection: `python3 scripts/ci/phpunit-shards.py --shards=24 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`, then delete `phpunit-ci-mysql-*`.
