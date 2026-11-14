DECISION: APPROVE at c94770ad261875cda6abfd331485529b8eaf8200

All conditions are closed. I found no P0, P1 or P2.

**Conditions**
- **P2-A: closed.** A copy is now dropped only when the listing succeeded (exit 0, or 3 for a missing path) and showed no ciphertext (backup.sh:476–483). In my outage probe the copy is kept with "the remote could not be listed", and the retry after the outage ships with exit 0. The guide's text (around lines 118–121) and the log line are now accurate.
- **P3-B: closed.** "Already shipped" now also requires the size from `lsf --format sp` to match the record (423–425, 457–458). A replaced ciphertext of a different size and a deleted one are both refused. The guide (around line 117) states honestly that this is a size check, not a content check.
- **P3-C and the nit: closed.** R-b and R-c are now killed by the two new tests.
- **P3-D: closed.** The guide sentence on the deploy runner's time limit is added; it marks Forge as not verified.
- **R-a: equivalent only while the record holds a non-empty size.**
  - For a missing ciphertext, `remote_size` prints nothing, which does not equal a recorded number, so the ship is refused.
  - With a record that lacks a size, R-a would also accept a deleted ciphertext.

**New finding**

P3-1 (nit): a record without a size, combined with a failing size listing, passes the check.
- **What the code does:** `remote_size` turns any `lsf` failure into empty output (424). An empty `size=` (458) therefore equals it.
- **Probe:** with the record cut to only the digest line, or to `size=`, and `lsf --format sp` failing, a repeat ship over a garbage ciphertext of the same name exits 0 "already shipped".
- **How a record gets there:** the code writes `size=` only if `stat` fails, because the echo still succeeds (486). A record in the a28a87c5 format would also qualify, but none exists outside tests.
- **Risk:** low. A well-formed record plus a failing listing is refused.
- **Remedy:** require `^[0-9]+$` on both sides, and fail the record write when `stat` fails. That also makes R-a truly equivalent.

The `$shipped.new` redirect would follow a planted symlink, which needs root write access in the 0700 backups directory. That is negligible.

**Checks** (fresh `git archive` scratch copy; vendor set up as briefed; `App\` confirmed to resolve to the scratch copy)
- **Ops suite:** **313 OK**.
- **PHPUnit, the three files:** **149 tests, 2,693 assertions, OK**.
- **Kit:** `build-kit --check` ok, 13 files; `--delta` byte-identical to `semantic-delta.diff`.
- **Lint:** `bash -n` and shellcheck at default severity are clean on backup.sh.
- **Mutations:** 7 run, 6 killed, 1 survived.
  - Killed: R-b, R-c, size comparison removed, listing failure ignored, record written without size, digest compared against the whole record.
  - Survived: R-a, which is conditionally equivalent as described above.
  - The scratch backup.sh was restored and is byte-identical to HEAD; the worktree is clean.
- **Probes:** real rclone 1.60.1 against temp local remotes only.

**Not verified**
- Any real remote, or the host's rclone version.
- An `lsf` permission error (I ran as root).
- Forge, systemd or cron.
- PHPUnit beyond the three files.
- The round-four evidence file, which I only spot-checked.
