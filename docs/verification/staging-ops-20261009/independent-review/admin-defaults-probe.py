"""Execute the exact CLI admission prelude and first query with owned fixtures.

UID authority and /tmp's parent mode are modeled; file modes, link counts,
canonicalization, FIFO/directory types and Bash control flow are real. No host
mutation or database operation runs.
"""

import argparse
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


REPO = Path(__file__).resolve().parents[4]
SOURCE = ""


class AdminDefaultsReview(unittest.TestCase):
    def probe(self, state):
        prelude = SOURCE[:SOURCE.index("\nAPP_GROUP=")]
        query = SOURCE[SOURCE.index("MYSQL=(mysql"):SOURCE.index("\nmyver=")]
        self.assertLess(SOURCE.index("\nAPP_GROUP="), SOURCE.index("apt-get update"))
        self.assertLess(SOURCE.index("\nAPP_GROUP="), SOURCE.index("# ---------------------------------------------------------------- 4."))
        with tempfile.TemporaryDirectory(prefix="admin-defaults-review-") as directory:
            fixture = Path(directory)
            kit = fixture / "kit"
            kit.mkdir(mode=0o755)
            ancestors = fixture / "credential-root"
            ancestors.mkdir(mode=0o700)
            parent = ancestors / "private"
            parent.mkdir(mode=0o700)
            profile = parent / "admin.cnf"
            profile.write_text("[client]\nuser=synthetic_admin\n")
            profile.chmod(0o600)
            path = str(profile)
            bad_uid = ""
            if state == "missing":
                profile.unlink()
            elif state == "leaf-symlink":
                alias = parent / "alias.cnf"
                alias.symlink_to(profile)
                path = str(alias)
            elif state == "dangling-link":
                alias = parent / "alias.cnf"
                alias.symlink_to(parent / "missing.cnf")
                path = str(alias)
            elif state == "hardlink":
                os.link(profile, parent / "second.cnf")
            elif state == "directory":
                profile.unlink()
                profile.mkdir(mode=0o600)
            elif state == "fifo":
                profile.unlink()
                os.mkfifo(profile, 0o600)
            elif state.startswith("mode-"):
                profile.chmod(int(state[5:], 8))
            elif state == "app-leaf":
                bad_uid = str(profile)
            elif state == "app-parent":
                bad_uid = str(parent)
            elif state == "app-ancestor":
                bad_uid = str(ancestors)
            elif state == "group-parent":
                parent.chmod(0o770)
            elif state == "world-ancestor":
                ancestors.chmod(0o707)
            elif state == "ancestor-symlink":
                alias = fixture / "alias"
                alias.symlink_to(ancestors, target_is_directory=True)
                path = str(alias / "private" / "admin.cnf")
            elif state == "dot-component":
                path = str(parent) + "/./admin.cnf"
            elif state == "parent-component":
                path = str(parent) + "/../private/admin.cnf"
            elif state == "double-slash":
                path = str(parent) + "//admin.cnf"
            elif state == "relative":
                path = os.path.relpath(profile, REPO)
            elif state == "trailing-slash":
                path += "/"
            elif state == "socket-default":
                path = ""
            elif state == "valid-spaces":
                renamed = parent / "admin with spaces.cnf"
                profile.rename(renamed)
                profile = renamed
                path = str(profile)

            original = profile.lstat() if profile.exists() else None
            trace = fixture / "trace"
            # The entire real argument parser runs. Only host identity/stat UID
            # and the subsequent MySQL call are substitutes.
            harness = r'''
id() {
  if [ "$#" = 1 ] && [ "$1" = -u ]; then echo 0;
  elif [ "$#" = 2 ] && [ "$1" = -u ]; then echo 1000;
  else return 0; fi
}
stat() {
  local uid=0 fmt=$2 file=$3
  [ "$file" != "$BAD_UID" ] || uid=1000
  case "$fmt" in
    %u) printf '%s\n' "$uid" ;;
    %a) if [ "$file" = /tmp ]; then echo 755; else command stat "$@"; fi ;;
    '%u %a %h') printf '%s %s %s\n' "$uid" "$(command stat -c %a "$file")" "$(command stat -c %h "$file")" ;;
    *) command stat "$@" ;;
  esac
}
mysql() { printf 'query %s\n' "$*" >> "$TRACE"; }
'''
            # The sentinel follows admission and precedes all omitted host
            # stages. The real first-query construction is then exercised.
            script = harness + prelude + '\nprintf "admitted\\n" >> "$TRACE"\n' + query
            args = ["bash", "-c", script, "review-provision", "--host", "staging.example.test",
                    "--root", str(kit), "--skip-packages"]
            if path:
                args += ["--mysql-admin-defaults", path]
            result = subprocess.run(args, cwd=REPO, capture_output=True, text=True, timeout=2,
                                    env={"PATH": "/usr/bin:/bin", "BAD_UID": bad_uid, "TRACE": str(trace)})
            admitted = state in ("valid", "valid-spaces", "socket-default")
            lines = trace.read_text().splitlines() if trace.exists() else []
            if admitted:
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(len(lines), 2)
                self.assertEqual(lines[0], "admitted")
                option = "--protocol=socket -uroot" if not path else "--defaults-extra-file=" + path
                self.assertIn(option, lines[1])
                self.assertIn("-N -e SELECT 1", lines[1])
            else:
                self.assertNotEqual(result.returncode, 0, f"{state}: unsafe CLI admission succeeded")
                self.assertEqual(lines, [], "unsafe defaults reached the admission/query boundary")
                self.assertIn("provision: ERROR:", result.stderr)
            if original is not None:
                after = profile.lstat()
                self.assertEqual((after.st_dev, after.st_ino, after.st_mode, after.st_nlink),
                                 (original.st_dev, original.st_ino, original.st_mode, original.st_nlink))

    def test_leaf_custody(self):
        for state in ("missing", "leaf-symlink", "dangling-link", "hardlink", "directory", "fifo",
                      "mode-400", "mode-640", "mode-644", "mode-660", "mode-666", "app-leaf"):
            with self.subTest(state=state):
                self.probe(state)

    def test_parent_authority(self):
        for state in ("app-parent", "app-ancestor", "group-parent", "world-ancestor", "ancestor-symlink"):
            with self.subTest(state=state):
                self.probe(state)

    def test_canonical_path(self):
        for state in ("dot-component", "parent-component", "double-slash", "relative", "trailing-slash"):
            with self.subTest(state=state):
                self.probe(state)

    def test_protected_profiles_and_socket_default(self):
        for state in ("valid", "valid-spaces", "socket-default"):
            with self.subTest(state=state):
                self.probe(state)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-sha", required=True)
    options = parser.parse_args()
    SOURCE = subprocess.run(["git", "show", options.source_sha + ":ops/staging/provision.sh"],
                            cwd=REPO, check=True, capture_output=True, text=True).stdout
    print("Assessed exact source:", options.source_sha, flush=True)
    unittest.main(argv=[__file__], verbosity=2)
