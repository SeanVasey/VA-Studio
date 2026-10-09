"""Actual admission and first query; root authority and MySQL are bounded fixtures."""
from pathlib import Path
import os
import subprocess
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[2]


class AdminDefaultsCustodyTest(unittest.TestCase):
    def probe(self, state):
        source = (REPO / "ops/staging/provision.sh").read_text()
        guards = source[source.index("root_ancestry()"):source.index('\n[[ "$HOST"')]
        admission = source[source.index('[[ "$ROOT"'):source.index('[[ "$DB_NAME"')]
        query = source[source.index("MYSQL=(mysql"):source.index('myver=')]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            parent = root / "protected"
            parent.mkdir(mode=0o700)
            profile = parent / "admin.cnf"
            profile.write_text("[client]\nuser=fixture_admin\n")
            profile.chmod(0o600)
            path = str(profile)
            bad = ""
            if state == "missing":
                profile.unlink()
            elif state == "symlink":
                link = parent / "alias.cnf"
                link.symlink_to(profile)
                path = str(link)
            elif state == "hardlink":
                os.link(profile, parent / "second.cnf")
            elif state in ("directory", "fifo"):
                profile.unlink()
                if state == "directory":
                    profile.mkdir(mode=0o600)
                else:
                    os.mkfifo(profile, 0o600)
            elif state in ("public", "readonly", "writable"):
                profile.chmod({"public": 0o644, "readonly": 0o400, "writable": 0o666}[state])
            elif state == "app-owned-file":
                bad = str(profile)
            elif state == "app-owned-parent":
                bad = str(parent)
            elif state == "writable-parent":
                parent.chmod(0o777)
            elif state == "symlink-parent":
                alias = root / "alias"
                alias.symlink_to(parent, target_is_directory=True)
                path = str(alias / profile.name)
            elif state == "noncanonical":
                path = str(parent) + "/./admin.cnf"
            elif state == "relative":
                path = os.path.relpath(profile)
            elif state == "socket":
                path = ""
            trace = root / "queries"
            shell = '''die() { echo "$*" >&2; exit 1; }
stat() {
  local uid=0
  [ "$3" != "$BAD" ] || uid=1000
  case "$2" in
    %u) printf '%s\\n' "$uid" ;;
    %a) if [ "$3" = /tmp ]; then echo 755; else command stat "$@"; fi ;;
    '%u %a %h') printf '%s %s %s\\n' "$uid" "$(command stat -c %a "$3")" "$(command stat -c %h "$3")" ;;
    *) command stat "$@" ;;
  esac
}
mysql() { printf '%s\\n' "$*" >> "$TRACE"; }
'''
            run = subprocess.run(["bash", "-eu", "-c", shell + guards + admission + query],
                                 env={"PATH": "/usr/bin:/bin", "ROOT": str(root), "BAD": bad,
                                      "MYSQL_ADMIN_DEFAULTS": path, "TRACE": str(trace)},
                                 capture_output=True, text=True, timeout=2)
            expected = state in ("valid", "socket")
            self.assertEqual(run.returncode == 0, expected, run.stdout + run.stderr)
            if expected:
                calls = trace.read_text().splitlines()
                self.assertEqual(len(calls), 1)
                self.assertIn("--protocol=socket -uroot" if state == "socket"
                              else "--defaults-extra-file=" + path, calls[0])
            else:
                self.assertFalse(trace.exists(), "unsafe defaults reached the privileged first query")

    def test_unsafe_admin_defaults_refuse_before_the_first_query(self):
        for state in ("missing", "symlink", "hardlink", "directory", "fifo", "public", "readonly",
                      "writable", "app-owned-file", "app-owned-parent", "writable-parent",
                      "symlink-parent", "noncanonical", "relative"):
            with self.subTest(state=state):
                self.probe(state)

    def test_protected_admin_defaults_reach_the_first_query(self):
        self.probe("valid")

    def test_default_socket_path_does_not_require_a_defaults_file(self):
        self.probe("socket")


if __name__ == "__main__":
    unittest.main(verbosity=2)
