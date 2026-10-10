# BeatStars export normalizer and dry run — lane evidence (2026-10-10)

Branch `harness/beatstars-normalizer` from main `f57e7256891d0d3c117e151a36f7fb967c724ab7`.
Tested commits: `3519dc09ff318c4c6f7ab2a9c3f776d72ddffdd8` (feature) and
`8ceb96b8b01721a868a04f2629c7b151d6837767` (documentation, no code change).
Development lane under Sean's 2026-10-06 CI cost policy: these are focused local checks on
SQLite in the Claude Code container (PHP 8.4.26, PHPUnit 12.5.34), not Foundation CI and not
final acceptance. No PR was opened and nothing was pushed from this lane. `PLAN.md` was
committed before implementation.

## What was built

- `app/Domain/Migration/BeatStars/`: `BeatStarsExportMapping` (operator mapping admission),
  `BeatStarsExportSheet` (CSV admission), `BeatStarsExportNormalizer` (pure mapping-driven
  normalizer to `vasey-private-catalog-drafts-v1`), `BeatStarsDryRunReport` (deterministic
  JSON/Markdown), `BeatStarsDryRunFiles` (private output boundary), `CellText` (shared bounds).
- `scripts/migration/beatstars-dry-run.php`: autoload-only CLI; no Laravel boot, no database.
- `tests/Fixtures/migration/beatstars-synthetic/`: synthetic sheet, findings copy, mapping, README.
- `tests/Unit/Migration/BeatStarsExportNormalizerTest.php`, `tests/Feature/Migration/BeatStarsDryRunCommandTest.php`.
- `docs/migration/beatstars-export-normalization.md`.

## Acceptance criteria from PLAN.md

| Criterion | Result | Evidence |
| --- | --- | --- |
| A1 synthetic sheet: zero findings, 4 normalized, decodable `catalog.json` | met | `evidence/unit-normalizer.txt`, `evidence/cli-fixture-runs.txt` (`dry_run_clean`, exit 0) |
| A2 findings copy: exactly `unmapped_column` (sheet, `Likes`) and `missing_rights_reference` (row 3); no normalized rows | met | `evidence/cli-fixture-runs.txt` (`findings":2`, `normalized":0`, exit 3, no `catalog.json`); unit test `test_one_unmapped_column_and_one_missing_rights_reference_are_exactly_the_findings_and_withhold_rows` |
| A3 source id, `row_sha256` and `source_record_sha256` preserved per normalized entry | met | unit test (fixture test asserts `record_key`, hashes, and equality with the decoded snapshot records) |
| A4 missing/non-numeric price, unknown license, duplicate id, repeated row hash, unknown visibility, invalid metadata are findings, never defaults | met | unit test data providers `rowFindings` (24 cases), duplicate and sheet-structure tests |
| A5 byte-identical JSON and Markdown across runs; identical rerun rewrites nothing; different existing files refused | met | `evidence/cli-fixture-runs.txt` (identical SHA-256 in two directories, rerun `written:[]`), feature test (`output_differs` refusal) |
| A6 nothing written outside the output directory; zero SQL in-process; catalog/draft tables unchanged on SQLite | met for the tested paths | feature test: `QueryExecuted` listener records zero statements, `CatalogDatabaseEvidence` target unchanged, `tracks`/`catalog_import_*`/`audit_events` counts 0; refusal cases leave the directory listing unchanged |
| A7 marketing consent never inferred | met | mapping has no consent field (`mapping_columns` refusal for `marketing_consent`/`customer_email`); report `statements.marketing_consent = not_inferred`; snapshot bytes contain no `consent` |

Additional proof: the emitted `catalog.json`, staged with the export at `raw/export.csv`, is
admitted by `PrivateSourceFiles::snapshot` with `source_sha256` equal to the report's
`snapshot_sha256` (`bd893c40…83968`), in both the feature test and `evidence/cli-fixture-runs.txt`.

## Commands and counts

| Check | Command (SQLite env, direct PHPUnit) | Result |
| --- | --- | --- |
| Normalizer unit test (no database) | `-- tests/Unit/Migration/BeatStarsExportNormalizerTest.php` | OK, 71 tests, 403 assertions, 0 skipped (`evidence/unit-normalizer.txt`) |
| Dry-run command feature test (SQLite, real CLI subprocess) | `-- tests/Feature/Migration/BeatStarsDryRunCommandTest.php` | OK, 13 tests, 110 assertions, 0 skipped (`evidence/feature-dry-run-command.txt`) |
| Style, changed PHP files only | `vendor/bin/pint --test <6 domain files> scripts/migration/beatstars-dry-run.php <2 tests>` | passed (`evidence/pint.txt`) |
| Adjacent onboarding tests, unchanged files | 4 files (`PersistentCatalogDraftImportTest`, `NormalizedCatalogSourceTest`, `CatalogDryRunTest`, `CatalogDryRunCommandTest`) | OK, 132 tests, 836 assertions (`evidence/regression.txt`) |
| Whole Unit suite | `--testsuite Unit` | OK, 1070 tests, 6607 assertions, 1 pre-existing native-MySQL-only skip (`evidence/regression.txt`) |
| Real CLI on the fixture | `php scripts/migration/beatstars-dry-run.php --export … --mapping … --output …` | clean: exit 0, 3 files mode 0600; findings copy: exit 3, 2 files (`evidence/cli-fixture-runs.txt`) |

"Red first" does not apply: this lane adds a new capability rather than fixing a refusal or
defect; the CLI and classes did not exist before commit `3519dc09`.

## Not verified here

- Behaviour on a real BeatStars export (S-9 not supplied): real column names, encodings,
  per-license price layout and visibility vocabulary are unknown and must be declared in the
  mapping. Only the synthetic sheet was exercised.
- MySQL: the dry run opens no database connection on any driver, so no MySQL run was made and
  none would add evidence. The container's mysqld is 8.0.46 (CI: 8.4.11); it was not started.
- The Feature suite as a whole, browser specs, Foundation CI and independent migration review
  (M-09 is marked **migration** review in the Monday plan).
- Applying the snapshot through `CatalogDraftImporter` (separately authorized; not run).
- `composer audit` / `npm audit`: no dependency was added or changed.

## Lane hygiene

Worktree left clean on `harness/beatstars-normalizer`; no `phpunit-ci-*` files were generated;
no server was started; `vendor/` is a hard-link mirror of a sibling worktree and was not written.
