# BeatStars export normalizer and dry run (M-09 / M-10) — lane plan

Branch `harness/beatstars-normalizer` from `f57e7256891d0d3c117e151a36f7fb967c724ab7`.
Written before any implementation, per the AGENTS.md work protocol. This is a
development lane under Sean's 2026-10-06 CI cost policy; nothing here is final
acceptance, and nothing here applies an import.

## Files owned

- `app/Domain/Migration/BeatStars/**` (new): mapping admission, CSV reading,
  normalizer, report rendering, private output files.
- `scripts/migration/beatstars-dry-run.php` (new): the operator CLI.
- `tests/Unit/Migration/BeatStars*` and `tests/Feature/Migration/BeatStars*` (new).
- `tests/Fixtures/migration/beatstars-synthetic/**` (new, synthetic).
- `docs/migration/beatstars-export-normalization.md` (new).
- `docs/verification/beatstars-normalizer-20261010/**` (new).

Read only: `app/Domain/Migration/CatalogOnboarding/*` (another lane owns it),
`app/Support/CanonicalJson.php`, `scripts/migration/catalog-dry-run.php`,
`scripts/migration/persistent-catalog.php`. `CHANGELOG.md`, `README.md` and the
other excluded files are not edited; the changelog line is returned to the integrator.

## Assumptions

1. No real BeatStars export exists (input S-9). The plan's fallback applies: the
   normalizer is built and proven against a hand-made, clearly synthetic CSV sheet.
   The real export's column names are unknown, so semantics come only from an
   explicit operator-authored mapping file, never from header guessing.
2. The target format is exactly `vasey-private-catalog-drafts-v1` as decoded by
   `NormalizedSourceSnapshot::decode`. That schema is metadata-only: it has no
   slot for prices, license names or rights references. Those are validated from
   the sheet and retained in the dry-run report as evidence; they are not written
   into the draft snapshot and are never turned into offers, licenses or rights.
3. CSV only. XLSX is out of scope (no spreadsheet dependency is added); the
   operator exports or saves the sheet as UTF-8 CSV first.
4. The dry run is a plain PHP CLI (`scripts/migration/beatstars-dry-run.php`)
   that requires only the Composer autoloader, like `catalog-dry-run.php`. It does
   not boot Laravel, so it cannot open a database connection; "writes nothing to
   any database or draft store" is structural, not a flag. `persistent-catalog.php`
   boots a retained installation and prompts for credentials, which a read-only
   dry run must not need, so an artisan command is not used.
5. The report must be byte-identical across runs: it carries no timestamps other
   than the operator-declared `acquired_at`/`source_as_of`, no absolute paths, and
   only basenames plus SHA-256 of the inputs.
6. USD is the only currency the normalizer converts to minor units (two decimals);
   any other declared currency is refused rather than given an invented exponent.

## Acceptance criteria

- A1. The synthetic sheet plus its mapping produce a report with zero findings,
  one `normalized` entry per row, and a `catalog.json` that
  `NormalizedSourceSnapshot::decode` accepts unchanged.
- A2. A copy of the sheet with one unmapped column and one missing rights
  reference produces exactly two findings (`unmapped_column`, sheet scope;
  `missing_rights_reference`, row scope), and no normalized rows for the affected
  entries: the sheet finding withholds every row, the row finding withholds its row.
- A3. Every normalized entry preserves the source id, the per-row content hash of
  the raw cells and the schema's `source_record_sha256`.
- A4. Missing or non-numeric price, unknown license name, duplicate source id,
  repeated row content hash, unknown visibility value and invalid metadata are
  findings, never defaults.
- A5. Two runs on the same inputs write byte-identical JSON and Markdown; a run
  into a directory that already holds identical files succeeds without rewriting;
  different existing files are refused.
- A6. Nothing is written outside the operator-chosen output directory; the
  in-process run executes zero SQL statements and leaves catalog and draft tables
  unchanged on SQLite.
- A7. Marketing consent is never inferred: the mapping has no consent field and
  the report states it.

## Tests to add

- `tests/Unit/Migration/BeatStarsExportNormalizerTest.php` (no database): A1–A4,
  A7, mapping refusals, CSV edge cases (BOM, quoted multiline cell, CRLF, blank
  line, column-count mismatch), determinism.
- `tests/Feature/Migration/BeatStarsDryRunCommandTest.php` (SQLite): real CLI on
  the synthetic fixture, exit codes, file modes, stdout free of raw values and
  paths, A5, A6 with a `QueryExecuted` listener, the findings copy (exit 3, no
  `catalog.json`), refusals (exit 1, nothing written).

## What cannot be proven in this container

- Behaviour on a real BeatStars export: none exists. Column names, encodings,
  per-license price layout and visibility vocabulary of the real export are
  unknown; the mapping file is where the operator declares them.
- MySQL: the dry run opens no database, so no MySQL evidence applies. The feature
  test runs on SQLite; CI uses MySQL 8.4.11 and this container has only 8.0.46,
  neither of which the dry run touches.
- Import application: `CatalogDraftImporter` consumes the emitted snapshot in a
  separate, separately authorized step that this lane does not run.
