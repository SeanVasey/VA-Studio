DECISION: APPROVE

# Review: beatstars-normalizer 3d6b755..20f5dc9

Reviewed range: `3d6b755357b085482e8fdedf3d62320b2e17301f..20f5dc9941dff75e274ed2d26436871d748bbadc`. It has two
commits: `37b80be` (checked sheet write, private real mappings) and `20f5dc9` (refuse bare CR outside quotes).
Worktree `/home/user/wt-beatstars`, branch `harness/beatstars-normalizer`. Run as root in the container, PHP 8.4.26,
ext4. A non-root re-run used uid 65534.

The Codex P1 (unchecked sheet write) and P2 (mapping outside the private boundary) are fixed correctly. Condition N1
of the 3d6b755 review is met: outside quotes, a CR must begin CRLF. Differential fuzzing found the admitted set equal
to the strict RFC 4180 set (LF or CRLF line endings) over 1.6M inputs. `fgetcsv` agreed with the strict reader on
every admitted input. N3 is met by the guide wording.

The new tests are red on the old source, and the red/green counts in both evidence files match this run exactly.
No bypass was found in the mapping boundary. The findings are two Low documentation items and three Info items.
None blocks a development merge.

## Findings

- **P1, checked write into `php://memory`: correct** (BeatStarsExportSheet.php:33-45).
  - `php://memory` has no spill threshold. The loop refuses a `false` or zero-byte write.
  - `fstat($handle)['size']` is an int equal to the bytes written (probe: 12345 → `int(12345)`).
  - A 906,906-byte, 1,000-row CRLF sheet reads through `read()` with all rows.
  - The size check and the loop have no killing test. The mutant "fstat size check removed" survives. The evidence
    file says so honestly, and its reasoning is sound: an in-memory write fails only on memory exhaustion, which is
    fatal.
- **P2, mapping through `PrivateSourceFiles`: correct** (BeatStarsDryRunFiles.php:36-37, 99-122).
  - **Order.** The mapping is checked before the export, as before (`input()` ran on it first at 3d6b755). With both
    inputs bad, the mapping's code is reported. Every existing refusal case keeps its code.
  - **In-repo non-fixture mapping.** `str_starts_with(…/tests/Fixtures/) && decode(…)` short-circuits, so a mapping
    elsewhere in the checkout is refused as `input_inside_repository` without being decoded. Probe
    `composer.json` → `input_inside_repository`, and the new feature case covers it.
  - **Fixture-path mapping.** The mapping is decoded from its own bytes (`$mapping ?? $bytes`):
    - A synthetic mapping is admitted.
    - A fixture CSV given as the mapping → `mapping_json`, which is still a refusal.
    - An `official_export` mapping placed temporarily in `tests/Fixtures/` with a private export →
      `input_inside_repository` (probe; the file was removed straight after).
  - **Bypass probes (CLI)**, all against a private real mapping:

    | Probe | Result |
    | --- | --- |
    | `tests/Fixtures/../composer.json` | `path` (`path()` refuses `..` and requires `realpath` equality) |
    | symlinked real mapping | `path` |
    | hard-linked real mapping (`nlink` 2) | `input_not_private` |
    | 0600 mapping in a 0700 directory whose parent is 0777 | `input_not_private` |
    | 0640 mapping (feature case) | `input_not_private` |
    | private official mapping with private export | admitted (`dry_run_clean`) |

  - **Paths outside this checkout.** A prefix sibling such as `/home/user/wt-beatstars-x` is outside because of the
    trailing `/` in the check. Another worktree of the same repository is refused by the `.git` ancestor rule.
- **N1, bare CR outside quotes: met** (BeatStarsExportSheet.php:80-86).
  - **Differential fuzz** (`fuzz.php`, `fuzz-utf8.php`). Each input went through the real `quotes()` by reflection,
    and every admitted input was compared, under `fgetcsv(…, ',', '"', '')`, with a strict RFC 4180 reader
    (`rfc()`: LF or CRLF line endings; no bare CR, quote or text after a closing quote outside quotes).

    | Alphabet | Inputs | Admitted (= RFC-valid) |
    | --- | --- | --- |
    | tokens without CR | 300k | 113,546 |
    | tokens with CR, VT, FF | 300k | 89,174 |
    | raw bytes | 300k | 84,837 |
    | BOM and CRLF mix | 300k | 85,044 |
    | UTF-8 (é, NBSP, emoji) | 200k | 57,370 |
    | long inputs (up to 40 tokens) | 200k | 22,852 |

    In every alphabet: RFC-valid inputs refused 0, admitted inputs that are RFC-invalid 0, admitted inputs where
    `fgetcsv` differs from the strict reader 0. The 3d6b755 review found residual differences, all with a bare CR;
    none remain.
  - **Legitimate input through `read()`** (`legit.php`): all admitted with the expected cells.
    - BOM with CRLF, and BOM with a quoted header.
    - No trailing newline, after an unquoted or a quoted last field.
    - CRLF, LF and bare CR inside quotes.
    - Doubled quotes, empty quoted fields, a quoted comma, empty unquoted cells.
    - A blank line between records, a trailing comma at EOF, CRLF after an empty last field, and mixed LF/CRLF.
    - Both synthetic fixture CSVs.
  - **Refused as `export_csv_quotes`:**
    - the 3d6b755 `crsplit` case;
    - a trailing bare CR at EOF, and `\r\r\n`;
    - a mid-cell CR;
    - CR-only (classic Mac) line endings.
  - **Info.** Rejecting CR-only line endings is a deliberate behavior change. It is RFC-consistent and documented in
    the guide, but it reports under a "quotes" code. `$offset += … 2` after CRLF is equivalent to `+1` (the mutant
    survives because the LF is consumed as an empty span on the next pass). It is harmless.
- **CI (non-root runner, `/tmp` root-owned sticky): OK.**
  - The helpers put the private files in `<sys_get_temp_dir()>/vasey-beatstars-dry-run-<hex>/private`:
    - `privateDirectory()` is 0700.
    - `privateFile()` writes the file and then chmods it to 0600.
    - The test base is caller-owned 0700, which satisfies `PrivateSourceFiles`' caller-owned, non-writable parent
      rule.
    - `/tmp` passes as a root-owned sticky ancestor.
  - The workflows run on `ubuntu-latest` with no container and no `TMPDIR`.
  - Whole feature file as uid 65534 (`setpriv --reuid=65534`): 27 of 28 pass, including every mapping and private
    case. The one error, `output inside repository: mkdir(): Permission denied`, comes from this simulation: the
    checkout is root-owned here, while a runner owns its checkout. It is an existing case and unrelated to this delta.
- **L-a (Low, docs): `export_unreadable` is missing from the guide's reason-code list**
  (`docs/migration/beatstars-export-normalization.md`, around line 146). The code predates this delta (the `fopen`
  branch). This delta adds two more paths to it, and the README now names it as a refusal.
- **L-b (Low, docs): the class docblock is stale.** `BeatStarsDryRunFiles.php:14-15` still says only the export must
  be private ("The export must be a caller-owned…"). `source()`'s own docblock is correct.
- **I-a (Info): untested clause.** The mutant "a fixture-path mapping need not itself declare `synthetic_fixture`"
  (only the export's check decodes it) survives both files. The code is correct (probe above). A feature case that
  writes an `official_export` mapping under `tests/Fixtures/` with a private export, and removes it in `tearDown`,
  would kill it. The `input_changed` comparison in `source()` (`$proof['bytes'] === $bytes`) also has no killing
  test. That predates this delta for the export.
- **I-b (Info, by design).** A synthetic fixture mapping in `tests/Fixtures/` can be paired with a real private
  export (probe: `dry_run_clean`). The export still goes through the private boundary, so nothing leaks. This
  matches the guide.
- **Docs and evidence are accurate.**
  - **Guide.** Lines 28-41:
    - mapping privacy;
    - "keep the export and the mapping in a 0700 directory inside another 0700 directory";
    - the in-checkout rule;
    - the qualified rollback sentence, which matches probe D of the 3d6b755 review (N3).

    The `export_csv_quotes` text on the CR rule is also accurate.
  - **README.** "Codex round on `3d6b755`" and the review paragraph are accurate. N2 and I1 are correctly recorded
    as accepted and unchanged.
  - **Evidence.**
    - `codex-sheet-write-and-private-mapping-red-green.txt`: red `Tests: 2, Assertions: 4, Failures: 2` and green
      88/722 and 28/208 are reproduced. The 37b80be tests were run against the 37b80be source.
    - `review-3d6b755-bare-cr-red-green.txt`: red `Tests: 1, Assertions: 1, Failures: 1` and green 89/724 and 28/208
      are reproduced.
  - `independent-review-3d6b755-DECISION.md` is byte-identical to the reviewer's original
    (`review-3d6b755/DECISION.md`).

## Commands and results

- **Given command**, both files at HEAD 20f5dc9: `OK (117 tests, 932 assertions)`. Separately: unit
  `OK (89 tests, 724 assertions)` and feature `OK (28 tests, 208 assertions)`.
- **Red on the 3d6b755 source.** Both source files were replaced by `git show 3d6b755:…`.
  - Filtered `group-readable real mapping|repository file as the mapping` gave
    `Tests: 2, Assertions: 4, Failures: 2` ("Failed asserting that two arrays are identical").
  - Full feature: `Tests: 28, Assertions: 200, Failures: 2` (only those two).
  - Full unit: `Tests: 89, Assertions: 723, Failures: 1` (bare CR).
- **Red on the 37b80be sheet.** Filtered `bare carriage return in an unquoted field` gave `Failed asserting that
  exception of type "InvalidArgumentException" is thrown`, `Tests: 1, Assertions: 1, Failures: 1`. Full unit:
  `Tests: 89, Assertions: 723, Failures: 1`.
- **Green at 37b80be.** The sheet and unit test were set to 37b80be: unit `OK (88 tests, 722 assertions)` and
  feature `OK (28 tests, 208 assertions)`.
- **Mutants** (`mutate.py`). Each was applied in place, then restored and sha256-verified.

  | Mutant | Result |
  | --- | --- |
  | bare-CR rule removed | killed (unit 1 failure) |
  | fixture mapping need not be synthetic itself | survived (I-a) |
  | proof-bytes comparison removed | survived (I-a, older) |
  | fstat size check removed | survived (documented) |
  | CR advance `+2` → `+1` | survived (equivalent) |

- **Non-root.** The feature file ran as uid 65534: 27 of 28 passed, with 1 error caused by the simulation, as
  described above.
- **Probes:** `fuzz.php`, `fuzz-utf8.php`, `legit.php`, and the CLI boundary probes. Each probe's temporary
  directories were removed, along with the temporary `tests/Fixtures/review-probe-official-mapping.json`.
- `vendor/bin/pint --test` on the 4 changed PHP files: passed. `git diff --check 3d6b755..20f5dc9`: clean.
- At the end: `sha256sum -c orig.sha256` and `orig2.sha256` were OK, `git status --short` was empty, and HEAD was
  `20f5dc9941dff75e274ed2d26436871d748bbadc`.

## Optional follow-ups (non-blocking)

1. L-a: add `export_unreadable` to the guide's reason codes ("the export could not be staged in memory intact").
2. L-b: update the `BeatStarsDryRunFiles` class docblock to say that the mapping is private too.
3. I-a: add a feature case for an `official_export` mapping under `tests/Fixtures/` with a private export, expecting
   `input_inside_repository`.
