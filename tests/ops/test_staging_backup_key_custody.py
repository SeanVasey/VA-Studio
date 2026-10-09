"""Actual snapshot/admission functions with harmless dump fixtures; no database or host privilege."""
import os
import grp
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[2]
BACKUP = REPO / "ops/staging/backup.sh"
PHP = os.environ.get("PHP_BIN") or shutil.which("php8.4") or shutil.which("php")


class BackupKeyCustodyTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.addCleanup(self.scratch.cleanup)
        self.root = Path(self.scratch.name)
        self.release = self.root / "releases" / ("a" * 40)
        self.release.mkdir(parents=True)
        (self.root / "private").mkdir(mode=0o700)
        (self.root / "backups").mkdir(mode=0o700)
        self.source = BACKUP.read_text()
        self.definitions = self.source[self.source.index("mycnf_ok()"):self.source.index('RC_WORK=""')]
        self.environment = {"PATH": "/usr/bin:/bin", "VASEY_ROOT": str(self.root),
                            "VASEY_BACKUP_DIR": str(self.root / "backups"),
                            "PRIVATE": str(self.root / "private"), "VASEY_DB_NAME": "synthetic",
                            "VASEY_BACKUP_MYCNF": str(self.root / "fake-my.cnf"),
                            "VASEY_PHP": PHP, "VASEY_APP_GROUP": grp.getgrgid(os.getgid()).gr_name}
        self.shell = """set -eu
umask 077
die() { echo "$*" >&2; exit 1; }
log() { echo "$*"; }
mysqldump() { echo synthetic-dump; }
"""

    def snapshot(self):
        return subprocess.run(["bash", "-c", self.shell + self.definitions +
                               "\nmycnf_ok() { return 0; }\nsnapshot"],
                              env=self.environment, capture_output=True, text=True)

    def test_served_release_missing_env_refuses_before_publishing_any_snapshot(self):
        (self.root / "current").symlink_to(self.release)
        run = self.snapshot()
        self.assertNotEqual(run.returncode, 0, run.stdout)
        self.assertFalse((self.root / "backups/.last").exists())
        self.assertFalse(list((self.root / "backups").glob("*/MANIFEST")))

    def test_dangling_outside_and_non_symlink_current_cannot_become_first_install(self):
        current = self.root / "current"
        for target in (self.root / "missing", self.root / "private"):
            current.symlink_to(target)
            run = self.snapshot()
            self.assertNotEqual(run.returncode, 0, run.stdout)
            current.unlink()
        current.mkdir()
        self.assertNotEqual(self.snapshot().returncode, 0)

    def test_genuine_no_current_snapshot_records_none(self):
        run = self.snapshot()
        self.assertEqual(run.returncode, 0, run.stderr)
        directory = Path((self.root / "backups/.last").read_text().strip())
        self.assertIn("release_sha=none\n", (directory / "MANIFEST").read_text())

    def restore_admission(self, manifest=None, env=None, hashed=False, hash_contents=None):
        backup = self.root / "candidate"
        backup.mkdir(exist_ok=True)
        for name in ("database.sql", "private.tar"):
            (backup / name).write_bytes(b"synthetic")
            result = subprocess.run(["sha256sum", name], cwd=backup, capture_output=True, text=True, check=True)
            (backup / (name + ".sha256")).write_text(result.stdout)
        (backup / "RESTORE_CHECK").write_text("stale success")
        if manifest is not None:
            (backup / "MANIFEST").write_text(manifest)
        if env is not None:
            (backup / "env.backup").write_text(env)
            if hashed:
                result = subprocess.run(["sha256sum", "env.backup"], cwd=backup, capture_output=True, text=True, check=True)
                (backup / "env.backup.sha256").write_text(result.stdout)
        if hash_contents is not None:
            (backup / "env.backup.sha256").write_text(hash_contents)
        prefix = self.source[self.source.index("restore_check()"):self.source.index('  install -d -m 0711')]
        run = subprocess.run(["bash", "-c", self.shell + self.definitions + prefix +
                              '\n}\nrestore_check "$CANDIDATE"\necho ACCEPTED'],
                             env={**self.environment, "CANDIDATE": str(backup)}, capture_output=True, text=True)
        self.assertFalse((backup / "RESTORE_CHECK").exists())
        return run

    def test_served_backup_requires_env_and_its_hash(self):
        for env, hashed in ((None, False), ("APP_KEY=base64:" + "A" * 43 + "=\n", False)):
            with self.subTest(env_present=env is not None):
                run = self.restore_admission("release_sha=" + "a" * 40 + "\n", env, hashed)
                self.assertNotEqual(run.returncode, 0, run.stdout)

    def test_absent_or_ambiguous_manifest_refuses(self):
        for manifest in (None, "release_sha=bogus\n", "release_sha=none\nrelease_sha=none\n"):
            run = self.restore_admission(manifest)
            self.assertNotEqual(run.returncode, 0, run.stdout)
            (self.root / "candidate/MANIFEST").unlink(missing_ok=True)

    def test_genuine_first_install_admission_is_explicit(self):
        run = self.restore_admission("release_sha=none\n")
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("ACCEPTED", run.stdout)

    def test_served_restore_requires_the_exact_environment_hash_record(self):
        key = "APP_KEY=base64:" + "A" * 43 + "=\n"
        valid = self.restore_admission("release_sha=" + "a" * 40 + "\n", key, True)
        self.assertEqual(valid.returncode, 0, valid.stderr)
        backup = self.root / "candidate"
        wrong = (backup / "database.sql.sha256").read_text()
        for manifest in (wrong, (backup / "env.backup.sha256").read_text() + wrong):
            with self.subTest(manifest=manifest):
                run = self.restore_admission("release_sha=" + "a" * 40 + "\n", key, True, manifest)
                self.assertNotEqual(run.returncode, 0, run.stdout)

    def test_key_looking_lines_inside_multiline_values_are_not_admitted(self):
        key = "base64:" + "A" * 43 + "="
        path = self.root / "multiline.env"
        for quote in ('"', "'"):
            path.write_text("CONTACT_FROM_NAME=" + quote + "multiline\nAPP_KEY=" + key + "\n" + quote + "\n")
            run = subprocess.run(["bash", "-c", self.shell + self.definitions + '\nvalid_env_key "$KEY_ENV"'],
                                 env={**self.environment, "KEY_ENV": str(path)}, capture_output=True, text=True)
            self.assertNotEqual(run.returncode, 0, run.stdout)

    def test_valid_literal_quoted_keys_are_accepted_but_duplicate_empty_or_wrong_length_keys_refuse(self):
        key = "base64:" + "A" * 43 + "="
        for value in (key, '"' + key + '"', "'" + key + "' # synthetic comment"):
            path = self.root / "key.env"
            path.write_text("APP_KEY=" + value + "\n")
            run = subprocess.run(["bash", "-c", self.shell + self.definitions + '\nvalid_env_key "$KEY_ENV"'],
                                 env={**self.environment, "KEY_ENV": str(path)}, capture_output=True, text=True)
            self.assertEqual(run.returncode, 0, run.stderr)
        for body in ("APP_KEY=\n", "APP_KEY=base64:AAAA\n", "APP_KEY=" + key + "\nAPP_KEY=" + key + "\n",
                     "APP_KEY=" + key + "\0\n"):
            path.write_text(body)
            run = subprocess.run(["bash", "-c", self.shell + self.definitions + '\nvalid_env_key "$KEY_ENV"'],
                                 env={**self.environment, "KEY_ENV": str(path)}, capture_output=True, text=True)
            self.assertNotEqual(run.returncode, 0)
            self.assertEqual(run.stdout, "")


if __name__ == "__main__":
    unittest.main(verbosity=2)
