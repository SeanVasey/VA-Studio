DECISION: APPROVE WITH CONDITIONS

# Review: beatstars-normalizer 65f6107..3d6b755

Reviewed range: `65f61078c2ea4c3cee183fe791175ffad10ccbdb..3d6b755357b085482e8fdedf3d62320b2e17301f` (one commit, 9 files),
worktree `/home/user/wt-beatstars`, branch `harness/beatstars-normalizer`. Run as root in the container, PHP 8.4.26, ext4.

Both Codex P2 findings are fixed correctly. Both conditions from the 65f6107 review (L1, L2) are met. The new tests
fail on the old source, and the red/green counts in the evidence match this run exactly.

One Low finding remains. It predates this delta: a bare CR outside a quoted field is still admitted, and it causes
the same record-splitting ambiguity that Codex finding (a) was about. It is not recorded as an accepted gap. There
are two Info items about the L1 rollback. Nothing here blocks a development merge.

## Findings

- **(a) Strict quotes: fixed** (BeatStarsExportSheet.php:65-94). An unquoted field now runs to the next `"`, `,` or
  LF, and stopping at a `"` is refused. This covers whitespace before a quote, which was the earlier rule.
  - **Differential fuzz.** `fuzz2.php` calls the real `quotes()` by reflection. It ran 300k inputs for each of three
    alphabets:
    - tokens without a bare CR: `a , " LF CRLF space tab ""`;
    - tokens with a bare CR: the same plus `CR VT FF`;
    - raw bytes: `a , " LF CR space tab`.

    Each admitted input was compared, using `fgetcsv(…, ',', '"', '')`, with a strict RFC 4180 reader
    (`rfc.php`, which accepts LF or CRLF line endings). Across all 900k inputs:

    | Check | Result |
    | --- | --- |
    | admitted inputs with a quote in an unquoted cell | 0 |
    | RFC-valid inputs refused | 0 |
    | admitted inputs that differ from the RFC reader and contain no bare CR | 0 |
    | inputs with no bare CR (126,164 admitted) | `fgetcsv` agrees exactly with the strict reader |

    Every remaining difference (19,679 and 32,114 in the two alphabets that contain a CR) contains a bare CR. Among
    them, every difference that also involves a quote disappears once bare CRs are removed (0 remain). A separate
    random-byte fuzz (`fuzz.php`, 300k inputs) gave the same result.
  - **Legitimate RFC 4180 input is still admitted** (`legit.php`, through `read()`): BOM with CRLF, BOM with a quoted
    header, no trailing newline (after an unquoted or a quoted last field), quoted multi-line LF and CRLF, a bare CR
    inside quotes, doubled quotes (`"12"" vinyl"` and `""""`), empty quoted fields (at the end and mid-record), a
    quoted comma, empty unquoted cells, a blank line between records, a trailing comma at EOF, and spaces inside an
    unquoted cell. Both synthetic fixture CSVs are still admitted.
- **N1 (Low, pre-existing): bare CR outside quotes is inconsistent, and another reader can split it differently.**
  - The quoted branch refuses a bare CR after a closing quote (`"x"\ry`). The unquoted branch admits a bare CR
    anywhere.
  - `fgetcsv` strips trailing CRs from an unquoted cell (`a\r,b` gives `["a","b"]`; `a\r\r\n` gives `["a"]`) and
    keeps a mid-cell CR (`title\r1`).
  - Concrete case: `Track ID,Title,Notes\nR1,a\r,"b\nc"\n` is admitted by `read()` as one clean three-column record
    `["R1","a","b\nc"]`, and the CR is silently dropped. Python's `csv` module reads the same bytes as two short
    records, `['R1','a']` and `['','b\nc']` (`crsplit.php` and `crsplit.csv`).
  - This is the harm class of Codex finding (a): one reader sees one record, another sees two. It predates this
    delta. The 67d7a89 review recorded it as "differences … involve bare CR", and the README repeats that only
    inside the delta-review summary. It is not listed under "Integration scope and accepted gaps", and the guide does
    not mention it.
  - Real BeatStars exports are unlikely to contain a bare CR, and a mid-cell CR in a single-line field becomes a
    finding through `CellText::line`. The trailing-CR strip and the record split are silent, though.
- **(b) Retained outputs must be caller-owned 0600: fixed** (BeatStarsDryRunFiles.php:195-197).
  - Probed through a hooked scratch copy of the class (`retained.php`). An identical rerun is admitted
    (`unchanged` = all three outputs). A retained `catalog.json` with mode 0400, 0644 or 0700, with uid 65534, or with
    a second hard link is refused as `output_directory`.
  - Order: the check is in `directory()`, which runs before the identical-bytes comparison. A retained file with the
    wrong mode and different bytes therefore reports `output_directory`, not `output_differs`. A foreign file still
    reports `output_directory_not_empty`. Both are refusals, and every existing refusal case passes.
  - No regression on reruns. `stage()` writes 0600 with umask 0077 and an explicit chmod, and the feature rerun test
    (line 79) and the probe both pass with zero writes.
  - `posix_geteuid()` in the loop is safe, because line 169 has already refused when the function is missing.
- **L1 (from the 65f6107 review): met.** `stage()` returns the path, dev and ino from `fstat`. On failure, `catch`
  unlinks a path only while its `lstat` dev/ino still match.

  Probe results (`probe/l1.php` on a scratch copy with four hook points):

  | Case | Reason | Result |
  | --- | --- | --- |
  | A: swap after publication | `output_directory` | Moved original holds all three outputs. Replacement `beatstars-dry-run.json` intact (the evidence's claim, reproduced). |
  | B: foreign file appears at the second target before its rename | `output_write` | The first published file and both `.pending` files are removed. The foreign file is intact. |
  | C: foreign `.pending` appears before the second stage | `output_pending_exists` | The first `.pending` is removed. The foreign `.pending` is intact. |
  | D: swap after the first rename | `output_write` | Replacement intact. The moved original keeps the published file and two `.pending` files (see N3). |

  Staging failure paths:
  - A write, chmod or fsync failure unlinks its own `.pending` before throwing.
  - The `fstat`-failure branch unlinks and refuses `output_write`. It cannot be triggered on a valid handle, and it
    is correct by reading.
  - A rename failure leaves `$created[i]['path']` on the `.pending` path, so only this run's file is considered.
  - The mutant "rollback never unlinks" and the mutant "stage records ino 0" are both killed by the write-failure
    feature case. So `fstat` and `lstat` dev/ino do agree on this filesystem, and ordinary rollback is exercised.
- **N2 (Info): inode reuse.** The dev/ino identity holds only while the run's file exists.
  - On ext4 a freed inode is reused immediately (`probe/reuse.php`: 200 of 200 trials).
  - Probe E: after publication, the run's `beatstars-dry-run.md` was deleted and a different file was created at the
    same path. That file reused the inode and was deleted by the rollback.
  - Only the caller or root can do this inside the run window, because the ancestor rule and the 0700 directory
    prevent anyone else.
  - Optional hardening: keep each staged handle open until `run()` returns, so an open descriptor pins the inode,
    and compare against that handle's `fstat`.
- **N3 (Info, docs): a mid-publication swap leaves files behind.** In probe D the run reports `output_write`, but the
  published file and `.pending` files stay in the moved original.
  - Guide line 39 says "every file the run created is removed before it reports `output_write`". The README
    describes only the post-publication case.
  - The same leftovers existed before this delta, and only the caller or root can cause them.
- **L2 (from the 65f6107 review): met.**
  - The sentence in `codex-padded-header-and-output-ancestors-red-green.txt` is corrected accurately.
  - The new evidence says the other-owner clause stays untested, which is accurate.
  - The new 0757 case kills the `0022`→`0020` mutant, so optional item I1 from the last review is partly addressed.
- **I1 (info): untested clauses.** Each mutant below survives both files:
  - rollback unlinks by path (the pre-L1 behavior). The evidence honestly calls the L1 swap a probe, not a test.
  - the retained uid clause removed;
  - the retained mode relaxed from `=== 0600` to "no group or other bits", which would admit 0400.

  This container runs as root, so a root-only `chown` case could cover the uid clause. The code is correct for each
  of them (probes above).
- **Docs and evidence are accurate.**
  - Guide: the `export_csv_quotes` text, including the `12" vinyl` → `"12"" vinyl"` guidance, and the output-directory
    sentence ("outputs are still caller-owned `0600` files").
  - README: the new section and its conditions recap.
  - Evidence file: red is unit `Tests: 2, Assertions: 2, Failures: 2` and feature `Tests: 1, Assertions: 2,
    Failures: 1`; green is 88/722 and 26/196; the A swap probe output is reproduced.
  - The guide's "strict RFC 4180 quoting" claim is correct as worded (quoting only), but see N1 for line endings.
  - The docblock on `run()` is accurate except for the inode-reuse edge (N2).

## Commands and results

- Given command, both files together at HEAD 3d6b755: `OK (114 tests, 918 assertions)`. Run separately: unit
  `OK (88 tests, 722 assertions)` and feature `OK (26 tests, 196 assertions)`.
- **Red check.** The two source files were replaced in place by `git show 65f6107:…`, the full new test files were
  run, and the files were restored (`git checkout`, then `sha256sum -c orig.sha256` OK).
  - Unit: `Tests: 88, Assertions: 720, Failures: 2` (quote after text, quote opening mid-field).
  - Feature: `Tests: 26, Assertions: 191, Failures: 1` (retained report readable by others).
  - The evidence's filtered runs were reproduced exactly: `Tests: 2, Assertions: 2, Failures: 2` and
    `Tests: 1, Assertions: 2, Failures: 1` ("Failed asserting that 0 is identical to 1").
  - The 0757 case passes on the old source as well. It is coverage, not red, and the evidence does not claim it is red.
- **Mutants** (`mutate.py`, `mutate2.py`). Each was applied in place, then restored and sha256-verified.

  | Mutant | Result |
  | --- | --- |
  | rollback never unlinks | killed |
  | rollback unlinks by path | survived (I1) |
  | retained mode clause removed | killed |
  | retained uid clause removed | survived (I1) |
  | retained 0600 relaxed to no group/other bits | survived (I1) |
  | stage records ino 0 | killed |
  | quote-in-unquoted check removed | killed (4 failures) |
  | `strcspn` without `"` | killed (4 failures) |
  | ancestor `0022`→`0020` | killed (0757 case) |

- Fuzz and probes: `fuzz.php`, `fuzz2.php`, `rfc.php`, `show.php`, `legit.php`, `crsplit.php`, `probe/l1.php`,
  `probe/retained.php` and `probe/reuse.php`. The hooked copy is `probe/cls/a/b/c/d/ProbeDryRunFiles.php`, nested so
  that `dirname(__DIR__, 4)` falls outside the probe's run directories.
- `vendor/bin/pint --test` on the 4 changed PHP files: passed. `git diff --check 65f6107..3d6b755`: clean.
- At the end, `sha256sum -c orig.sha256` was OK for both source files, `git status --short` was empty, and HEAD was
  `3d6b755357b085482e8fdedf3d62320b2e17301f`.

## Conditions

1. **N1, before final acceptance.** Choose one:
   - Refuse a bare CR outside a quoted field. In the unquoted branch, add `\r` to the `strcspn` set and require it to
     be followed by `\n`, as the quoted branch already does. Add a unit case for `R1,a\r,"b\nc"`.
   - Record bare CR in unquoted cells as an accepted gap, in the README's "Integration scope and accepted gaps" and
     in the guide, stating that `fgetcsv` drops trailing CRs and that other readers treat a CR as a record break.
2. Optional (N2): pin each staged inode by holding its handle open until `run()` returns.
3. Optional (N3): qualify guide line 39 so it notes that a directory swapped mid-run keeps this run's files in the
   moved original.
4. Optional (I1): add a root-only case that `chown`s a retained output and expects `output_directory`.
