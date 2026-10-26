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

## Status at handoff (2026-10-26)

**Branch** `harness/reporting-rep-20261024`, not pushed, no PR. `origin/main` `dd587603` (PRs #173, #174) **is merged** (commit
`Merge origin/main (dd587603)`); the three conflicts (CHANGELOG, the two CI count comments) were resolved and the native selection
regenerated: **2589 cases in 255 files of 9797 discovered** (main alone 2579 in 249; the six reporting files add 10 cases).

### Done
FP-028 (four dashboard widgets), FP-089 (sales and conversion report page, audited CSV), FP-090 (customer segments page and audited
CSV, default-off). Design and limits: `docs/staff-reporting.md`. FP-069 skipped (U-11). Code: `app/Domain/Reporting/*`,
`app/Filament/{Widgets,Pages,Reporting}/*`, `resources/views/filament/reporting/*`, `config/reporting.php`, tests
`tests/Feature/Reporting/*` and `tests/Unit/ReportingCsvTest.php`. `docs/remaining-parity-coverage.csv` statuses were not edited;
the lead decides when to move FP-028/089/090.

### Evidence (private native MySQL 8.4.11, `VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_*`, ONLY_FULL_GROUP_BY on)
| File | Tests / assertions | Source |
| --- | --- | --- |
| ReportingAuthorityTest | 3 / 87, 16 min | app `5c94a0e8` |
| OperationalHealthTest | 2 / 81, 7.6 min | `50b28112` |
| SalesReportTest | 1 / 46, 4.6 min | `bac4e9f3` |
| RefundNettingReportTest | 1 / 31, 6 min | `5c94a0e8` |
| ProductionSeparationReportTest | 1 / 26, ~5 min | `ff294363` |
| CustomerSegmentsTest | 2 / 109, 13.5 min | `5c94a0e8` |

All pass. The first native run found two MySQL-only defects that SQLite hid (SQL aliases `delayed` and `lines` are reserved words;
fixed in `5c94a0e8`) and two test-fixture issues (`customer_inquiries.request_key` is `char(36)`; MySQL JSON reorders object keys).
A MySQL mutant that adds an ungrouped `ORDER BY f.id` to the period query fails with error 1055 (the SQLite run accepts it).
No source file under `app/` changed after those native runs; only merged-in main changes came after.

SQLite (direct PHPUnit, command in "Commands"): `tests/Feature/Reporting` + `ReportingCsvTest` 23 tests, 393 assertions at the merge
commit. Regression sample at `bac4e9f3`: FoundationTest, LicensingAdminTest, StaffCommerceAdministrationTest, TestCommerceOperationsTest,
CustomerIdentityHttpTest: 66 tests, 660 assertions, OK. Safeguards at the merge commit: test-phpunit-shards 52, test-ci-scope 27,
test-workflow-cadence 14, test-focused-tests 53 all OK; `composer validate --strict` valid.

### Mutation testing (SQLite, 25 mutants, one at a time)
Killed (22): staff authority without MFA, without the gate; exporter without enrolment; sales and segment export without audit row;
tier refunds from raw allocation sums; order refunds counting every observation or ignoring status; paid exceptions counted as revenue;
rehearsal funds merged into live; consent: any grant verified, suppression ignored, recipient change ignored, notice binding
ignored; marketing segment admitting unknown; export ignoring the disabled flag; export adding an email column; unreadable queue
shown as zero; widget and page entry points unauthorized together with the read model; unscoped test account.
Survived (3, by design): M4 (use the retained user model: the gate and MFA helper re-read the user themselves, so equivalent),
M16 (removes only the redundant consent assertion on export rows; the primary filter remains and M15 kills it), M23/M24 (remove only
the widget `canView` or the page `boot`/`canAccess` layer; the read model still refuses, so these are defence in depth).

### Unverified
- The merged tree was not re-run on native MySQL (only SQLite, safeguards and the selection script). App code is unchanged by the merge,
  but a new native pass of the six files is the proper acceptance.
- Browser specs (Playwright) were not run: the admin dashboard now shows four more widgets.
- No provider, real data or real host; production-section SQL runs on hand-built rows with foreign keys off (shape-valid, not a real journey).
- Multi-currency totals are grouped by currency but only USD can occur today, so only USD is exercised.
- `finalized_at` has no index; the period scan is unmeasured on a large table. `RefundDisputeCase::load()` takes momentary `FOR UPDATE`
  locks on refunded orders (max 200 per report); a non-locking variant would touch the A5 domain.
- The customer export is deliberately contact-free; marketing use needs U-13 (processor, unsubscribe path) and is not built.

### Next steps
1. Re-run natively (about 45 minutes for all six on a loaded host; start a private `mysqld` on your own datadir and a free port, never another lane's):
   `scripts/dev/with-mysql-test-server.sh` wraps one command, or set `DB_CONNECTION=mysql DB_DATABASE=vaseyaudio_x VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_x APP_ENV=testing` and run PHPUnit on each
   `tests/Feature/Reporting/*Test.php`. Run files separately: each test rebuilds the schema (about 1 to 4 minutes per case).
2. SQLite: `env APP_ENV=testing APP_DEBUG=false APP_KEY=base64:$(head -c32 /dev/zero | base64) DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning tests/Feature/Reporting tests/Unit/ReportingCsvTest.php`
   (needs a real `vendor/` for this lock file and no `public/build`).
3. If main moves again: merge, then `python3 scripts/ci/phpunit-shards.py --shards=24 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`,
   update the count comments in `.github/workflows/final-verification.yml` and `.gitlab-ci.yml` (currently 2589 cases in 255 files),
   delete `phpunit-ci-mysql-*`, rerun the four `scripts/ci/test-*.py` safeguards and `composer validate --strict`.
4. Independent review of authority, refund netting and consent filtering before merge (money, authorization, personal-data export).
5. Owner inputs that gate use: U-13 for enabling customer segments (`REPORTING_*` flags stay off); U-11 for FP-069.
