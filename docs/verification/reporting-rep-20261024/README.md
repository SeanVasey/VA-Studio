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
