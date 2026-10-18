# Ops follow-up batch: evidence packet (lane O2, 2026-10-18)

Branch `harness/ops-followup-20261018`, based on `origin/main` `efb22df3`. It closes the findings that PR #148 (lane O1) deferred
under the freeze-and-batch rule (`docs/handoff/2026-10-07/codex-deferred-20261011.md`, the O1 and PR #148 entries). Nothing was
pushed, no PR was opened and no workflow was dispatched. Nothing ran on a real host or against a real remote: every run used
temporary directories, generated throwaway age keys, the real `age`, `rsync` and `rclone` (local backend), and stand-in scripts
for `systemctl`, `ufw` and the like. No application code, route, config, census file or workflow changed.

## Commits

| Commit | Items | Files |
| --- | --- | --- |
| `b8ac48a8` fix(ops): converge off-host backup retries and serialize hardened ships | 1, 4, 5 | `ops/staging/backup.sh`, `tests/ops/test_staging_backup_offhost.py`, `docs/ops/backup-offhost-hardening.md` |
| `c531c15f` fix(ops): fail apply-unattended-upgrades when the apt timers cannot start | 2 | `ops/monitoring/host-baseline.sh`, `tests/ops/test_monitoring_scripts.py`, `docs/ops/monitoring-and-host-baseline.md` |
| `1dd10a98` fix(ops): keep an opt-in clamd running when staging is re-provisioned | 3 | `ops/staging/provision.sh`, `ops/production/build-kit.py`, `tests/ops/test_clamav_profile.py`, `docs/verification/production-ops-kit-20261017/semantic-delta.diff` |
| `9b053ae5` test(ops): check the env scrub of the built production forge-deploy.sh | 7 | `tests/ops/test_staging_code_drift.py` |
| `810794a8` docs(ops): say where the twelve held flags sit and why the synthetic flag is not held | 6, 8 | `ops/staging/env.staging.example`, `scripts/ops/capability-boundary.php` |
| `a1c94023` docs(ops): record the O2 follow-up batch evidence and changelog | docs | `CHANGELOG.md`, this directory |
| `108c04ae` fix(ops): refuse to overwrite a differing remote backup object on any rclone | R2-1 | `ops/staging/backup.sh`, `tests/ops/test_staging_backup_offhost.py`, `docs/ops/backup-offhost-hardening.md` |
| `f6cb3b2d` test(ops): pin semantic-delta.diff to build-kit.py --delta | R2-2 | `tests/ops/test_production_kit.py` |
| `cd35fb15` docs(ops): record the O2 round-two evidence and changelog | docs | `CHANGELOG.md`, this directory |
| `d8cff5e7` fix(ops): refuse to ship over existing remote objects and bound kept copies | R3 P2-1, P2-2, P3-1 to P3-4 | `ops/staging/backup.sh`, `tests/ops/test_staging_backup_offhost.py`, `docs/ops/backup-offhost-hardening.md` |
| `22d1bd81` test(ops): host baseline must not accept an enabled-runtime apt timer | R3 P3-4 | `tests/ops/test_monitoring_scripts.py` |
| `ebc93106` fix(ops): match the clamd profile marker only as the exact first line | R3 P3-5 | `ops/staging/provision.sh`, `tests/ops/test_clamav_profile.py` |
| `9b360103` test(ops): follow the first-line clamd marker guard in the production kit test | R3 P3-5 | `tests/ops/test_production_kit.py` |
| this update's commit | docs | `CHANGELOG.md`, this directory |

**Tested source:** the first-round checks ran at `810794a8` and again at `a1c94023`; the round-two checks at `f6cb3b2d` and again at
`cd35fb15`, which the independent review approved with conditions. After the round-three fixes (below), every check in the
verification table ran at `9b360103`, the last code commit. The commit that updates this packet adds only documentation; the full
suite was run again on it, and the lane report gives that SHA.

## Items: what changed, and red/green

Red means the new or changed test run against the script as it is at `efb22df3`; green means the same test against the fix. Raw
output for each is in [`evidence/red-green-and-mutations.txt`](evidence/red-green-and-mutations.txt).

### 1. Codex P2 r4305404770: off-host retries converge

- **Problem.** In `ship_hardened`, a failure after the first `rclone copyto` removed the staging directory. A retry encrypted the
  set again, age never produces the same ciphertext twice, and a destination that refuses overwrites then refuses the new bytes:
  the set could never ship under its name.
- **Fix** (`ship_encrypted_rclone`, `retry_copy_ok`). On the age-plus-rclone path, the encrypted copy goes into a private per-set
  directory, `backups/.offhost-retry.<set>` (0700, renamed atomically from the staging directory). It holds three files: the
  ciphertext, its digest, and a digest of the recipients file. All transfers send from there. The copy is removed only after
  `rclone check --one-way` passes. A retry (`ship <set dir>`) re-sends the kept bytes, and only if all of these hold:
  - the copy is complete: real files, not links;
  - it starts with the age header;
  - it matches its digest;
  - it was made for the recipients configured now.

  Otherwise the copy is discarded and the set is encrypted again. If the remote then already holds a different
  `<set>.tar.age`, the ship stops with a message to remove that object first. Under the lock (item 4), the next hardened ship
  drops a kept copy whose set has been pruned, since shipping needs the set and its proof.
- **Kept as required.**
  - Plaintext never goes off the host. The kept copy is ciphertext and digests only.
  - Encryption is still to public recipients only, with no new key handling.
  - The refusal of a remote without recipients is unchanged, because `offhost_config_ok` still runs first.
  - The rsync path and the explicit unencrypted-remote path are unchanged.
  - The default path is unchanged: `ship()` takes the same branch, and a new assertion checks that it creates nothing in the
    backup directory.
- **Test.** `test_a_failed_checksum_upload_is_completed_by_a_retry_with_the_same_ciphertext`. The rclone is real, behind a stand-in
  that fails the first checksum upload. The first ship fails and the remote holds only `<set>.tar.age`. The retry succeeds: the
  remote file is byte-identical to the first attempt's, its digest verifies, it decrypts with the test key, and nothing is left behind.
  - **Red:** `AssertionError: 1 != 0 : flaky-rclone: immutable file modified` (the retry re-encrypted and was refused).
  - **Green:** OK.
  - In this first round the stand-in also refused overwrites, because the script then used `copyto`, which 1.60.1 lets replace an
    object. Since R2-1 the script uses `copy --immutable`, the real rclone refuses, and the stand-in only simulates the interruption.
- **Further tests** (all red at `efb22df3`, green now):
  - the kept copy is private ciphertext only;
  - a copy made for rotated recipients is never sent;
  - a damaged copy is discarded;
  - a copy that is not age ciphertext, even with a matching digest, is never sent;
  - the copy of a pruned set is removed.

  `test_a_failing_remote_fails_the_ship_and_cleans_the_staging_copy` now also asserts that the retry copy is kept.

### 2. Codex P2 r4305404773: host baseline

- **Fix.** In `apply-unattended-upgrades`, a failed `systemctl enable --now apt-daily.timer apt-daily-upgrade.timer` now calls
  `die`: exit 2, a message naming both timers, and no "applied". `check` gains a row that is OK only when `systemctl is-enabled`
  prints `enabled` and `systemctl is-active` prints `active` for both timers. Anything else (`disabled`, `masked`, `static`,
  `inactive`, not found) is a WARN that names the units.
- **Tests:** `test_both_apt_timers_must_be_enabled_and_active` and `test_apply_unattended_upgrades_fails_when_the_timers_cannot_be_enabled`.
  The `systemctl` stand-in now answers the two queries from per-unit files. A conforming host still produces no mutation log.
  - **Red:** `0 != 2` (the apply printed "applied"), and the timer row was missing.
  - **Green:** OK.

### 3. Cursor Bugbot Medium r4305410457: staging clamd on re-provision

- **Fix.** The marker guard (`grep -qs '^# VASEY.AUDIO clamd profile' /etc/clamav/clamd.conf`) is now in `ops/staging/provision.sh`
  itself, and the production-only `Replace` patch in `build-kit.py` is removed, so the derived file inherits the guard.
  - The executable lines of the built production `provision.sh` are byte-identical to before (both kits were built and diffed).
  - Only its comment differs. The comment avoids the literal `install-clamd` because the existing guard
    `test_nothing_in_the_staging_kit_installs_or_enables_it` forbids that string in staging `provision.sh`; that guard was left as is.
  - `semantic-delta.diff` was regenerated with `python3 -I ops/production/build-kit.py --delta`, in the same format: the exact
    output, as before. It went from 223 to 213 lines: the clamd hunk is gone and the other hunks only moved.
- **Test:** `ReprovisioningKeepsAnOptInClamd`. It runs the block from the staging script and from the derived production script
  against a stub `systemctl`. With the installed profile there is no `disable`. With a packaged or absent `clamd.conf` there is one
  `disable --now clamav-daemon`.
  - **Red:** staging called `disable --now clamav-daemon` with the profile installed (production passed, through its patch).
  - **Green:** OK for both.

### 4. O1 re-review P3-2: the cleanup comment and the lock

- **Fix.** The lock was chosen over a comment change. `ship_hardened` takes a non-blocking `flock` on `backups/.offhost.lock`
  (fd 6; fds 7 to 9 are the existing locks) before any cleanup, encryption or transfer, and holds it until the staging directory
  is removed. A concurrent hardened ship, whether nightly, from a deploy or manual, refuses with "another off-host ship is
  running" before it removes anything.
  - The lock is refused if it is a symlink.
  - The default path takes no lock.
  - The comment now describes the lock and keeps the one-day age guard as a second margin.
- **Test:** `test_a_concurrent_hardened_ship_refuses_before_it_cleans_up_anything`. While the test holds the lock, the ship fails,
  a stale `.ship.OLD123` survives, and nothing is sent. Once the lock is released the ship succeeds and the stale directory goes.
  - **Red:** `0 == 0` (the ship ran under a held lock).
  - **Green:** OK.

### 5. O1 re-review P3-3: cleanup mutations

The stale-cleanup test gains a seven-character `.ship.ABCDEFG` decoy and sets the symlink target's mtime old as well. Both
mutations now fail it, and both passed the old test:
- broadening the name to `.ship.*` fails with "a seven-character suffix is not the .ship.XXXXXX shape";
- `find -L` on the cleanup line fails with "a link is never followed".

### 6. P3-6 and the related part of third-record P3-C: template wording

`ops/staging/env.staging.example` no longer says the twelve held flags are "below". It names the two that sit above
(`CONTACT_TEST_ORDER_INQUIRIES_ENABLED`, `CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED`) and the ten in the capability block
below, and says the synthetic catalog line after that block is not one of them. This is a comment-only change:
- the non-comment lines are byte-identical, and so are the commented assignments;
- the template-coverage tests (`test_staging_capability_profile`, 17) pass;
- PHPUnit for the three validator files passes.

Being a comment, it has no red run.

### 7. Third-record P3-B: the built production forge-deploy.sh

`test_the_built_production_deploy_scrubs_every_config_env_key_too` builds the production kit into a scratch directory with
`build_kit.build` and applies the same config-key coverage check to the built `forge-deploy.sh`. The red run used the mutation the
reviewer used, with the arm narrowed to `VASEY_STAGING_SYNTHETIC_*`:
- the new test fails and names `VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED` (the production rename turns the pattern into
  `VASEY_PRODUCTION_SYNTHETIC_*`);
- the pre-existing staging check alone still passes.

Green on the real arm.

### 8. Third-record P3-D remainder: capability-boundary.php

One clause in the `ADDITIONAL_FLAGS` docblock records why `VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED` is not held:
- it admits only the `vasey:staging-synthetic-catalog` console command, which refuses production whatever the flag says
  (`SyntheticRehearsalPolicy.php:31`);
- runbook section 5a sets it true for the seeding run (`docs/ops/staging-runbook.md:207`).

The change is a comment only. It passes `php -l` and `pint --test`, and the PHPUnit runs below pass.

## Mutations of the fixed code

Each mutation was applied alone, the test module was run, and the file was restored and compared byte for byte. Every mutation
below failed at least one test:
- **backup.sh:**
  - the `flock` removed;
  - the kept copy never reused;
  - the recipients digest not compared;
  - the ciphertext digest not compared;
  - copies of pruned sets kept;
  - the kept copy left after a verified ship;
  - the cleanup moved before the lock;
  - the age-header check removed.
- **host-baseline.sh:**
  - `is-active` dropped;
  - only one timer checked;
  - the enable failure turned back into a warning;
  - `is-enabled` judged by exit status, so `static` is accepted.

The age-header mutation first survived. That is why `test_a_kept_copy_that_is_not_age_ciphertext_is_never_sent` exists. The
evidence file lists which test caught each mutation.

## Round 2: the coordinator's follow-up items

### R2-1. Remote immutability on any rclone version (`108c04ae`)

- **Change.** In `ship_encrypted_rclone`, the two `rclone copyto --checksum --immutable` uploads are now
  `rclone copy --checksum --immutable --include "/<set>.tar.age" <kept copy> <remote>` and the same with `"/<set>.tar.age.sha256"`.
  Each filter is anchored, so it matches exactly one file and the `recipients.sha256` in the kept copy never leaves. Unchanged:
  - the order (ciphertext, then digest, then `rclone check --one-way`);
  - the retry convergence;
  - the lock and everything else.

  The failure message now also covers a set that already shipped completely.
- **Proof against the real rclone 1.60.1** with a local-filesystem remote (a named `type = local` remote over a temp dir). Probe in
  the evidence file:
  - fresh upload: exit 0;
  - identical object present: "There was nothing to transfer", exit 0, the file unchanged;
  - differing object present, same size: exit 6, "immutable file modified", the object kept. A different size gives the same
    result.
- **Tests.**
  - New: `test_a_differing_object_already_on_the_remote_is_refused_and_never_overwritten`. It plants a differing `<set>.tar.age`,
    and in a second case a differing `<set>.tar.age.sha256`, on the real local remote. The ship fails with "immutable file modified"
    and the planted bytes are untouched. In the first case the digest is never sent, because the ciphertext goes first.
  - The retry test now relies on the real rclone for the identical-object case: the stand-in only fails the first checksum upload
    and keys on the new `copy --include` call.
  - `test_encrypted_set_goes_to_the_remote_and_decrypts` used to assert that a second ship of the same set succeeds. That held only
    because `copyto` replaced the remote ciphertext with a fresh encryption. It now asserts that the second ship is refused and both
    remote objects keep their digests.
- **Red.** At `a1c94023` (still `copyto`), both planted-object cases fail with "0 == 0 : LOG: shipped ..." because the object was
  overwritten, and so does the re-ship assertion; 8 failures in all. **Green:** 53 OK.
- **Mutations**, each failing tests:
  - either upload put back to `copyto`;
  - `--immutable` dropped;
  - the filter dropped;
  - the digest sent first.
- **Guide.** `docs/ops/backup-offhost-hardening.md` now states:
  - what `--immutable` guarantees with this invocation: this script's upload never changes an existing remote object; a differing
    one is refused and an identical one is accepted as present;
  - what it does not guarantee: it does not hold against anyone with the credential or other commands; it compares sizes only on a
    backend without a common hash (not verified); and it cannot serialize writers on different hosts;
  - that the no-overwrite credential remains the primary control.

### R2-2. The delta snapshot is pinned (`f6cb3b2d`)

`test_the_committed_semantic_delta_snapshot_is_current` (in `tests/ops/test_production_kit.py`) runs
`python3 -I ops/production/build-kit.py --delta` and compares its output byte for byte with the committed `semantic-delta.diff`. On a
mismatch it prints the regeneration command and the first lines of the drift.
- **Red:** a one-line edit to the snapshot (` (edited)` appended to line 1) fails the test with that diff.
- **Green** again after `git checkout` of the snapshot.

## Round 3: conditions of the independent review of `cd35fb15`

The review approved with conditions (no P0 or P1). Each item, red then green; raw output and mutations are in the evidence file.

- **P2-1, false "shipped" on a backend with no common hash** (`d8cff5e7`).
  - **Problem.** On such a backend rclone compares sizes only, and every age output of one set has the same size.
  - **Fix.** Before a fresh encryption, the remote is listed with `rclone lsf --include /<set>.tar.age --include /<set>.tar.age.sha256`.
    This works on any backend; exit 3, a path that does not exist yet, counts as empty. The ship refuses before encrypting if either
    name exists. After the upload, the remote digest, read back with `rclone cat`, must equal the local one, and `rclone check` must
    pass.
  - **Red.** `test_a_partial_upload_then_a_rotation_on_a_backend_without_hashes_is_refused` reproduces the reviewer's case on a real
    `chunker` remote with `hash_type = none`. At `cd35fb15` it failed with `0 == 0 : LOG: shipped ... (age-encrypted via rclone)`;
    a forged remote digest also shipped.
  - **Green.** The ship refuses, the old ciphertext is untouched and no digest is sent. A fresh set still ships to that remote.
- **P2-2, kept copies.**
  - A copy is kept only if the remote holds `<set>.tar.age` after the failure. Red at `cd35fb15`: a failed first upload kept one.
  - At most two copies are kept: the oldest sets' copies go first, and never the copy for the set being shipped. Red: all four
    were kept.
  - Every rclone ship logs, and `check-offhost` prints, "N kept retry copies, B bytes". Red: the function did not exist.
  - The guide sizes the disk for this.
- **P3-1, deploy against a manual ship.** `predeploy` sets `SHIP_LOCK_WAIT=600`, and the ship uses `flock -w`; a manual `ship` still
  refuses at once. The test releases a held lock after 2 s and the ship succeeds; with a 1 s bound and the lock still held it fails,
  saying "waited 1s". The guide documents what happens past the bound.
- **P3-2, pruning.** It runs outside the lock; this is documented in the guide and in a comment, not moved.
- **P3-3, repeat ship.**
  - A verified ship records its digest in `backups/.offhost-shipped.<set>`.
  - A repeat ship exits 0 with "<set> is already shipped" only when both objects are on the remote and the remote digest matches
    the record. It encrypts nothing and leaves no copy.
  - Without the record, or with a changed remote digest, it refuses before encrypting.
  - Red at `cd35fb15`: the repeat ship failed and left a copy.
- **P3-4, test gaps.** All six of the reviewer's mutations now fail a test, and each passed before:
  - `rclone check` removed;
  - the lock released before the transfer;
  - the symlink checks in `retry_copy_ok`;
  - `--checksum` dropped;
  - the symlinked-lock refusal;
  - `enabled-runtime` accepted.

  Seven mutations of the new code also fail tests. The missing-path one first survived, which is why
  `test_the_first_ship_to_a_remote_path_that_does_not_exist_yet_succeeds` exists.
- **P3-5, the clamd marker.** The guard compares the first line of `clamd.conf` literally (`head -n 1`). Red at `cd35fb15`: the marker
  on a later line, and the look-alike `# VASEY-AUDIO clamd profile`, both kept a packaged clamd running, in staging and in the
  derived production script. The production kit test that pinned the old text is updated (`9b360103`). The delta snapshot is
  unchanged.

Process notes:
- I committed `ebc93106` before running the full suite, which then showed that pinned assertion failing; `9b360103` fixes it.
- Two mutation commands briefly left stray empty files in the worktree (`&1` in round 1, `&-` here) through a mistyped redirection.
  Both were removed before any commit.
- A debug step's `git checkout` in round 2 discarded uncommitted test edits, which were re-applied.

## Verification at `9b360103`

| Check | Command | Result |
| --- | --- | --- |
| Ops tests | `python3 -m unittest discover -s tests/ops -p 'test_*.py'` | **309 OK** (280 before: off-host 45 to 68, monitoring 42 to 44, ClamAV 26 to 28, drift 9 to 10, production kit 49 to 50; 294 at `cd35fb15`) |
| Kit build | `python3 -I ops/production/build-kit.py --check` | ok, 13 files; `--delta` identical to the committed snapshot, now also pinned by a test |
| Shell syntax | `bash -n` on `backup.sh`, `provision.sh`, `host-baseline.sh` | ok |
| Lint | `shellcheck` 0.9.0 at the default severity on `backup.sh` and `host-baseline.sh`; `-S warning` on those, `provision.sh`, `forge-deploy.sh` and the five derived production scripts | clean (`provision.sh` keeps its old SC1091 info at the default severity) |
| PHP | `php -l` and `vendor/bin/pint --test` on `scripts/ops/capability-boundary.php` | no syntax errors; passed |
| PHPUnit | the brief's SQLite command with `StagingRuntimeValidatorTest`, `TestCommerceProfileValidatorTest`, `ProductionEnvironmentTemplateTest` | **149 tests, 2,693 assertions OK** (unchanged counts) |
| Neighbour | `python3 scripts/ops/test-private-server-preflight.py` | OK |

`public/build` was absent for the PHPUnit runs.

## Found while doing this (not in the brief)

- **rclone 1.60.1 does not enforce `--immutable` on `copyto`.** In this container (`rclone v1.60.1-DEV`), a second `copyto
  --checksum --immutable` with different bytes logged "Copied (replaced existing)" and exited 0. `rclone copy --immutable` in the
  same situation exited 6 with "immutable file modified" and kept the destination (probe in the evidence file).
  - So with this rclone the O1 retry did not fail as Codex described: it silently replaced the partial object.
  - The finding is still real with a newer rclone that enforces it, or with the no-overwrite credential the guide recommends.
    The retry tests model that case, and the fix converges either way.
  - The guide's sentence that `--immutable` "makes a rerun ... an error for changed ones" was false for `copyto` here and is
    corrected.
  - The host's rclone version was not checked.
  - Acted on in R2-1: the encrypted path now uses `rclone copy --immutable` with anchored `--include` filters.
  - A consequence in round two, a refused second ship of a completed set, is replaced in round three by "already shipped" (P3-3).
- **No test pinned `semantic-delta.diff` to `--delta`.** Acted on in R2-2.
- **The deferral ledger still lists these items as open.** After the review, the lead may record them as closed at the reviewed SHA.

## Not verified (no host)

- No real host: no systemd, no real apt timers, no Forge, no ClamAV re-provision on a real host, no cron.
- No real remote or real credential: no S3-compatible store, B2 or SFTP, and no object-lock or no-overwrite credential. The
  overwrite refusal was proven with the real rclone 1.60.1 against a local-filesystem remote only.
- Any other rclone version, including the host's. A backend without a common hash was exercised only through `chunker` with
  `hash_type = none`. On such a backend the ciphertext itself is still compared by size only; the digest file is read back.
- The 600 s lock wait inside a real deploy, and what an operator sees when it runs out.
- The lock and the retry copy inside the real `nightly`/`predeploy` flow under the control helper, where the helper's fds 7 and 8
  are inherited. That was reasoned (fd 6 is free in both scripts), not run.
- A process killed between encryption and the rename into the retry copy. The rename is one `rename(2)`, and the staging leftover is
  cleaned by the stale rule, but this was not exercised.
- The disk space kept copies take on a real host, and how long they last under tiered retention (until a retry succeeds or the set
  is pruned).
- The age restore drill with a real key.
