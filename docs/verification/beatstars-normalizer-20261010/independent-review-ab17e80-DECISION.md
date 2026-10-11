# Review: beatstars-normalizer 58d16e2..ab17e80 (Codex P2s, PR #89)

**Decision: APPROVE WITH CONDITIONS.** All four fixes are correct and fail closed. No finding is above Low; the conditions make the docs match the code.

## Findings

- **L1: the privacy check is not tied to the read.** `export()` reads through `input()` (BeatStarsDryRunFiles.php:76), then checks uid/mode/nlink on a separate `lstat` (:82). That stat is never compared with the dev/ino `input()` verified (:110), so a rename swap between the two checks a different inode. `PrivateSourceFiles::read` checks privacy on the stat it reads and compares uid/mode/nlink, so "as PrivateSourceFiles::read does" (:93) overstates parity. Exploiting it needs write access to the export's parent directory.
- **L2: whole-second timestamps.** PHP stat times are whole seconds, so a same-size, same-inode rewrite within one second goes undetected. The README overstates this fix.
- **L3: two trims remain.** `rights_reference` is trimmed with no finding (Normalizer.php:328); a probe confirmed a padded reference was normalized. A `slug` column is trimmed too (:207), though the raw slug is kept and duplicates are still caught; the test name `..._slug_and_currency_columns_are_checked_exactly` overstates it. Source id, visibility, license and currency are exact, and no finding is reported twice.
- **L4: the boundary covers only this checkout** (:77-78). A probe admitted a 0600 export from another checkout; 0644 checkout files are refused.
- **I1: four guards untested.** Mutants survive for the uid check and all three identity comparisons; the README discloses only the concurrent-rewrite gap.
- **I2: docs say mode `0600`**, but any mode without group/other bits passes; 0400 was admitted in a probe.

## Answers

- **Repository root:** `dirname(__DIR__, 4)` is correct because `__DIR__` resolves symlinks. A run through a symlinked checkout still refused with `input_inside_repository`.
- **Path aliases:** a symlinked export path is refused (`path`), and a hard link fails nlink. Other checkouts, bind mounts and case variants reach the private branch (L4).
- **Mapping file:** it doesn't need the same boundary. It is operator configuration, copied verbatim into the report, and the fixture mapping lives in the repository. Its `acquisition_method` is only self-attested.
- **`synthetic_fixture` exception:** tight enough. Titles must start with `SYNTHETIC ` (:199-201), so a real export in the checkout is fully withheld. Restricting it to `tests/Fixtures/` would be a cheap option.
- **(d):** sound apart from L1 and L2. `maxlength+1` with size equality catches growth, and the final `lstat` after `fclose` catches replacement. The `output_differs` path still works.

## Conditions

1. Check uid/mode/nlink on the stat `input()` verified (have it return that stat), or document the gap. Either way, correct the parity claim.
2. In the README, state the one-second limit and list the surviving mutants.
3. Make `rights_reference` exact or document the trim (also slug), and rename or fix the slug test.
4. Change "mode `0600`" to "no group or other permission bits".

Optional: refuse any ancestor directory that contains `.git` (L4).

## Commands and results

- Worktree tests at ab17e80: unit `OK (78 tests, 695 assertions)`, feature `OK (17 tests, 139 assertions)`. The worktree is unchanged.
- Pint `--test` (scratch copy): passed.
- 14 mutants in a scratch copy with its own autoloader (`mutants.txt`):
  - Killed (10): nlink and mode checks; trims on visibility, the early path, currency, license and the interpret() id; the forced synthetic exception; the early-path invalid check; the prefix test.
  - Survived (4): the uid check, the final `lstat`, before-vs-after and before-vs-lstat.
- CLI probes: symlinked checkout, symlinked path, other checkout at 0644 and at 0600, synthetic file in the checkout, 0400 export, padded rights reference.
