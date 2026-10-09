"""Actual provisioning/activation paths; database and host authority are bounded fixtures."""
from pathlib import Path
import subprocess
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[2]


class OperationCustodyTest(unittest.TestCase):
    def test_existing_database_identity_requires_private_complete_credential_custody(self):
        source = (REPO / "ops/staging/provision.sh").read_text()
        start = source.index("app_credential_custody()") if "app_credential_custody()" in source else source.index("if ! user_exists vasey_app;")
        body = source[start:source.index("# Backup-only account")]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            credential = root / "db-app.env"
            trace = root / "trace"
            shell = '''die() { echo "$*" >&2; exit 1; }
user_exists() { return 0; }
mysql_q() { echo grant >> "$TRACE"; }
info() { :; }
stat() {
  if [ "$2" = '%u %a %h' ]; then printf '0 %s %s\\n' "$(command stat -c %a "$3")" "$(command stat -c %h "$3")";
  else command stat "$@"; fi
}
'''
            for case in ("missing", "empty", "wrong-user", "extra-line", "writable", "symlink", "hardlink", "fifo", "nul", "oversized", "missing-newline", "valid"):
                with self.subTest(case=case):
                    credential.unlink(missing_ok=True)
                    trace.unlink(missing_ok=True)
                    other = root / "other"
                    other.unlink(missing_ok=True)
                    if case != "missing":
                        content = "DB_USERNAME=vasey_app\nDB_PASSWORD=" + "A" * 40 + "\n"
                        if case == "empty": content = ""
                        if case == "wrong-user": content = content.replace("vasey_app", "someone_else")
                        if case == "extra-line": content += "UNEXPECTED=1\n"
                        if case == "nul": content = content.replace("DB_USERNAME", "DB_USER\0NAME")
                        if case == "oversized": content += "A" * 300
                        if case == "missing-newline": content = content.rstrip()
                        credential.write_text(content)
                        credential.chmod(0o666 if case == "writable" else 0o600)
                        if case == "symlink":
                            credential.rename(other)
                            credential.symlink_to(other)
                        if case == "hardlink":
                            other.hardlink_to(credential)
                        if case == "fifo":
                            credential.unlink()
                            import os
                            os.mkfifo(credential, 0o600)
                    run = subprocess.run(["bash", "-eu", "-c", shell + body], capture_output=True, text=True,
                                         env={"PATH": "/usr/bin:/bin", "SECRETS": str(root), "DB_NAME": "fixture_database", "TRACE": str(trace)}, timeout=3)
                    self.assertEqual(run.returncode == 0, case == "valid", run.stdout + run.stderr)
                    self.assertEqual(trace.exists(), case == "valid", "database grant occurred without credential custody")
                    self.assertNotIn("A" * 40, run.stdout + run.stderr)

    def test_forge_uses_one_privileged_activation_instead_of_releasing_before_migration(self):
        source = (REPO / "ops/staging/forge-deploy.sh").read_text()
        body = source[source.index("# ---------------------------------------------------------------- 5-6."):source.index('step "pruning old releases"')]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            shell = '''step() { :; }
die() { echo "$*" >&2; exit 1; }
ctl_fixture() {
  if [ "$1" = snapshot ]; then echo open > "$GATE"; fi
  if [ "$1" = activate ]; then echo atomic-activation >> "$TRACE"; fi
}
art() {
  if [ "$1" = migrate ] && [ "${2:-}" = --force ] && [ "$(cat "$GATE")" = open ]; then
    echo migration-raced-resume >&2; exit 73
  fi
}
CTL=(ctl_fixture)
'''
            gate = root / "gate"
            gate.write_text("closed\n")
            (root / "current").symlink_to(root)
            run = subprocess.run(["bash", "-eu", "-c", shell + body], capture_output=True, text=True,
                                 env={"PATH": "/usr/bin:/bin", "GATE": str(gate), "TRACE": str(root / "trace"),
                                      "SHA": "a" * 40, "EVIDENCE": str(root), "REL": str(root), "CURRENT": str(root / "current"), "FIRST_INSTALL": "0"})
            self.assertEqual(run.returncode, 0, run.stderr)
            self.assertEqual((root / "trace").read_text().strip(), "atomic-activation")


if __name__ == "__main__":
    unittest.main(verbosity=2)
