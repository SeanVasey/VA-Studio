DECISION: APPROVE WITH CONDITIONS

# Review: beatstars-normalizer d880e3c..65f6107

Reviewed range: `d880e3c7f250fba6b0dfc212d64ec0625c01a2ec..65f61078c2ea4c3cee183fe791175ffad10ccbdb` (one commit, 7 files),
worktree `/home/user/wt-beatstars`, branch `harness/beatstars-normalizer`. Run as root in the container, PHP 8.4.26.

Both Codex P2 findings are fixed correctly, and the tests for each fix fail on the old source. One Low defect is new
in this delta: the rollback that runs when the post-publication identity pin fails. One Low inaccuracy is in the
evidence text. Neither blocks a development merge. Both should be addressed before final acceptance (conditions below).

## Findings

- **Padded headers: fixed** (BeatStarsExportSheet.php:40-44). Headers are no longer trimmed. A header whose `trim()`
  differs is refused as `export_header`.
  - Probed through the real reader. Refused: quoted ` "Track ID "`, a leading VT and a trailing FF.
  - Still admitted: CRLF, BOM+CRLF, and a quoted last header with CRLF, so `fgetcsv` removes the line ending before
    the check.
  - A header with NBSP (U+00A0) padding is admitted verbatim. That is acceptable: nothing is sanitized, so the
    mapping must declare the exact bytes and `export.columns` records what was exported.
  - Info: the explicit `$header === trim($header)` is redundant, because `CellText::line` already requires
    `trim($value) === $value`. Removing it is an equivalent mutant. It is harmless and documents intent.
- **Output-directory ancestors: fixed** (BeatStarsDryRunFiles.php:167-180). Probed `directory()` by reflection on a
  scratch copy:

  | Case | Result |
  | --- | --- |
  | parent 0755 root | admitted |
  | parent 0770 root | refused `output_directory` |
  | parent 0757 root | refused `output_directory` |
  | parent 0777 root, not sticky | refused `output_directory` |
  | parent 1777 root | admitted |
  | parent 1770 root | admitted |
  | parent 0700 owned by uid 65534 | refused `output_directory` |
  | parent 1777 owned by uid 65534 | refused `output_directory` |
  | grandparent 0777 root, not sticky | refused `output_directory` |
  | symlinked parent | refused `path` |
  | `/` as the output | refused `path` |
  | leaf 0770 | refused `output_directory` |

  - The loop covers every ancestor, including `/`. The leaf keeps its own check (0700, owned by the caller).
  - `path()` runs first and rejects symlinks and `realpath` aliases, so `lstat` on each ancestor is sound.
  - POSIX ACL write grants raise the group-mode mask, so they are caught by `0022`. `setfacl` is not installed here,
    so this was reasoned, not probed.
  - The rule is the right one for preventing a rename: only the owner, root or a writer of the parent can rename an
    entry, and the sticky bit limits that to the entry's owner.
- **Refusal order (info, no regression).** For each ancestor, the `.git` check runs before the new permission check.
  A badly-permissioned ancestor *below* a `.git` ancestor now reports `output_directory` instead of
  `output_inside_repository`. Both are refusals, and all existing refusal cases still pass.
- **L1 (Low): the rollback after a post-publication pin mismatch deletes the wrong files** (BeatStarsDryRunFiles.php:76-80).
  - The check sits inside the `try`. On a mismatch, `catch` unlinks `$outputDirectory/<name>` by path, which now
    resolves to the *replacement* directory.
  - Probe: a scratch copy of the class with a hook just before line 76 renamed the output directory away and created
    a replacement holding `beatstars-dry-run.json`. Result:
    - the run refused `output_directory`, as it should;
    - the replacement directory's `beatstars-dry-run.json` was deleted, although this run did not create it;
    - all three published outputs were left in the pinned directory that had been moved away.
  - So the comment "on any failure remove every file this run created" is false on this path, and the rollback can
    destroy an unrelated file.
  - Exposure is limited. After this commit's ancestor rule, only the caller or root can make the swap, and the files
    left behind sit in the caller's 0700 directory. The pin is defense in depth, and it is untested (as stated in the
    evidence).
  - The second-admission pin (line 63) is outside the `try` and runs before anything is created, so it is correct.
- **L2 (Low, docs): one evidence claim is inaccurate.** The evidence says "the group-writable case exercises the
  same check" for an ancestor owned by another user. It does not.
  - The mutant that removes `in_array($entry['uid'], [0, posix_geteuid()], true)` survives both test files.
  - The group-writable test kills the mode clause, not the owner clause.
  - This container runs as root and can `chown` (the probe above did). A test that runs only when euid is 0 and is
    skipped elsewhere would cover the owner clause.
- **I1 (info): other untested clauses.** Each mutant below survives:
  - granting the sticky exemption to any owner instead of only root;
  - `0022` changed to `0020`, so a world-writable-only ancestor is untested;
  - removing the ancestor is-directory check (equivalent, because `path()` already guarantees directories);
  - removing either identity pin, or making `sameDirectory()` always true (documented in the evidence as untested).

  The code is correct for every one of them (see the probe table).
- **Docs.**
  - The guide's ancestor sentence and `export_header` text are accurate.
  - The README's "Codex round on `d880e3c`" section is accurate. It says the directory's dev/ino "is pinned from
    admission through publication" without mentioning the L1 rollback behavior.
  - The evidence's red/green counts match this run: red is 2 unit and 1 feature failure; green is 86/718 and 24/183.
  - Info: the class docblock (lines 17-19) does not mention the ancestor rule. The method docblock does.

## Commands and results

- Both test files at HEAD 65f6107, with the given env/direct-PHPUnit command:
  - Unit: `OK (86 tests, 718 assertions)`.
  - Feature: `OK (24 tests, 183 assertions)`.
- **Red check.** The two source files were replaced in place with their `d880e3c` versions (`git show`), the full
  new test files were run, and the files were restored and confirmed by sha256.
  - Unit: `Tests: 86, Assertions: 716, Failures: 2` (padded header, trailing tab).
  - Feature: `Tests: 24, Assertions: 178, Failures: 1` (group-writable parent).
- **Mutants** (`mutate.py`). Each was applied in place to the backed-up file, run against the relevant test file,
  then restored:

  | Mutant | Result |
  | --- | --- |
  | trim map restored | killed (2 failures) |
  | explicit trim check removed | survived (equivalent) |
  | ancestor check disabled | killed |
  | ancestor owner check removed | survived (L2) |
  | sticky exemption for any owner | survived (I1) |
  | sticky exemption removed | killed (the `/tmp` cases fail) |
  | `0022` to `0002` | killed |
  | `0022` to `0020` | survived (I1) |
  | ancestor is-directory check removed | survived (equivalent) |
  | second-admission pin removed | survived (documented) |
  | post-publication pin removed | survived (documented) |
  | `sameDirectory` always true | survived (documented) |

- `probe.php`: the `directory()` table and the swap-rollback probe (L1), on a renamed scratch copy of the class with
  one hook line.
- `headers.php`: the header edge cases above.
- `vendor/bin/pint --test` on the 4 changed PHP files: passed. `git diff --check d880e3c..65f6107`: clean.
- At the end, `sha256sum -c orig.sha256` was OK for both source files, `git status --short` was empty, and HEAD was
  `65f61078c2ea4c3cee183fe791175ffad10ccbdb`.

## Conditions

1. **L1.** Make the rollback identity-safe. Two acceptable fixes:
   - Preferred: record each staged file's dev/ino (`fstat` in `stage()`), and in `catch` unlink a path only when its
     `lstat` dev/ino still matches.
   - Alternative: do not unlink at all after a post-publication pin mismatch.

   Either way, state in the README/guide that a mismatch can leave the outputs in the original, moved directory.
2. **L2.** Correct the evidence sentence about an ancestor owned by another user. Either say the owner clause is
   untested, or add a test that runs only as root (`posix_geteuid() === 0`, otherwise skipped) that `chown`s the
   parent to another uid and expects `output_directory`.
3. Optional (I1): add cases for a world-writable-only (`0757`) parent, and for a caller-owned sticky writable parent
   (non-root runner only).
