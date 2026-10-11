# Review: beatstars-normalizer 82993ff..67d7a89

**Decision: APPROVE.** All three conditions from the 82993ff review are met. No new defects were found.

## Findings

- **M1 resolved** (BeatStarsExportSheet.php:69-72). At the start of an unquoted field, `strspn(" \t\v\f\r")` followed by `"` is refused as `export_csv_quotes`. This matches the variant I fuzzed.
- **Fuzz of the committed method.** It calls the real `quotes()` by reflection from the archive copy, with 300k inputs per alphabet.
  - Alphabet `a , " LF CRLF space`: 0 admitted misparses and 0 refusals of RFC-valid input outside the whitespace-before-quote class.
  - Extended alphabet (adds bare CR, tab, VT, FF): 0 refusals outside that class. 37,361 differences remain, and every one contains a CR.
  - The 4,098 of those that contain a quote all match once CRs are removed. They come from `fgetcsv` removing CR in unquoted cells, which already existed and has nothing to do with quotes.
- **L1 resolved.** The `"x"\ry` case now kills the bare-CR mutant.
- **L2 resolved.** The `.git`-file feature case now kills the `is_dir` mutant.
- **I1 (info, no action): no single test pins each whitespace byte.** Removing CR from the `strspn` set survives; VT and FF are likewise untested. The code is correct (the fuzz above covers it), and those bytes before a quote are unlikely in an export.
- **Docs are accurate:**
  - the guide's `export_csv_quotes` text;
  - the README's delta section and accepted gaps;
  - `evidence/whitespace-quote-red-green.txt`, whose red/green matches this run's counts.

## Commands and results

- Both test files were run in the worktree at HEAD 67d7a89 with the given env/phpunit command. The worktree was clean.
  - Unit: `OK (84 tests, 714 assertions)`.
  - Feature: `OK (23 tests, 177 assertions)`.
- In a fresh `git archive 67d7a89` copy (deleted afterwards), the baseline was also unit 84 OK and feature 23 OK. Mutants:

  | Mutant | Result |
  | --- | --- |
  | whitespace check removed | killed (space and tab cases) |
  | tab dropped from set | killed |
  | space dropped from set | killed |
  | CR dropped from set | survived (I1) |
  | bare CR after a closing quote allowed | killed |
  | `lstat` replaced by `is_dir` | killed |

- Scratch scripts: `fuzz-real.php` and `quote-diffs.php` (new), plus `fuzz.php`, `mutate.py` and `run.sh`.
