#!/usr/bin/env python3
"""Independent repaired-boundary tests. No real privileged service/mount calls."""
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[4]
CTL = (REPO / "ops/staging/bin/vasey-staging-ctl").read_text()
DEPLOY = (REPO / "ops/staging/forge-deploy.sh").read_text()
SPEC = importlib.util.spec_from_file_location("original_independent_probes", Path(__file__).with_name("review_probes.py"))
ORIGINAL = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ORIGINAL)
end = CTL.index('\ncase "${1:-}" in\n  allocate|')
if "\n# The sudo entry point is independently callable" in CTL:
    # These function-level policy probes use authority substitutes defined below. The separate
    # concurrency/lock drivers execute the full trusted bootstrap and genuine action lock.
    end = CTL.index("\n# The sudo entry point is independently callable")
FUNCTIONS = CTL[CTL.index("valid_sha()"):end]
SHA = "a" * 40

AUTHORITY = r'''
die() { printf '%s\n' "$*" >&2; exit 1; }
stat() {
  if [ "${1:-}" = -c ]; then
    [ "${2:-}" != %u ] || { echo 0; return; }
    [ "${2:-}:${!#}" != %a:/tmp ] || { echo 755; return; }
    if [ "${2:-}" = %d:%i ] && [ "${!#}" = "$REVIEW_TARGET" ] && [ "${REVIEW_ATTACHED:-0}" = 1 ]; then
      command stat -c %d:%i "$PRIVATE"; return
    fi
  fi
  command stat "$@"
}
pgrep() { return 1; }
supervisorctl() { printf 'synthetic %s\n' "${REVIEW_WORKERS:-STOPPED}"; }
systemctl() { [ "${REVIEW_WEB:-stopped}" = active ]; }
umount() { printf 'unexpected umount\n' >> "$TRACE"; return 73; }
'''


def execute(source, environment):
    return subprocess.run(["bash", "-eu", "-c", source], env={"PATH": "/usr/bin:/bin"} | environment,
                          text=True, capture_output=True, timeout=15)


class RepairedBoundaries(unittest.TestCase):
    def helper_fixture(self, directory):
        root = Path(directory)
        rel = root / "releases" / SHA
        target = rel / "storage/app/private"
        target.mkdir(parents=True)
        (rel / "storage/framework").mkdir()
        (root / "private").mkdir(mode=0o700)
        env = {"ROOT": str(root), "RELEASES": str(root / "releases"), "CURRENT": str(root / "current"),
               "PRIVATE": str(root / "private"), "TRACE": str(root / "trace"), "SHA": SHA,
               "REVIEW_TARGET": str(target), "VASEY_PHP_FPM_SERVICE": "synthetic-only", "VASEY_APP_USER": "synthetic-only"}
        return root, rel, target, env

    def test_detach_rejects_escaped_leaf_after_canonical_ancestor_checks(self):
        with tempfile.TemporaryDirectory() as directory:
            root, rel, target, env = self.helper_fixture(directory)
            target.rmdir()
            target.symlink_to("/proc")
            run = execute(FUNCTIONS + AUTHORITY + '\ncmd_detach "$SHA"', env)
            self.assertNotEqual(run.returncode, 0)
            self.assertIn("private mount target", run.stderr)
            self.assertFalse((root / "trace").exists())

    def test_detach_rejects_an_unrelated_mount_before_unmount(self):
        with tempfile.TemporaryDirectory() as directory:
            root, rel, target, env = self.helper_fixture(directory)
            run = execute(FUNCTIONS + AUTHORITY + '\nmountpoint() { return 0; }\ncmd_detach "$SHA"', env)
            self.assertNotEqual(run.returncode, 0)
            self.assertIn("unrelated mount", run.stderr)
            self.assertFalse((root / "trace").exists())

    def test_application_writable_privileged_ancestry_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root, rel, target, env = self.helper_fixture(directory)
            (root / "releases").chmod(0o777)
            run = execute(FUNCTIONS + AUTHORITY + '\nsealed_release "$SHA"', env)
            self.assertNotEqual(run.returncode, 0)
            self.assertIn("group/world writable", run.stderr)

    def test_invalid_current_is_not_treated_as_first_install(self):
        for shape in ("regular", "outside", "dangling"):
            with self.subTest(shape=shape), tempfile.TemporaryDirectory() as directory:
                root, rel, target, env = self.helper_fixture(directory)
                if shape == "regular":
                    (root / "current").write_text("invalid current")
                elif shape == "outside":
                    outside = root / SHA
                    outside.mkdir()
                    (root / "current").symlink_to(outside)
                else:
                    (root / "current").symlink_to(root / "releases" / ("b" * 40))
                run = execute(FUNCTIONS + AUTHORITY + '\nsupervisorctl() { printf service-call >> "$TRACE"; }\ncmd_quiesce', env)
                self.assertNotEqual(run.returncode, 0)
                self.assertIn("refusing a first-install fallback", run.stderr)
                self.assertFalse((root / "trace").exists())

    def test_switch_checks_quiescence_maintenance_and_attachment_before_selection(self):
        for failure in ("workers", "web", "maintenance", "attachment", None):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as directory:
                root, rel, target, env = self.helper_fixture(directory)
                if failure != "maintenance":
                    (rel / "storage/framework/maintenance.php").touch()
                env["REVIEW_ATTACHED"] = "0" if failure == "attachment" else "1"
                env["REVIEW_WORKERS"] = "RUNNING" if failure == "workers" else "STOPPED"
                env["REVIEW_WEB"] = "active" if failure == "web" else "stopped"
                run = execute('PROGRAMS=(synthetic-only)\n' + FUNCTIONS + AUTHORITY + '\ncmd_switch "$SHA"', env)
                if failure is None:
                    self.assertEqual(run.returncode, 0, run.stderr)
                    self.assertEqual((root / "current").resolve(), rel)
                else:
                    self.assertNotEqual(run.returncode, 0)
                    self.assertFalse((root / "current").exists())

    def test_quoted_production_flag_is_refused(self):
        ORIGINAL.IndependentStagingOpsProbes().test_validator_refuses_effective_quoted_production_flag()

    def test_failed_reproof_removes_old_success_marker(self):
        ORIGINAL.IndependentStagingOpsProbes().test_failed_reproof_invalidates_previous_success_marker()

    def test_failed_activation_phase_does_not_claim_stopped_writers(self):
        handler = DEPLOY[DEPLOY.index("on_exit()"):DEPLOY.index("trap on_exit EXIT")]
        run = execute("ENV_SNAPSHOT=''\n" + handler + "\nQUIESCED=1\ntrap on_exit EXIT\nexit 73", {})
        self.assertEqual(run.returncode, 73)
        self.assertIn("service and writer state is unconfirmed", run.stderr)
        self.assertNotIn("writers are stopped", run.stderr)

    def test_refresh_preserves_key_saves_old_env_and_stops_on_control_failure(self):
        branch = DEPLOY[DEPLOY.index('if [ -e "$REL" ]; then'):DEPLOY.index('install -d -m 0750 "$ROOT/evidence/')]
        gate = DEPLOY[DEPLOY.index("runtime_gate()"):DEPLOY.index("\nart()")]
        envget = DEPLOY[DEPLOY.index("envget()"):DEPLOY.index("\nneed()")]
        for failure in (None, "quiesce", "snapshot", "cache", "key"):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                release = root / SHA
                (release / "ops/staging").mkdir(parents=True)
                (release / "ops/staging/validate-runtime.php").symlink_to(REPO / "ops/staging/validate-runtime.php")
                previous = ORIGINAL.valid_environment("SESSION_HTTP_ONLY=true\nCONTACT_FROM_NAME=old")
                candidate = previous.replace("NAME=old", "NAME=new")
                if failure == "key":
                    candidate = candidate.replace("A" * 43, "B" * 43)
                (release / ".env").write_text(previous)
                candidate_path = root / "candidate.env"
                candidate_path.write_text(candidate)
                (root / "current").symlink_to(release)
                shell = r'''
step() { :; }
die() { printf '%s\n' "$*" >&2; exit 1; }
ctl_stub() {
  printf '%s\n' "$1" >> "$TRACE"
  [ "$1" != "$FAIL" ] || return 73
  [ "$1" != snapshot ] || cp "$REL/.env" "$ROOT/env.backup"
}
art() { printf 'cache\n' >> "$TRACE"; [ "$FAIL" != cache ]; }
CTL=(ctl_stub)
'''
                env = {"ROOT": str(root), "REL": str(release), "CURRENT": str(root / "current"), "RUNTIME_ENV": str(candidate_path),
                       "SHA": SHA, "TRACE": str(root / "trace"), "FAIL": failure or "none", "QUIESCED": "0",
                       "PHP": ORIGINAL.PHP, "VASEY_EXPECTED_APP_ENV": "local", "VASEY_STAGING_HOST": "staging.example.invalid",
                       "VASEY_DB_NAME": "vasey_staging"}
                run = execute(shell + gate + envget + "\n" + branch, env)
                self.assertNotIn("synthetic-review-only", run.stdout + run.stderr)
                trace = (root / "trace").read_text().splitlines() if (root / "trace").exists() else []
                if failure is None:
                    self.assertEqual(run.returncode, 0, run.stderr)
                    self.assertEqual(trace, ["quiesce", "snapshot", "cache", "resume"])
                    self.assertEqual((root / "env.backup").read_text(), previous)
                    self.assertEqual((release / ".env").read_text(), candidate)
                else:
                    self.assertNotEqual(run.returncode, 0)
                    self.assertNotIn("resume", trace)
                    if failure == "cache":
                        self.assertEqual((root / "env.backup").read_text(), previous)
                    else:
                        self.assertEqual((release / ".env").read_text(), previous)
                    if failure == "key":
                        self.assertEqual(trace, [])
                        self.assertIn("runtime.key_custody", run.stderr)


if __name__ == "__main__":
    unittest.main(verbosity=2)
