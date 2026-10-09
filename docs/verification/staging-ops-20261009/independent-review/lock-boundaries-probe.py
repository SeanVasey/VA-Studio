#!/usr/bin/env python3
"""Independent helper-lock, descriptor and nightly control-flow checks.

The helper's actual lock/flock and dispatcher run against owned fixtures. The
authority adapter only replaces UID/mount/removal semantics as documented by the
concurrency driver. No host service, /etc file or privileged command is changed.
"""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import time
import unittest

REPO = Path(__file__).resolve().parents[4]
CTL_SOURCE = (REPO / "ops/staging/bin/vasey-staging-ctl").read_text()
BACKUP_SOURCE = (REPO / "ops/staging/backup.sh").read_text()
SPEC = importlib.util.spec_from_file_location("concurrency_fixture", Path(__file__).with_name("ctl-concurrency-probe.py"))
FIXTURE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(FIXTURE)


def prepare(base):
    root = base / "root"
    (root / "releases").mkdir(parents=True)
    (root / "private").mkdir(mode=0o700)
    configuration = base / "etc/vasey-staging"
    configuration.mkdir(parents=True)
    (configuration / "ctl.lock").touch(mode=0o600)
    user = subprocess.check_output(["id", "-un"], text=True).strip()
    group = subprocess.check_output(["id", "-gn"], text=True).strip()
    (configuration / "staging.conf").write_text("\n".join([
        "VASEY_STAGING_HOST=staging.synthetic.invalid", "VASEY_APP_USER=" + user,
        "VASEY_APP_GROUP=" + group, "VASEY_ROOT=" + str(root), "VASEY_PHP=/usr/bin/false",
        "VASEY_PHP_FPM_SERVICE=synthetic-review-only", "VASEY_PROBE_NETRC=" + str(base / "unused.netrc"),
        "VASEY_KEEP_RELEASES=1", "",
    ]))
    authority = base / "authority.sh"
    authority.write_text(FIXTURE.BASH_ENV + r'''
supervisorctl() { printf 'synthetic STOPPED\n'; }
pgrep() { return 1; }
systemctl() { return 1; }
''')
    helper = base / "ctl"
    helper.write_text(CTL_SOURCE.replace("/etc/vasey-staging", str(configuration)).replace("/etc/fstab", str(base / "fstab")))
    env = {"PATH": "/usr/bin:/bin", "BASH_ENV": str(authority), "REVIEW_BASE": str(base),
           "REVIEW_TARGET": str(root / "releases/unused/storage/app/private")}
    return root, configuration, helper, env


class LockBoundaries(unittest.TestCase):
    def test_lock_symlink_directory_or_wide_mode_is_refused(self):
        for shape in ("symlink", "directory", "mode"):
            with self.subTest(shape=shape), tempfile.TemporaryDirectory() as directory:
                base = Path(directory)
                root, configuration, helper, env = prepare(base)
                lock = configuration / "ctl.lock"
                if shape == "mode":
                    lock.chmod(0o666)
                else:
                    lock.unlink()
                    if shape == "directory":
                        lock.mkdir()
                    else:
                        target = base / "untouched"
                        target.write_text("unchanged")
                        lock.symlink_to(target)
                run = subprocess.run(["bash", str(helper), "allocate", "a" * 40], env=env, text=True, capture_output=True, timeout=5)
                self.assertNotEqual(run.returncode, 0)
                self.assertIn("untrusted helper action lock", run.stderr)
                self.assertFalse((root / "releases" / ("a" * 40)).exists())
                if shape == "symlink":
                    self.assertEqual(target.read_text(), "unchanged")

    def test_reprovision_keeps_existing_lock_inode(self):
        provision = (REPO / "ops/staging/provision.sh").read_text()
        block = provision[provision.index("# Preserve the lock inode"):provision.index('info "directories under')]
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            root, configuration, helper, env = prepare(base)
            lock = configuration / "ctl.lock"
            inode = lock.stat().st_ino
            # This execution environment skips BASH_ENV on bash -c; load only the owned authority
            # adapter explicitly for this extracted provisioning block, as full-helper runs do.
            script = '. "$BASH_ENV"\ndie() { printf "%s\\n" "$*" >&2; exit 1; }\n' + block.replace("/etc/vasey-staging", str(configuration))
            for _ in range(2):
                run = subprocess.run(["bash", "-eu", "-c", script], env=env, text=True, capture_output=True, timeout=5)
                self.assertEqual(run.returncode, 0, run.stderr)
                self.assertEqual(lock.stat().st_ino, inode)

    def test_application_subprocess_does_not_inherit_root_action_descriptor(self):
        line = next(line for line in CTL_SOURCE.splitlines() if line.startswith("as_app()"))
        with tempfile.TemporaryDirectory() as directory:
            lock = Path(directory) / "lock"
            script = r'''
runuser() { while [ "$1" != -- ]; do shift; done; shift; "$@"; }
VASEY_APP_USER=synthetic-only
exec 8>>"$LOCK"
flock -x 8
''' + line + '\nas_app bash -eu -c \'[ ! -e /proc/self/fd/8 ]\'\n'
            run = subprocess.run(["bash", "-eu", "-c", script], env={"PATH": "/usr/bin:/bin", "LOCK": str(lock)},
                                 text=True, capture_output=True, timeout=5)
            self.assertEqual(run.returncode, 0, run.stderr)

    def test_resume_waits_for_the_entire_snapshot_action(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            root, configuration, helper, env = prepare(base)
            backup = base / "backup"
            backup.write_text(r'''#!/usr/bin/env bash
set -eu
[ "$1" = predeploy ]
[ -e /proc/self/fd/8 ]
: > "$REVIEW_BASE/snapshot-entered"
while [ ! -f "$REVIEW_BASE/snapshot-continue" ]; do sleep 0.01; done
''')
            backup.chmod(0o700)
            helper.write_text(helper.read_text().replace("BACKUP_BIN=/usr/local/sbin/vasey-staging-backup", "BACKUP_BIN=" + str(backup)))
            snapshot = subprocess.Popen(["bash", str(helper), "snapshot"], env=env, text=True,
                                        stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            resume = None
            try:
                FIXTURE.wait_for(base / "snapshot-entered", snapshot)
                resume = subprocess.Popen(["bash", str(helper), "resume"], env=env, text=True,
                                          stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                time.sleep(0.2)
                self.assertIsNone(resume.poll(), "resume entered while the serialized snapshot was active")
                (base / "snapshot-continue").touch()
                _, error = snapshot.communicate(timeout=5)
                self.assertEqual(snapshot.returncode, 0, error)
                _, error = resume.communicate(timeout=5)
                self.assertNotEqual(resume.returncode, 0)
                self.assertIn("no served release to resume", error)
            finally:
                (base / "snapshot-continue").touch(exist_ok=True)
                for process in (snapshot, resume):
                    if process is not None and process.poll() is None:
                        process.kill()
                        process.communicate()

    def test_disposable_mysql_children_close_root_action_descriptor(self):
        start = BACKUP_SOURCE.index('  "$VASEY_MYSQLD" --no-defaults --initialize-insecure')
        end = BACKUP_SOURCE.index("  RC_PID=$!", start) + len("  RC_PID=$!")
        block = BACKUP_SOURCE[start:end]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "run").mkdir()
            executable = root / "fake-mysqld"
            executable.write_text('#!/usr/bin/env bash\nset -eu\n[ ! -e /proc/self/fd/8 ]\nprintf "closed\\n" >> "$TRACE"\n')
            executable.chmod(0o700)
            prefix = 'die() { printf "%s\\n" "$*" >&2; exit 1; }\nexec 8>>"$LOCK"\nflock -x 8\n'
            run = subprocess.run(["bash", "-eu", "-c", prefix + block + '\nwait "$RC_PID"\n[ -e /proc/self/fd/8 ]\n'],
                                 env={"PATH": "/usr/bin:/bin", "VASEY_MYSQLD": str(executable), "RC_WORK": str(root),
                                      "data": str(root / "data"), "run": str(root / "run"), "sock": str(root / "run/socket"),
                                      "LOCK": str(root / "lock"), "TRACE": str(root / "trace")},
                                 text=True, capture_output=True, timeout=5)
            self.assertEqual(run.returncode, 0, run.stderr)
            self.assertEqual((root / "trace").read_text().splitlines(), ["closed", "closed"])

    def test_nightly_uses_serialized_snapshot_and_resumes_after_failure(self):
        block = BACKUP_SOURCE[BACKUP_SOURCE.index("  nightly)\n") + len("  nightly)\n"):BACKUP_SOURCE.index("    ;;\n  predeploy)")]
        for fail in ("no", "yes"):
            with self.subTest(fail=fail), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                (root / "current").symlink_to(root / "unused")
                ctl = root / "ctl"
                ctl.write_text('#!/usr/bin/env bash\nprintf "%s\\n" "$1" >> "$TRACE"\n[ "$1:$FAIL" != snapshot:yes ]\n')
                ctl.chmod(0o700)
                prefix = r'''
die() { printf '%s\n' "$*" >&2; exit 1; }
install() { mkdir -p "$VASEY_BACKUP_DIR"; }
snapshot() { echo unsafe-direct-snapshot >> "$TRACE"; return 73; }
restore_check() { echo duplicate-restore >> "$TRACE"; return 73; }
ship() { echo duplicate-ship >> "$TRACE"; return 73; }
prune_local() { echo prune-local >> "$TRACE"; }
'''
                env = {"PATH": "/usr/bin:/bin", "VASEY_ROOT": str(root), "VASEY_BACKUP_DIR": str(root / "backups"),
                       "CTL": str(ctl), "TRACE": str(root / "trace"), "FAIL": fail}
                run = subprocess.run(["bash", "-eu", "-c", prefix + block], env=env, text=True, capture_output=True, timeout=5)
                trace = (root / "trace").read_text().splitlines()
                if fail == "no":
                    self.assertEqual(run.returncode, 0, run.stderr)
                    self.assertEqual(trace, ["quiesce", "snapshot", "resume", "prune-local"])
                else:
                    self.assertNotEqual(run.returncode, 0)
                    self.assertEqual(trace, ["quiesce", "snapshot", "resume"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
