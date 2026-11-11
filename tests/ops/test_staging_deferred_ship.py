"""Off-host shipping never holds the site down and never fails an operation whose local proof succeeded.

2026-11-11 (staging): shipping the ~38 GB age-encrypted set while the site was quiesced added ~15 minutes to every deploy, and a B2
"403 storage_cap_exceeded" failed the deploy before the switch and left the site down. predeploy and the nightly snapshot now only
queue a pending-ship marker; `ship-pending` sends the queued sets after the release is resumed, detached, under the existing
off-host lock, and records its own dashboard evidence.

Everything below runs the shipped script's own dispatcher and functions in bash. The snapshot and the restore proof are stubs
(covered by test_staging_backup_*), `age` and `rsync` are the real tools where a transfer is wanted and recording fakes where the
assertion is that they are never called, and `rclone` is a recording wrapper that either passes through to the real binary against
a local-directory remote or fails like B2 did. Nothing touches a network, a database or a real host."""
import subprocess
import sys
import time
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from test_staging_backup_offhost import (DEFAULTS, DEFINITIONS, HAVE, PRELUDE, SOURCE, OffhostBase,  # noqa: E402
                                         set_name)

DISPATCH = SOURCE[SOURCE.index('\ncase "${1:-}" in'):]
NEEDS_TOOLS = unittest.skipUnless(HAVE["age"] and HAVE["age-keygen"] and HAVE["rclone"] and HAVE["rsync"],
                                  "age, rclone and rsync are required")
FIRST = set_name("20261110", "030000", "a" * 12)
SECOND = set_name("20261111", "031700", "b" * 12)


def executable(path, text):
    path.write_text(text)
    path.chmod(0o755)
    return path


class DeferredShipBase(OffhostBase):
    def setUp(self):
        super().setUp()
        self.bin = self.root / "fakebin"
        self.bin.mkdir()
        self.calls = self.root / "tool-calls"
        self.trace = self.root / "trace"
        self.evidence = self.root / "evidence"
        self.log = self.root / "ship-pending.log"
        self.remote = self.root / "remote"
        self.remote.mkdir()
        self.mode = self.root / "rclone-mode"
        self.mode.write_text("real")
        self.config = self.root / "rclone.conf"
        self.config.write_text("[vasey-backup]\ntype = local\n")
        self.config.chmod(0o600)
        self.app_root = self.root / "app"
        (self.app_root / "releases" / ("c" * 40)).mkdir(parents=True)
        (self.app_root / "current").symlink_to(self.app_root / "releases" / ("c" * 40))
        self.env["PATH"] = f"{self.bin}:{self.env['PATH']}"
        self.env.update(FAKE_CALLS=str(self.calls), FAKE_MODE=str(self.mode), T_TRACE=str(self.trace), T_ROOT=str(self.app_root))
        # age and rsync record every call and exit 1: a test that expects them to stay unused asserts the log stays empty.
        for tool in ("age", "rsync", "ssh"):
            executable(self.bin / tool, f'#!/bin/bash\necho "{tool} $*" >> "$FAKE_CALLS"\nexit 1\n')
        real_rclone = HAVE["rclone"] or "/usr/bin/rclone"
        executable(self.bin / "rclone", f'''#!/bin/bash
echo "rclone $*" >> "$FAKE_CALLS"
sub=''
for a in "$@"; do case "$a" in lsf|copy|check|cat|deletefile|purge) sub=$a; break ;; esac; done
echo "rclone-$sub" >> "$T_TRACE"
case "$(cat "$FAKE_MODE" 2>/dev/null || echo real)" in
  403) [ "$sub" = lsf ] && exit 0
       echo "ERROR : x.tar.age: Failed to copy: 403 storage_cap_exceeded (Cap exceeded)" >&2; exit 1 ;;
  exit1) echo "ERROR : rclone failed: connection reset" >&2; exit 1 ;;
  *) exec {real_rclone} "$@" ;;
esac
''')
        # A recorder instead of systemd: it runs the unit's command detached, like a transient unit would.
        executable(self.bin / "systemd-run", '''#!/bin/bash
echo "systemd-run $*" >> "$FAKE_CALLS"
[ "${FAKE_SYSTEMD:-ok}" != record ] || exit 0   # accepted, never run: the unit is "still queued"
[ "${FAKE_SYSTEMD:-ok}" = ok ] || exit 1
while [ "$#" -gt 0 ]; do case "$1" in --*) shift ;; *) break ;; esac; done
setsid "$@" </dev/null >/dev/null 2>&1 &
exit 0
''')
        self.remote_settings = {"VASEY_BACKUP_REMOTE": f"vasey-backup:{self.remote}", "VASEY_BACKUP_RCLONE_BIN": self.bin / "rclone",
                                "VASEY_BACKUP_RCLONE_CONFIG": self.config}

    # ---- fixtures ----------------------------------------------------------------------------------------------
    def hardened(self, **extra):
        """Settings for age + rclone to a local-directory remote (through the recording wrapper)."""
        if not hasattr(self, "_recipients"):
            self._recipients = self.recipients()  # one key pair per test: age-keygen refuses to overwrite its identity file
        return dict(self.remote_settings, VASEY_BACKUP_AGE_RECIPIENTS_FILE=self._recipients, VASEY_BACKUP_AGE_BIN=HAVE["age"],
                    VASEY_BACKUP_REQUIRE_ENCRYPTION=1, **extra)

    def tool_calls(self):
        return self.calls.read_text().splitlines() if self.calls.exists() else []

    def evidence_lines(self):
        return self.evidence.read_text().splitlines() if self.evidence.exists() else []

    def marker(self, name):
        return self.backups / f".offhost-pending.{name}"

    def fields(self, name):
        return dict(line.split("=", 1) for line in self.marker(name).read_text().splitlines())

    def queue(self, name, attempts=0):
        self.marker(name).write_text(f"set={name}\nqueued_utc=2026-11-10T03:00:00Z\nattempts={attempts}\nlast_attempt_utc=\nlast_result=\nlast_reason=\n")

    def helper(self):
        """The shipped dispatcher with only the snapshot, the restore proof, the evidence call and the paths replaced."""
        path = self.root / "vasey-backup-under-test"
        executable(path, "#!/bin/bash\n" + PRELUDE + DEFAULTS + DEFINITIONS + f'''
VASEY_ROOT="$T_ROOT"; CTL="{self.root}/fake-ctl"
BACKUP_SELF="{path}"; SHIP_PENDING_LOG="{self.log}"
install() {{ mkdir -p -- "${{@: -1}}"; }}
snapshot() {{
  echo snapshot >> "$T_TRACE"
  [ -z "${{T_SNAPSHOT_FAILS:-}}" ] || die "synthetic snapshot failure"
  [ -d "$VASEY_BACKUP_DIR/$T_SET" ] || die "the test set is missing"
  printf '%s\\n' "$VASEY_BACKUP_DIR/$T_SET" > "$VASEY_BACKUP_DIR/.last"
}}
restore_check() {{ echo "restore-check $1" >> "$T_TRACE"; }}
record_evidence() {{ echo "$1 $2 $(basename -- "${{3:-}}")" >> "{self.evidence}"; }}
''' + DISPATCH)
        executable(self.root / "fake-ctl", f'''#!/bin/bash
echo "ctl $1" >> "$T_TRACE"
case "$1" in
  snapshot) exec "{path}" predeploy ;;
  quiesce|resume) exit 0 ;;
esac
''')
        return path

    def run_helper(self, *arguments, timeout=120, **settings):
        env = dict(self.env, **{k: str(v) for k, v in settings.items()})
        env.setdefault("T_SET", FIRST)
        return subprocess.run([str(self.helper()), *arguments], env=env, capture_output=True, text=True, timeout=timeout)

    def wait_for(self, condition, seconds=60):
        deadline = time.monotonic() + seconds
        while time.monotonic() < deadline:
            if condition():
                return True
            time.sleep(0.05)
        return False


@NEEDS_TOOLS
class PredeployQueuesAndNeverShips(DeferredShipBase):
    def test_predeploy_takes_the_snapshot_and_proof_then_only_queues_a_marker(self):
        self.make_set(FIRST)
        run = self.run_helper("predeploy", **self.hardened())
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertEqual(self.trace.read_text().splitlines(), ["snapshot", f"restore-check {self.backups / FIRST}"])
        # No encryption, no transfer, no staging directory, no ciphertext: nothing network- or set-sized happens while quiesced.
        self.assertEqual(self.tool_calls(), [])
        self.assertEqual([p.name for p in self.backups.iterdir() if p.name.startswith((".ship", ".offhost-retry", ".offhost-shipped"))], [])
        self.assertEqual(list(self.remote.iterdir()), [])
        self.assertTrue(self.marker(FIRST).is_file())
        self.assertEqual(oct(self.marker(FIRST).stat().st_mode & 0o777), "0o600")
        self.assertEqual(self.fields(FIRST)["set"], FIRST)
        self.assertEqual(self.fields(FIRST)["attempts"], "0")
        self.assertIn("queued", run.stdout)
        self.assertEqual(self.evidence_lines(), [], "predeploy must not touch the dashboard")

    def test_the_default_rsync_path_is_deferred_too(self):
        self.make_set(FIRST)
        key = self.root / "ssh-key"
        key.write_text("synthetic")
        run = self.run_helper("predeploy", VASEY_BACKUP_DEST=self.root / "dest", VASEY_BACKUP_SSH_KEY=key)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertEqual(self.tool_calls(), [])
        self.assertTrue(self.marker(FIRST).is_file())

    def test_a_refused_configuration_cannot_fail_the_deploy_and_is_reported_when_the_ship_runs(self):
        self.make_set(FIRST)
        settings = dict(self.remote_settings, VASEY_BACKUP_REQUIRE_ENCRYPTION=1)  # encryption required, no recipients
        run = self.run_helper("predeploy", **settings)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("WARNING: the off-host configuration is refused", run.stdout)
        self.assertTrue(self.marker(FIRST).is_file())
        shipped = self.run_helper("ship-pending", **settings)
        self.assertNotEqual(shipped.returncode, 0)
        self.assertEqual(self.evidence_lines(), ["daily failed "])
        self.assertEqual(list(self.remote.iterdir()), [], "nothing, least of all plaintext, reaches the remote")
        self.assertEqual(self.tool_calls(), [])
        self.assertIn("refused", self.fields(FIRST)["last_reason"])

    def test_a_marker_that_cannot_be_written_cannot_fail_the_deploy(self):
        self.make_set(FIRST)
        self.marker(FIRST).mkdir()  # a directory where the marker goes: the atomic rename onto it fails
        run = self.run_helper("predeploy", **self.hardened())
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("WARNING: could not queue", run.stdout)
        self.assertEqual([p.name for p in self.backups.iterdir() if p.name.startswith(".offhost-pending-new")], [])

    def test_without_a_destination_nothing_is_queued_and_it_says_so(self):
        self.make_set(FIRST)
        run = self.run_helper("predeploy")
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("NOT an off-host backup", run.stdout)
        self.assertFalse(self.marker(FIRST).exists())

    def test_the_predeploy_branch_contains_no_transfer_at_all(self):
        predeploy = SOURCE[SOURCE.index("  predeploy)"):SOURCE.index("  space-check)")]
        code = "\n".join(line for line in predeploy.splitlines() if not line.lstrip().startswith("#"))
        for forbidden in ("ship ", "ship_", "rclone", "rsync", "age ", "encrypt", "SHIP_LOCK_WAIT"):
            self.assertNotIn(forbidden, code)
        self.assertLess(code.index("snapshot"), code.index("restore_check"))
        self.assertLess(code.index("restore_check"), code.index("mark_pending"))


@NEEDS_TOOLS
class PruneNeverRemovesAPendingSet(DeferredShipBase):
    def sets(self, count):
        names = [set_name(f"202611{day:02d}") for day in range(1, count + 1)]
        for name in names:
            self.make_set(name)
        return names

    def names(self):
        return sorted(p.name for p in self.backups.iterdir() if p.is_dir())

    def test_flat_retention_spares_a_pending_set_and_prunes_it_once_it_is_cleared(self):
        names = self.sets(6)
        self.queue(names[0])
        self.queue(names[2])
        run = self.bash("prune_local", VASEY_BACKUP_KEEP=2, **self.remote_settings)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(self.names(), sorted([names[0], names[2], names[4], names[5]]))
        self.marker(names[0]).unlink()
        self.assertEqual(self.bash("prune_local", VASEY_BACKUP_KEEP=2, **self.remote_settings).returncode, 0)
        self.assertEqual(self.names(), sorted([names[2], names[4], names[5]]))

    def test_tiered_retention_spares_a_pending_set(self):
        names = self.sets(8)
        self.queue(names[1])
        settings = dict(VASEY_BACKUP_RETENTION="tiered", VASEY_BACKUP_KEEP=2, VASEY_BACKUP_KEEP_DAILY=0,
                        VASEY_BACKUP_KEEP_WEEKLY=0, VASEY_BACKUP_KEEP_MONTHLY=0)
        run = self.bash("prune_local", **settings)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(self.names(), sorted([names[1], names[-2], names[-1]]))

    def test_the_prune_subcommand_and_the_deploy_prune_both_spare_it(self):
        names = self.sets(5)
        self.queue(names[0])
        self.assertEqual(self.run_helper("prune", VASEY_BACKUP_KEEP=2).returncode, 0)
        self.assertIn(names[0], self.names())
        self.make_set(set_name("20261120"))
        self.assertEqual(self.bash(f'prune_local "{set_name("20261120")}"', VASEY_BACKUP_KEEP=1).returncode, 0)
        self.assertIn(names[0], self.names())

    def test_stray_and_linked_markers_protect_nothing(self):
        names = self.sets(4)
        outside = self.root / "outside"
        outside.write_text("x")
        self.marker(names[0]).symlink_to(outside)
        (self.backups / ".offhost-pending.not-a-set").write_text("x")
        self.assertEqual(self.bash("prune_local", VASEY_BACKUP_KEEP=1).returncode, 0)
        self.assertEqual(self.names(), [names[-1]])

    def test_pruning_is_a_dispatcher_branch_that_cannot_ship(self):
        self.sets(2)
        self.assertEqual(self.run_helper("prune").returncode, 0)
        self.assertEqual(self.tool_calls(), [])


@NEEDS_TOOLS
class ShipPendingRunsWithTheSiteUp(DeferredShipBase):
    def test_a_pending_set_ships_encrypted_clears_its_marker_and_records_ok(self):
        bk = self.make_set(FIRST)
        self.queue(FIRST)
        run = self.run_helper("ship-pending", **self.hardened())
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertEqual(sorted(p.name for p in self.remote.iterdir()), [f"{FIRST}.tar.age", f"{FIRST}.tar.age.sha256"])
        for path in self.remote.iterdir():
            self.assertNotIn(b"SYNTHETIC_DATABASE_MARKER", path.read_bytes())
            self.assertNotIn(b"SYNTHETIC_KEY_MARKER", path.read_bytes())
        self.assertFalse(self.marker(FIRST).exists())
        self.assertEqual(self.evidence_lines(), [f"daily ok {FIRST}"])
        self.assertTrue((bk / "RESTORE_CHECK").exists(), "the local set is untouched")
        self.assertEqual([p.name for p in self.backups.iterdir() if p.name.startswith(".ship.")], [])
        self.assertEqual(self.tool_calls() and [c for c in self.tool_calls() if c.startswith(("age", "rsync", "ssh"))], [])

    def test_the_rsync_path_ships_after_the_resume_too(self):
        self.make_set(FIRST)
        self.queue(FIRST)
        # Real rsync this time: the recording fake is replaced for this test only.
        (self.bin / "rsync").unlink()
        (self.bin / "ssh").unlink()
        dest = self.root / "dest"
        dest.mkdir(mode=0o700)
        key = self.root / "ssh-key"
        key.write_text("synthetic")
        executable(self.bin / "rsync", f'#!/bin/bash\n# the destination is a local directory: drop the ssh transport\nargs=()\nskip=0\nfor a in "$@"; do\n  if [ "$skip" = 1 ]; then skip=0; continue; fi\n  [ "$a" = -e ] && {{ skip=1; continue; }}\n  args+=("$a")\ndone\nexec {HAVE["rsync"]} "${{args[@]}}"\n')
        run = self.run_helper("ship-pending", VASEY_BACKUP_DEST=dest, VASEY_BACKUP_SSH_KEY=key)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertTrue((dest / FIRST / "database.sql").is_file())
        self.assertFalse(self.marker(FIRST).exists())
        self.assertEqual(self.evidence_lines(), [f"daily ok {FIRST}"])

    def test_a_b2_403_leaves_the_set_pending_records_failed_and_the_next_run_completes_it(self):
        bk = self.make_set(FIRST)
        self.queue(FIRST)
        self.mode.write_text("403")
        run = self.run_helper("ship-pending", **self.hardened())
        self.assertNotEqual(run.returncode, 0, "the run reports the failure to a human who runs it")
        self.assertEqual(self.evidence_lines(), ["daily failed "])
        fields = self.fields(FIRST)
        self.assertEqual((fields["attempts"], fields["last_result"]), ("1", "failed"))
        self.assertIn("403 storage_cap_exceeded", fields["last_reason"])
        self.assertNotIn("\n", fields["last_reason"])
        self.assertLessEqual(len(fields["last_reason"]), 240)
        self.assertTrue(bk.is_dir() and (bk / "RESTORE_CHECK").is_file(), "the local set is intact")
        self.assertEqual(list(self.remote.iterdir()), [])
        self.assertIn("stays pending", run.stdout)
        # The next run (the nightly's) retries and completes it.
        self.mode.write_text("real")
        again = self.run_helper("ship-pending", **self.hardened())
        self.assertEqual(again.returncode, 0, again.stdout + again.stderr)
        self.assertFalse(self.marker(FIRST).exists())
        self.assertEqual(self.evidence_lines(), ["daily failed ", f"daily ok {FIRST}"])
        self.assertEqual(sorted(p.name for p in self.remote.iterdir()), [f"{FIRST}.tar.age", f"{FIRST}.tar.age.sha256"])

    def test_a_generic_rclone_failure_is_recorded_the_same_way(self):
        self.make_set(FIRST)
        self.queue(FIRST)
        self.mode.write_text("exit1")
        run = self.run_helper("ship-pending", **self.hardened())
        self.assertNotEqual(run.returncode, 0)
        self.assertEqual(self.evidence_lines(), ["daily failed "])
        self.assertEqual(self.fields(FIRST)["last_result"], "failed")
        self.assertTrue(self.marker(FIRST).is_file())

    def test_the_newest_set_goes_first_and_the_first_failure_stops_the_run(self):
        self.make_set(FIRST)
        self.make_set(SECOND)
        self.queue(FIRST)
        self.queue(SECOND)
        self.mode.write_text("exit1")
        self.assertNotEqual(self.run_helper("ship-pending", **self.hardened()).returncode, 0)
        self.assertEqual(self.fields(SECOND)["attempts"], "1")
        self.assertEqual(self.fields(FIRST)["attempts"], "0", "the older set waits for the next run")
        self.mode.write_text("real")
        run = self.run_helper("ship-pending", **self.hardened())
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertEqual(sorted(p.name for p in self.remote.iterdir()),
                         sorted(f"{n}.tar.age{s}" for n in (FIRST, SECOND) for s in ("", ".sha256")))
        self.assertEqual(self.evidence_lines(), ["daily failed ", f"daily ok {SECOND}"])
        self.assertFalse(self.marker(FIRST).exists() or self.marker(SECOND).exists())

    def test_a_set_queued_while_the_run_is_going_is_picked_up_before_it_exits(self):
        self.make_set(FIRST)
        self.make_set(SECOND)
        self.queue(FIRST)
        # The second set is queued by a deploy while the first one transfers: the rclone wrapper queues it on its first copy.
        executable(self.bin / "rclone", f'''#!/bin/bash
for a in "$@"; do [ "$a" = copy ] && [ ! -e "{self.root}/queued" ] && touch "{self.root}/queued" \
  && printf 'set={SECOND}\\nqueued_utc=x\\nattempts=0\\nlast_attempt_utc=\\nlast_result=\\nlast_reason=\\n' > "{self.marker(SECOND)}"; done
exec {HAVE["rclone"]} "$@"
''')
        run = self.run_helper("ship-pending", **self.hardened())
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertTrue((self.remote / f"{SECOND}.tar.age").is_file() and (self.remote / f"{FIRST}.tar.age").is_file())
        self.assertFalse(self.marker(FIRST).exists() or self.marker(SECOND).exists())

    def test_encryption_required_never_sends_plaintext_when_the_recipients_vanish(self):
        self.make_set(FIRST)
        self.queue(FIRST)
        settings = dict(self.remote_settings, VASEY_BACKUP_REQUIRE_ENCRYPTION=1, VASEY_BACKUP_AGE_RECIPIENTS_FILE=self.root / "gone.txt",
                        VASEY_BACKUP_AGE_BIN=HAVE["age"])
        run = self.run_helper("ship-pending", **settings)
        self.assertNotEqual(run.returncode, 0)
        self.assertEqual(list(self.remote.iterdir()), [])
        self.assertEqual(self.evidence_lines(), ["daily failed "])
        self.assertTrue(self.marker(FIRST).is_file())

    def test_encryption_required_blocks_a_plain_rsync_of_a_pending_set(self):
        self.make_set(FIRST)
        self.queue(FIRST)
        key = self.root / "ssh-key"
        key.write_text("synthetic")
        dest = self.root / "dest"
        dest.mkdir(mode=0o700)
        run = self.run_helper("ship-pending", VASEY_BACKUP_DEST=dest, VASEY_BACKUP_SSH_KEY=key, VASEY_BACKUP_REQUIRE_ENCRYPTION=1)
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("VASEY_BACKUP_AGE_RECIPIENTS_FILE is not set", run.stdout)
        self.assertEqual(list(dest.iterdir()), [], "no plaintext reached the destination")
        self.assertEqual(self.tool_calls(), [])
        self.assertEqual(self.evidence_lines(), ["daily failed "])

    def test_a_set_that_is_gone_drops_its_marker_without_recording_a_failure(self):
        self.queue(FIRST)
        run = self.run_helper("ship-pending", **self.hardened())
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("no longer on this host", run.stdout)
        self.assertFalse(self.marker(FIRST).exists())
        self.assertEqual(self.evidence_lines(), [])

    def test_a_destination_that_disappeared_keeps_the_set_pending_instead_of_clearing_it(self):
        self.make_set(FIRST)
        self.queue(FIRST)
        run = self.run_helper("ship-pending")
        self.assertNotEqual(run.returncode, 0)
        self.assertTrue(self.marker(FIRST).is_file())
        self.assertEqual(self.evidence_lines(), ["daily failed "])

    def test_nothing_pending_does_nothing_and_records_nothing(self):
        run = self.run_helper("ship-pending", **self.hardened())
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("no off-host shipments pending", run.stdout)
        self.assertEqual(self.evidence_lines(), [])

    def test_a_second_run_exits_quietly_while_one_is_active(self):
        self.make_set(FIRST)
        self.queue(FIRST)
        import fcntl
        with open(self.backups / ".ship-pending.lock", "a") as held:
            fcntl.flock(held, fcntl.LOCK_EX)
            run = self.run_helper("ship-pending", **self.hardened())
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("another ship-pending run is active", run.stdout)
        self.assertEqual(list(self.remote.iterdir()), [])
        self.assertEqual(self.evidence_lines(), [])
        self.assertTrue(self.marker(FIRST).is_file())

    def test_it_uses_the_existing_off_host_lock_and_waits_for_a_manual_ship(self):
        import fcntl
        self.make_set(FIRST)
        self.queue(FIRST)
        held = open(self.backups / ".offhost.lock", "a")
        self.addCleanup(held.close)
        fcntl.flock(held, fcntl.LOCK_EX)
        self.assertTrue(self.helper().is_file())
        process = subprocess.Popen([str(self.helper()), "ship-pending"], env=dict(self.env, T_SET=FIRST, **{k: str(v) for k, v in self.hardened().items()}),
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        time.sleep(1.5)
        self.assertIsNone(process.poll(), "it waits for the manual ship instead of failing")
        held.close()
        out, err = process.communicate(timeout=60)
        self.assertEqual(process.returncode, 0, out + err)
        self.assertFalse(self.marker(FIRST).exists())

    def test_a_manual_ship_still_works_and_clears_the_marker_only_when_it_succeeds(self):
        bk = self.make_set(FIRST)
        self.queue(FIRST)
        self.mode.write_text("403")
        failed = self.run_helper("ship", str(bk), **self.hardened())
        self.assertNotEqual(failed.returncode, 0)
        self.assertTrue(self.marker(FIRST).is_file())
        self.mode.write_text("real")
        done = self.run_helper("ship", str(bk), **self.hardened())
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertFalse(self.marker(FIRST).exists())
        self.assertTrue((self.remote / f"{FIRST}.tar.age").is_file())
        self.assertEqual(self.evidence_lines(), [], "a manual ship records nothing on the dashboard, as before")

    def test_pending_lists_the_queue_and_pending_clear_abandons_one_set(self):
        self.make_set(FIRST)
        self.queue(FIRST, attempts=2)
        listed = self.run_helper("pending")
        self.assertEqual(listed.returncode, 0, listed.stderr)
        self.assertIn(f"{FIRST} queued=2026-11-10T03:00:00Z attempts=2", listed.stdout)
        self.assertNotEqual(self.run_helper("pending-clear", SECOND).returncode, 0)
        cleared = self.run_helper("pending-clear", FIRST)
        self.assertEqual(cleared.returncode, 0, cleared.stdout + cleared.stderr)
        self.assertFalse(self.marker(FIRST).exists())
        self.assertTrue((self.backups / FIRST).is_dir(), "abandoning a shipment never deletes the set")
        self.assertIn("no sets are waiting", self.run_helper("pending").stdout)

    def test_a_reason_is_one_short_line_without_control_characters(self):
        self.make_set(FIRST)
        run = self.bash(f'pending_write "{FIRST}" now 1 now failed "$(printf \'line one\\nset=other\\x01\\x1b[31m %0400d\' 7)"')
        self.assertEqual(run.returncode, 0, run.stderr)
        text = self.marker(FIRST).read_text()
        self.assertEqual(len(text.splitlines()), 6)
        self.assertEqual(self.fields(FIRST)["set"], FIRST)
        self.assertLessEqual(len(self.fields(FIRST)["last_reason"]), 240)
        self.assertTrue(all(ch == "\n" or ch.isprintable() for ch in text))
        self.assertNotIn("\x1b", text)


@NEEDS_TOOLS
class TheDetachedStart(DeferredShipBase):
    def test_it_starts_a_transient_unit_that_runs_ship_pending_and_returns_at_once(self):
        self.make_set(FIRST)
        self.queue(FIRST)
        started = time.monotonic()
        run = self.run_helper("ship-pending-start", **self.hardened())
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertLess(time.monotonic() - started, 30)
        call = next(c for c in self.tool_calls() if c.startswith("systemd-run"))
        for part in ("--collect", "--unit=vasey-staging-ship-pending", "--property=Nice=10", "ship-pending", str(self.log)):
            self.assertIn(part, call)
        self.assertIn("started as vasey-staging-ship-pending.service", run.stdout)
        # The unit really does the work, with the settings it was given, and logs to the ship-pending log.
        self.assertTrue(self.wait_for(lambda: not self.marker(FIRST).exists()), self.log.read_text() if self.log.exists() else "no log")
        self.assertTrue(self.wait_for(lambda: self.evidence_lines() == [f"daily ok {FIRST}"]))
        self.assertIn("shipping pending set", self.log.read_text())

    def test_without_systemd_a_setsid_child_gets_no_lock_descriptor_and_the_caller_does_not_wait(self):
        self.make_set(FIRST)
        self.queue(FIRST)
        fds = self.root / "child-fds"
        slow = executable(self.root / "slow-self", f'#!/bin/bash\nls -l /proc/$$/fd > "{fds}"\nsleep 4\necho "$@" > "{self.root}/child-args"\n')
        script = f'''BACKUP_SELF="{slow}"; SHIP_PENDING_LOG="{self.log}"
exec 9>>"{self.root}/deploy.lock"; exec 8>>"{self.root}/ctl.lock"; exec 6>>"{self.root}/offhost.lock"
started=$SECONDS
ship_pending_start
echo "returned after $((SECONDS - started))s"'''
        run = self.bash(script, FAKE_SYSTEMD="fail", **self.hardened())
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("started detached (no systemd unit)", run.stdout)
        self.assertRegex(run.stdout, r"returned after [01]s")
        self.assertTrue(self.wait_for(fds.exists))
        listing = fds.read_text()
        for lock in ("deploy.lock", "ctl.lock", "offhost.lock"):
            self.assertNotIn(lock, listing, "a lock descriptor leaked into the detached shipment")
        self.assertTrue(self.wait_for((self.root / "child-args").exists, 15))
        self.assertEqual((self.root / "child-args").read_text().strip(), "ship-pending")

    def test_it_is_a_no_op_without_a_destination_or_without_anything_pending(self):
        self.make_set(FIRST)
        self.assertEqual(self.run_helper("ship-pending-start", **self.hardened()).returncode, 0)
        self.queue(FIRST)
        self.assertEqual(self.run_helper("ship-pending-start").returncode, 0)
        self.assertEqual([c for c in self.tool_calls() if c.startswith("systemd-run")], [])

    def test_a_start_that_cannot_run_anything_still_succeeds(self):
        self.make_set(FIRST)
        self.queue(FIRST)
        script = f'BACKUP_SELF="{self.root}/does-not-exist"; SHIP_PENDING_LOG="{self.root}/no-dir/ship.log"; ship_pending_start; echo "rc=$?"'
        run = self.bash(script, FAKE_SYSTEMD="fail", **self.hardened())
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("WARNING: could not start the off-host shipment", run.stdout)
        self.assertIn("rc=0", run.stdout)
        self.assertTrue(self.marker(FIRST).is_file(), "the set stays pending for the next nightly run")


@NEEDS_TOOLS
class TheNightlyRun(DeferredShipBase):
    def nightly(self, **settings):
        self.trace.unlink(missing_ok=True)
        return self.run_helper("nightly", **settings)

    def test_it_snapshots_resumes_then_starts_the_shipment_and_a_failing_remote_cannot_fail_it(self):
        self.make_set(FIRST)
        self.mode.write_text("403")
        run = self.nightly(**self.hardened())
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        # (The detached shipment may already be appending its own rclone lines after the resume.)
        self.assertEqual(self.trace.read_text().splitlines()[:5],
                         ["ctl quiesce", "ctl snapshot", "snapshot", f"restore-check {self.backups / FIRST}", "ctl resume"])
        self.assertEqual(self.tool_calls() and [c for c in self.tool_calls() if c.startswith(("age", "rsync", "ssh"))], [])
        # The detached shipment hits the 403 and records it; the nightly run had long since returned 0.
        self.assertTrue(self.wait_for(lambda: self.evidence_lines() == ["daily failed "]), self.log.read_text() if self.log.exists() else "no log")
        trace = self.trace.read_text().splitlines()
        transfers = [i for i, line in enumerate(trace) if line.startswith("rclone-")]
        self.assertTrue(transfers, "the detached shipment never reached rclone")
        self.assertLess(trace.index("ctl resume"), min(transfers), "the remote was touched while the site was quiesced")
        self.assertEqual(self.fields(FIRST)["last_result"], "failed")
        self.assertIn("403 storage_cap_exceeded", self.fields(FIRST)["last_reason"])
        self.assertTrue((self.backups / FIRST / "RESTORE_CHECK").exists())
        self.assertIn("shipping pending set", self.log.read_text())

    def test_the_next_nightly_retries_the_pending_set_and_clears_it(self):
        self.make_set(FIRST)
        self.mode.write_text("403")
        self.assertEqual(self.nightly(**self.hardened()).returncode, 0)
        self.assertTrue(self.wait_for(lambda: self.evidence_lines() == ["daily failed "]))
        self.make_set(SECOND)
        self.mode.write_text("real")
        run = self.nightly(T_SET=SECOND, **self.hardened())
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertTrue(self.wait_for(lambda: not self.marker(FIRST).exists() and not self.marker(SECOND).exists()),
                        self.log.read_text())
        self.assertEqual(self.evidence_lines(), ["daily failed ", f"daily ok {SECOND}"])
        self.assertEqual(len(list(self.remote.glob("*.tar.age"))), 2)

    def test_with_an_off_host_destination_the_ok_row_waits_for_the_shipment(self):
        self.make_set(FIRST)
        self.mode.write_text("real")
        # The unit is accepted but has not run: the nightly run itself must not have recorded an "ok" for a set that has no off-host copy.
        run = self.nightly(FAKE_SYSTEMD="record", **self.hardened())
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertEqual(self.evidence_lines(), [], "daily ok was recorded for a set that has no off-host copy")
        self.assertTrue(self.marker(FIRST).is_file())

    def test_without_a_destination_it_records_ok_immediately_as_before(self):
        self.make_set(FIRST)
        run = self.nightly()
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertEqual(self.evidence_lines(), [f"daily ok {FIRST}"])
        self.assertEqual([p.name for p in self.backups.iterdir() if p.name.startswith(".offhost-pending")], [])

    def test_a_failed_snapshot_still_resumes_records_failed_starts_the_retry_and_fails_the_run(self):
        self.make_set(FIRST)
        self.queue(set_name("20261109"))  # a set an earlier failure left pending
        self.make_set(set_name("20261109"))
        run = self.nightly(T_SNAPSHOT_FAILS=1, **self.hardened())
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("ctl resume", self.trace.read_text())
        self.assertIn("snapshot or restore proof failed", run.stderr)
        self.assertIn("daily failed ", self.evidence_lines())
        self.assertTrue(self.wait_for(lambda: not self.marker(set_name("20261109")).exists()), "the earlier set is retried")

    def test_the_branch_orders_resume_then_evidence_then_the_detached_start_and_never_ships_in_the_foreground(self):
        nightly = SOURCE[SOURCE.index("  nightly)"):SOURCE.index("  predeploy)")]
        code = "\n".join(line for line in nightly.splitlines() if not line.lstrip().startswith("#"))
        self.assertLess(code.index('"$CTL" resume'), code.index("ship_pending_start"))
        self.assertLess(code.index("ship_pending_start"), code.index('[ "$snapshot_status" = 0 ] || die'))
        self.assertLess(code.index('[ "$snapshot_status" = 0 ] || die'), code.index("prune_local"))
        for forbidden in ("ship ", "ship_pending\n", "ship_pending;", "rclone", "rsync", "age "):
            self.assertNotIn(forbidden, code)


class TheDeployNeverShips(unittest.TestCase):
    def test_forge_deploy_and_ctl_have_no_foreground_transfer(self):
        repo = Path(__file__).resolve().parents[2]
        deploy = (repo / "ops/staging/forge-deploy.sh").read_text()
        ctl = (repo / "ops/staging/bin/vasey-staging-ctl").read_text()
        for text in (deploy, ctl):
            code = "\n".join(line for line in text.splitlines() if not line.lstrip().startswith("#"))
            for forbidden in ("rclone ", "rsync ", "age --encrypt", " ship "):
                self.assertNotIn(forbidden, code)
        # ctl: the only shipping call is the detached start, and it is non-fatal.
        self.assertEqual(ctl.count('"$BACKUP_BIN" ship-pending-start'), 2)
        start = ctl[ctl.index("start_ship_pending() {"):]
        start = start[:start.index("\n}\n")]
        self.assertIn("|| echo", start)
        self.assertIn("return 0", start)


if __name__ == "__main__":
    unittest.main()
