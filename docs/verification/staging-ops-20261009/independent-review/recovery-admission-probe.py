"""Independent interrupted-state probes using owned files; no host/root/mount/provider changes."""
import argparse
from pathlib import Path
import subprocess
import tempfile
import unittest


REPO = Path(__file__).resolve().parents[4]
PARSER = argparse.ArgumentParser()
PARSER.add_argument("--source-sha")
ARGUMENTS, TEST_ARGUMENTS = PARSER.parse_known_args()
SOURCE_SHA = ARGUMENTS.source_sha or subprocess.check_output(
    ["git", "-C", str(REPO), "rev-parse", "HEAD"], text=True
).strip()


def source(path):
    return subprocess.check_output(["git", "-C", str(REPO), "show", f"{SOURCE_SHA}:{path}"], text=True)


class RecoveryAdmissionProbe(unittest.TestCase):
    def test_actual_python_wrapper_failure_already_propagates(self):
        script = source("ops/staging/bin/vasey-staging-ctl")

        def function(name):
            begin = script.index(name + "() {")
            return script[begin:script.index("\n}\n", begin) + 3]

        functions = function("seal_tool") + function("sealed_release") + function("served_release")
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            release = root / "releases" / ("a" * 40)
            (release / "storage/app/private").mkdir(parents=True)
            (root / "current").symlink_to(release)
            sealer = root / "harmless-sealer.py"
            sealer.write_text("import sys\nsys.exit(23)\n")
            shell = """die() { echo "$*" >&2; exit 1; }
valid_sha() { [[ "$1" =~ ^[0-9a-f]{40}$ ]]; }
protected_dir() { :; }
stat() { echo '0 644'; }
"""
            run = subprocess.run(
                ["bash", "-eu", "-c", shell + functions + '\ncaptured=$(served_release) || exit 1'],
                capture_output=True, text=True,
                env={"PATH": "/usr/bin:/bin", "CURRENT": str(root / "current"),
                     "RELEASES": str(root / "releases"), "SEALER": str(sealer),
                     "VASEY_APP_USER": "fixture-app", "VASEY_APP_GROUP": "fixture-app"},
            )
            self.assertEqual(run.returncode, 1)
            self.assertEqual(run.stdout, "")
            self.assertIn("protected release operation refused", run.stderr)

    def test_health_proof_rejects_each_partial_state_without_mutation(self):
        script = source("ops/staging/bin/vasey-staging-ctl")

        def function(name):
            begin = script.index(name + "() {")
            return script[begin:script.index("\n}\n", begin) + 3]

        functions = function("served_release") + function("program_state") + function("cmd_healthy")
        programs = next(line for line in script.splitlines() if line.startswith("PROGRAMS=("))
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            sha = "a" * 40
            release = root / "releases" / sha
            (release / "storage/framework").mkdir(parents=True)
            (release / "storage/app/private").mkdir(parents=True)
            (release / ".env").write_text("synthetic retained environment\n")
            (root / "private").mkdir()
            current = root / "current"
            current.symlink_to(release)
            gate = root / "writer-admission"
            gate.write_text("open\n")
            fstab = root / "fstab"
            entry = f"{root / 'private'} {release / 'storage/app/private'} none bind 0 0\n"
            fstab.write_text(entry)
            shell = """die() { echo "$*" >&2; exit 1; }
valid_sha() { [[ "$1" =~ ^[0-9a-f]{40}$ ]]; }
sealed_release() { [ "$MODE" != unsealed ]; }
writer_files() { :; }
mountpoint() { [ "$MODE" != unmounted ]; }
stat() {
  if [ "$MODE" = wrong-source ] && [ "$3" != "$PRIVATE" ]; then echo 2:2;
  else echo 1:1; fi
}
systemctl() {
  [ "$1" = is-active ] || die 'unexpected service mutation'
  [ "$MODE" != inactive-fpm ]
}
supervisorctl() {
  case "$1" in
    status)
      if [ "$MODE" = stopped-worker ] && [ "$2" = vasey-staging:vasey-staging-default ]; then
        echo "$2 STOPPED";
      else echo "$2 RUNNING"; fi ;;
    pid)
      if [ "$MODE" = invalid-pid ] && [ "$2" = vasey-staging:vasey-staging-default ]; then
        echo invalid;
      else echo 123; fi ;;
    *) die 'unexpected worker mutation' ;;
  esac
}
readlink() {
  if [ "$2" = /proc/123/cwd ]; then
    if [ "$MODE" = wrong-cwd ]; then echo "$RELEASES/$(printf 'b%.0s' {1..40})";
    else echo "$SERVED"; fi
  else command readlink "$@"; fi
}
probe() { if [ "$MODE" = bad-http ]; then echo 503; else echo 200; fi; }
"""
            modes = ("healthy", "unsealed", "closed-gate", "malformed-gate", "maintenance-file",
                     "dangling-maintenance", "down-file", "dangling-down", "unmounted", "wrong-source",
                     "missing-fstab", "inactive-fpm", "stopped-worker", "invalid-pid", "wrong-cwd", "bad-http",
                     "wrong-sha", "invalid-sha")
            maintenance = release / "storage/framework/maintenance.php"
            down = release / "storage/framework/down"
            retained = (release / ".env").read_bytes()
            for mode in modes:
                with self.subTest(mode=mode):
                    maintenance.unlink(missing_ok=True)
                    down.unlink(missing_ok=True)
                    gate.write_text("closed\n" if mode == "closed-gate" else "open\nextra\n" if mode == "malformed-gate" else "open\n")
                    fstab.write_text("# synthetic absent bind entry\n" if mode == "missing-fstab" else entry)
                    if mode in ("maintenance-file", "down-file"):
                        (maintenance if mode == "maintenance-file" else down).write_text("synthetic marker")
                    elif mode in ("dangling-maintenance", "dangling-down"):
                        (maintenance if mode == "dangling-maintenance" else down).symlink_to(root / "absent")
                    requested = "b" * 40 if mode == "wrong-sha" else "invalid" if mode == "invalid-sha" else sha
                    before = (gate.read_bytes(), fstab.read_bytes(), (release / ".env").stat().st_ino)
                    run = subprocess.run(
                        ["bash", "-eu", "-c", shell + programs + "\n" + functions.replace("/etc/fstab", str(fstab)) + '\ncmd_healthy "$SHA"'],
                        capture_output=True, text=True,
                        env={"PATH": "/usr/bin:/bin", "MODE": mode, "SHA": requested,
                             "CURRENT": str(current), "RELEASES": str(root / "releases"),
                             "SERVED": str(release), "PRIVATE": str(root / "private"),
                             "WRITER_GATE": str(gate), "VASEY_PHP_FPM_SERVICE": "synthetic-fpm"},
                    )
                    self.assertEqual(run.returncode == 0, mode == "healthy", run.stderr)
                    self.assertEqual((gate.read_bytes(), fstab.read_bytes(), (release / ".env").stat().st_ino), before)
                    self.assertEqual((release / ".env").read_bytes(), retained)

    def test_same_sha_equal_environment_requires_health_without_repair(self):
        script = source("ops/staging/forge-deploy.sh")
        body = script[script.index('if [ -e "$REL" ]; then'):script.index('FIRST_INSTALL=1')]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            sha = "a" * 40
            release = root / sha
            release.mkdir()
            candidate = root / "frozen.env"
            candidate.write_text("synthetic identical environment\n")
            (release / ".env").write_bytes(candidate.read_bytes())
            (root / "current").symlink_to(release)
            shell = """step() { :; }
die() { echo "$*" >&2; exit 1; }
ctl_fixture() {
  echo "$*" >> "$TRACE"
  [ "$1" = healthy ] && [ "$2" = "$SHA" ] && [ "$HEALTHY" = 1 ]
}
CTL=(ctl_fixture)
"""
            for healthy in ("0", "1"):
                with self.subTest(healthy=healthy):
                    trace = root / "trace"
                    trace.unlink(missing_ok=True)
                    before = (release / ".env").read_bytes()
                    run = subprocess.run(
                        ["bash", "-eu", "-c", shell + body], capture_output=True, text=True,
                        env={"PATH": "/usr/bin:/bin", "REL": str(release), "SHA": sha,
                             "CURRENT": str(root / "current"), "RUNTIME_ENV": str(candidate),
                             "HEALTHY": healthy, "TRACE": str(trace)},
                    )
                    self.assertEqual(run.returncode == 0, healthy == "1", run.stderr)
                    self.assertEqual(trace.read_text().splitlines() if trace.exists() else [], ["healthy " + sha])
                    self.assertEqual((release / ".env").read_bytes(), before)

    def test_initial_and_existing_deploy_require_snapshot_before_migration(self):
        script = source("ops/staging/forge-deploy.sh")
        body = script[script.index('# ---------------------------------------------------------------- 5-6.'):script.index('# ---------------------------------------------------------------- 7.')]
        shell = """step() { :; }
ctl_fixture() { echo "$1"; if [ "$1" = snapshot ] && [ "$SNAPSHOT_REFUSED" = 1 ]; then return 64; fi; }
CTL=(ctl_fixture)
"""
        for first_install in ("0", "1"):
            for refused in ("0", "1"):
                with self.subTest(first_install=first_install, snapshot_refused=refused):
                    run = subprocess.run(
                        ["bash", "-eu", "-c", shell + body + '\nprintf "migration-boundary-reached\\n"'],
                        capture_output=True, text=True,
                        env={"PATH": "/usr/bin:/bin", "FIRST_INSTALL": first_install, "SNAPSHOT_REFUSED": refused},
                    )
                    self.assertEqual(run.returncode, 64 if refused == "1" else 0, run.stderr)
                    self.assertEqual(run.stdout.splitlines(), ["snapshot"] if refused == "1" else ["snapshot", "migration-boundary-reached"])

    def test_interrupted_mounted_attach_persists_exact_entry_or_refuses(self):
        script = source("ops/staging/bin/vasey-staging-ctl")
        start = script.index("persist_private_bind()") if "persist_private_bind()" in script else script.index("cmd_attach()")
        block = script[start:script.index("cmd_detach()")]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            sha = "a" * 40
            private = root / "private"
            private.mkdir(mode=0o700)
            target = root / "releases" / sha / "storage/app/private"
            target.mkdir(parents=True, mode=0o700)
            fstab = root / "fstab"
            unrelated = "# synthetic unrelated host entry\n/dev/synthetic /unrelated ext4 defaults 0 0\n"
            fstab.write_text(unrelated)
            expected = f"{private} {target} none bind 0 0"
            body = block.replace("/etc/fstab", str(fstab))
            shell = """die() { echo "$*" >&2; exit 1; }
valid_sha() { [[ "$1" =~ ^[0-9a-f]{40}$ ]]; }
seal_release() { :; }
mountpoint() { return 0; }
mount() { echo unexpected-mount >&2; return 71; }
stat() {
  if [ "$2" = '%U %a' ]; then echo 'fixture-app 700';
  elif [ "$3" = "$PRIVATE" ] || [ "$DIFFERENT_SOURCE" = 0 ]; then echo 1:1;
  else echo 2:2; fi
}
"""
            environment = {"PATH": "/usr/bin:/bin", "PRIVATE": str(private),
                           "RELEASES": str(root / "releases"), "VASEY_APP_USER": "fixture-app",
                           "SHA": sha, "DIFFERENT_SOURCE": "0"}

            def probe():
                return subprocess.run(["bash", "-eu", "-c", shell + body + '\ncmd_attach "$SHA"'],
                                      capture_output=True, text=True, env=environment)

            for attempt in range(2):
                with self.subTest(retry=attempt):
                    run = probe()
                    self.assertEqual(run.returncode, 0, run.stderr)
                    self.assertEqual(fstab.read_text().splitlines().count(expected), 1)
                    self.assertTrue(fstab.read_text().startswith(unrelated))
            before = fstab.read_bytes()
            environment["DIFFERENT_SOURCE"] = "1"
            refused = probe()
            self.assertNotEqual(refused.returncode, 0)
            self.assertEqual(fstab.read_bytes(), before)
            environment["DIFFERENT_SOURCE"] = "0"
            fstab.write_text(unrelated)
            fstab.chmod(0o400)
            try:
                refused = probe()
                self.assertNotEqual(refused.returncode, 0, "mounted attach reported success despite persistence failure")
                self.assertEqual(fstab.read_text(), unrelated)
            finally:
                fstab.chmod(0o600)


if __name__ == "__main__":
    print(f"Exact assessed source: {SOURCE_SHA}", flush=True)
    unittest.main(argv=[__file__] + TEST_ARGUMENTS, verbosity=2)
