# Review: lane beatstars, condition delta (harness/beatstars-normalizer)

Range `ae1db1e2..b329686727d9d4478d744ab683fd5d1c82f1a6cd`. Reviewed head `b329686727d9d4478d744ab683fd5d1c82f1a6cd`.

## Decision: APPROVE

Both conditions from the ae1db1e review are met. The boundary findings below are LOW and recommended, not blocking: the code is correct, and every failure mode fails closed.

## Scope
All 10 changed files are in the owned paths. Nothing changed under resources/contracts, vendor, lockfiles, CHANGELOG, README or CLAUDE.md. All new test data is synthetic: `SYNTHETIC` titles, ids and rights references. A diff scan found no secrets, e-mail addresses, paths or real data. `independent-review-ae1db1e-DECISION.md` is byte-identical (`cmp`) to the original review.

## Condition 1 (oversize snapshot): met
- `BeatStarsExportNormalizer.php:114-126` measures `encode(snapshot)."\n"`. That is the same byte string the CLI writes (`BeatStarsDryRunReport.php:30`) and the decoder caps at `<= 1048576` (`NormalizedSourceSnapshot.php:25`). A larger snapshot becomes the sheet finding `snapshot_too_large`, and `$snapshot` is set to null.
- The check runs only when there are zero other findings, so `catalog.json` is still gated by "no findings at all". The existing sheet-finding rule (`:141`) withholds every row. Rows keep `source_id` and `row_sha256`; the statements do not change.
- I probed the boundary directly on real code. A 1,048,576-byte snapshot is clean and the decoder admits it. A 1,048,577-byte snapshot gives the finding "1048577 bytes, maximum 1048576".
- The operator guide states the 1 MiB cap, the duplicated-text cause and the remedy (split the export). I checked the 0.6 MB claim: a 617,530-byte CSV gives a 1,270,425-byte snapshot.

## Condition 2 (prices): met
New cases: `12.5` gives 1250, `$0.5` gives 50, `0.05` gives 5 and `$29.9` gives 2990. My M6 mutant fails exactly this test (1 of 73).

## Mutations (10 on the new guard, scratch copy, unit suite)
Killed (7):
- size check removed
- oversize snapshot not cleared
- scope `row` instead of `sheet`
- limit doubled
- limit halved
- sheet withholding ignored
- detail omits the byte count

Survived (3):
- **D2** `>` changed to `>=`
- **D3** size measured without the trailing `\n`
- **D5** the decoder self-check within the limit skipped

D3 reintroduces the `internal_error` refusal at exactly 1,048,577 bytes. I confirmed this with a probe: `RuntimeException normalizer_output_rejected`.

## Findings
- **LOW**: the boundary is not pinned by any test. D2 and D3 survive (`BeatStarsExportNormalizer.php:118-119`). The limit is also a separate literal from the decoder's (`:34` and `NormalizedSourceSnapshot.php:25`), so the two could drift. Every failure mode fails closed: a conservative finding, or `internal_error`. Recommendation: add a unit case at exactly 1,048,576 bytes (clean, decodes) and at 1,048,577 bytes (finding). My probe hit those sizes with 100 synthetic rows: row 99's description shortened by 115 characters, row 100's description `SYNTHETIC ` only, and 1 or 2 characters appended to row 100's id.
- **INFO**: D5 survives. The within-limit decoder self-check is defence in depth against a normalizer defect, and no test exercises it. The unit test decodes the 40-row snapshot itself.
- **INFO**: `snapshot_too_large` is reported only after every other finding is fixed, so an operator may need one more rerun. The guide's wording ("Every row passed its checks") matches this behaviour.

## Claims reproduced
- Unit 73/662 and feature 14/121.
- Adjacent onboarding tests 132/836.
- Whole Unit suite 1072/6866 with 1 skip. The skip is `MysqlConnectionTimezoneTest::test_native_session_is_utc_...` (native MySQL only), which resolves the implementer's "not listed" item.
- Pint passed.
- Red: the new tests against the ae1db1e normalizer error with `normalizer_output_rejected`, and the price test passes.
- CLI: clean outputs match byte for byte (json 8f5965e3, md 6d760eea, catalog.json bd893c40…83968). The rerun reported `written=[]`. The findings copy exits 3 (md f17e8372). The oversize sheet exits 3 with `snapshot_too_large`, writes 2 files at mode 0600 and no `catalog.json`; the report has `applied=false` and `database_writes=0`.
- No MySQL evidence is claimed or needed.

## Invariants
- Money is in integer minor units (USD).
- Nothing is applied.
- Consent is not inferred.
- Source ids and row hashes are preserved on withheld rows.
- No catalog or rights data is fabricated.

Grants, downloads and staff authority are untouched.

## Conditions
None.

Recommended: a boundary test at 1,048,576 and 1,048,577 bytes in `tests/Unit/Migration/BeatStarsExportNormalizerTest.php`. The earlier non-blocking items (slug drops non-ASCII letters, duplicate keys in the mapping JSON) remain open.
