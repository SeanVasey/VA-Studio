"""Owned-path provisioning probes. Host authority is modeled, never exercised."""

import argparse
from contextlib import redirect_stderr
import io
import os
from pathlib import Path
import pwd
import re
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch


REPO = Path(__file__).resolve().parents[4]
SOURCE = ""


def old_initializer(root, fastcgi, trace):
    start = SOURCE.index("# ---------------------------------------------------------------- 4. directories and modes")
    stop = SOURCE.index('install -m 0600 -o "$APP_USER"', start)
    block = SOURCE[start:stop]
    nginx_line = next(line for line in SOURCE.splitlines()
                      if line.startswith('install -d') and '/var/lib/nginx/vasey-staging-fastcgi' in line)
    shell = r'''
set -eu
die() { echo "$*" >&2; exit 1; }
chown() { printf 'bounded chown %s\n' "$*" >> "$TRACE"; }
install() {
  local -a args=()
  while [ "$#" -gt 0 ]; do
    case "$1" in
      -o|-g) shift 2 ;;
      /var/lib/nginx/vasey-staging-fastcgi) args+=("$FASTCGI"); shift ;;
      *) args+=("$1"); shift ;;
    esac
  done
  command install "${args[@]}"
}
'''
    account = pwd.getpwuid(os.getuid()).pw_name
    return subprocess.run(["bash", "-c", shell + block + "\n" + nginx_line],
                          env={"PATH": "/usr/bin:/bin", "ROOT": str(root), "FASTCGI": str(fastcgi),
                               "APP_USER": account, "APP_GROUP": account,
                               "NGINX_USER": account, "NGINX_GROUP": account, "TRACE": str(trace)},
                          capture_output=True, text=True, timeout=3)


def new_initializer(root, fastcgi, protected, race=None):
    body = re.search(r"initialize_runtime_directories\(\).*?<<'PY'\n(.*?)\nPY\n}",
                     SOURCE, re.S).group(1)
    real_open, real_fchown = os.open, os.fchown
    global_root = fastcgi.parent.parents[2]
    slash_opens = 0
    raced = False
    captured = []
    stderr = io.StringIO()

    def open_owned(path, flags, *args, **kwargs):
        nonlocal slash_opens, raced
        self_path = os.fspath(path)
        if self_path == "/":
            slash_opens += 1
            if slash_opens == 2:
                # Map only the nginx host-tree traversal onto its owned mirror.
                path = str(global_root)
        if race == "gitignore-before-create" and self_path == ".gitignore" and flags & os.O_EXCL:
            (root / "private/.gitignore").symlink_to(protected / "new-root-file")
            raced = True
        if race == "child-before-open" and self_path == "branding" and not raced:
            original = root / "private/branding"
            original.rename(original.with_name("branding.pinned"))
            original.symlink_to(protected, target_is_directory=True)
            raced = True
        if flags & os.O_DIRECTORY:
            assert flags & os.O_NOFOLLOW, "directory was opened without no-follow"
        fd = real_open(path, flags, *args, **kwargs)
        captured.append(fd)
        if race == "gitignore-after-create" and self_path == ".gitignore" and flags & os.O_EXCL:
            original = root / "private/.gitignore"
            original.rename(root / "private/.gitignore.pinned")
            original.symlink_to(protected / "untouched")
            raced = True
        return fd

    def chown_owned(fd, uid, gid):
        nonlocal raced
        path = os.readlink("/proc/self/fd/" + str(fd))
        assert path.startswith(str(root.parent) + "/"), "unexpected global metadata mutation"
        if path.endswith("/private/branding") and not raced:
            if race == "child-after-open":
                original = root / "private/branding"
                original.rename(original.with_name("branding.pinned"))
                original.symlink_to(protected, target_is_directory=True)
                raced = True
        real_fchown(fd, uid, gid)

    code = 0
    with patch("sys.argv", ["runtime-initializer", str(root), str(os.getuid()), str(os.getgid()),
                            str(os.getuid()), str(os.getgid())]), \
            patch("os.open", open_owned), patch("os.fchown", chown_owned), redirect_stderr(stderr):
        try:
            exec(compile(body, "<exact provision initializer>", "exec"), {})
        except SystemExit as error:
            code = error.code
    for fd in set(captured):
        try:
            os.fstat(fd)
        except OSError:
            continue
        raise AssertionError("initializer leaked an opened descriptor")
    if race:
        assert raced, "replacement hook never ran"
    return SimpleNamespace(returncode=code, stderr=stderr.getvalue())


def initialize(root, fastcgi, trace, protected, race=None):
    if "initialize_runtime_directories()" in SOURCE:
        return new_initializer(root, fastcgi, protected, race)
    return old_initializer(root, fastcgi, trace)


class RuntimeDirectoryReview(unittest.TestCase):
    def probe_symlink(self, state):
        with tempfile.TemporaryDirectory(prefix="runtime-dir-review-") as directory:
            fixture = Path(directory)
            root = fixture / "kit"
            root.mkdir(mode=0o755)
            (root / "tmp").mkdir(mode=0o700)
            (root / "private").mkdir(mode=0o700)
            fastcgi = fixture / "hostroot/var/lib/nginx/vasey-staging-fastcgi"
            fastcgi.parent.mkdir(mode=0o755, parents=True)
            protected = fixture / "protected"
            protected.mkdir(mode=0o755)
            target = protected / "root-authority"
            if state.startswith("gitignore-"):
                ignore = root / "private/.gitignore"
                if state == "gitignore-dangling":
                    ignore.symlink_to(target)
                elif state == "gitignore-existing":
                    target.write_text("synthetic protected bytes\n")
                    target.chmod(0o640)
                    ignore.symlink_to(target)
                elif state == "gitignore-hardlink":
                    target.write_text("synthetic protected bytes\n")
                    target.chmod(0o640)
                    os.link(target, ignore)
                elif state == "gitignore-fifo":
                    os.mkfifo(ignore, 0o600)
                elif state == "gitignore-directory":
                    ignore.mkdir(mode=0o700)
                original = None
            else:
                target.mkdir(mode=0o755)
                original = target.stat()
                link = fastcgi if state == "fastcgi" else root / state
                if link.exists():
                    link.rmdir()
                link.symlink_to(target, target_is_directory=True)
            entries = sorted(protected.iterdir())
            original_bytes = target.read_bytes() if target.is_file() else None
            result = initialize(root, fastcgi, fixture / "trace", protected)
            if original is None:
                self.assertEqual(sorted(protected.iterdir()), entries,
                                 "root initializer followed .gitignore into protected storage")
                if original_bytes is not None:
                    self.assertEqual(target.read_bytes(), original_bytes)
            else:
                now = target.stat()
                self.assertEqual((now.st_dev, now.st_ino, now.st_mode),
                                 (original.st_dev, original.st_ino, original.st_mode),
                                 "root initializer changed the symlink's protected target")
                self.assertEqual(sorted(target.iterdir()), [], "root initializer created entries in the protected target")
            self.assertNotEqual(result.returncode, 0, "unsafe runtime symlink was admitted")

    def test_direct_application_owned_directory_links(self):
        for state in ("evidence", "tmp", "private"):
            with self.subTest(state=state):
                self.probe_symlink(state)

    def test_application_owned_child_directory_links(self):
        for state in ("tmp/php-upload", "tmp/php-sys", "private/branding"):
            with self.subTest(state=state):
                self.probe_symlink(state)

    def test_nginx_directory_and_gitignore_links(self):
        for state in ("fastcgi", "gitignore-dangling", "gitignore-existing", "gitignore-hardlink",
                      "gitignore-fifo", "gitignore-directory"):
            with self.subTest(state=state):
                self.probe_symlink(state)

    def test_normal_initialization_and_existing_gitignore(self):
        for existing in (False, True):
            with self.subTest(existing=existing), tempfile.TemporaryDirectory(prefix="runtime-dir-normal-") as directory:
                fixture = Path(directory)
                root = fixture / "kit"
                root.mkdir(mode=0o755)
                fastcgi = fixture / "hostroot/var/lib/nginx/vasey-staging-fastcgi"
                fastcgi.parent.mkdir(parents=True)
                ignore = root / "private/.gitignore"
                if existing:
                    ignore.parent.mkdir(mode=0o700)
                    ignore.write_bytes(b"existing ordinary private bytes\n")
                    ignore.chmod(0o600)
                    before = (ignore.stat().st_ino, ignore.stat().st_mode, ignore.read_bytes())
                result = initialize(root, fastcgi, fixture / "trace", fixture / "protected")
                self.assertEqual(result.returncode, 0, result.stderr)
                for path in (root / "tmp", root / "tmp/php-upload", root / "tmp/php-sys",
                             root / "private", root / "private/branding", fastcgi):
                    self.assertEqual(path.stat().st_mode & 0o777, 0o700, str(path))
                self.assertEqual((root / "evidence").stat().st_mode & 0o777, 0o750)
                if existing:
                    self.assertEqual((ignore.stat().st_ino, ignore.stat().st_mode, ignore.read_bytes()), before)
                else:
                    self.assertEqual(ignore.read_bytes(), b"*\n!.gitignore\n")
                    self.assertEqual(ignore.stat().st_mode & 0o777, 0o644)

    def test_replacement_races_keep_metadata_and_writes_on_pinned_inodes(self):
        if "initialize_runtime_directories()" not in SOURCE:
            self.skipTest("descriptor race proof applies only to the new initializer")
        for race in ("child-before-open", "child-after-open", "gitignore-before-create", "gitignore-after-create"):
            with self.subTest(race=race), tempfile.TemporaryDirectory(prefix="runtime-dir-race-") as directory:
                fixture = Path(directory)
                root = fixture / "kit"
                root.mkdir(mode=0o755)
                (root / "private").mkdir(mode=0o700)
                (root / "private/branding").mkdir(mode=0o755)
                fastcgi = fixture / "hostroot/var/lib/nginx/vasey-staging-fastcgi"
                fastcgi.parent.mkdir(parents=True)
                protected = fixture / "protected"
                protected.mkdir(mode=0o755)
                file = protected / "untouched"
                file.write_bytes(b"synthetic protected bytes\n")
                file.chmod(0o640)
                before = (protected.stat().st_mode, file.stat().st_mode, file.read_bytes(), sorted(protected.iterdir()))
                result = initialize(root, fastcgi, fixture / "trace", protected, race)
                self.assertEqual((protected.stat().st_mode, file.stat().st_mode, file.read_bytes(),
                                  sorted(protected.iterdir())), before)
                if race == "gitignore-after-create":
                    self.assertEqual(result.returncode, 0, result.stderr)
                    self.assertTrue((root / "private/.gitignore").is_symlink())
                    self.assertEqual((root / "private/.gitignore.pinned").read_bytes(), b"*\n!.gitignore\n")
                else:
                    self.assertNotEqual(result.returncode, 0, "replaced current entry was admitted")

    def test_initializer_is_invoked_in_the_real_provisioning_stage(self):
        if "initialize_runtime_directories()" not in SOURCE:
            self.skipTest("the old provisioning source uses path-based commands")
        call = SOURCE.index("\ninitialize_runtime_directories\n")
        self.assertEqual(SOURCE.count("\ninitialize_runtime_directories\n"), 1)
        self.assertLess(SOURCE.index('# ---------------------------------------------------------------- 4.'), call)
        self.assertLess(SOURCE.index('install -d -m 0700 -o root -g root "$ROOT/backups"'), call)
        self.assertLess(call, SOURCE.index('install -m 0600 -o "$APP_USER"'))
        self.assertLess(call, SOURCE.index("# ---------------------------------------------------------------- 5. MySQL"))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-sha", required=True)
    options = parser.parse_args()
    SOURCE = subprocess.run(["git", "show", options.source_sha + ":ops/staging/provision.sh"],
                            cwd=REPO, check=True, capture_output=True, text=True).stdout
    print("Assessed exact source:", options.source_sha, flush=True)
    unittest.main(argv=[__file__], verbosity=2)
