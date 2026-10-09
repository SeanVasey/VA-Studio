"""Actual recovery branches; mount/root authority is bounded to owned files, no host writes."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[2]


class RecoveryAdmissionTest(unittest.TestCase):
    def test_served_release_propagates_a_failed_sealing_check_inside_command_substitution(self):
        source = (REPO / "ops/staging/bin/vasey-staging-ctl").read_text()
        body = source[source.index("served_release()"):source.index("program_state()")]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            release = root / "releases" / ("a" * 40)
            release.mkdir(parents=True)
            (root / "current").symlink_to(release)
            shell = 'die() { exit 1; }; valid_sha() { return 0; }; sealed_release() { return 1; }\n'
            run = subprocess.run(["bash", "-eu", "-c", shell + body + '\ncaptured=$(served_release) || exit 1'],
                                 capture_output=True, text=True,
                                 env={"PATH": "/usr/bin:/bin", "CURRENT": str(root / "current"), "RELEASES": str(root / "releases")})
            self.assertNotEqual(run.returncode, 0, "failed sealer was swallowed by command substitution")

    def test_first_install_also_takes_the_pre_migration_snapshot(self):
        source = (REPO / "ops/staging/bin/vasey-staging-ctl").read_text()
        body = source[source.index('cmd_activate()'):source.index('cmd_refresh()')]
        shell = '''die() { exit 1; }
valid_sha() { return 0; }
release_step_file() { :; }
operation_evidence() { :; }
sealed_release() { :; }
operation_begin() { :; }
cmd_quiesce() { :; }
cmd_snapshot() { echo snapshot; [ "$SNAPSHOT_OK" = 1 ]; }
as_app() { echo migration; }
cmd_switch() { :; }
cmd_resume() { :; }
operation_finish() { :; }
'''
        with tempfile.TemporaryDirectory() as directory:
            for snapshot_ok in ('0', '1'):
                run = subprocess.run(["bash", "-eu", "-c", shell + body + '\ncmd_activate "$SHA" "$EVIDENCE"'], capture_output=True, text=True,
                                     env={"PATH": "/usr/bin:/bin", "CURRENT": str(Path(directory) / 'absent-current'),
                                          "SHA": 'a' * 40, "EVIDENCE": directory, "RELEASE_STEP": 'fixture-step', "SNAPSHOT_OK": snapshot_ok})
                self.assertEqual(run.returncode == 0, snapshot_ok == '1', run.stderr)
                self.assertEqual(run.stdout.splitlines(), ['snapshot', 'migration'] if snapshot_ok == '1' else ['snapshot'])

    def test_healthy_checks_admission_maintenance_bind_services_path_and_http_without_repair(self):
        source = (REPO / "ops/staging/bin/vasey-staging-ctl").read_text()
        body = source[source.index("cmd_healthy()"):source.index("cmd_snapshot()")]
        served = source[source.index("served_release()"):source.index("program_state()")]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            release = root / "releases" / ("a" * 40)
            (release / "storage/framework").mkdir(parents=True)
            (release / "storage/app/private").mkdir(parents=True)
            private = root / "private"
            private.mkdir()
            (root / "current").symlink_to(release)
            gate = root / "gate"
            fstab = root / "fstab"
            child = subprocess.Popen(["sleep", "10"], cwd=release)
            wrong_child = subprocess.Popen(["sleep", "10"], cwd=root)
            shell = '''die() { echo "$*" >&2; exit 1; }
valid_sha() { [[ "$1" =~ ^[0-9a-f]{40}$ ]]; }
sealed_release() { [ "$CASE" != unsealed ]; }
writer_files() { [ "$CASE" != untrusted-gate ]; }
mountpoint() { [ "$CASE" != unmounted ]; }
stat() { if [ "$CASE" = wrong-bind ] && [ "$3" != "$PRIVATE" ]; then echo 2:2; else echo 1:1; fi; }
systemctl() { [ "$CASE" != fpm-stopped ]; }
program_state() { if [ "$CASE" = worker-stopped ]; then echo STOPPED; else echo RUNNING; fi; }
supervisorctl() { echo "$WORKER_PID"; }
probe() { if [ "$CASE" = http-failed ]; then echo 503; else echo 200; fi; }
PROGRAMS=(one two three four five)
'''
            body = body.replace("/etc/fstab", str(fstab))
            try:
                for case in ("healthy", "closed", "maintenance", "unsealed", "untrusted-gate", "unmounted",
                             "wrong-bind", "missing-fstab", "fpm-stopped", "worker-stopped", "wrong-worker-path", "http-failed", "wrong-sha"):
                    gate.write_text("closed\n" if case == "closed" else "open\n")
                    fstab.write_text("" if case == "missing-fstab" else f"{private} {release}/storage/app/private none bind 0 0\n")
                    maintenance = release / "storage/framework/maintenance.php"
                    maintenance.unlink(missing_ok=True)
                    if case == "maintenance":
                        maintenance.touch()
                    before = gate.read_bytes(), fstab.read_bytes(), maintenance.exists()
                    env = {"PATH": "/usr/bin:/bin", "CURRENT": str(root / "current"), "RELEASES": str(root / "releases"),
                           "PRIVATE": str(private), "WRITER_GATE": str(gate), "VASEY_PHP_FPM_SERVICE": "fixture-fpm",
                           "CASE": case, "SHA": ("b" if case == "wrong-sha" else "a") * 40,
                           "WORKER_PID": str(wrong_child.pid if case == "wrong-worker-path" else child.pid)}
                    run = subprocess.run(["bash", "-eu", "-c", shell + served + body + '\ncmd_healthy "$SHA"'],
                                         capture_output=True, text=True, env=env)
                    with self.subTest(case=case):
                        self.assertEqual(run.returncode == 0, case == "healthy", run.stdout + run.stderr)
                        self.assertEqual((gate.read_bytes(), fstab.read_bytes(), maintenance.exists()), before)
            finally:
                for process in (child, wrong_child):
                    process.terminate()
                    process.wait(timeout=2)

    def test_already_mounted_bind_recovers_exact_fstab_entry_without_duplication(self):
        source = (REPO / "ops/staging/bin/vasey-staging-ctl").read_text()
        start = source.index("persist_private_bind()") if "persist_private_bind()" in source else source.index("cmd_attach()")
        body = source[start:source.index("cmd_detach()")]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            private = root / "private"
            private.mkdir(mode=0o700)
            fstab = root / "fstab"
            fstab.write_text("# synthetic existing host entry\n")
            body = body.replace("/etc/fstab", str(fstab))
            shell = '''die() { echo "$*" >&2; exit 1; }
valid_sha() { return 0; }
seal_release() { :; }
mountpoint() { return 0; }
stat() { if [ "$2" = '%U %a' ]; then echo 'fixture-app 700'; else echo 1:1; fi; }
'''
            for _ in range(2):
                run = subprocess.run(["bash", "-eu", "-c", shell + body + '\ncmd_attach "$SHA"'],
                                     capture_output=True, text=True,
                                     env={"PATH": "/usr/bin:/bin", "PRIVATE": str(private), "RELEASES": str(root / "releases"),
                                          "VASEY_APP_USER": "fixture-app", "SHA": "a" * 40})
                self.assertEqual(run.returncode, 0, run.stderr)
            expected = str(private) + " " + str(root / "releases" / ("a" * 40) / "storage/app/private") + " none bind 0 0"
            self.assertEqual(fstab.read_text().splitlines().count(expected), 1)


if __name__ == "__main__":
    unittest.main(verbosity=2)
