"""Exact application credential block; file behavior retained, root/client authority bounded."""
import argparse
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


REPO = Path(__file__).resolve().parents[4]
PARSER = argparse.ArgumentParser()
PARSER.add_argument("--source-sha", required=True)
ARGUMENTS, TEST_ARGUMENTS = PARSER.parse_known_args()
SOURCE_SHA = ARGUMENTS.source_sha


class AppAuthenticationProbe(unittest.TestCase):
    def test_authentication_identity_private_client_and_cleanup_before_grants(self):
        source = subprocess.check_output(["git", "-C", str(REPO), "show", f"{SOURCE_SHA}:ops/staging/provision.sh"], text=True)
        body = source[source.index("app_credential_custody()"):source.index("# Backup-only account")]
        shell = '''die() { echo "$*" >&2; exit 1; }
user_exists() { return 0; }
info() { :; }
mysql_q() { echo grant >> "$TRACE"; }
rm() { [ "$CLEANUP_FAIL" = 0 ] || return 24; command rm "$@"; }
stat() {
  if [ "$2" = '%u %a %h' ]; then printf '0 %s %s\\n' "$(command stat -c %a "$3")" "$(command stat -c %h "$3")";
  else command stat "$@"; fi
}
env() {
  [ "$1" = -i ] || return 87
  shift
  [ "$1" = PATH=/usr/local/bin:/usr/bin:/bin ] && [ "$2" = LC_ALL=C ] || return 88
  shift 2
  [ "$1" = mysql ] || return 89
  shift
  mysql "$@"
}
mysql() {
  local file='' option login=0 protocol=0 timeout=0
  for option in "$@"; do
    case "$option" in
      --defaults-file=*) file=${option#--defaults-file=} ;;
      --no-login-paths) login=1 ;;
      --protocol=TCP) protocol=1 ;;
      --connect-timeout=5) timeout=1 ;;
      --password=*|-p?*) return 90 ;;
    esac
  done
  [ "$login$protocol$timeout" = 111 ] || return 91
  [ -f "$file" ] && [ ! -L "$file" ] && [ "$(dirname "$file")" = "$SECRETS" ] \
    && [ "$(command stat -c '%a %h' "$file")" = '600 1' ] || return 92
  cmp -s -- "$file" <(printf '[client]\\nhost=127.0.0.1\\nport=3306\\nuser=vasey_app\\npassword=%s\\n' "$(sed -n 's/^DB_PASSWORD=//p' "$SECRETS/db-app.env")") || return 93
  echo authentication >> "$TRACE"
  case "$AUTH" in
    fail) return 23 ;;
    wrong-user) echo root@localhost ;;
    wrong-host) echo vasey_app@localhost ;;
    good) echo vasey_app@127.0.0.1 ;;
  esac
}
'''
        profile = b"DB_USERNAME=vasey_app\nDB_PASSWORD=" + b"A" * 40 + b"\n"
        for auth in ("good", "fail", "wrong-user", "wrong-host", "cleanup-fail"):
            with self.subTest(auth=auth), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                root.chmod(0o700)
                credential = root / "db-app.env"
                credential.write_bytes(profile)
                credential.chmod(0o600)
                trace = root / "trace"
                run = subprocess.run(["bash", "-euo", "pipefail", "-c", shell + body], capture_output=True, text=True, timeout=2,
                                     env={"PATH": "/usr/bin:/bin", "SECRETS": str(root), "TRACE": str(trace),
                                          "DB_NAME": "synthetic_database", "AUTH": "good" if auth == "cleanup-fail" else auth,
                                          "CLEANUP_FAIL": "1" if auth == "cleanup-fail" else "0"})
                self.assertEqual(run.returncode == 0, auth == "good", run.stdout + run.stderr)
                self.assertEqual(trace.read_text().splitlines(), ["authentication", "grant"] if auth == "good" else ["authentication"])
                if auth == "cleanup-fail":
                    leftovers = [path for path in root.iterdir() if path.name not in ("db-app.env", "trace")]
                    self.assertEqual(len(leftovers), 1, "cleanup failure boundary was not exercised")
                    self.assertEqual(leftovers[0].stat().st_mode & 0o777, 0o600)
                else:
                    self.assertEqual(sorted(path.name for path in root.iterdir()), ["db-app.env", "trace"], "private temporary client remains after process exit")
                self.assertEqual(credential.read_bytes(), profile)
                self.assertEqual(credential.stat().st_mode & 0o777, 0o600)
                self.assertNotIn("A" * 40, run.stdout + run.stderr)


if __name__ == "__main__":
    print(f"Exact assessed source: {SOURCE_SHA}", flush=True)
    unittest.main(argv=[__file__] + TEST_ARGUMENTS, verbosity=2)
