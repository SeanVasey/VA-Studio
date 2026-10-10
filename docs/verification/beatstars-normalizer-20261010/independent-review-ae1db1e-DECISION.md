# Review: lane beatstars (harness/beatstars-normalizer)

Range `f57e7256..ae1db1e2ef92314db9c1c9c8d4e8a11c97f06b0c`. Reviewed head `ae1db1e2ef92314db9c1c9c8d4e8a11c97f06b0c`.

## Decision: APPROVE WITH CONDITIONS

## Scope
All 21 changed files are in the owned paths. No changes to resources/contracts, vendor, lockfiles, CHANGELOG, README or CLAUDE.md. Fixtures are synthetic and labelled as synthetic: `SYNTHETIC ` titles, `synthetic_fixture` acquisition, and a README that says the prices are placeholders. A diff scan found no secrets, e-mail addresses or real data.

## Findings
- **MEDIUM**: `BeatStarsExportNormalizer.php:405-413` and `NormalizedSourceSnapshot.php:26` (1 MiB cap). A clean sheet well inside the documented limits can still produce a snapshot larger than 1 MiB. My test sheet was 609 KB: 120 rows, each with a 5,000-character description, which is counted twice (raw and metadata). The decoder rejects that snapshot and the normalizer throws `RuntimeException`. The CLI then prints `dry_run_refused` / `internal_error` and writes no report (`scripts/migration/beatstars-dry-run.php:41`). The operator gets nothing to diagnose. The code comment calls this case a "normalizer defect", but operator input can reach it. The guide does not mention the limit. The behaviour fails closed, so there is no integrity risk.
- **LOW**: price conversion for one decimal place (`12.5`, `$0.5`) has no test. Mutation M6 changed `str_pad` to `(int) $match[2]`, which would turn `29.9` into 2909 cents. All 71 unit tests still passed. The current code is correct (I checked directly: 29.9 gives 2990, 12.5 gives 1250, $0.5 gives 50). The guide (line 147) claims `12.5` is accepted.
- **LOW**: slug derivation (`BeatStarsExportNormalizer.php:451-456`) silently loses non-ASCII letters. `Été Café` becomes `t-caf` with zero findings. This goes against the class docblock, which says values that cannot be interpreted exactly become findings.
- **LOW**: the mapping JSON decoder lets a duplicate key silently win. For example, `"Public":"draft","Public":"public"` is accepted. The effective mapping and its SHA-256 appear in the report, which reduces the risk.
- **INFO**: a row with a `column_count_mismatch` keeps its row number and the hash of the whole record, but its `source_id` is null (line 89).
- **INFO**: a zero price (`0`) is accepted as 0 minor units. One extra blank line at the end of the file is a sheet finding and withholds every row; the guide documents this.

## Sensitivity: confirmed
- Nothing is applied and no database is opened. Under strace, the real CLI made zero socket or connect calls, opened no `.env` or database file, and wrote only the three `*.pending` files in the output directory. The lane code imports no Illuminate classes.
- No consent field exists. The mapping refuses `marketing_consent` and `customer_email`.
- Rights, prices and licenses are only checked and kept in the report. They never enter `catalog.json`. Money is integer minor units, and the currency is explicitly USD.
- Source ids, `row_sha256` and `source_record_sha256` are preserved per row. Withheld rows keep their id and hash.
- A finding withholds the row, and any finding at all withholds the snapshot.

## Mutations (16 in a scratch copy, unit suite)
15 were killed: sheet findings withholding rows, snapshot gating, unmapped column, missing rights, duplicate row content, currency mismatch, unknown license, row hash including the id, column reuse, unknown visibility defaulting, thousands-separator prices, blank record, synthetic marker, duplicate slug, mapped column missing. M6 survived.

## Claims reproduced
Unit tests 71/403, feature tests 13/110, adjacent tests 132/836 and Pint all match the claims. The CLI output hashes match the evidence byte for byte (`catalog.json` bd893c40…83968). The rerun reported `written=[]`, the findings copy exited 3 with 2 findings, and all output modes were 0600. I did not rerun the whole Unit suite. No MySQL evidence is claimed, and none is needed.

## Conditions
1. Turn the oversize snapshot into a stable outcome instead of `internal_error`. Either refuse with a named reason code such as `snapshot_too_large` or report it as a sheet finding with the report written. State the roughly 1 MiB snapshot limit in `docs/migration/beatstars-export-normalization.md`, and add a unit or feature test that covers it.
2. Add unit cases for one-decimal and sub-dollar prices (`12.5` gives 1250, `$0.5` gives 50) in `tests/Unit/Migration/BeatStarsExportNormalizerTest.php`, so that M6 is killed.

Recommended, not blocking: add a finding (or transliteration) when a derived slug drops letters; refuse duplicate keys in the mapping JSON.
