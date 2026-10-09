"""Same-release refresh must install the exact file admitted before stopping services."""
from pathlib import Path
import subprocess
import tempfile
import unittest

DEPLOY = Path(__file__).resolve().parents[2] / "ops/staging/forge-deploy.sh"


class EnvironmentRefreshTest(unittest.TestCase):
    def test_same_sha_unchanged_environment_requires_a_successful_health_proof(self):
        source = DEPLOY.read_text()
        branch = source[source.index('if [ -e "$REL" ]; then'):source.index('FIRST_INSTALL=1')]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            release = root / ("a" * 40)
            release.mkdir()
            (release / ".env").write_text("synthetic unchanged environment")
            (root / "current").symlink_to(release)
            shell = '''step() { :; }
die() { echo "$*" >&2; exit 1; }
ctl_fixture() { echo "$1" >> "$TRACE"; [ "$1" = healthy ] && [ "$HEALTHY" = 1 ]; }
CTL=(ctl_fixture)
'''
            for healthy in ("0", "1"):
                trace = root / "trace"
                trace.unlink(missing_ok=True)
                run = subprocess.run(["bash", "-eu", "-c", shell + branch], capture_output=True, text=True,
                                     env={"PATH": "/usr/bin:/bin", "REL": str(release), "CURRENT": str(root / "current"),
                                          "SHA": "a" * 40, "RUNTIME_ENV": str(release / ".env"),
                                          "TRACE": str(trace), "HEALTHY": healthy})
                with self.subTest(healthy=healthy):
                    self.assertEqual(run.returncode == 0, healthy == "1", run.stdout + run.stderr)
                    self.assertEqual(trace.read_text().strip() if trace.exists() else "", "healthy")

    def test_forge_edit_after_admission_does_not_replace_validated_candidate(self):
        source = DEPLOY.read_text()
        branch = source[source.index('if [ -e "$REL" ]; then'):source.index('install -d -m 0750 "$ROOT/evidence/')]
        freeze = ""
        if 'freeze_runtime_environment()' in source:
            freeze = source[source.index('freeze_runtime_environment()'):source.index('\nexec 9>>')]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "evidence").mkdir()
            release = root / ("a" * 40)
            release.mkdir()
            previous = "APP_KEY=base64:" + "A" * 43 + "=\nAPP_DEBUG=false\nCONTACT_FROM_NAME=old\n"
            candidate = previous.replace("NAME=old", "NAME=new")
            mirror = root / "mirror.env"
            mirror.write_text(candidate)
            (release / ".env").write_text(previous)
            (root / "current").symlink_to(release)
            shell = '''step() { :; }
die() { printf '%s\\n' "$*" >&2; exit 1; }
envget() { sed -n "s/^$1=//p" "$RUNTIME_ENV"; }
runtime_gate() { cmp -s "$2" "$EXPECTED" || exit 97; }
art() { :; }
ctl_stub() {
  printf '%s\\n' "$1" >> "$TRACE"
  if [ "$1" = snapshot ]; then printf 'APP_DEBUG=true\\n' >> "$MIRROR_ENV"; fi
  if [ "$1" = configure ]; then cp -- "$3" "$REL/.env"; fi
}
CTL=(ctl_stub)
'''
            expected = root / "expected.env"
            expected.write_text(candidate)
            environment = {"PATH": "/usr/bin:/bin", "ROOT": str(root), "REL": str(release),
                           "CURRENT": str(root / "current"), "RUNTIME_ENV": str(mirror),
                           "MIRROR_ENV": str(mirror), "EXPECTED": str(expected),
                           "SHA": "a" * 40, "TRACE": str(root / "trace")}
            invocation = '\nfreeze_runtime_environment\n' if freeze else ""
            run = subprocess.run(["bash", "-eu", "-c", shell + freeze + invocation + branch],
                                 env=environment, capture_output=True, text=True)
            self.assertEqual(run.returncode, 0, run.stderr)
            self.assertEqual((release / ".env").read_text(), candidate,
                             "refresh installed a later Forge edit instead of the profile it validated")
            self.assertEqual((root / "trace").read_text().splitlines(), ["quiesce", "snapshot", "configure", "resume"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
