"""Real flock/runner/helper sequencing; host ownership and services are authority fixtures."""
import fcntl
import os
from pathlib import Path
import signal
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]
RUNNER = ROOT / "scripts/ops/run-test-commerce-pipeline.sh"
CONTROL = ROOT / "ops/staging/bin/vasey-staging-ctl"


class PipelineAdmissionTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.directory = Path(self.temporary.name)
        self.conf = self.directory / "conf"
        self.conf.mkdir()
        self.lock = self.conf / "writer.lock"
        self.lock.touch(mode=0o644)
        self.lock.chmod(0o644)
        self.gate = self.conf / "writer-admission"
        self.gate.write_text("open\n")
        self.gate.chmod(0o644)
        self.app = self.directory / "app"
        self.app.mkdir()
        (self.app / "artisan").touch()
        self.trace = self.directory / "trace"
        self.entered = self.directory / "entered"
        self.release = self.directory / "release"
        self.binary = self.directory / "bin"
        self.binary.mkdir()
        # Model protected host ownership/ancestry only; retain real file modes, links, sizes and flock.
        stat = self.binary / "stat"
        stat.write_text('''#!/bin/bash
path=${@: -1}
if [ "$1" = -c ]; then
  case "$2" in
    %u) echo 0; exit 0 ;;
    %a) if [ -d "$path" ]; then echo 755; exit 0; fi ;;
    '%u %a') printf '0 %s\\n' "$(/usr/bin/stat -c %a "$path")"; exit 0 ;;
  esac
fi
exec /usr/bin/stat "$@"
''')
        stat.chmod(0o755)
        self.php = self.binary / "fixture-php"
        self.php.write_text('''#!/bin/bash
printf '%s\\n' "$2" >> "$TRACE"
if [ "${BLOCK:-0}" = 1 ] && [ "$2" = vasey:process-stripe-receipts ]; then
  : > "$ENTERED"
  while [ ! -e "$RELEASE" ]; do sleep 0.01; done
fi
''')
        self.php.chmod(0o755)
        self.runner = self.directory / "runner.sh"
        self.runner.write_text(RUNNER.read_text().replace("/etc/vasey-staging", str(self.conf))
                               .replace("/usr/bin/stat", str(stat)))
        self.environment = {
            "PATH": str(self.binary) + ":/usr/bin:/bin", "APP_ROOT": str(self.app),
            "PHP_BIN": str(self.php), "STATE_DIR": str(self.directory / "state"),
            "MAX_PAGES": "1", "RECONCILE_INTERVAL_SECONDS": "0", "COMMAND_TIMEOUT_SECONDS": "5",
            "TRACE": str(self.trace), "ENTERED": str(self.entered), "RELEASE": str(self.release),
        }

    def tearDown(self):
        self.temporary.cleanup()

    def run_runner(self):
        return subprocess.run(["bash", str(self.runner)], env=self.environment,
                              capture_output=True, text=True, timeout=10)

    def run_control(self, action):
        source = CONTROL.read_text()
        definitions = source[source.index("valid_sha() {"):source.index("# The sudo entry point")]
        definitions = definitions.replace("/etc/vasey-staging", str(self.conf))
        backup = self.binary / "backup"
        backup.write_text('#!/bin/bash\n: > "$BACKUP_MARKER"\n')
        backup.chmod(0o755)
        fixture = '''die() { printf '%s\\n' "$*" >&2; exit 1; }
program_state() { echo STOPPED; }
web_stopped() { return 0; }
pgrep() { return 1; }
systemctl() { return 0; }
'''
        environment = dict(self.environment, ROOT=str(self.directory / "host"),
                           RELEASES=str(self.directory / "host/releases"),
                           CURRENT=str(self.directory / "host/current"),
                           VASEY_APP_USER="fixture-app", VASEY_PHP_FPM_SERVICE="fixture-fpm",
                           BACKUP_BIN=str(backup), BACKUP_MARKER=str(self.directory / "backup-marker"))
        return subprocess.run(["bash", "-eu", "-c", 'PROGRAMS=(one two three four five)\n'
                               + definitions + fixture + "\ncmd_" + action],
                              env=environment, capture_output=True, text=True, timeout=10)

    def test_closed_gate_blocks_repeated_future_sweeps(self):
        self.gate.write_text("closed\n")
        for _ in range(2):
            run = self.run_runner()
            self.assertEqual(run.returncode, 0, run.stderr)
            self.assertIn("writer admission closed", run.stdout)
        self.assertFalse(self.trace.exists(), "scheduled invocation reached artisan while quiesced")
        self.assertFalse((self.directory / "state").exists(), "closed invocation changed private state")

    def test_missing_malformed_symlinked_or_writable_gate_refuses_before_artisan(self):
        for case in ("missing", "malformed", "null-byte", "symlink", "writable"):
            with self.subTest(case=case):
                self.gate.unlink(missing_ok=True)
                if case == "symlink":
                    self.gate.symlink_to(self.directory / "missing")
                elif case != "missing":
                    self.gate.write_text({"malformed": "unexpected\n", "null-byte": "open\0"}.get(case, "open\n"))
                    self.gate.chmod(0o666 if case == "writable" else 0o644)
                run = self.run_runner()
                self.assertEqual(run.returncode, 2, run.stdout + run.stderr)
                self.assertFalse(self.trace.exists())

    def test_exclusive_root_lock_blocks_an_open_gate_sweep(self):
        with self.lock.open() as held:
            fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
            run = self.run_runner()
            self.assertEqual(run.returncode, 0, run.stderr)
            self.assertFalse(self.trace.exists(), "runner ignored root's writer barrier")
            self.assertFalse((self.directory / "state").exists())

    def test_partial_kit_refuses_while_an_absent_kit_keeps_the_local_harness(self):
        self.lock.unlink()
        self.assertEqual(self.run_runner().returncode, 2)
        self.gate.unlink()
        self.assertEqual(self.run_runner().returncode, 2)
        self.assertFalse(self.trace.exists())
        self.conf.rmdir()
        self.assertEqual(self.run_runner().returncode, 0)
        self.assertEqual(len(self.trace.read_text().splitlines()), 5)

    def test_open_gate_runs_all_five_stages(self):
        run = self.run_runner()
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(len(self.trace.read_text().splitlines()), 5)

    def test_writer_lock_survives_runner_death_until_its_child_finishes(self):
        environment = dict(self.environment, BLOCK="1")
        runner = subprocess.Popen(["bash", str(self.runner)], env=environment,
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
        try:
            deadline = time.monotonic() + 5
            while not self.entered.exists() and runner.poll() is None and time.monotonic() < deadline:
                time.sleep(0.01)
            self.assertTrue(self.entered.exists(), "blocking artisan fixture did not enter")
            runner.kill()
            runner.wait(timeout=5)
            with self.lock.open() as held:
                with self.assertRaises(BlockingIOError):
                    fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.release.touch()
            runner.communicate(timeout=5)
            with self.lock.open() as held:
                fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
        finally:
            self.release.touch()
            try:
                os.killpg(runner.pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
            runner.communicate(timeout=5)

    def test_snapshot_requires_the_durable_closed_gate(self):
        run = self.run_control("snapshot")
        self.assertNotEqual(run.returncode, 0, "snapshot accepted an open writer gate")
        self.assertFalse((self.directory / "backup-marker").exists())

    def test_quiesce_leaves_future_invocations_closed_after_helper_exits(self):
        run = self.run_control("quiesce")
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(self.gate.read_text(), "closed\n")
        self.assertEqual(self.run_runner().returncode, 0)
        self.assertFalse(self.trace.exists())
        snapshot = self.run_control("snapshot")
        self.assertEqual(snapshot.returncode, 0, snapshot.stderr)
        self.assertTrue((self.directory / "backup-marker").exists())

    def test_snapshot_does_not_cross_an_already_admitted_shared_writer(self):
        self.gate.write_text("closed\n")
        with self.lock.open() as held:
            fcntl.flock(held, fcntl.LOCK_SH | fcntl.LOCK_NB)
            run = self.run_control("snapshot")
            self.assertNotEqual(run.returncode, 0, "snapshot crossed an admitted pipeline child")
        self.assertFalse((self.directory / "backup-marker").exists())


if __name__ == "__main__":
    unittest.main(verbosity=2)
