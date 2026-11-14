# BeatStars history archive, delta capture and exclusivity authority

**Implemented scope (FP-098 extension, FP-099, FP-101; lane MIG, 2026-10-24):** tooling to dry-run, archive and
reconcile historical BeatStars orders, order lines, customers and original contract files; to capture the delta
between a baseline export and a later export; and to record which single system may sell exclusives during cutover.
It was built and tested only against the hand-made synthetic packages in
`tests/Fixtures/migration/history-synthetic/`. No real export (input S-9) has been inspected, so the shape of the
real files, the mapping Sean writes for them and the tool's behaviour on them are unproven.

**BL-07 (FP-098, FP-099; 2026-11-10), focused synthetic checks, not acceptance:** two additions, also built and
tested only on synthetic fixtures (`tests/Fixtures/migration/history-synthetic/families/`). Adapters archive kit,
service and membership order lines in the same line record as track lines ("Product-family adapters"), and an
append-only table links archived track lines to store tracks through `catalog_import_mappings` ("Track links").
Both are archive-only: no entitlement, grant, active order, subscription, credit or customer account is created,
and nothing reads a link or a family line to grant anything. The real export's family sheets and ids are unknown
(S-9), and a link names a store track, not an asset revision.

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

Optional, and read only when `mapping.json` declares the family under `product_families`: `kit_orders.csv`,
`service_orders.csv` and `membership_orders.csv`. A declared sheet must be present; a family sheet the mapping does
not declare refuses the package (`source_undeclared_family_sheet`), so history is never left out silently.

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

### Product-family adapters (kits, services, memberships)

The history schema itself names no product family: its line record is a track license line. `ProductFamilyAdapter`
adds the three non-track families the cutover runbook inventories, without changing that record. A package that
declares no family normalizes to exactly the same bytes and snapshot hash as before (pinned in
`ProductFamilyAdapterTest`).

```json
"product_families": {
    "kits": {"columns": {"source_order_id": "Order ID", "source_line_id": "Line ID", "source_customer_id": "Customer ID",
                         "ordered_at": "Ordered At", "status": "Status", "product_source_id": "Kit ID", "terms": "Kit License",
                         "amount": "Amount", "refunded_amount": "Refunded", "payment_reference": "Payment Ref", "currency": "Currency"},
             "ignored_columns": [],
             "terms": {"Sound Kit License": {"label": "sound-kit-license", "exclusive": false}}},
    "services": {"columns": {"…": "…"}, "terms": {"Mixing Package": {"label": "mixing-package", "exclusive": false}}},
    "memberships": {"columns": {"…": "…"}, "ignored_columns": ["Period Start", "Period End"],
                    "terms": {"Pro Monthly": {"label": "pro-monthly", "exclusive": false}}}
}
```

| Family | Sheet (findings name it) | Stored `track_source_id` | `terms` column means |
| --- | --- | --- | --- |
| `kits` | `kit_orders` | `kit:<Kit ID>` | the kit's license name |
| `services` | `service_orders` | `service:<Service ID>` | the service package |
| `memberships` | `membership_orders` | `membership:<Plan ID>` | the plan; one row per charge |

- **Same line shape.** A family row becomes an ordinary order line: same fields, record key, hash, table and
  `archive_only` classification. `product_source_id` fills `track_source_id` under the family's namespace and `terms`
  fills `license_name`/`license_label`. Status values are the orders sheet's `status_values`; amounts, currency,
  customer, time and payment reference follow the same rules and findings. A family line may share an order with
  track lines; order-level cells must agree across sheets (`order_inconsistent`) and a line id is unique within its
  order across sheets (`duplicate_source_id`). Line identity is order id plus line id across every sheet, so a real
  export whose family sheets number lines independently inside a shared order (kit line 1 beside track line 1) is
  refused as `duplicate_source_id`. That refusal is fail-closed and has no mapping-level remedy yet.
- **Consumers must check the family.** `legacy_history_lines.track_source_id` holds family ids too. Any reader must
  call `ProductFamilyAdapter::familyOf()` on it and treat it as a track id only when that returns `null`.
- **Never a track.** A kit whose raw id equals a track's id is stored as `kit:<id>`, and a track id inside `kit:`,
  `service:` or `membership:` on the orders sheet is a finding (`reserved_family_namespace`), so the two cannot be
  confused. Track linking skips family lines.
- **Nothing is defaulted.** Every terms value must be declared (`unknown_terms_value` otherwise). `exclusive` must be
  declared `false`: the exclusivity ledger is about track licenses, and an exclusive family sale is not supported
  (`mapping_family_exclusive`). Other mapping refusals: `mapping_product_families`, `mapping_family_terms`.
- **History only.** A membership line records one past charge, not a subscription, paid period or credit; a service
  line records one past payment, not an open job, deposit or milestone. Those obligations (M-030, M-032, U-012,
  U-013) are not modelled here. Ignored columns such as notes or billing periods are not retained (only the raw row
  hash covers them). No family line is reviewable: the dry run and `review-grant` report
  `product_family_not_reviewable`, so it can never become a `verified_grant`.

## Commands

All output is one canonical JSON line of counts, codes, record keys and hashes: no e-mail address, name, payment
reference, path or contract text. Findings name the sheet row and `source_id_sha256` (never the id), an unreferenced
contract file appears only as `file_sha256:<hash of its name>`, and a delta disposition's reason appears only as
`reason_sha256`. Unmapped columns are reported by their header text, which comes from the export's header row. Exit codes: `0` clean/complete/reconciled, `1` refused or failed, `2` import
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
php artisan vasey:legacy-history link-tracks --source /private/history/2026-11-01                             # dry run
php artisan vasey:legacy-history link-tracks --source /private/history/2026-11-01 --write --attest \
    --actor-id 7 --snapshot <snapshot_sha256> --limit 100                                 # repeat until exit 0
php artisan vasey:legacy-history resolve-track-link --line <line record key>              # state and candidates only
php artisan vasey:legacy-history resolve-track-link --line <key> --mapping <catalog import mapping id> \
    --write --attest --actor-id 9 --reference "<private evidence pointer>"
php artisan vasey:exclusivity-authority status
php artisan vasey:exclusivity-authority freeze --actor-id 7 [--write --attest --reference "<where the freeze is documented>"]
php artisan vasey:exclusivity-authority handover --actor-id 7 --baseline <previous package> --later <post-freeze package> \
    [--dispositions <file>] [--write --attest --reference "<...>"]
```

Every write confirms the staff account's password at a hidden prompt (interactive console only) and records a
`staff.console_attestation` audit row first; `--attest` is required in every environment for these commands. The
domain services enforce it again inside the write transaction (review condition C4): the actor's staff authority is
re-read under lock, the admin panel's staff MFA rule applies wherever the panel requires it (`StaffWriteAuthority::require`),
and the attestation must be recorded, belong to the actor, be at most 900 seconds old and be bound to the exact write
(import: `legacy-history.import` on the snapshot hash; review: `legacy-history.review-grant` on the line and reference;
ledger: `exclusivity-authority.<transition>` on the reference). Each write spends its attestation
(`StaffWriteAuthority::requireFor`), so one console invocation admits one segment, review or transition.
`requireFor` refuses outside `local`, `testing` and `staging`, so admitting production needs a separate design decision.

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

### Track links: linked, unlinked, ambiguous

`legacy_history_track_links` holds at most one immutable row per archived line version, naming the catalog import
mapping and the store track for the line's `track_source_id`. `reconcile` prints a `track_links` report beside the
archive report (it never changes `reconciled`/`unreconciled`), and `link-tracks` without `--write` prints the same
report: counts per state, every track line's state by record key, and, for lines that need a person, the candidate
mapping and track ids. If the link report itself is refused (`catalog_mapping_integrity`, `track_link_integrity` or
`archive_integrity`), `reconcile` still prints the unchanged archive result and exit code with
`track_links: {"refused": "<reason>"}`; `link-tracks` and `resolve-track-link` refuse outright.

A **candidate** is every `catalog_import_mappings` row whose retained source record carries the line's
`track_source_id` as its source id, in any catalog source system. A mapping whose encrypted evidence cannot be read
and proved against its record key refuses the whole read (`catalog_mapping_integrity`), because it could hide a
candidate.

| State | Meaning |
| --- | --- |
| `linked` | A link row exists (`linked_by`: `exact_source_identity` or `staff_resolution`). |
| `ambiguous` | More than one candidate. It stays ambiguous until staff resolve it. |
| `unlinked` | No link and at most one candidate: `no_candidate`, `exact_candidate_pending` (one candidate in the line's own source system, not linked yet) or `other_source_system_candidate` (one candidate, but from another source system). |

- **Never a guess.** `link-tracks --write` records only `exact_candidate_pending` lines: same source system and same
  source id is the identity the catalog importer itself used. Matching by title, slug or a different source system
  is never done. A single candidate from another source system proves no identity and waits for staff, like an
  ambiguous line.
- **Restartable and idempotent.** Links follow a completely archived snapshot (`import_incomplete` otherwise). Each
  segment is one transaction under the batch row lock, at most `--limit` links, with a
  `legacy_history.track_link_segment` audit event; an interrupted segment rolls back, and re-running the import
  batch or the link segment creates no second row (unique `line_id`).
- **Staff resolution.** `resolve-track-link --write` records one staff choice for the line's current version: the
  mapping must be one of that line's candidates (`candidate_refused`), the line must need a choice
  (`resolution_not_required`, `no_candidate`, `not_a_track_line`), and the write needs the same staff authority, MFA
  rule and spent console attestation as every other write here, bound to the line, the mapping and the reference.
  It stores the reference only as a keyed hash and writes a `legacy_history.track_link_resolved` audit event. The
  same choice again is a no-op; a different one is refused (`already_linked`).
- **Append-only.** UPDATE, DELETE and REPLACE are refused by triggers on SQLite and MySQL; an insert guard admits only
  the two methods byte-exactly, requires a reference hash exactly for a staff resolution, and requires the link to
  name the exact archived line version and the exact track of its mapping. A changed line in a later export is a
  new line version and is linked afresh.
- **Not supported yet:** correcting or withdrawing a recorded link (it would need a superseding-row design and
  Sean's decision), separation of duties between the importer and the resolver, a Filament screen for resolution,
  links to asset revisions, and any use of a link by checkout, grants or delivery.
- **Retention:** indefinite, with the legacy order record (`RetentionSchedule`, "with the order").

### Historical grants: `archive_only` and `verified_grant`

Every archived line is `archive_only`: kept for history, with no download right. `review-grant --write` lets a staff
reviewer classify one line as `verified_grant`. The reviewer must not have inserted any evidence row the review relies
on (line, order, buyer, contract: each row records its inserting actor), nor created those rows' batches, nor created
or run any segment of the newest export of the source (whose member list decides what is current). Every
condition is re-derived against the newest archived export of the source (by watermark), which must be fully archived
(`latest_export_not_archived` otherwise): the line, order, buyer and contract must still be in that export with the
archived hashes (`evidence_removed` otherwise), the order `paid` with nothing refunded and a payment reference, the buyer
unambiguous, exactly one stored original contract whose bytes match their hash, and no competing unrefunded sale of
the same exclusive. The last three read every other record's latest archived version across all exports, so an export
that omits a competing sale, a customer sharing the address or a second contract never clears the blocker; only a
newer version of that record does (for example the competing sale refunded). Clearing a blocker by an owner-approved
removal is not supported yet; such a line stays `archive_only`. The review is one immutable row bound to that exact line version and its
evidence hashes. `classification()` repeats the derivation: when a later export drops the evidence or changes it (for
example the buyer's address), the review no longer counts (`reviewed_evidence_changed`) and the line reads
`archive_only` again; a new line version may be reviewed afresh, but the same line version cannot be reviewed again
(the review row is unique per line version), so it stays `archive_only` even after an owner disposition. `verified_grant` is still not an entitlement:
nothing reads it to grant a download. Turning verified historical grants into active entitlements is a separate,
reviewed import that needs Sean's authorization.

## Delta capture (runbook §7)

`delta` compares a baseline package with a later package of the same source system (the later watermark must be
newer). New records, refunds (status moves forward and the refunded amount grows), completed payments, restated raw
rows, changed display names and case-only address spellings are captured automatically. Changed customer addresses,
removed records, changed amounts, tracks, licenses, buyers or dates, changed original contract bytes and any change to an exclusive line other than its refund
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
| `handover` | `frozen` → `vasey_store` | The later package's watermark is at or after the freeze; the baseline is the latest archived export before it; both packages are fully archived and reconcile; their delta reconciles (with dispositions where needed). The watermark is the operator's `source_as_of` declaration in `mapping.json`, so the handover reference must point to evidence of when the post-freeze export was actually taken. |
| `revert` | `vasey_store` → `frozen` | Rollback starts by stopping the store; sold exclusives stay sold. |
| `unfreeze` | `frozen` → `beatstars` | Only for a freeze that directly followed BeatStars. After a store period it is refused (`source_resale_unproven`): reopening BeatStars first needs proof it cannot resell what the store sold. |

Anything else is refused: `authority_already_declared`, `authority_not_declared`, `freeze_requires_beatstars`,
`handover_requires_freeze`, `second_authority_refused`, `revert_requires_store`, `unfreeze_requires_freeze`, plus the
handover evidence codes (`baseline_not_archived`, `later_not_archived`, `baseline_not_reconciled`,
`later_not_reconciled`, `later_export_precedes_freeze`, `baseline_not_latest`, `delta_unreconciled`). Concurrent
transitions serialize on the ledger head (`SELECT … FOR UPDATE`) and the unique sequence number; the loser is refused
(`authority_changed_concurrently` or the transition code it then sees), proven with two independent MySQL processes.

`ExclusivityAuthority::storeMaySellExclusives()` is true only while `vasey_store` holds the authority. Production
checkout already consults it through `CurrentSelection` and `ExclusiveInventoryGate`: absent, unreadable, frozen or
BeatStars authority is refused. Even with `vasey_store` authority, exclusive inventory remains refused as
`unsupported_inventory`; there is no supported production exclusive consumer. Supporting exclusive activation and
checkout remains a separate launch gate in `docs/live-payment-and-production-preparation-queue.md` and tasks T39/T40.
This authority check does not activate historical verified grants or turn archive classifications into entitlements.

## Verification

Focused development checks, not acceptance: `docs/verification/migration-tooling-20261024/README.md` records the
exact commands, counts, the native MySQL run, mutation results and the untested conditions.

BL-07 (2026-11-10), focused checks, not acceptance: `tests/Unit/Migration/ProductFamilyAdapterTest.php`,
`tests/Feature/Migration/LegacyTrackLinksTest.php`, `tests/Feature/Migration/LegacyHistoryTrackLinkMigrationTest.php`
and the track-link case in `LegacyHistoryCommandTest.php` on SQLite; the migration test and two link tests ran
natively on MySQL 8.4.11. Untested: any real export, concurrent link or resolve processes on MySQL (the unique
`line_id` and the batch row lock are the guard; no two-process race was run), more than a handful of catalog
mappings (every mapping's evidence is decrypted per read), and the real catalog importer's rows (tests insert
mappings in the importer's evidence shape rather than running it).

### Mandatory review procedure for competing-record changes (V3)

Before `review-grant --attest`, the reviewer must examine the exact delta whose
baseline and later snapshot SHA-256 values identify the retained prior export and
newest export being reviewed. Check its blocking items and dispositions, and inspect
the retained history of every competing line/order, customer and contract that
cleared a blocker, including versions moved to another track, address or line.
A newer export copying or omitting that version does not waive this requirement.
Record the exact snapshot hashes and checked delta reference in the review evidence
reference. Do not attest while a change remains unexplained. This is an operator
procedure: the review command does not persist or enforce a reconciled-delta approval.
Historical `verified_grant` activation remains blocked pending all MIG gate repairs,
including declaration-complete missing-original evidence and independent review.

### V2 contract declarations and legacy history admission

New normalization uses `vasey-legacy-history-v2` /
`vasey-legacy-history-transform-v2`. Existing record identities retain the exact
`vasey-legacy-history-v1` namespace, and original records retain their original
bytes and hashes. V2 adds immutable `contract_declarations` after the original
`contracts` cohort. Every valid declaration is retained, including declarations
whose original is absent. A missing original produces no artifact row, size, hash
or path. A genuinely supplied zero-byte original is distinct from absence.

V1 snapshots remain readable, archivable, resumable and reconcilable. V1-to-v1
and v2-to-v2 deltas are supported; mixed-version deltas refuse and require genuine
re-normalization of both source packages before comparison. No in-memory upgrade
or completeness backfill exists. Inspection/classification and writes refuse for
a source system with **any retained v1 batch**, complete or incomplete, regardless
of newest v2 evidence (`declaration_history_coverage_unproven`). No approved package
coverage recovery mechanism exists in this batch. Renaming a source system,
deleting batches or manufacturing new equivalent records is not recovery.

For v2-only history, all five newest membership cohorts must be complete. Every
retained declaration ever associated with a line remains a candidate. Any historical
change of its line/order association is an unresolved
`contract_declaration_relinked` blocker, including move-back, later copy and later
omission. Delta dispositions and the V3 review procedure cannot authorize relinks.
Exactly one declaration, present in the newest batch with its exact genuine verified
original, is required. Historical reviews remain immutable; stronger evidence may
withdraw classification. A preexisting review on the same immutable line version
cannot be overwritten to escape `reviewed_evidence_changed`.

Every retained same-source v2 batch must also have every advertised membership row
physically archived, including superseded versions. An older interrupted export may
advertise a new missing-original declaration or an intermediate relink that physical
row discovery cannot yet associate. A newer complete export cannot prove that unknown
evidence irrelevant. `retained_export_not_archived` therefore refuses review and
classification until the genuine original package is resumed. Resume does not waive
multiple declarations, historical relinks or any other substantive blocker.
