"""Actual provisioning credential block; root/database authority are bounded fixtures."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[2]


class BackupCredentialCustodyTest(unittest.TestCase):
    def probe(self, root, exists=True, auth=True):
        source = (REPO / "ops/staging/provision.sh").read_text()
        body = source[source.index("# Backup-only account"):source.index("# Binary logging stays on")]
        body = body.replace("BACKUP_CNF=/etc/vasey-staging/backup.my.cnf", 'BACKUP_CNF=${BACKUP_CNF:?}')
        shell = '''die() { echo "$*" >&2; exit 1; }
user_exists() { [ "$EXISTS" = 1 ]; }
new_secret() { printf '%s' AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA; }
mysql_q() { echo "$1" >> "$TRACE"; }
mysql() { echo auth >> "$TRACE"; [ "$AUTH" = 1 ] || return 1; printf 'vasey_backup@127.0.0.1\\n'; }
env() { while [[ "${1:-}" == -* || "${1:-}" == *=* ]]; do shift; done; "$@"; }
chown() { :; }
stat() {
  if [ "$2" = '%u %a %h' ]; then printf '0 %s %s\\n' "$(command stat -c %a "$3")" "$(command stat -c %h "$3")";
  else command stat "$@"; fi
}
'''
        return subprocess.run(["bash", "-eu", "-c", shell + body], capture_output=True, text=True, timeout=2,
                              env={"PATH": "/usr/bin:/bin", "BACKUP_CNF": str(root / "backup.my.cnf"),
                                   "TRACE": str(root / "trace"), "DB_NAME": "fixture_database",
                                   "EXISTS": "1" if exists else "0", "AUTH": "1" if auth else "0"})

    def profile(self):
        return "[client]\nhost=127.0.0.1\nport=3306\nuser=vasey_backup\npassword=" + "A" * 40 + "\n"

    def test_existing_backup_account_refuses_incomplete_or_unsafe_profile_before_grants(self):
        for case in ("missing", "empty", "truncated", "wrong-user", "extra-line", "nul", "missing-newline",
                     "writable", "symlink", "hardlink", "fifo", "oversized", "valid"):
            with self.subTest(case=case), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                credential = root / "backup.my.cnf"
                if case != "missing":
                    content = self.profile()
                    if case == "empty": content = ""
                    if case == "truncated": content = content.replace("A" * 40, "A")
                    if case == "wrong-user": content = content.replace("vasey_backup", "somebody_else")
                    if case == "extra-line": content += "socket=/tmp/other.sock\n"
                    if case == "nul": content = content.replace("password", "pass\0word")
                    if case == "missing-newline": content = content.rstrip()
                    if case == "oversized": content += "A" * 300
                    credential.write_text(content)
                    credential.chmod(0o666 if case == "writable" else 0o600)
                    if case == "symlink":
                        other = root / "other"
                        credential.rename(other)
                        credential.symlink_to(other)
                    if case == "hardlink": (root / "other").hardlink_to(credential)
                    if case == "fifo":
                        credential.unlink()
                        os.mkfifo(credential, 0o600)
                run = self.probe(root)
                self.assertEqual(run.returncode == 0, case == "valid", run.stdout + run.stderr)
                trace = root / "trace"
                self.assertEqual(trace.exists(), case == "valid", "grant/auth crossed unsafe credential admission")
                if case == "valid": self.assertTrue(trace.read_text().startswith("auth\n"), "grant occurred before authentication")
                self.assertNotIn("A" * 40, run.stdout + run.stderr)

    def test_valid_looking_but_non_authenticating_profile_refuses_before_grants(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            credential = root / "backup.my.cnf"
            credential.write_text(self.profile())
            credential.chmod(0o600)
            run = self.probe(root, auth=False)
            self.assertNotEqual(run.returncode, 0, "provisioning succeeded with non-authenticating backup credential")
            self.assertEqual((root / "trace").read_text(), "auth\n")
            self.assertNotIn("A" * 40, run.stdout + run.stderr)

    def test_missing_database_account_never_overwrites_orphan_backup_profile(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            credential = root / "backup.my.cnf"
            credential.write_text("orphan credential requiring explicit recovery\n")
            credential.chmod(0o600)
            before = credential.read_bytes()
            run = self.probe(root, exists=False)
            self.assertNotEqual(run.returncode, 0, "orphan credential silently replaced")
            self.assertEqual(credential.read_bytes(), before)
            self.assertFalse((root / "trace").exists(), "account mutation crossed orphan custody refusal")

    def test_new_backup_account_persists_private_profile_before_authentication(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            run = self.probe(root, exists=False)
            self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
            credential = root / "backup.my.cnf"
            self.assertEqual(credential.read_text(), self.profile())
            self.assertEqual(credential.stat().st_mode & 0o777, 0o600)
            trace = (root / "trace").read_text().splitlines()
            self.assertLess(trace.index("auth"), next(i for i, line in enumerate(trace) if line.startswith("GRANT")))


if __name__ == "__main__":
    unittest.main(verbosity=2)
