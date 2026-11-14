DECISION: APPROVE WITH CONDITIONS at cd35fb1599173845c3e71bfcde156757611d2e44

I found no P0 or P1. Conditions: either fix P2-1 and P2-2, or log both in `docs/handoff/2026-10-07/codex-deferred-20261011.md` and add their caveats to `docs/ops/backup-offhost-hardening.md` (lines 92–106) before the deferral ledger closes r4305404770.

**What holds (verified)**
- **The retry folder never holds plaintext.** `tar | age` streams straight into the 0700 staging directory, which is renamed into the retry folder only after encryption and the digest succeed (backup.sh:382–388, 416–419).
- **Reuse is guarded.** A kept copy is reused only if it starts with the age header, matches its own digest, matches the current recipients digest, and none of its files is a link (394–400). The folder is cleaned only after `rclone check` passes (421–427).
- **Copies of pruned sets are dropped under the lock**, and only for well-formed set names (449–456).
- **No routine path ever re-ships a set.** The nightly run goes `ctl snapshot` → `predeploy`, and deploy activate/refresh go through the same `cmd_snapshot` → `predeploy` (vasey-staging-ctl:359–364, 451, 464; backup.sh:594–598). Each makes a new set, named to the second, before shipping `.last`. So two nightly runs in one day, or a deploy right after a nightly, do not fail or raise alerts.
- **The nightly run and a deploy never compete for `.offhost.lock`.** They are already serialized by `.deploy.lock` (forge-deploy.sh:89–90) and `ctl.lock` (ctl:530–531).
- **Host baseline.** `check` compares the output exactly. `enabled-runtime`, `static`, `masked`, `linked`, `alias` and `activating` all give WARN, so there are no false OKs. `apply` dies with exit 2 and never prints "applied" (host-baseline.sh:108–112, 131–133).
- **clamd.** The guard is now in staging provision.sh:263. A scratch build of the production kit at base and at head has identical non-comment lines; only the comment changed. The delta went from 223 to 213 lines and is byte-identical to `--delta`.

**Findings**

**P2-1: on a backend with no hash in common with the local disk, the refusal does not happen and the script reports a false "shipped".**
- age output size is fixed for a given set and recipient count. So with rclone's size-only fallback, every re-encryption counts as "already present", and `rclone check` passes on sizes alone.
- Reproduced with the real script against a chunker remote with `hash_type = none`: a partial upload, then a recipients rotation, then `ship` exits 0 with "shipped … age-encrypted". The remote is left holding the **old-key** ciphertext with a digest that does not match it, and the kept copy is deleted.
- If the rotation happened because the old key was lost, that set is unrestorable but reported as shipped. A chrooted SFTP-only server is a plausible target backend with no hashes.
- This contradicts the guide's "never sent … the ship stops" (lines 96–105) and the CHANGELOG claim.
- **Remedy:** when no kept copy is reused, refuse if `rclone lsf --include "/<set>.tar.age" --include "/<set>.tar.age.sha256"` finds anything on the remote. This works on any backend. Alternatively, use `rclone check --download` for the pair, or document that only backends sharing a hash with the local disk are supported.

**P2-2: kept copies pile up and nothing uses them.**
- Every failed rclone ship keeps a set-sized copy, even when nothing reached the remote (the unreachable-remote test asserts this). The copy stays until its set is pruned, which can be months for a monthly tier.
- My driver: three failed nights followed by one successful night left three copies on disk.
- The free-space check (380) only reserves room for the next copy, leaving about 5% headroom that now persists. `snapshot` has no space check, and the backups very likely share a filesystem with MySQL.
- Line 106 says "about one encrypted copy" and does not mention that they accumulate.
- **Remedy:** keep a copy only if `<set>.tar.age` exists on the remote after the failure (otherwise a fresh encryption is safe). Cap copies by count or age, report them in `check-offhost` and the log, and document disk sizing.

**P3-1: a manual `ship` that overlaps a deploy turns the deploy into an outage.**
- The deploy's `predeploy` dies on the lock. Under `set -e` (ctl:29), activate or refresh then exits with the operation marker still in place (394–398), and the site stays quiesced until root recovery.
- Line 57 does not mention this.
- **Remedy:** document it in the runbook, or have `predeploy` wait on the lock with `flock -w`.

**P3-2: pruning runs outside the lock.** `prune_local` and `prune_remote` (591–592) run after the lock is released. A race with a manual `ship` of an old set is harmless, but it should be documented.

**P3-3: a manual re-ship of a completed set fails and leaves a kept copy that can never be used** (a fresh encryption that will never match the remote) until the set is pruned.
- **Remedy:** a local shipped marker written after `rclone check`, checked before encrypting, so a repeat ship exits 0 with "already shipped". Or the presence check from P2-1.

**P3-4: test gaps.** These mutations of mine survived the tests:
- `rclone check` removed;
- lock released before encryption and transfer;
- symlink checks in `retry_copy_ok` removed;
- `--checksum` dropped (my probe shows identical bytes with a different mtime are then refused);
- the refusal of a symlinked lock removed;
- `enabled-runtime` accepted by host-baseline.

**P3-5 (nit): the clamd marker** matches any line starting with `# VASEY.AUDIO clamd profile`, not only the first line, and `.` matches any character. Faking it needs root write access to /etc/clamav; the impact is low.

**Commands and counts** (fresh `mktemp` scratch copies via `git archive`; vendor set up as briefed; `App\` confirmed to resolve to the scratch copy)
- **Ops suite:** `python3 -m unittest discover -s tests/ops -p 'test_*.py'` gave **294 OK**, and **294 OK** again after the reboot. Without vendor it gives 1 failure and 19 skips, which is environmental.
- **PHPUnit** (the brief's SQLite command, three files): **149 tests, 2,693 assertions OK**.
- **Kit:** `build-kit --check` ok, 13 files. `--delta` is byte-identical to the committed snapshot. The production backup.sh is the staging file plus renames.
- **Lint:** `bash -n` ok on the three scripts. shellcheck at default severity is clean on backup.sh and host-baseline.sh. `-S warning` is clean on 9 files (staging and derived production). provision.sh has only its old SC1091 info.
- **rclone 1.60.1, local remote:**
  - a fresh upload exits 0;
  - an identical object (with `--checksum`, different mtime) exits 0 and is unchanged;
  - a differing object of the same size exits 6 and is untouched; a different size, the same;
  - `copyto --immutable` replaced a differing object with exit 0;
  - the anchored `--include` never sends `recipients.sha256`.
- **Packet mutations:** 6 in backup.sh (flock, reuse, recipients digest, age header, copyto, kept copy left after a verified ship) and 3 in host-baseline (is-active, warn-on-enable, one timer). All 9 were killed. Restored files were confirmed byte-identical.
- **My mutations:** 6, all of which survived (P3-4).
- One stray `/tmp` output file was written and removed.

**Not verified**
- Any real host: systemd, cron, the Forge and ctl flow end to end (the lock and operation-marker interaction is reasoned from source).
- Any real remote (S3, B2, SFTP) or the host's rclone version. The no-hash case was emulated with chunker only.
- A process killed between encryption and the rename into the retry folder.
- How `snapshot` behaves on a full disk.
- A real-key restore drill.
- PHPUnit beyond the three files.
- The packet's evidence file, line by line (spot-checked only).
