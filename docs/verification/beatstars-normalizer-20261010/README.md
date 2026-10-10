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

## Independent review of ae1db1e and fixes (addendum)

The independent review returned **APPROVE WITH CONDITIONS**
(`independent-review-ae1db1e-DECISION.md`, copied verbatim). Both conditions are applied in
`fa34fd5bc6689d174815955b97c5f1b6a1170ae8`:

1. An oversize snapshot is no longer `internal_error`. A clean sheet whose drafts-v1 snapshot
   would exceed the decoder's 1,048,576-byte cap is now the sheet finding `snapshot_too_large`
   ("N bytes, maximum 1048576"). Every row is withheld, both reports are written, no
   `catalog.json` is written, and the CLI exits 3. The limit and the split-the-export remedy
   are in `docs/migration/beatstars-export-normalization.md`. New unit test: over the limit
   (120 rows) and under it (40 rows, admitted by the decoder). New feature test: the real CLI on
   a 617,530-byte synthetic sheet.
2. New unit cases for one-decimal and sub-dollar prices: `12.5` gives 1250, `$0.5` gives 50,
   `$29.9` gives 2990 and `0.05` gives 5. Mutation M6 now fails exactly this test (1 of 73).

| Check | Result | Evidence |
| --- | --- | --- |
| Red: new tests against the unchanged normalizer | the oversize unit test errors with `normalizer_output_rejected`; the feature test gets `dry_run_refused`/`internal_error`, exit 1 | `evidence/review-fixes-red-green.txt` |
| Red: M6 mutant | 1 failure in 73 (1205 for 1250, 5 for 50) | `evidence/review-fixes-red-green.txt` |
| Normalizer unit test | OK, 73 tests, 662 assertions | `evidence/review-fixes-red-green.txt` |
| Dry-run command feature test | OK, 14 tests, 121 assertions | `evidence/review-fixes-red-green.txt` |
| Pint on lane PHP files | passed | `evidence/review-fixes-red-green.txt` |
| Adjacent onboarding tests | OK, 132 tests, 836 assertions | `evidence/review-fixes-regression.txt` |
| Whole Unit suite | OK, 1072 tests, 6866 assertions, 1 skip (same count as before; not listed) | `evidence/review-fixes-regression.txt` |
| Real CLI | the clean fixture outputs are byte-identical to the earlier run (`catalog.json` `bd893c40…83968`); findings copy exit 3; oversize sheet exit 3 with `snapshot_too_large`, 2 files at mode 0600 | `evidence/cli-review-fixes.txt` |

The Markdown note under sheet findings now names each remedy. This changes the findings copy's
`beatstars-dry-run.md` hash (`f17e8372…`), but not the clean run's outputs.

The review's non-blocking recommendations are not applied in this lane: a finding when slug
derivation drops non-ASCII letters, and refusing duplicate keys in the mapping JSON. They are
returned to the integrator as remaining work.

## Delta review of b329686

An independent delta review of the condition commits `ae1db1e..b329686` returned APPROVE with no
conditions (`independent-review-b329686-DECISION.md`). Its LOW finding stands as follow-up work: no
test pins the 1,048,576 / 1,048,577-byte snapshot boundary, so two boundary mutants survive, and the
normalizer's limit is a separate literal from the decoder's. Every such failure mode fails closed.

## Not verified here

- Behaviour on a real BeatStars export (S-9 not supplied): real column names, encodings,
  per-license price layout and visibility vocabulary are unknown and must be declared in the
  mapping. Only the synthetic sheet was exercised.
- MySQL: the dry run opens no database connection on any driver, so no MySQL run was made and
  none would add evidence. The container's mysqld is 8.0.46 (CI: 8.4.11); it was not started.
- The Feature suite as a whole, browser specs and Foundation CI. The independent migration
  review assessed `ae1db1e` (addendum above), and the delta review assessed `b329686` (above).
- Applying the snapshot through `CatalogDraftImporter` (separately authorized; not run).
- `composer audit` / `npm audit`: no dependency was added or changed.

## Lane hygiene

Worktree left clean on `harness/beatstars-normalizer`; no `phpunit-ci-*` files were generated;
no server was started; `vendor/` is a hard-link mirror of a sibling worktree and was not written.
