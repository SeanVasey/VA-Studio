# BeatStars export normalization and dry run

**Implemented scope (M-09, M-10):** a mapping-driven normalizer from a BeatStars CSV export
into the `vasey-private-catalog-drafts-v1` draft format that
`app/Domain/Migration/CatalogOnboarding` consumes, and a dry-run report that applies nothing.
No real BeatStars export has been inspected (input S-9 is still open); the tool was built and
proven against the hand-made synthetic sheet in `tests/Fixtures/migration/beatstars-synthetic/`.
Its behaviour on the real export depends on the mapping Sean writes for it.

The dry run never applies. It opens no database, writes no draft, media, offer, license, rights
declaration, customer record or consent, and it cannot: the CLI requires only the Composer
autoloader and never boots Laravel. It never infers marketing consent, and it never invents
rights, prices or license terms. Every value in the output comes from the sheet or the mapping.

## What Sean supplies

1. **The export**, as a UTF-8 CSV file (comma-separated, double-quote quoted, one header
   line). XLSX is out of scope: no spreadsheet dependency is added, so save or export the sheet
   as CSV first. A UTF-8 byte-order mark and CRLF line endings are accepted. Limits: 8 MiB,
   1,000 data rows, 200 columns, unique non-blank headers.
2. **The mapping**, a JSON file that names every column and declares every vocabulary. Write
   it from the real export's header row; the template below mirrors the synthetic fixture.

Both files stay in private storage outside the repository, like every other source artifact
(`docs/migration/README.md`). Only the report's hashes belong in Git.

```json
{
    "schema_version": 1,
    "purpose": "vasey-beatstars-export-mapping-v1",
    "snapshot_id": "beatstars-catalog-2026-10-xx",
    "source_system": "beatstars/pro-page:<account reference>",
    "operator_reference": "who exported it, from where, and when",
    "acquisition_method": "official_export",
    "acquired_at": "2026-10-12T18:00:00Z",
    "source_as_of": "2026-10-12T17:30:00Z",
    "currency": "USD",
    "slug_policy": "derive_from_title",
    "columns": {
        "source_id": "Track ID", "title": "Title", "bpm": "BPM", "musical_key": "Key",
        "genre": "Genre", "mood": "Mood", "tags": "Tags", "description": "Description",
        "visibility": "Status", "rights_reference": "Rights Reference"
    },
    "constants": {"artist": "Seller name as it should appear"},
    "visibility_values": {"Public": "public", "Private": "private", "Draft": "draft", "Sold": "sold"},
    "tag_separator": ";",
    "licenses": {"Basic Lease": "basic-lease", "Premium Lease": "premium-lease"},
    "offers": [{"license_column": "License", "price_column": "Price"}],
    "ignored_columns": ["Plays", "Likes"]
}
```

| Key | Meaning |
| --- | --- |
| `snapshot_id`, `source_system`, `operator_reference` | Copied into the snapshot unchanged (1–190 printable characters each). |
| `acquisition_method` | One of the snapshot's methods: `official_export`, `authorized_read_only_audit`, `seller_owned_original`, `independently_verified_manual_entry`, `synthetic_fixture`. A `synthetic_fixture` sheet must have titles starting with `SYNTHETIC `. |
| `acquired_at`, `source_as_of` | Explicit UTC timestamps (`YYYY-MM-DDTHH:MM:SSZ`); the source watermark cannot follow acquisition. |
| `currency` | `USD` only. Prices are converted to integer minor units with two decimals; any other code is refused rather than given an invented exponent. |
| `slug_policy` | `column` (a `slug` column holds the final lowercase hyphenated slug) or `derive_from_title` (ASCII letters and digits of the title, everything else becomes `-`). Derivation is shown in the report; a title that yields nothing is a finding. |
| `columns` | Target field → header. Required: `source_id`, `title`, `rights_reference`, plus `artist` and `visibility` unless given as constants. Optional: `slug`, `bpm`, `musical_key`, `genre`, `mood`, `tags`, `description`, `currency` (checked against the declared currency). No other field exists; in particular there is no consent, e-mail or customer field. |
| `constants` | Sheet-wide `artist` (text) and/or `visibility` (one of `draft`, `private`, `unlisted`, `public`, `sold`, `unknown`) when the export has no such column. |
| `visibility_values` | Exact source value → visibility. Required when `visibility` is a column. Any other value is a finding; map it to `unknown` explicitly if that is the truth. |
| `tag_separator` | One character; required when `tags` is a column. Tags are trimmed; empty pieces are dropped; duplicates, more than 20 tags or tags over 80 characters are findings. |
| `licenses` | Source license name → operator label (`[a-z0-9-]`, up to 80). Labels only identify the name for later reconciliation; they are not store licenses. |
| `offers` | 1–10 entries, each a `price_column` plus exactly one of `license` (a name from `licenses`) or `license_column`. Blank or non-numeric prices and unknown or blank license names are findings. |
| `ignored_columns` | Headers deliberately not interpreted. A header that is neither mapped nor ignored is a finding. A header can play only one role. |

## Run the dry run

```sh
umask 077
report="$(mktemp -d /tmp/vasey-beatstars-XXXXXX)"
php scripts/migration/beatstars-dry-run.php \
  --export /private/path/beatstars-export.csv \
  --mapping /private/path/beatstars-mapping.json \
  --output "$report"
```

Paths must be absolute without `.` or `..` components or symlinks. The output directory must be
mode `0700`, owned by the caller, outside the checkout, and either empty or holding an earlier
run of this tool. The export basename becomes the snapshot's artifact path (`raw/<basename>`),
so keep it to letters, digits, `.`, `_` and `-`.

Standard output is one JSON line with the status code and counts: no title, value, path or
hash from the sheet. Exit codes: `0` zero findings (`dry_run_clean`), `3` findings
(`dry_run_findings`, report written, no snapshot), `1` refused (`dry_run_refused` with a
reason code, nothing written).

The run writes at most three files, each `0600`, each through a `.pending` file and an atomic
rename:

| File | Contents |
| --- | --- |
| `beatstars-dry-run.json` | The complete deterministic report (below). |
| `beatstars-dry-run.md` | The same report for reading: inputs, effective mapping, counts, findings, one row per sheet row, statements. |
| `catalog.json` | The `vasey-private-catalog-drafts-v1` snapshot. Written only when there are zero findings and at least one row. |

A rerun on the same inputs into the same directory finds identical bytes and rewrites nothing.
Different existing bytes are refused (`output_differs`): a report is never silently replaced,
so choose a new directory per changed input. The report carries no timestamp other than the
declared `acquired_at`/`source_as_of`, no absolute path and no host detail, which is why two
runs are byte-identical.

## What the report contains

- `export`: basename, SHA-256, byte count, header list and record count of the sheet.
- `mapping`: SHA-256 of the mapping file and the complete effective mapping, so a reviewer
  sees exactly which column fed each field.
- `counts`: rows, normalized, withheld, findings (sheet and row).
- `findings`: scope (`sheet` or `row`), row number, source id, code, column and a short detail.
- `entries`, one per non-blank row in sheet order: `row`, `source_id`, `row_sha256` (hash of
  the raw cells except the source id cell), `disposition` (`normalized` or `withheld`), the
  row's own finding codes and, for normalized rows, `record_key`, `source_record_sha256` (the
  snapshot's record hash), `visibility`, `metadata`, `raw_metadata`, `offers` (license name,
  label, price in minor units, currency) and `rights_reference`.
- `snapshot_sha256`: SHA-256 of `catalog.json`, equal to the `source_sha256` that the importer
  review will later bind, or `null`.
- `statements`: applies nothing; consent not inferred; rights references are operator
  references, not clearance; prices and license names are retained evidence, not offers.

Prices, license names and rights references are validated and retained in the report only.
The draft schema is metadata-only (`title`, `slug`, `artist`, `bpm`, `musical_key`, `genre`,
`mood`, `tags`, `description`, plus visibility and the raw cells), so none of them enter
`catalog.json`, and the importer creates metadata drafts only. Turning retained prices or
licenses into offers is separate, later, separately authorized work.

## What it refuses and what it reports

A **refusal** (exit `1`) means the inputs cannot be interpreted at all; nothing is written.
Reason codes: `usage`, `path`, `input_file`, `output_directory`, `output_inside_repository`,
`output_directory_not_empty`, `output_differs`, `output_pending_exists`, `output_write`,
`export_name`, `export_too_large`, `export_encoding`, `export_header`, `export_too_many_rows`,
`export_empty`, and `mapping_*` (`json`, `keys`, `purpose`, `identity`, `acquisition_method`,
`timestamp`, `currency`, `slug_policy`, `columns`, `constants`, `required_field`,
`visibility_values`, `tag_separator`, `licenses`, `offers`, `ignored_columns`,
`column_reuse`, `too_large`).

A **finding** (exit `3`) is recorded in the report and withholds rows; nothing is defaulted.

| Scope | Code | Effect |
| --- | --- | --- |
| sheet | `unmapped_column`, `mapped_column_missing`, `blank_record` | Every row withheld: the sheet is not fully understood. Map or ignore the column, fix the mapping, or remove the blank line, then rerun. |
| row | `column_count_mismatch`, `missing_source_id`, `invalid_source_id`, `duplicate_source_id`, `duplicate_row_content`, `duplicate_slug` | The row (and every row sharing the duplicate) is withheld. |
| row | `missing_title`, `invalid_title`, `synthetic_title_required`, `missing_slug`, `invalid_slug`, `slug_underivable`, `missing_artist`, `invalid_artist`, `invalid_bpm`, `invalid_musical_key`, `invalid_genre`, `invalid_mood`, `invalid_tags`, `invalid_description` | The row is withheld; the detail says which bound failed. BPM must be an integer 20–400; `0095` is `95`, `95.0` is not. |
| row | `missing_visibility`, `unknown_visibility_value` | The row is withheld until the value is declared in `visibility_values`. |
| row | `missing_rights_reference`, `invalid_rights_reference` | The row is withheld. A reference is required on every row; it is not checked against any evidence store. |
| row | `currency_mismatch`, `missing_license_name`, `unknown_license_name`, `missing_price`, `non_numeric_price` | The row is withheld. Prices accept `12`, `12.5`, `12.50` and `$12.50`; `Negotiable`, `1,299.00` and negatives are not numbers here. |

Any finding at all also withholds `catalog.json`: the snapshot is produced only for a sheet
the operator has fully reconciled. Sold rows pass through as `sold`; the importer review, not
this tool, decides their disposition.

## After a clean dry run

The snapshot is an input to the separately authorized importer review
(`scripts/migration/persistent-catalog.php review`), which binds the exact snapshot bytes,
the live target and the staff actor before anything can be applied. To stage it, create a
private `0700` directory, copy `catalog.json` into it, and place the exact export at
`raw/<basename>` (mode `0600`, a copy rather than a hard link). `PrivateSourceFiles::snapshot`
then verifies the export's SHA-256 and byte count against the artifact entry. Applying the
import remains Sean's decision (AGENTS.md release boundary); this document does not authorize it.

## Verification

Focused development checks, not acceptance: `docs/verification/beatstars-normalizer-20261010/`
records the commands, counts and the exact tested commit for the unit test
(`tests/Unit/Migration/BeatStarsExportNormalizerTest.php`, no database) and the SQLite feature
test (`tests/Feature/Migration/BeatStarsDryRunCommandTest.php`): byte-identical reports across
runs, zero SQL statements during an in-process run, unchanged catalog and draft tables, the
emitted snapshot admitted by `PrivateSourceFiles`, and the findings copy producing exactly
`unmapped_column` and `missing_rights_reference`. The dry run opens no database on any driver,
so MySQL adds no evidence for it. Behaviour on the real export is unproven until S-9 is supplied.
