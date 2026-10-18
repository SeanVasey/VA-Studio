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
| this packet's commit | docs | `CHANGELOG.md`, this directory |

**Tested source:** every check below ran at `810794a8` (the last code commit). The packet commit adds only documentation; the full
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
  that fails the first checksum `copyto` and refuses an overwrite with different bytes. The first ship fails and the remote holds
  only `<set>.tar.age`. The retry succeeds: the remote file is byte-identical to the first attempt's, its digest verifies, it
  decrypts with the test key, and nothing is left behind.
  - **Red:** `AssertionError: 1 != 0 : flaky-rclone: immutable file modified` (the retry re-encrypted and was refused).
  - **Green:** OK.
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

## Verification at `810794a8`

| Check | Command | Result |
| --- | --- | --- |
| Ops tests | `python3 -m unittest discover -s tests/ops -p 'test_*.py'` | **292 OK** (280 before: off-host 45 to 52, monitoring 42 to 44, ClamAV 26 to 28, drift 9 to 10) |
| Kit build | `python3 -I ops/production/build-kit.py --check` | ok, 13 files; `--delta` identical to the committed snapshot |
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
  - **For the lead:** whether to move the encrypted path to `rclone copy --immutable` with `--include` filters, so that immutability
    holds regardless of version, is a reviewed change and was not made here.
- **No test pins `semantic-delta.diff` to `--delta`.** The snapshot was regenerated and compared by hand (identical). A drift in it
  would not fail CI. Not added: outside the items.
- **The deferral ledger still lists these items as open.** After the review, the lead may record them as closed at the reviewed SHA.

## Not verified (no host)

- No real host: no systemd, no real apt timers, no Forge, no ClamAV re-provision on a real host, no cron.
- No real remote or real credential: no S3-compatible store, B2 or SFTP, and no object-lock or no-overwrite credential. The
  overwrite refusal was a stand-in.
- The lock and the retry copy inside the real `nightly`/`predeploy` flow under the control helper, where the helper's fds 7 and 8
  are inherited. That was reasoned (fd 6 is free in both scripts), not run.
- A process killed between encryption and the rename into the retry copy. The rename is one `rename(2)`, and the staging leftover is
  cleaned by the stale rule, but this was not exercised.
- The disk space kept copies take on a real host, and how long they last under tiered retention (until a retry succeeds or the set
  is pruned).
- The age restore drill with a real key.
