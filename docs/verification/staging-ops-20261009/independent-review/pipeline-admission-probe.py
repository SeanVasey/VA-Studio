#!/usr/bin/env python3
"""Independent actual-helper/runner admission checks using only disposable authority fixtures.

Root identity is mapped in owned files and /tmp ancestry; real modes, links,
canonicalization, file bytes, subprocesses and flock are retained. Service, curl,
runuser and backup authority are harmless fixture commands. No host path is written.
"""
import argparse
import fcntl
import os
from pathlib import Path
import subprocess
import tempfile
import time
import unittest

REPO = Path(__file__).resolve().parents[4]
SHA = "a" * 40
SOURCE_SHA = None


def source(path):
    if SOURCE_SHA:
        return subprocess.check_output(["git", "show", SOURCE_SHA + ":" + path], cwd=REPO, text=True)
    return (REPO / path).read_text()


def wait_for(path, process=None):
    deadline = time.monotonic() + 5
    while not path.exists():
        if process is not None and process.poll() is not None:
            out, err = process.communicate()
            raise AssertionError("fixture exited before marker: " + out + err)
        if time.monotonic() >= deadline:
            raise AssertionError("fixture marker deadline exceeded: " + path.name)
        time.sleep(0.01)


class AdmissionProbe(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="vasey-independent-admission.")
        self.base = Path(self.temporary.name)
        self.conf = self.base / "etc/vasey-staging"
        self.conf.mkdir(parents=True, mode=0o755)
        self.root = self.base / "root"
        self.rel = self.root / "releases" / SHA
        self.target = self.rel / "storage/app/private"
        self.target.mkdir(parents=True)
        (self.rel / "storage/framework").mkdir()
        self.private = self.root / "private"
        self.private.mkdir(mode=0o700)
        self.lock = self.conf / "writer.lock"
        self.lock.touch(mode=0o644)
        self.lock.chmod(0o644)
        self.gate = self.conf / "writer-admission"
        self.gate.write_text("open\n")
        self.gate.chmod(0o644)
        (self.conf / "ctl.lock").touch(mode=0o600)
        self.bin = self.base / "bin"
        self.bin.mkdir()
        self.stat = self.bin / "stat"
        self.stat.write_text(r'''#!/bin/bash
path=${@: -1}
if [ "$1" = -c ]; then
  case "$2" in
    %u) if [ "$path" = "${WRONG_OWNER:-}" ]; then echo 1000; else echo 0; fi; exit 0 ;;
    %a) if [ "$path" = /tmp ]; then echo 755; exit 0; fi ;;
    '%u %a')
      owner=0; [ "$path" != "${WRONG_OWNER:-}" ] || owner=1000
      printf '%s %s\n' "$owner" "$(/usr/bin/stat -c %a -- "$path")"; exit 0 ;;
    %d:%i) if [ "$path" = "$REVIEW_TARGET" ]; then exec /usr/bin/stat -c %d:%i -- "$REVIEW_PRIVATE"; fi ;;
  esac
fi
exec /usr/bin/stat "$@"
''')
        self.stat.chmod(0o700)
        self.php = self.bin / "harmless-php"
        self.php.write_text(r'''#!/bin/bash
set -eu
command=$2
case "$command" in
  down|up|queue:restart)
    [ ! -e /proc/self/fd/7 ] && [ ! -e /proc/self/fd/8 ]
    printf 'app-%s\n' "$command" >> "$REVIEW_BASE/trace"
    if [ "$command" = down ]; then
      [ "${FAIL:-}" != rollback-down ] || exit 73
      : > "$REVIEW_RELEASE/storage/framework/maintenance.php"
    elif [ "$command" = up ]; then
      [ "${FAIL:-}" != artisan-up ] || exit 73
      rm -f -- "$REVIEW_RELEASE/storage/framework/maintenance.php"
    fi ;;
  *)
    printf '%s\n' "$command" >> "$REVIEW_BASE/pipeline-trace"
    pwd -P >> "$REVIEW_BASE/pipeline-cwd"
    if [ "${BLOCK_PIPELINE:-0}" = 1 ] && [ "$command" = vasey:process-stripe-receipts ]; then
      : > "$REVIEW_BASE/pipeline-entered"
      while [ ! -e "$REVIEW_BASE/pipeline-continue" ]; do /usr/bin/sleep 0.01; done
    fi ;;
esac
''')
        self.php.chmod(0o700)
        self.backup = self.bin / "harmless-backup"
        self.backup.write_text(r'''#!/bin/bash
set -eu
[ "$1" = predeploy ]
[ -e /proc/self/fd/8 ]
: > "$REVIEW_BASE/backup-entered"
if [ "${BLOCK_BACKUP:-0}" = 1 ]; then
  while [ ! -e "$REVIEW_BASE/backup-continue" ]; do /usr/bin/sleep 0.01; done
fi
''')
        self.backup.chmod(0o700)
        self.adapter = self.base / "authority.sh"
        self.adapter.write_text(r'''
id() { if [ "${1:-}" = -u ]; then echo 0; else command id "$@"; fi; }
stat() { "$REVIEW_STAT" "$@"; }
pgrep() { [ "${REAL_PGREP:-0}" != 1 ] || { command pgrep "$@"; return; }; return 1; }
sleep() { /usr/bin/sleep 0.01; }
systemctl() {
  printf 'service-%s\n' "$1" >> "$REVIEW_BASE/trace"
  case "$1" in
    start) [ "${FAIL:-}" != fpm-start ] ;;
    is-active) [ "${CONTROL_ACTION:-}" = resume ] ;;
    *) return 0 ;;
  esac
}
supervisorctl() {
  case "$1" in
    status)
      state=STOPPED
      if [ "${CONTROL_ACTION:-}" = resume ]; then
        state=RUNNING; [ "${FAIL:-}" != workers ] || state=FATAL
      fi
      printf 'synthetic %s\n' "$state" ;;
    pid) printf '%s\n' "$WORKER_PID" ;;
    *) printf 'supervisor-%s\n' "$1" >> "$REVIEW_BASE/trace" ;;
  esac
}
runuser() { while [ "$1" != -- ]; do shift; done; shift; "$@"; }
curl() {
  count=0; [ ! -e "$REVIEW_BASE/probe-count" ] || count=$(cat "$REVIEW_BASE/probe-count")
  count=$((count+1)); printf '%s\n' "$count" > "$REVIEW_BASE/probe-count"
  code=503
  if [ "$count" = 1 ] && [ "${FAIL:-}" = first-probe ]; then code=500; fi
  if [ "$count" = 2 ]; then
    code=200
    case "${FAIL:-}" in final-probe|rollback-down) code=500 ;; esac
    if [ "${BLOCK_HEALTH:-0}" = 1 ]; then
      [ "$(cat "$REVIEW_GATE")" = closed ]
      : > "$REVIEW_BASE/healthy-proof-entered"
      while [ ! -e "$REVIEW_BASE/healthy-proof-continue" ]; do /usr/bin/sleep 0.01; done
    fi
  fi
  printf '%s' "$code"
}
''')
        user = subprocess.check_output(["id", "-un"], text=True).strip()
        self.config = self.conf / "staging.conf"
        self.config.write_text("\n".join([
            "VASEY_STAGING_HOST=staging.synthetic.invalid", "VASEY_APP_USER=" + user,
            "VASEY_APP_GROUP=" + user, "VASEY_ROOT=" + str(self.root), "VASEY_PHP=" + str(self.php),
            "VASEY_PHP_FPM_SERVICE=synthetic-only", "VASEY_PROBE_NETRC=" + str(self.base / "unused.netrc"),
            "VASEY_KEEP_RELEASES=3", "",
        ]))
        self.config.chmod(0o600)
        self.control = self.base / "control"
        self.control.write_text(source("ops/staging/bin/vasey-staging-ctl")
                                .replace("/etc/vasey-staging", str(self.conf))
                                .replace("BACKUP_BIN=/usr/local/sbin/vasey-staging-backup", "BACKUP_BIN=" + str(self.backup)))
        self.runner = self.base / "runner"
        self.runner.write_text(source("scripts/ops/run-test-commerce-pipeline.sh")
                               .replace("/etc/vasey-staging", str(self.conf))
                               .replace("/usr/bin/stat", str(self.stat)))
        (self.rel / "artisan").touch()
        self.environment = {
            "PATH": "/usr/bin:/bin", "APP_ROOT": str(self.rel), "PHP_BIN": str(self.php),
            "STATE_DIR": str(self.base / "pipeline-state"), "MAX_PAGES": "1",
            "RECONCILE_INTERVAL_SECONDS": "0", "COMMAND_TIMEOUT_SECONDS": "10",
            "REVIEW_BASE": str(self.base), "REVIEW_STAT": str(self.stat), "REVIEW_GATE": str(self.gate),
            "REVIEW_RELEASE": str(self.rel), "REVIEW_TARGET": str(self.target), "REVIEW_PRIVATE": str(self.private),
        }
        self.processes = []

    def tearDown(self):
        for marker in ("pipeline-continue", "backup-continue", "healthy-proof-continue"):
            (self.base / marker).touch(exist_ok=True)
        for process in self.processes:
            if process.poll() is None:
                process.terminate()
            try:
                process.communicate(timeout=3)
            except subprocess.TimeoutExpired:
                process.kill()
                process.communicate(timeout=3)
        self.temporary.cleanup()

    def start(self, command, environment):
        process = subprocess.Popen(command, env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        self.processes.append(process)
        return process

    def runner_environment(self, **settings):
        return self.environment | settings

    def control_environment(self, action, **settings):
        return self.environment | {"BASH_ENV": str(self.adapter), "CONTROL_ACTION": action} | settings

    def run_runner(self, **settings):
        return subprocess.run(["bash", str(self.runner)], env=self.runner_environment(**settings),
                              capture_output=True, text=True, timeout=10)

    def run_control(self, action, **settings):
        return subprocess.run(["bash", str(self.control), action], env=self.control_environment(action, **settings),
                              capture_output=True, text=True, timeout=10)

    def served_fixture(self, wrong_cwd=False):
        (self.root / "current").symlink_to(self.rel)
        (self.rel / "storage/framework/maintenance.php").touch()
        self.gate.write_text("closed\n")
        worker = subprocess.Popen(["sleep", "20"], cwd=self.base if wrong_cwd else self.rel)
        self.processes.append(worker)
        self.environment["WORKER_PID"] = str(worker.pid)

    def test_admitted_sweep_refuses_quiesce_and_future_starts_until_retry(self):
        runner = self.start(["bash", str(self.runner)], self.runner_environment(BLOCK_PIPELINE="1"))
        wait_for(self.base / "pipeline-entered", runner)
        quiesce = self.run_control("quiesce")
        self.assertNotEqual(quiesce.returncode, 0, "active admitted sweep was not drained")
        self.assertIn("still active", quiesce.stderr)
        self.assertEqual(self.gate.read_bytes(), b"closed\n")
        self.assertFalse((self.base / "trace").exists(), "services changed before the admitted sweep drained")
        later_state = self.base / "later-state"
        later = self.run_runner(STATE_DIR=str(later_state))
        self.assertEqual(later.returncode, 0, later.stderr)
        self.assertFalse(later_state.exists())
        self.assertNotEqual(self.run_control("snapshot").returncode, 0)
        self.assertFalse((self.base / "backup-entered").exists())
        (self.base / "pipeline-continue").touch()
        _, err = runner.communicate(timeout=5)
        self.assertEqual(runner.returncode, 0, err)
        retry = self.run_control("quiesce")
        self.assertEqual(retry.returncode, 0, retry.stderr)
        snapshot = self.run_control("snapshot")
        self.assertEqual(snapshot.returncode, 0, snapshot.stderr)

    def test_snapshot_excludes_future_sweep_and_serializes_resume(self):
        self.served_fixture()
        snapshot = self.start(["bash", str(self.control), "snapshot"], self.control_environment("snapshot", BLOCK_BACKUP="1"))
        wait_for(self.base / "backup-entered", snapshot)
        with self.lock.open() as independent:
            with self.assertRaises(BlockingIOError):
                fcntl.flock(independent, fcntl.LOCK_SH | fcntl.LOCK_NB)
        skipped = self.run_runner()
        self.assertEqual(skipped.returncode, 0, skipped.stderr)
        self.assertFalse((self.base / "pipeline-state").exists())
        resume = self.start(["bash", str(self.control), "resume"], self.control_environment("resume"))
        time.sleep(0.05)
        self.assertIsNone(resume.poll(), "resume crossed snapshot's root action lock")
        self.assertEqual(self.gate.read_bytes(), b"closed\n")
        (self.base / "backup-continue").touch()
        _, err = snapshot.communicate(timeout=5)
        self.assertEqual(snapshot.returncode, 0, err)
        _, err = resume.communicate(timeout=5)
        self.assertEqual(resume.returncode, 0, err)
        self.assertEqual(self.gate.read_bytes(), b"open\n")

    def test_resume_keeps_gate_closed_on_each_failure_and_wrong_worker_release(self):
        for failure in ("fpm-start", "first-probe", "workers", "artisan-up", "final-probe", "rollback-down", "worker-cwd"):
            with self.subTest(failure=failure):
                (self.root / "current").unlink(missing_ok=True)
                self.served_fixture(wrong_cwd=failure == "worker-cwd")
                (self.base / "probe-count").unlink(missing_ok=True)
                run = self.run_control("resume", FAIL=failure)
                self.assertNotEqual(run.returncode, 0, run.stdout + run.stderr)
                self.assertEqual(self.gate.read_bytes(), b"closed\n", "failed resume reopened admission")
                self.assertEqual(self.run_runner().returncode, 0)
                self.assertFalse((self.base / "pipeline-state").exists())

    def test_only_completed_healthy_resume_opens_gate_and_releases_shared_sweep(self):
        self.served_fixture()
        resume = self.start(["bash", str(self.control), "resume"], self.control_environment("resume", BLOCK_HEALTH="1"))
        wait_for(self.base / "healthy-proof-entered", resume)
        self.assertEqual(self.gate.read_bytes(), b"closed\n")
        self.assertEqual(self.run_runner().returncode, 0)
        self.assertFalse((self.base / "pipeline-state").exists())
        (self.base / "healthy-proof-continue").touch()
        _, err = resume.communicate(timeout=5)
        self.assertEqual(resume.returncode, 0, err)
        self.assertEqual(self.gate.read_bytes(), b"open\n")
        run = self.run_runner()
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(len((self.base / "pipeline-trace").read_text().splitlines()), 5)

    def test_untrusted_files_and_ancestry_refuse_even_with_alternate_state_directory(self):
        for shape in ("gate-symlink", "lock-symlink", "gate-mode", "lock-mode", "gate-owner", "lock-owner", "directory-mode", "missing-both", "directory-symlink"):
            with self.subTest(shape=shape):
                # Each subcase uses a fresh full fixture, preserving the parent fixture's paths.
                case = AdmissionProbe(methodName="test_untrusted_files_and_ancestry_refuse_even_with_alternate_state_directory")
                case.setUp()
                try:
                    env = {}
                    if shape.endswith("symlink"):
                        path = case.conf if shape == "directory-symlink" else case.gate if shape == "gate-symlink" else case.lock
                        if path.is_dir():
                            moved = path.with_name("real-conf")
                            path.rename(moved)
                            path.symlink_to(moved)
                        else:
                            path.unlink()
                            path.symlink_to(case.base / "missing")
                    elif shape.endswith("mode"):
                        path = case.conf if shape == "directory-mode" else case.gate if shape == "gate-mode" else case.lock
                        path.chmod(0o777 if path.is_dir() else 0o666)
                    elif shape.endswith("owner"):
                        env["WRONG_OWNER"] = str(case.gate if shape == "gate-owner" else case.lock)
                    else:
                        case.gate.unlink()
                        case.lock.unlink()
                    run = case.run_runner(STATE_DIR=str(case.base / "alternative-private-state"), **env)
                    self.assertEqual(run.returncode, 2, run.stdout + run.stderr)
                    self.assertFalse((case.base / "alternative-private-state").exists())
                    controlled = case.run_control("snapshot", **env)
                    self.assertNotEqual(controlled.returncode, 0)
                    self.assertFalse((case.base / "backup-entered").exists())
                finally:
                    case.tearDown()

    def test_gate_requires_exact_bytes_and_snapshot_refuses_open_or_malformed(self):
        for value in (b"open\0", b"open\n\0", b"open\n\n", b"OPEN\n", b"closed\0", b"open", b"x" * 8):
            with self.subTest(value=value):
                self.gate.write_bytes(value)
                run = self.run_runner()
                self.assertEqual(run.returncode, 2, run.stdout + run.stderr)
                self.assertFalse((self.base / "pipeline-state").exists())
                self.assertNotEqual(self.run_control("snapshot").returncode, 0)
                self.assertFalse((self.base / "backup-entered").exists())
        self.gate.write_bytes(b"open\n")
        self.assertNotEqual(self.run_control("snapshot").returncode, 0)

    def test_switch_requires_closed_admission_and_excludes_a_shared_writer(self):
        (self.rel / "storage/framework/maintenance.php").touch()
        command = ["bash", str(self.control), "switch", SHA]
        env = self.control_environment("switch")
        run = subprocess.run(command, env=env, capture_output=True, text=True, timeout=5)
        self.assertNotEqual(run.returncode, 0, "switch accepted an open admission gate")
        self.assertFalse((self.root / "current").exists())
        self.gate.write_bytes(b"closed\n")
        with self.lock.open() as held:
            fcntl.flock(held, fcntl.LOCK_SH | fcntl.LOCK_NB)
            run = subprocess.run(command, env=env, capture_output=True, text=True, timeout=5)
            self.assertNotEqual(run.returncode, 0, "switch crossed an admitted writer")
            self.assertFalse((self.root / "current").exists())
        run = subprocess.run(command, env=env, capture_output=True, text=True, timeout=5)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual((self.root / "current").resolve(), self.rel)
        self.assertEqual(self.gate.read_bytes(), b"closed\n")

    def test_current_script_default_root_runs_on_the_selected_physical_release(self):
        (self.root / "current").symlink_to(self.rel)
        scripts = self.rel / "scripts/ops"
        scripts.mkdir(parents=True)
        selected = scripts / "run-test-commerce-pipeline.sh"
        selected.write_text(self.runner.read_text())
        mirror = self.base / "home/forge/mirror"
        mirror.mkdir(parents=True)
        (mirror / "artisan").touch()
        env = self.environment.copy()
        env.pop("APP_ROOT")
        env.pop("STATE_DIR")
        run = subprocess.run(["bash", str(self.root / "current/scripts/ops/run-test-commerce-pipeline.sh")],
                             env=env, capture_output=True, text=True, timeout=5)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual((self.base / "pipeline-cwd").read_text().splitlines(), [str(self.rel)] * 5)
        self.assertTrue((self.target / "test-commerce-pipeline").is_dir())
        self.assertFalse((mirror / "storage").exists())

    def test_loop_rechecks_admission_before_each_later_sweep(self):
        runner = self.start(["bash", str(self.runner), "--loop"],
                            self.runner_environment(BLOCK_PIPELINE="1", LOOP_ITERATIONS="2", LOOP_SLEEP_SECONDS="1"))
        wait_for(self.base / "pipeline-entered", runner)
        self.assertNotEqual(self.run_control("quiesce").returncode, 0)
        self.assertEqual(self.gate.read_bytes(), b"closed\n")
        (self.base / "pipeline-continue").touch()
        out, err = runner.communicate(timeout=5)
        self.assertEqual(runner.returncode, 0, err)
        self.assertIn("writer admission closed; skipped", out)
        self.assertEqual(len((self.base / "pipeline-trace").read_text().splitlines()), 5,
                         "second loop sweep bypassed the closed gate")

    def test_provision_initializes_closed_once_and_preserves_lock_inode_and_gate_state(self):
        provision = source("ops/staging/provision.sh")
        block = provision[provision.index("# Preserve the lock inode"):provision.index('info "directories under')]
        block = block.replace("/etc/vasey-staging", str(self.conf))
        self.gate.unlink()
        self.lock.unlink()
        script = '. "$BASH_ENV"\numask 077\ndie() { printf "%s\\n" "$*" >&2; exit 1; }\n' + block
        env = self.control_environment("provision")
        run = subprocess.run(["bash", "-eu", "-c", script], env=env, capture_output=True, text=True, timeout=5)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(self.gate.read_bytes(), b"closed\n")
        self.assertEqual(self.lock.stat().st_mode & 0o777, 0o644)
        lock_inode = self.lock.stat().st_ino
        control_inode = (self.conf / "ctl.lock").stat().st_ino
        self.gate.write_bytes(b"open\n")
        for _ in range(2):
            run = subprocess.run(["bash", "-eu", "-c", script], env=env, capture_output=True, text=True, timeout=5)
            self.assertEqual(run.returncode, 0, run.stderr)
            self.assertEqual(self.lock.stat().st_ino, lock_inode)
            self.assertEqual((self.conf / "ctl.lock").stat().st_ino, control_inode)
            self.assertEqual(self.gate.read_bytes(), b"open\n")

    def test_manual_pipeline_command_is_caught_by_actual_process_scan(self):
        label = "php artisan " + "".join(["vasey:", "issue-test-contracts"])
        manual = subprocess.Popen(["bash", "-c", 'exec -a "$1" sleep 20', "manual-fixture", label])
        self.processes.append(manual)
        self.gate.write_bytes(b"closed\n")
        run = self.run_control("snapshot", REAL_PGREP="1")
        self.assertNotEqual(run.returncode, 0, "snapshot ignored a standalone pipeline command")
        self.assertIn("requires a quiesced host", run.stderr)
        self.assertFalse((self.base / "backup-entered").exists())

    def test_root_application_and_disposable_children_close_both_control_descriptors(self):
        helper = source("ops/staging/bin/vasey-staging-ctl")
        as_app = next(line for line in helper.splitlines() if line.startswith("as_app()"))
        backup = source("ops/staging/backup.sh")
        start = backup.index('  "$VASEY_MYSQLD" --no-defaults --initialize-insecure')
        end = backup.index("  RC_PID=$!", start) + len("  RC_PID=$!")
        mysql_block = backup[start:end]
        start = backup.index('  runuser -u "$VASEY_APP_USER" -- tar --extract')
        end = backup.index('\n\n', start)
        tar_block = backup[start:end]
        mysql = self.bin / "harmless-mysqld"
        mysql.write_text('#!/bin/bash\nset -eu\n[ ! -e /proc/self/fd/7 ]\n[ ! -e /proc/self/fd/8 ]\nprintf "mysql-closed\\n" >> "$DESC_TRACE"\n')
        mysql.chmod(0o700)
        bk = self.base / "backup-directory"
        bk.mkdir()
        (bk / "private.tar").write_text("synthetic archive input")
        script = r'''
set -eu
die() { printf '%s\n' "$*" >&2; exit 1; }
exec 7<"$WRITER_LOCK"
exec 8>>"$CONTROL_LOCK"
flock -x 7
flock -x 8
runuser() {
  while [ "$1" != -- ]; do shift; done; shift
  if [ "$1" = tar ]; then
    bash -eu -c '[ ! -e /proc/self/fd/7 ]; [ ! -e /proc/self/fd/8 ]; cat >/dev/null; printf "tar-closed\n" >> "$DESC_TRACE"'
  else "$@"; fi
}
''' + as_app + r'''
as_app bash -eu -c '[ ! -e /proc/self/fd/7 ]; [ ! -e /proc/self/fd/8 ]; printf "app-closed\n" >> "$DESC_TRACE"'
''' + mysql_block + '\nwait "$RC_PID"\n' + tar_block + '\n[ -e /proc/self/fd/7 ]; [ -e /proc/self/fd/8 ]\n'
        env = self.environment | {
            "WRITER_LOCK": str(self.lock), "CONTROL_LOCK": str(self.conf / "ctl.lock"),
            "VASEY_APP_USER": "synthetic-only", "VASEY_MYSQLD": str(mysql),
            "RC_WORK": str(self.base), "data": str(self.base / "data"), "run": str(self.base),
            "sock": str(self.base / "socket"), "bk": str(bk), "rpriv": str(self.base),
            "DESC_TRACE": str(self.base / "descriptor-trace"),
        }
        run = subprocess.run(["bash", "-eu", "-c", script], env=env, capture_output=True, text=True, timeout=5)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual((self.base / "descriptor-trace").read_text().splitlines(),
                         ["app-closed", "mysql-closed", "mysql-closed", "tar-closed"])

    def test_orphan_artisan_child_retains_admission_until_it_finishes(self):
        runner = self.start(["bash", str(self.runner)], self.runner_environment(BLOCK_PIPELINE="1"))
        wait_for(self.base / "pipeline-entered", runner)
        runner.kill()
        runner.wait(timeout=5)
        quiesce = self.run_control("quiesce")
        self.assertNotEqual(quiesce.returncode, 0, "runner death released its live child's admission")
        self.assertEqual(self.gate.read_bytes(), b"closed\n")
        self.assertFalse((self.base / "trace").exists())
        (self.base / "pipeline-continue").touch()
        runner.communicate(timeout=5)
        deadline = time.monotonic() + 5
        while True:
            with self.lock.open() as held:
                try:
                    fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    break
                except BlockingIOError:
                    if time.monotonic() >= deadline:
                        raise AssertionError("orphan child did not finish")
                    time.sleep(0.01)
        retry = self.run_control("quiesce")
        self.assertEqual(retry.returncode, 0, retry.stderr)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-sha")
    options, remaining = parser.parse_known_args()
    SOURCE_SHA = options.source_sha
    print("Assessed source: " + (SOURCE_SHA or subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()), flush=True)
    unittest.main(argv=[__file__] + remaining, verbosity=2)
