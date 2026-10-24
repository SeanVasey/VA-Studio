# BeatStars history archive, delta capture and exclusivity authority

**Implemented scope (FP-098 extension, FP-099, FP-101; lane MIG, 2026-10-24):** tooling to dry-run, archive and
reconcile historical BeatStars orders, order lines, customers and original contract files; to capture the delta
between a baseline export and a later export; and to record which single system may sell exclusives during cutover.
It was built and tested only against the hand-made synthetic packages in
`tests/Fixtures/migration/history-synthetic/`. No real export (input S-9) has been inspected, so the shape of the
real files, the mapping Sean writes for them and the tool's behaviour on them are unproven.

What it never does: create or change a customer account, order, grant, entitlement, consent, offer, download or
inventory row; regenerate, render or substitute contract text; infer marketing consent; merge customers; revoke a
prior grant; run outside `local`/`testing` (unless a reviewed code change names another environment); or write
anything without `--write`, an attested staff console and the dry run's snapshot hash. Importing customers into
active entitlements is outside this tool and needs Sean's authorization (AGENTS.md release boundary).

## Source package

A package is one private directory holding exactly these files, each a caller-owned `0600` single-link file, in a
caller-owned `0700` directory inside another `0700` directory, outside any Git working tree (the
`PrivateSourceFiles` boundary used by the catalog dry run):

| File | Content |
| --- | --- |
| `mapping.json` | `vasey-beatstars-history-mapping-v1`: acquisition attestation plus every column, status value, license name and contract media type (below). |
| `customers.csv` | One row per BeatStars customer: id, e-mail, optional display name. |
| `orders.csv` | One row per order line: order id, line id, customer id, UTC order time, status, track id, license name, amount, refunded amount, optional payment reference and currency. |
| `contracts.csv` | One row per original contract: contract id, order id, line id, file name, optional declared SHA-256. |
| `contracts/` | The original contract files, byte for byte as BeatStars generated them. |

CSV rules are the catalog normalizer's (`BeatStarsExportSheet`): UTF-8, strict RFC 4180 quoting, one header line,
at most 1,000 data rows and 200 columns per sheet (split a larger export into several packages), 8 MiB per sheet.
Contract files: at most 2,000, 16 MiB each, 256 MiB in all. Only a package whose mapping declares
`synthetic_fixture` may be read from this checkout, and only from `tests/Fixtures/`.

### Mapping

```json
{
    "schema_version": 1,
    "purpose": "vasey-beatstars-history-mapping-v1",
    "snapshot_id": "beatstars-history-2026-11-xx",
    "source_system": "beatstars/pro-page:<account reference>",
    "operator_reference": "who exported it, from where, and when",
    "acquisition_method": "official_export",
    "acquired_at": "2026-11-01T18:00:00Z",
    "source_as_of": "2026-11-01T17:30:00Z",
    "currency": "USD",
    "customers": {"columns": {"source_customer_id": "Customer ID", "email": "Email", "display_name": "Name"},
                  "ignored_columns": ["Marketing Opt-In"]},
    "orders": {"columns": {"source_order_id": "Order ID", "source_line_id": "Line ID", "source_customer_id": "Customer ID",
                           "ordered_at": "Ordered At", "status": "Status", "track_source_id": "Track ID", "license": "License",
                           "amount": "Amount", "refunded_amount": "Refunded", "payment_reference": "Payment Ref", "currency": "Currency"},
               "status_values": {"Completed": "paid", "Refunded": "refunded", "Partially Refunded": "partially_refunded", "Pending": "unpaid"}},
    "contracts": {"columns": {"source_contract_id": "Contract ID", "source_order_id": "Order ID", "source_line_id": "Line ID",
                              "file_name": "File", "declared_sha256": "SHA-256"},
                  "media_types": {"pdf": "application/pdf", "txt": "text/plain"}},
    "licenses": {"Basic Lease": {"label": "basic-lease", "exclusive": false},
                 "Exclusive Rights": {"label": "exclusive-rights", "exclusive": true}}
}
```

Every header in a sheet must be mapped or listed in that sheet's `ignored_columns`. There is no consent field: a
consent or opt-in column can only be ignored, its values are not retained (only the raw row hash covers them), and
every customer record says `marketing_consent: unknown`. Statuses map to `paid`, `refunded`, `partially_refunded`,
`unpaid` or an explicitly declared `unknown`. Whether a license is exclusive is the operator's declaration. Amounts are
USD only and become integer cents (`12`, `12.5`, `$12.50`); `1,299.00`, negatives and words are not numbers.

## Commands

All output is one canonical JSON line of counts, codes, record keys and hashes: no e-mail address, name, payment
reference, path or contract text. Exit codes: `0` clean/complete/reconciled, `1` refused or failed, `2` import
segment committed with more pending, `3` findings or unexplained differences.

```sh
php artisan vasey:legacy-history dry-run   --source /private/history/2026-11-01
php artisan vasey:legacy-history import    --source /private/history/2026-11-01          # still a dry run
php artisan vasey:legacy-history import    --source /private/history/2026-11-01 --write --attest \
    --actor-id 7 --snapshot <snapshot_sha256 from the dry run> --limit 100                # repeat until exit 0
php artisan vasey:legacy-history reconcile --source /private/history/2026-11-01
php artisan vasey:legacy-history delta     --source /private/history/2026-11-01 --later /private/history/2026-12-01 \
    [--dispositions /private/history/dispositions.json]
php artisan vasey:legacy-history review-grant --line <line record key>                    # eligibility only
php artisan vasey:legacy-history review-grant --line <key> --write --attest --actor-id 9 --reference "<private evidence pointer>"
php artisan vasey:exclusivity-authority status
php artisan vasey:exclusivity-authority freeze --actor-id 7 [--write --attest --reference "<where the freeze is documented>"]
php artisan vasey:exclusivity-authority handover --actor-id 7 --baseline <previous package> --later <post-freeze package> \
    [--dispositions <file>] [--write --attest --reference "<...>"]
```

Every write confirms the staff account's password at a hidden prompt (interactive console only) and records a
`staff.console_attestation` audit row first; `--attest` is required in every environment for these commands.

### Dry run and findings

The dry run normalizes the package into a `vasey-legacy-history-v1` snapshot and prints its SHA-256, per-cohort
counts, findings, recorded exceptions, the plan against the archive (records to create or skip) and how many lines
could be put to review. Any finding withholds the snapshot and blocks import; nothing is defaulted.

| Sheet | Findings |
| --- | --- |
| any | `unmapped_column`, `mapped_column_missing`, `blank_record`, `column_count_mismatch`, `missing_source_id`, `invalid_source_id`, `synthetic_identity_required`, `duplicate_source_id` |
| customers | `invalid_email`, `invalid_display_name` |
| orders | `unknown_customer_reference`, `invalid_ordered_at`, `ordered_after_watermark`, `unknown_status_value`, `unknown_license_name`, `invalid_amount`, `invalid_refunded_amount`, `refund_exceeds_amount`, `currency_mismatch`, `invalid_payment_reference`, `order_inconsistent` (order-level cells differ between its lines), `refund_status_mismatch` |
| contracts | `unknown_line_reference`, `invalid_file_name` (path components refused), `unknown_media_type`, `duplicate_contract_file`, `invalid_declared_sha256`, `contract_hash_mismatch` (the file's bytes do not match the declared hash: tampered or substituted), `unreferenced_contract_file` |

Recorded exceptions are facts of the source, preserved as archive-only history; they never withhold a record:
`contract_original_missing` (a declared original was not supplied; nothing is generated in its place),
`line_without_contract`, `ambiguous_customer_identity` (two source customers share an address; never merged) and
`duplicate_exclusive_sale` (an exclusive sold more than once and not fully refunded; every buyer's record is kept and
nothing is revoked).

Refusals (exit `1`, nothing read past the failure, nothing written) include `environment_refused`,
`environment_ambiguous`, `source_not_private`, `source_inside_repository`, `source_path`, `<sheet>_csv_quotes` and
the other `<sheet>_*` CSV codes, `mapping_*`, `snapshot_mismatch`, `attestation_required`,
`interactive_console_required`, `actor_refused` and `synthetic_source_in_production`.

### Import, restart and reconciliation

`import --write` archives at most `--limit` records per run in one transaction under the batch row lock: customers,
then orders, lines and contracts. A record already archived with the same key and hash (by any batch) is skipped; a
changed record from a later export becomes a new immutable version beside the old one. An interrupted run rolls its
segment back; re-run the same command to resume. Originals are stored unchanged on the private disk at
`legacy-history/contracts/<aa>/<sha256>` and re-read to verify their hash; customer, order, line and contract records
are stored encrypted with the application key, with only ids, hashes, money, status and an e-mail HMAC in clear
columns. Every row is immutable in SQL on SQLite and MySQL; consent other than `unknown` and a line classification
other than `archive_only` are refused by insert guards.

`reconcile` compares the snapshot with the archive: per cohort expected, matched, missing and unexpected records;
every stored original re-hashed; money totals per currency from the stored columns; and every stored record's
encrypted copy decrypted and proved against its hash. Recorded exceptions are listed as explained. Anything else is
unexplained, and the result is `reconciled` only with zero unexplained differences.

### Historical grants: `archive_only` and `verified_grant`

Every archived line is `archive_only`: kept for history, with no download right. `review-grant --write` lets a staff
reviewer who did not import any of the evidence classify one line as `verified_grant`, only when the stored archive
still proves it: order `paid`, nothing refunded, a payment reference, an unambiguous buyer, exactly one stored original
contract whose bytes match their hash, and no competing unrefunded sale of the same exclusive. The review is one
immutable row bound to that exact line version and its evidence hashes. `verified_grant` is still not an entitlement:
nothing reads it to grant a download. Turning verified historical grants into active entitlements is a separate,
reviewed import that needs Sean's authorization.

## Delta capture (runbook §7)

`delta` compares a baseline package with a later package of the same source system (the later watermark must be
newer). New records, refunds (status moves forward and the refunded amount grows), completed payments, restated raw
rows and changed customer contact details are captured automatically. Removed records, changed amounts, tracks,
licenses, buyers or dates, changed original contract bytes and any change to an exclusive line other than its refund
are blocking. A blocking item is explained only by an operator dispositions file bound to both snapshot hashes:

```json
{"schema_version": 1, "purpose": "vasey-legacy-history-delta-dispositions-v1",
 "baseline_snapshot_sha256": "<from the delta report>", "later_snapshot_sha256": "<from the delta report>",
 "items": {"order:<record key>": {"disposition": "retained_prior_record", "reason": "owner-confirmed purge of a refunded test order"}}}
```

Dispositions are `accepted_source_correction`, `retained_prior_record` or `excluded_with_owner_approval`. A
disposition for an item that is not currently blocking is refused (`dispositions_unknown_item`), so stale files
cannot hide a later change. The report lists exclusive sales and refunds captured in the interval and money totals
per currency; `result` is `reconciled` only with zero unexplained items. Archive the later package with `import` so
every post-baseline sale is preserved.

## Exclusivity authority (runbook §7, §10)

The ledger is append-only; at most one authority sells exclusives at any moment:

| Transition | From → to | Admitted when |
| --- | --- | --- |
| `declare` | (empty) → `beatstars` | The ledger is empty. |
| `freeze` | `beatstars` → `frozen` | BeatStars exclusive sales are stopped first (the reference points to the evidence). |
| `handover` | `frozen` → `vasey_store` | The later package's watermark is at or after the freeze; the baseline is the latest archived export before it; both packages are fully archived and reconcile; their delta reconciles (with dispositions where needed). |
| `revert` | `vasey_store` → `frozen` | Rollback starts by stopping the store; sold exclusives stay sold. |
| `unfreeze` | `frozen` → `beatstars` | Only for a freeze that directly followed BeatStars. After a store period it is refused (`source_resale_unproven`): reopening BeatStars first needs proof it cannot resell what the store sold. |

Anything else is refused: `authority_already_declared`, `authority_not_declared`, `freeze_requires_beatstars`,
`handover_requires_freeze`, `second_authority_refused`, `revert_requires_store`, `unfreeze_requires_freeze`, plus the
handover evidence codes (`baseline_not_archived`, `later_not_archived`, `baseline_not_reconciled`,
`later_not_reconciled`, `later_export_precedes_freeze`, `baseline_not_latest`, `delta_unreconciled`). Concurrent
transitions serialize on the ledger head (`SELECT … FOR UPDATE`) and the unique sequence number; the loser is refused
(`authority_changed_concurrently` or the transition code it then sees), proven with two independent MySQL processes.

`ExclusivityAuthority::storeMaySellExclusives()` is true only while `vasey_store` holds the authority. **The store's
exclusive activation and checkout do not consult it yet**: wiring it in touches checkout and inventory files owned by
the production-checkout/activation lanes and is listed as remaining work. Until then the ledger is the cutover record
and rehearsal tool, not an enforced checkout gate.

## Verification

Focused development checks, not acceptance: `docs/verification/migration-tooling-20261024/README.md` records the
exact commands, counts, the native MySQL run, mutation results and the untested conditions.
