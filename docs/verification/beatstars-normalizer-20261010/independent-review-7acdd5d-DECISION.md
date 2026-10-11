# Review: beatstars-normalizer 7acdd5d

**Decision: APPROVE WITH CONDITIONS.** The code is correct and fails closed. The prior review's four conditions and the optional `.git` check are met. The conditions below cover one test gap and one docs gap.

## Findings

- **L1: the run-level rollback is untested.** The fixture's JSON report is 11,309 bytes, so the 4 KiB case fails inside the first `stage()`. It exercises only `stage()`'s own unlink (BeatStarsDryRunFiles.php:213). Deleting the rollback loop (:77-79) survives all 99 tests. A probe kills that mutant: keep an identical JSON report, then run a 2-row sheet with long descriptions under `ulimit -f 6`. The real code leaves only the JSON; the mutant leaves `beatstars-dry-run.md.pending`.
- **L2: the docs understate the boundary.** `PrivateSourceFiles::directory` (:92-98) also requires the export directory's parent to be **caller-owned**, and every ancestor to be non-group/world-writable unless it is root-owned and sticky. The guide (:31-32), the verification README row and the comment at :102-104 omit this. As a non-root user, an export in `mktemp -d /tmp/...` is refused with `input_not_private`.
- **I1: the rename phase is correct but unpinned.** Mutants survive for :74 (`$created[$index] = $path`), :73 (`! exists()`) and :110 (bytes comparison). `rename()` replaces an existing file, so :73 is check-then-act. That only matters with same-user writes into a 0700 directory.
- **Rollback safety:** rollback can never delete a file this run did not create. Only `$written` names are staged, so `unchanged` files are never included. A path enters `$created` only after an O_EXCL `fopen` succeeds. A pre-existing `.pending` is refused earlier (`output_directory_not_empty`, or `stage()`'s check). When rename *i* fails, the published files 0..i-1 and the staged files i..n are all removed; a final file someone else creates is kept.
- **export():** fails closed. The returned bytes are the privacy-proven ones, and a 0400 export is refused (exact 0600, as documented).
- **CI portability:** the `ulimit` wrapper needs only bash, a lowered RLIMIT_FSIZE (allowed for a non-root user) and SIG_IGN inherited across exec. It does not depend on who owns /tmp. The private-dir helper relies on /tmp being root-owned and sticky (true on GitHub runners) and would break under a group-writable TMPDIR.

## Conditions

1. Add a feature case for the run-level rollback (the probe shape above), or record the surviving mutant in the verification README.
2. Correct the boundary text in all three places: the parent must be caller-owned, and the ancestor rule applies. Advise a 0700 directory under the operator's home, not directly under /tmp.
3. List the surviving rename-phase and bytes-comparison mutants in the verification README.

## Commands and results

- Worktree at 7acdd5d, as root: unit `OK (79 tests, 704 assertions)`, feature `OK (20 tests, 157 assertions)`. Nothing was modified.
- A copy owned by a temporary non-root user (`useradd`/`runuser`): unit 79 OK, feature 20 OK. Probes: export under `mktemp -d /tmp` refused; a nested caller-owned directory ran clean; 0400 refused.
- Mutants (`mutants.txt`): 5 killed (Fixtures prefix, rights trim, slug trim, `stage()` unlink, skipping `protectedFile`); 4 survived (rollback loop, :74, :73, :110). `probe.sh` kills the rollback-loop mutant.
