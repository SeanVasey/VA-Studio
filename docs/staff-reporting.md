# Staff reporting and operations views

Lane REP (FP-028, FP-089, FP-090). Read-only; the only writes are append-only `audit_events` rows for exports. Work package,
commands and results: [docs/verification/reporting-rep-20261024](verification/reporting-rep-20261024/README.md).

## Who can use it

Staff with the `administer-catalog` gate and the admin panel's MFA rule (required in staging and production). Every widget and
page re-reads the user on each Livewire request (`boot()` and Filament's widget `canView()`), and each read model and export
checks again. A hidden widget or navigation item is not the control. Exports additionally require an enrolled authenticator
app in every environment, including local and testing.

## Dashboard (FP-028)

Four widgets on the admin dashboard. Each metric is `OK`, `Needs attention` or `Unavailable`; a source the app cannot read is
never shown as zero.

| Section | Reads | Unavailable when |
| --- | --- | --- |
| Background work | database queue (ready, oldest age, running, stalled: reserved longer than the connection's `retry_after`), failed jobs, Stripe webhook receipt work | queue connection is not `database`, failed-job store is not a database store, table unreadable |
| Commerce attention (test commerce) | payment exceptions awaiting review, A5 refund/dispute attention, open and lost disputes, active holds, unallocated succeeded refunds, recorded refund reversals | outside `local`/`testing`/`staging`, Stripe test account not configured |
| Inquiries and media pipeline | inquiries in `new`, media runs failed, past their claim lease, queued, uploads in quarantine | table unreadable |
| Not recorded by the application | scheduler last run, backup and restore evidence, host health | always: the application stores none of this |

Thresholds are in `config/reporting.php` (`health`).

## Sales and conversion report (FP-089)

Admin panel, Reporting, Sales report. UTC periods (day or month buckets), at most 400 days (day buckets at most 92).
Integer minor units with an explicit currency, grouped by currency.

- **Test commerce** is labelled `TEST (synthetic, Stripe test mode)` and is not revenue. Gross is the verified payment amount
  including tax. Refunds are the latest accepted ledger observation's succeeded total, so a refund that succeeded and later
  failed stops counting. Per license tier, refunds come from `RefundDisputeCase::refundedByLine()` (status-aware); succeeded
  refunds with no line allocation are listed as not attributed rather than guessed into a tier. Refunds are netted against
  orders paid in the period, not dated by when the refund happened. Paid exceptions (payment received, no license granted) are
  reported apart from net; a paid exception later refunded through exception resolution stays in the exception gross (no refund
  amount is recorded for it). Net is gross minus succeeded refunds only: lost disputes (chargebacks) are not deducted and appear on
  the dashboard instead.
- **Production checkout** (`production_checkout_*`) is a separate section, split by funds mode: `PRODUCTION (live funds)` and
  `PRODUCTION CHAIN (test funds, rehearsal)`. USD, gross only: the refund ledger covers retained test orders only, and the
  page says so. Test and production figures are never summed.
- **Funnel**: quotes, orders prepared, paid, paid exceptions, released unpaid; events in the period (not a cohort), no bot or
  replay filter. The production funnel counts only orders prepared (an order has no funds mode until its payment intent) and says
  so; paid counts stay inside each funds-mode section, so live and rehearsal figures are never summed.
- **Not included**: membership billing, services, merchandise, the tax-calculated production checkout (`production_tax_checkout_*`)
  and collaborator revenue accounting (FP-069, scope pending U-11).
- **Bounds**: the tier table needs at most 5,000 paid orders in the period and 200 refunded orders; beyond that the page says it
  is unavailable and shows the exact order-level totals. Period filters on `order_finalizations.finalized_at` have no index; the
  scan is bounded by the period and acceptable at current volumes, and an index would be a schema change outside this lane.
- **No row locks**: the tier netting reads the A5 case through `RefundDisputeCase::load(..., forUpdate: false)` inside a snapshot
  transaction (plain consistent reads), and takes the order's succeeded total from that same snapshot, so a refund recorded after
  the period totals were read cannot leave a negative unattributed amount; a case that attributes more than its succeeded total is
  counted as unreadable. Because the period totals and each order's snapshot are separate reads, the tier refunded and unattributed
  totals on one page or CSV can be newer than (and add up to more than) the order-level refunded total when a refund lands between
  them; they are never negative and never drop a refund. It never waits on or blocks an A5 writer; `SalesReportLockFreeTest` proves it on native MySQL
  with a writer holding the order's ledger locks and a `performance_schema.data_locks` probe. Writers keep the default `forUpdate: true`.
- **CSV export**: one flat file with an `environment` column on every row; one audit row (actor, period, row count, SHA-256 of
  the file). Tier data carries the same caveats as the page: `tier_unverified_orders` and `tier_unreadable_refund_orders` rows,
  and a `tiers_unavailable` row with the reason when the caps apply. The page shows the same counts and unattributed refunds
  whenever the tier section is available, even when every paid order is excluded from the tier table. Cells that start like a
  spreadsheet formula, including after any mix of leading ASCII whitespace (spaces, tabs, line breaks), are prefixed with an apostrophe.

## Customer segments (FP-090), default-off

Off until the owner decides retention, consent purposes and processors (U-13). Flags in `config/reporting.php` and
`.env.example`: `REPORTING_CUSTOMER_SEGMENTS_ENABLED` (page and counts), `REPORTING_CUSTOMER_EXPORT_ENABLED` (export),
`REPORTING_MARKETING_SEGMENT_ENABLED` (the marketing segment). With the first flag off no customer table is read.

- Scope: first-party customer accounts and their retained test-chain orders. Accounts created by the production identity path
  (those with a `production_identity_origins` row) are excluded; no segment over production customers exists until U-07 and U-13.
- The screen shows counts and consent labels, never rows. Rows leave only through the audited export.
- Export fields: pseudonymous account reference, environment, segment, active flag, consent label, order count, first and last
  order date (UTC), currency, paid, refunded and net minor units. No email address, name, access key or order identifier is exported. An account's email is read, never exported, only to verify
  a consent grant's recipient binding.
- Consent labels: `unknown` (no recorded choice), `withdrawn`, `granted` (verified) and `granted_unverified`. A grant is verified
  only when it is the latest choice, bound to the currently configured notice, captured for the account's current recipient,
  grants are enabled in the runtime and no suppression record exists. Only verified grants enter the marketing segment;
  every other state, including unknown, is excluded. A purchase or import never implies consent.
- Segments: purchasers, repeat purchasers, purchasers with a succeeded refund, active accounts without a paid order, and
  marketing email: verified consent granted.

## Known limits

- No contact export: sending marketing needs an owner-approved processor and an unsubscribe path (U-13); this lane builds neither.
- Multi-currency totals are grouped by currency but the current system accepts USD only, so only USD is exercised.
- Follow-ups, not done here (a schema change or A5 change each): `refund_line_allocations` has no `order_id` index, so A5 writers'
  own locking reads scan the whole table on MySQL; `order_finalizations.finalized_at`, `quotes.created_at`, `orders.created_at`
  and `customer_inquiries.state` scans are unindexed and unmeasured at volume; the segments page recomputes the whole population
  on each segment change; full-width formula characters (such as U+FF1D) are not neutralised in CSV cells (mainstream spreadsheets do
  not evaluate them).
