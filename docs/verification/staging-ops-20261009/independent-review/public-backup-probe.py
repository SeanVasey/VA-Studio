"""Actual sealer and backup-provision block; owned I/O retained, root/client authority modeled."""
import argparse
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import types
import unittest


REPO = Path(__file__).resolve().parents[4]
PARSER = argparse.ArgumentParser()
PARSER.add_argument("--source-sha", required=True)
ARGUMENTS, TEST_ARGUMENTS = PARSER.parse_known_args()
SOURCE_SHA = ARGUMENTS.source_sha


def source(path):
    return subprocess.check_output(["git", "-C", str(REPO), "show", f"{SOURCE_SHA}:{path}"], text=True)


class PublicLinkProbe(unittest.TestCase):
    def release(self, root):
        release = root / "release"
        for path in ("app", "vendor", "public/build", "bootstrap/cache", "storage/framework", "storage/logs", "storage/app/private"):
            (release / path).mkdir(parents=True, exist_ok=True)
        for path in (".env", "app/source.php", "vendor/asset", "public/build/asset.js"):
            (release / path).write_text("owned synthetic content\n")
        module = types.ModuleType("assessed_public_sealer")
        exec(compile(source("ops/staging/seal-release.py"), "exact-assessed-sealer.py", "exec"), module.__dict__)
        module.ROOT_UID, module.ROOT_GID = os.getuid(), os.getgid()
        return release, module

    def test_environment_and_outside_public_aliases_refuse_during_seal_and_verify(self):
        cases = ("direct-env", "private-source", "private-directory", "env-chain", "internal-env", "public-root")
        for mode in ("seal", "verify"):
            for case in cases:
                with self.subTest(mode=mode, case=case), tempfile.TemporaryDirectory() as directory:
                    release, module = self.release(Path(directory))
                    if mode == "verify":
                        module.seal_release(release, os.getuid(), os.getgid())
                    if case == "public-root":
                        shutil.rmtree(release / "public")
                        (release / "public").symlink_to("app", target_is_directory=True)
                    else:
                        target = {"direct-env": "../.env", "private-source": "../app/source.php",
                                  "private-directory": "../app", "env-chain": "../app/relay", "internal-env": "../.env"}[case]
                        if case == "env-chain":
                            (release / "app/relay").symlink_to("../.env")
                        location = "app/friendly" if case == "internal-env" else "public/friendly"
                        (release / location).symlink_to(target)
                    with self.assertRaises((ValueError, OSError, RuntimeError)):
                        module.run(release, os.getuid(), os.getgid(), mode == "seal")

    def test_legal_public_asset_and_internal_code_aliases_remain_readable(self):
        with tempfile.TemporaryDirectory() as directory:
            release, module = self.release(Path(directory))
            (release / "public/asset-alias").symlink_to("build/asset.js")
            (release / "public/build-alias").symlink_to("build", target_is_directory=True)
            (release / "app/vendor-alias").symlink_to("../vendor/asset")
            module.seal_release(release, os.getuid(), os.getgid())
            module.run(release, os.getuid(), os.getgid(), False)
            for path in ("public/asset-alias", "public/build-alias/asset.js", "app/vendor-alias"):
                self.assertEqual((release / path).read_text(), "owned synthetic content\n")
            self.assertEqual((release / ".env").stat().st_mode & 0o777, 0o440)


class BackupCredentialProbe(unittest.TestCase):
    def profile(self):
        return b"[client]\nhost=127.0.0.1\nport=3306\nuser=vasey_backup\npassword=" + b"Q" * 40 + b"\n"

    def run_block(self, root, exists=True, auth="good", generation="good"):
        provision = source("ops/staging/provision.sh")
        body = provision[provision.index("# Backup-only account"):provision.index("# Binary logging stays on")]
        body = body.replace("BACKUP_CNF=/etc/vasey-staging/backup.my.cnf", 'BACKUP_CNF=${BACKUP_CNF:?}')
        shell = '''die() { echo "$*" >&2; exit 1; }
user_exists() { [ "$ACCOUNT_EXISTS" = 1 ]; }
new_secret() {
  if [ "$GENERATION" = bad ]; then echo invalid-short; else printf '%040d' 0 | tr 0 Q; fi
}
mysql_q() { echo database-mutation >> "$TRACE"; }
chown() { [ "${@: -1}" = "$BACKUP_CNF" ]; }
stat() {
  local path=${@: -1}
  if [ "$2" = '%u %a %h' ]; then printf '0 %s %s\\n' "$(command stat -c %a "$path")" "$(command stat -c %h "$path")";
  elif [ "$2" = '%u %a' ]; then printf '0 %s\\n' "$(command stat -c %a "$path")";
  else command stat "$@"; fi
}
env() {
  [ "$1" = -i ] || return 87
  shift
  local clean_path=0 clean_locale=0
  while [[ "${1:-}" == *=* ]]; do
    case "$1" in PATH=/usr/local/bin:/usr/bin:/bin) clean_path=1 ;; LC_ALL=C) clean_locale=1 ;; *) return 88 ;; esac
    shift
  done
  [ "$clean_path$clean_locale" = 11 ] || return 89
  [ "${1:-}" = mysql ] || return 90
  shift
  mysql "$@"
}
mysql() {
  local admitted_file=0 isolated_login=0 option
  for option in "$@"; do
    case "$option" in
      --defaults-file="$BACKUP_CNF") admitted_file=1 ;;
      --no-login-paths) isolated_login=1 ;;
      --password=*|-p?*) return 91 ;;
    esac
  done
  [ "$admitted_file$isolated_login" = 11 ] || return 92
  echo authentication >> "$TRACE"
  case "$AUTH" in
    fail) return 23 ;;
    wrong-user) echo root@localhost ;;
    wrong-host) echo vasey_backup@localhost ;;
    good) echo vasey_backup@127.0.0.1 ;;
  esac
}
'''
        return subprocess.run(["bash", "-euo", "pipefail", "-c", shell + body], capture_output=True, text=True, timeout=2,
                              env={"PATH": "/usr/bin:/bin", "BACKUP_CNF": str(root / "backup.my.cnf"),
                                   "TRACE": str(root / "trace"), "DB_NAME": "synthetic_database",
                                   "ACCOUNT_EXISTS": "1" if exists else "0", "AUTH": auth, "GENERATION": generation})

    def test_existing_identity_requires_exact_private_profile_without_repair(self):
        valid = self.profile()
        cases = {"valid": valid, "missing": None, "empty": b"", "truncated": valid[:-30],
                 "no-newline": valid.rstrip(), "crlf": valid.replace(b"\n", b"\r\n"),
                 "nul": valid.replace(b"user=", b"user=\0"), "wrong-port": valid.replace(b"3306", b"3307"),
                 "wrong-host": valid.replace(b"127.0.0.1", b"localhost"), "extra-client": valid + b"[client]\n",
                 "shell-looking": valid.replace(b"Q" * 40, b"$(touch injected)"), "oversized": valid + b"X" * 300,
                 "symlink": valid, "hardlink": valid, "writable": valid, "fifo": valid}
        for case, content in cases.items():
            with self.subTest(case=case), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                credential = root / "backup.my.cnf"
                if content is not None:
                    credential.write_bytes(content)
                    credential.chmod(0o666 if case == "writable" else 0o600)
                    if case == "symlink":
                        credential.rename(root / "retained")
                        credential.symlink_to(root / "retained")
                    if case == "hardlink":
                        (root / "retained").hardlink_to(credential)
                    if case == "fifo":
                        credential.unlink()
                        os.mkfifo(credential, 0o600)
                before_mode = credential.lstat().st_mode if content is not None else None
                run = self.run_block(root)
                self.assertEqual(run.returncode == 0, case == "valid", run.stdout + run.stderr)
                self.assertEqual((root / "trace").exists(), case == "valid")
                if case == "valid":
                    self.assertEqual((root / "trace").read_text().splitlines()[0], "authentication")
                if content is not None:
                    self.assertEqual(credential.lstat().st_mode, before_mode)
                    if case != "fifo":
                        self.assertEqual(credential.read_bytes(), content)
                self.assertNotIn("Q" * 40, run.stdout + run.stderr)
                self.assertFalse((REPO / "injected").exists())

    def test_bad_authentication_or_identity_never_reaches_grants(self):
        for auth in ("fail", "wrong-user", "wrong-host"):
            with self.subTest(auth=auth), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                credential = root / "backup.my.cnf"
                credential.write_bytes(self.profile())
                credential.chmod(0o600)
                run = self.run_block(root, auth=auth)
                self.assertNotEqual(run.returncode, 0, "authentication failure reached successful provisioning")
                self.assertEqual((root / "trace").read_text(), "authentication\n")
                self.assertEqual(credential.read_bytes(), self.profile())

    def test_missing_identity_refuses_orphan_files_and_invalid_generation_before_mutation(self):
        for case in ("orphan", "dangling", "bad-generation"):
            with self.subTest(case=case), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                credential = root / "backup.my.cnf"
                if case == "orphan":
                    credential.write_bytes(self.profile())
                    credential.chmod(0o600)
                if case == "dangling":
                    credential.symlink_to(root / "absent")
                run = self.run_block(root, exists=False, generation="bad" if case == "bad-generation" else "good")
                self.assertNotEqual(run.returncode, 0)
                self.assertFalse((root / "trace").exists(), "account mutation preceded custody admission")
                if case == "orphan":
                    self.assertEqual(credential.read_bytes(), self.profile())
                elif case == "dangling":
                    self.assertTrue(credential.is_symlink())
                    self.assertFalse((root / "absent").exists())
                else:
                    self.assertFalse(credential.exists())


if __name__ == "__main__":
    print(f"Exact assessed source: {SOURCE_SHA}", flush=True)
    unittest.main(argv=[__file__] + TEST_ARGUMENTS, verbosity=2)
