"""Real no-follow filesystem operations; privileged ownership uses the fixture UID."""
from pathlib import Path
import os
import pwd
import subprocess
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[2]


class RuntimeDirectoryCustodyTest(unittest.TestCase):
    def source(self):
        sha = os.environ.get("VASEY_RUNTIME_TEST_SOURCE_SHA")
        if sha:
            return subprocess.check_output(["git", "show", sha + ":ops/staging/provision.sh"],
                                           cwd=REPO, text=True)
        return (REPO / "ops/staging/provision.sh").read_text()

    def probe(self, state):
        source = self.source()
        modern = "initialize_runtime_directories()" in source
        with tempfile.TemporaryDirectory() as directory:
            fixture = Path(directory)
            root = fixture / "runtime"
            root.mkdir()
            for name in ("tmp", "private", "evidence"):
                (root / name).mkdir(mode=0o700)
            nginx = fixture / "nginx"
            nginx.mkdir()
            outside = fixture / "outside"
            outside.mkdir(mode=0o755)
            protected = outside / "unchanged"
            protected.write_text("owned protected-target fixture\n")
            protected.chmod(0o640)
            ignore = root / "private/.gitignore"
            directories = {"evidence": root / "evidence", "tmp": root / "tmp",
                           "php-upload": root / "tmp/php-upload", "php-sys": root / "tmp/php-sys",
                           "private": root / "private", "branding": root / "private/branding",
                           "nginx": nginx / "vasey-staging-fastcgi"}
            if state in directories:
                path = directories[state]
                if path.exists():
                    path.rmdir()
                path.symlink_to(outside, target_is_directory=True)
            elif state == "ignore-link":
                ignore.symlink_to(protected)
            elif state == "ignore-dangling":
                ignore.symlink_to(outside / "new-root-file")
            elif state == "ignore-hardlink":
                os.link(protected, ignore)
            elif state == "ignore-fifo":
                os.mkfifo(ignore, 0o600)
            elif state == "ignore-directory":
                ignore.mkdir()
            elif state == "existing":
                ignore.write_text("preserve existing ordinary bytes\n")
                ignore.chmod(0o600)
            elif state == "swap-after-open":
                (root / "private/branding").mkdir()
            before = (outside.stat().st_mode, protected.stat().st_mode,
                      protected.read_bytes(), sorted(path.name for path in outside.iterdir()))
            existing = (ignore.stat().st_ino, ignore.read_bytes(), ignore.stat().st_mode) if state == "existing" else None
            if modern:
                body = source[source.index("initialize_runtime_directories()"):source.index('\n[[ "$HOST"')]
                body = body.replace('open_directory("/var/lib/nginx")', 'open_directory("' + str(nginx) + '")')
                if state == "swap-after-open":
                    injection = '''
real_fchown = os.fchown
def raced_fchown(fd, uid, gid):
    if os.readlink("/proc/self/fd/" + str(fd)).endswith("/private/branding"):
        path = root + "/private/branding"
        os.rename(path, path + ".pinned")
        os.symlink(os.environ["OUTSIDE"], path)
    real_fchown(fd, uid, gid)
os.fchown = raced_fchown
'''
                    body = body.replace('try:\n    with ExitStack()', injection + '\ntry:\n    with ExitStack()', 1)
                body += "\ninitialize_runtime_directories\n"
            else:
                if state == "swap-after-open":
                    self.skipTest("descriptor-only race proof applies to the new initializer")
                start = source.index('install -d -m 0755 -o root -g root "$ROOT"')
                body = source[start:source.index('install -m 0600 -o "$APP_USER"', start)]
                body += '\ninstall -d -m 0700 -o "$NGINX_USER" -g "$NGINX_GROUP" "' + str(nginx) + '/vasey-staging-fastcgi"\n'
            shell = '''die() { echo "$*" >&2; exit 1; }
install() {
  local -a args=()
  while [ "$#" -gt 0 ]; do
    if [ "$1" = -o ] && [ "${2:-}" = root ]; then args+=(-o "$APP_USER"); shift 2;
    elif [ "$1" = -g ] && [ "${2:-}" = root ]; then args+=(-g "$APP_GROUP"); shift 2;
    else args+=("$1"); shift; fi
  done
  command install "${args[@]}"
}
'''
            account = pwd.getpwuid(os.getuid()).pw_name
            group = subprocess.check_output(["id", "-gn", account], text=True).strip()
            run = subprocess.run(["bash", "-eu", "-c", shell + body],
                                 env={"PATH": "/usr/bin:/bin", "ROOT": str(root), "APP_USER": account,
                                      "APP_GROUP": group, "NGINX_USER": account, "NGINX_GROUP": group,
                                      "OUTSIDE": str(outside)}, capture_output=True, text=True, timeout=2)
            after = (outside.stat().st_mode, protected.stat().st_mode,
                     protected.read_bytes(), sorted(path.name for path in outside.iterdir()))
            self.assertEqual(after, before, "root initialization followed a link into the external target")
            self.assertEqual(run.returncode == 0, state in ("valid", "existing"), run.stdout + run.stderr)
            if state in ("valid", "existing"):
                for path in (root / "tmp/php-upload", root / "tmp/php-sys", root / "private/branding",
                             nginx / "vasey-staging-fastcgi"):
                    self.assertEqual(path.stat().st_mode & 0o777, 0o700)
                self.assertEqual((root / "evidence").stat().st_mode & 0o777, 0o750)
                if existing:
                    self.assertEqual((ignore.stat().st_ino, ignore.read_bytes(), ignore.stat().st_mode), existing)
                else:
                    self.assertEqual(ignore.read_bytes(), b"*\n!.gitignore\n")
                    self.assertEqual(ignore.stat().st_mode & 0o777, 0o644)

    def test_links_and_special_files_refuse_without_touching_the_external_target(self):
        for state in ("evidence", "tmp", "php-upload", "php-sys", "private", "branding", "nginx",
                      "ignore-link", "ignore-dangling", "ignore-hardlink", "ignore-fifo", "ignore-directory"):
            with self.subTest(state=state):
                self.probe(state)

    def test_regular_runtime_directories_and_existing_gitignore_are_preserved(self):
        for state in ("valid", "existing"):
            with self.subTest(state=state):
                self.probe(state)

    def test_replacement_after_open_cannot_redirect_descriptor_metadata_changes(self):
        self.probe("swap-after-open")


if __name__ == "__main__":
    unittest.main(verbosity=2)
