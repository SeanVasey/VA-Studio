"""The private-store tree in staging backup sets (ops/staging/private-snapshot.py and its use in ops/staging/backup.sh).

A deploy snapshot copies only the private files that changed since the previous verified set and hard-links the rest to THAT
SET's verified copies, never to the live store. These tests run the shipped helper's real functions and dispatcher (snapshot,
restore-check, space-check, prune) and the real engine against throwaway directories. Only the database side is a stub (a
fixed dump, a disposable "mysqld" that sleeps); the private files, hashes, links, modes and inodes are real.
"""
import contextlib
import errno
import io
import hashlib
import importlib.util
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
BACKUP = REPO / "ops/staging/backup.sh"
ENGINE = REPO / "ops/staging/private-snapshot.py"
SOURCE = BACKUP.read_text()
DEFAULTS = SOURCE[SOURCE.index(': "${VASEY_BACKUP_AGE_RECIPIENTS_FILE:=}"'):SOURCE.index('[ "$(id -u "$VASEY_APP_USER")" -gt 0 ]')]
FUNCTIONS = SOURCE[SOURCE.index("mycnf_ok()"):SOURCE.index('\ncase "${1:-}" in')]
DISPATCH = SOURCE[SOURCE.index('\ncase "${1:-}" in'):]
IS_ROOT = os.geteuid() == 0

spec = importlib.util.spec_from_file_location("private_snapshot", ENGINE)
engine = importlib.util.module_from_spec(spec)
spec.loader.exec_module(engine)

# The database side of a set is a fixed synthetic dump; the disposable server is a process that sleeps until it is shut down.
PRELUDE = r"""set -euo pipefail
umask 077
die() { echo "DIE: $*" >&2; exit 1; }
log() { echo "LOG: $*"; }
DUMP_FLAGS=(--databases synthetic)
NORMALIZER=/nonexistent
CTL=/nonexistent
"""
OVERRIDES = r"""
mycnf_ok() { return 0; }
mysqldump() { printf '%s\n' '-- synthetic dump SYNTHETIC_DATABASE_MARKER' 'CREATE TABLE t (id int);'; }
mysql() {
  if [ "${1:-}" = "--defaults-extra-file=$VASEY_BACKUP_MYCNF" ]; then echo "${T_DB_BYTES:-0}"; return 0; fi
  case " $* " in *" -e "*) echo 3 ;; *) cat >/dev/null ;; esac
}
mysqladmin() { case " $* " in *" shutdown "*) kill "$RC_PID" 2>/dev/null || true ;; esac; return 0; }
[ -z "${T_FREE:-}" ] || df() { printf 'Avail\n%s\n' "$T_FREE"; }
"""


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def tree(root, seconds=False):
    """{relative name: (sha256 or 'dir', mode, mtime)} of a directory tree, never following links. A tar archive keeps whole
    seconds of mtime (as the tar sets always did), so a tree that went through tar is compared with `seconds=True`."""
    root = Path(root)
    out = {}
    for path in sorted(root.rglob("*")):
        st = path.lstat()
        rel = "./" + str(path.relative_to(root))
        if stat.S_ISDIR(st.st_mode):
            out[rel] = ("dir", stat.S_IMODE(st.st_mode), None)
        else:
            out[rel] = (digest(path), stat.S_IMODE(st.st_mode), st.st_mtime_ns // 10**9 if seconds else st.st_mtime_ns)
    return out


def inodes(root):
    return {str(p.relative_to(root)): (p.lstat().st_dev, p.lstat().st_ino) for p in Path(root).rglob("*") if p.is_file()}


def fields(path):
    return dict(line.split("=", 1) for line in Path(path).read_text().splitlines() if "=" in line)


@unittest.skipUnless(IS_ROOT, "the helper refuses to run as another user, and the set tree is checked for this user's ownership")
class PrivateTreeBase(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="va-private-tree-")
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.live = self.root / "app/private"
        self.backups = self.root / "app/backups"
        for directory in (self.live, self.backups):
            directory.mkdir(parents=True, mode=0o700)
            directory.chmod(0o700)
        self.root.chmod(0o711)  # an archive set's proof extracts as the application user under restore-check/, as on a host
        self.write("masters/track-one/master.wav", b"RIFF" + b"a" * 5000)
        self.write("masters/track-one/stems.zip", b"PK" + b"b" * 3000)
        self.write("contracts/c1.pdf", b"%PDF-1.7 synthetic", mode=0o400)
        self.write("kits/k1/.write-lock", b"")
        self.write(".gitignore", b"*\n!.gitignore\n", mode=0o644)
        (self.live / "processing").mkdir(mode=0o700)
        # The application user owns the live store; the set's copies must belong to root.
        for path in [self.live, *self.live.rglob("*")]:
            os.chown(path, 65534, 65534, follow_symlinks=False)
        mysqld = self.root / "fake-mysqld"
        mysqld.write_text('#!/bin/bash\nfor a; do [ "$a" = --initialize-insecure ] && exit 0; done\nexec sleep 120\n')
        mysqld.chmod(0o755)
        self.env = {"PATH": "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C", "HOME": str(self.root),
                    "VASEY_ROOT": str(self.root / "app"), "VASEY_BACKUP_DIR": str(self.backups), "PRIVATE": str(self.live),
                    "PRIVATE_SNAPSHOT": str(ENGINE), "VASEY_DB_NAME": "synthetic", "VASEY_BACKUP_MYCNF": "/nonexistent",
                    "VASEY_RESTORE_CHECK_DIR": str(self.root / "restore-check"), "VASEY_RESTORE_STRICT_MODES": "1",
                    "VASEY_MYSQLD": str(mysqld), "VASEY_APP_USER": "nobody",
                    "VASEY_APP_GROUP": subprocess.run(["id", "-gn", "nobody"], capture_output=True, text=True).stdout.strip(),
                    "VASEY_BACKUP_KEEP": "7", "VASEY_BACKUP_DEST": "", "VASEY_PHP": "/nonexistent"}
        # A live file whose ctime is within two seconds of the snapshot that recorded it is never trusted as unchanged.
        time.sleep(2.1)

    def write(self, rel, data, mode=0o600):
        path = self.live / rel
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        for parent in [p for p in path.parents if self.live in p.parents or p == self.live]:
            parent.chmod(0o700)
        path.write_bytes(data)
        path.chmod(mode)
        if path.stat().st_uid == 0 and self.live.stat().st_uid != 0:
            os.chown(path, 65534, 65534)
            for parent in path.parents:
                if parent == self.live:
                    break
                os.chown(parent, 65534, 65534)
        return path

    def run_helper(self, *arguments, **extra):
        env = dict(self.env, **{k: str(v) for k, v in extra.items()})
        script = PRELUDE + DEFAULTS + FUNCTIONS + OVERRIDES + DISPATCH
        return subprocess.run(["bash", "-c", script, "vasey-staging-backup", *arguments], env=env,
                              capture_output=True, text=True, timeout=180)

    def predeploy(self, preverify=True, **extra):
        """What a deploy does: re-verify before the quiesce (prepare), then the snapshot and its proof (activate)."""
        if preverify:
            run = self.run_helper("space-check", "--preverify", T_FREE=10**15, **extra)
            self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        before = set(self.sets())
        run = self.run_helper("predeploy", **extra)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        new = sorted(set(self.sets()) - before)
        self.assertEqual(len(new), 1, run.stdout)
        time.sleep(1.1)  # set names have one-second resolution
        return self.backups / new[0]

    def sets(self):
        return sorted(p.name for p in self.backups.iterdir() if p.is_dir() and p.name.startswith("20") and not p.name.endswith(".FAILED"))

    def live_files(self):
        return sorted(str(p.relative_to(self.live)) for p in self.live.rglob("*") if p.is_file())

    def corrupt(self, path, data=b"ROT!"):
        """Rewrites a file in place keeping its inode, size and mtime: only the bytes (and the ctime) change."""
        st = path.stat()
        with open(path, "r+b") as handle:
            handle.write(data)
        os.utime(path, ns=(st.st_atime_ns, st.st_mtime_ns))
        return st

    def token(self):
        return self.backups / ".link-token"


class SnapshotAndProof(PrivateTreeBase):
    def test_a_first_snapshot_without_an_earlier_set_copies_everything_and_proves_it(self):
        first = self.predeploy()
        manifest, proof = fields(first / "MANIFEST"), fields(first / "RESTORE_CHECK")
        files = len(self.live_files())
        self.assertEqual(manifest["private_format"], "tree")
        self.assertEqual(manifest["private_link_source"], "none")
        self.assertEqual((manifest["private_copied_files"], manifest["private_linked_files"]), (str(files), "0"))
        self.assertEqual(proof["result"], "RESTORE_VERIFIED")
        self.assertEqual((proof["private_format"], proof["private_proof"], proof["private_rehashed_files"],
                          proof["private_trusted_linked_files"]), ("tree", "incremental", str(files), "0"))
        self.assertEqual(proof["modes_and_ownership"], "pass")
        # Byte, mode and mtime identical to the live store; separate inodes; owned by root, never by the application user.
        self.assertEqual(tree(first / "private"), tree(self.live))
        self.assertFalse(set(inodes(first / "private").values()) & set(inodes(self.live).values()))
        for path in [first / "private", *(first / "private").rglob("*")]:
            self.assertEqual((path.lstat().st_uid, path.lstat().st_gid), (0, 0), path)
        # The manifests are what the tar sets carried, so the runbook's `sha256sum --check` and directory diff still apply.
        expected = subprocess.run("find . -type f -print0 | sort -z | xargs -0 -r sha256sum", shell=True, cwd=self.live,
                                  capture_output=True, env={"LC_ALL": "C", "PATH": "/usr/bin:/bin"}).stdout
        self.assertEqual((first / "private.sha256").read_bytes(), expected)
        dirs = subprocess.run("find . -type d | LC_ALL=C sort", shell=True, cwd=self.live, capture_output=True).stdout
        self.assertEqual((first / "private.dirs").read_bytes(), dirs)
        self.assertFalse((first / "private.tar").exists())

    def test_a_second_snapshot_links_unchanged_files_to_the_re_verified_set_and_copies_only_new_ones(self):
        first = self.predeploy()
        self.write("masters/track-two/master.wav", b"RIFF" + b"c" * 7000)
        second = self.predeploy()
        manifest, proof = fields(second / "MANIFEST"), fields(second / "RESTORE_CHECK")
        unchanged = len(self.live_files()) - 1
        self.assertEqual(manifest["private_link_source"], first.name)
        self.assertEqual((manifest["private_copied_files"], manifest["private_linked_files"]), ("1", str(unchanged)))
        self.assertEqual(manifest["private_copied_bytes"], str(7004))
        self.assertEqual(proof["result"], "RESTORE_VERIFIED")
        self.assertEqual((proof["private_rehashed_files"], proof["private_trusted_linked_files"]), ("1", str(unchanged)))
        self.assertEqual(tree(second / "private"), tree(self.live))
        self.assertFalse(self.token().exists(), "the token is consumed by the snapshot that used it")
        older, newer, live = inodes(first / "private"), inodes(second / "private"), inodes(self.live)
        for rel, ident in newer.items():
            self.assertNotEqual(ident, live[rel], f"{rel} must never share the live inode")
            if rel != "masters/track-two/master.wav":
                self.assertEqual(ident, older[rel], f"{rel} is shared with the previous set")

    def test_an_in_place_write_to_a_live_file_never_reaches_a_snapshot(self):
        # Reservation counters, lock files and Flysystem `put` rewrite private files in place (same inode). Same size, mtime put
        # back: only the ctime shows the change, and it must be enough.
        first = self.predeploy()
        second = self.predeploy()
        target = self.live / "masters/track-one/stems.zip"
        before = (tree(first / "private"), tree(second / "private"))
        st = self.corrupt(target, b"XX")
        self.assertEqual((target.stat().st_ino, target.stat().st_size, target.stat().st_mtime_ns), (st.st_ino, st.st_size, st.st_mtime_ns))
        self.assertEqual((tree(first / "private"), tree(second / "private")), before, "a live write changed a snapshot")
        time.sleep(2.1)
        third = self.predeploy()
        self.assertEqual(fields(third / "MANIFEST")["private_copied_files"], "1")
        self.assertEqual(digest(third / "private/masters/track-one/stems.zip"), digest(target))
        self.assertEqual((tree(first / "private"), tree(second / "private")), before)

    def test_pruning_an_older_linked_set_leaves_the_newer_one_byte_identical(self):
        first = self.predeploy()
        second = self.predeploy()
        kept = (tree(second / "private"), inodes(second / "private"))
        self.assertTrue(set(inodes(first / "private").values()) & set(kept[1].values()), "the sets share inodes")
        run = self.run_helper("prune", VASEY_BACKUP_KEEP=1)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertEqual(self.sets(), [second.name])
        self.assertEqual((tree(second / "private"), inodes(second / "private")), kept)
        # And it is still provably restorable: a manual reproof re-hashes every file.
        run = self.run_helper("restore-check", str(second))
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        proof = fields(second / "RESTORE_CHECK")
        self.assertEqual((proof["result"], proof["private_proof"], proof["private_rehashed_files"]),
                         ("RESTORE_VERIFIED", "full", str(len(self.live_files()))))

    def test_restoring_a_linked_set_by_the_runbook_reproduces_the_store(self):
        self.predeploy()
        second = self.predeploy()
        runbook = (REPO / "docs/ops/staging-runbook.md").read_text()
        command = 'tar --create --file=- --directory="$SET/private" --numeric-owner .'
        self.assertIn(command, runbook)
        restored = self.root / "restored"
        restored.mkdir(mode=0o700)
        run = subprocess.run(["bash", "-c", f"set -o pipefail; {command} | tar --extract --file=- --directory=\"$DEST\" "
                              "--no-same-owner --preserve-permissions"],
                             env={"PATH": "/usr/bin:/bin", "SET": str(second), "DEST": str(restored)}, capture_output=True, text=True)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(tree(restored, seconds=True), tree(self.live, seconds=True))
        check = subprocess.run(["sha256sum", "--check", "--strict", "--quiet", str(second / "private.sha256")], cwd=restored,
                               capture_output=True, text=True)
        self.assertEqual(check.returncode, 0, check.stdout + check.stderr)

    def test_shipping_a_linked_set_sends_the_whole_set_and_its_copy_is_provable_elsewhere(self):
        # encrypt_set tars the set directory and sizes it with du: both must see every byte, shared files included. The extracted
        # copy has new inodes and whole-second mtimes, and the documented drill proves it with restore-check (full).
        self.predeploy()
        second = self.predeploy()
        logical = sum(p.stat().st_size for p in second.rglob("*") if p.is_file())
        du = int(subprocess.run(["du", "-sb", "--", str(second)], capture_output=True, text=True).stdout.split()[0])
        self.assertGreaterEqual(du, logical)
        archive = self.root / "set.tar"
        subprocess.run(["tar", "--create", f"--file={archive}", f"--directory={self.backups}", "--", second.name], check=True)
        listing = subprocess.run(["tar", "--list", "--verbose", f"--file={archive}"], capture_output=True, text=True).stdout
        self.assertFalse([line for line in listing.splitlines() if line.startswith("h")], "a hard link entry would lose content")
        elsewhere = self.root / "elsewhere"
        elsewhere.mkdir(mode=0o700)
        subprocess.run(["tar", "--extract", f"--file={archive}", f"--directory={elsewhere}"], check=True)
        copy = elsewhere / second.name
        self.assertEqual(tree(copy / "private", seconds=True), tree(self.live, seconds=True))
        (copy / "RESTORE_CHECK").unlink()
        run = self.run_helper("restore-check", str(copy), VASEY_BACKUP_DIR=elsewhere)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertEqual(fields(copy / "RESTORE_CHECK")["result"], "RESTORE_VERIFIED")
        # A tampered copy still fails: the full proof re-hashes every file.
        self.corrupt(copy / "private/masters/track-one/master.wav")
        self.assertNotEqual(self.run_helper("restore-check", str(copy), VASEY_BACKUP_DIR=elsewhere).returncode, 0)

    def test_linking_can_be_turned_off(self):
        self.predeploy()
        second = self.predeploy(VASEY_BACKUP_LINK=0)
        self.assertEqual(fields(second / "MANIFEST")["private_linked_files"], "0")
        self.assertEqual(fields(second / "MANIFEST")["private_copied_files"], str(len(self.live_files())))

    def make_tar_set(self, name):
        old = self.backups / name
        old.mkdir(mode=0o700)
        (old / "database.sql").write_text("-- synthetic dump SYNTHETIC_DATABASE_MARKER\nCREATE TABLE t (id int);\n")
        with open(old / "private.sha256", "wb") as out:
            subprocess.run("find . -type f -print0 | sort -z | xargs -0 -r sha256sum", shell=True, cwd=self.live, check=True,
                           stdout=out, env={"LC_ALL": "C", "PATH": "/usr/bin:/bin"})
        with open(old / "private.dirs", "wb") as out:
            subprocess.run("find . -type d | LC_ALL=C sort", shell=True, cwd=self.live, check=True, stdout=out)
        subprocess.run(["tar", "--create", f"--file={old / 'private.tar'}", f"--directory={self.live}", "--numeric-owner", "."], check=True)
        for item in ("database.sql", "private.tar"):
            subprocess.run(f"sha256sum {item} > {item}.sha256", shell=True, cwd=old, check=True)
        (old / "MANIFEST").write_text("release_sha=none\n")
        run = self.run_helper("restore-check", str(old))
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        return old

    def test_an_archive_set_from_before_the_change_is_still_proven_and_never_shared(self):
        old = self.make_tar_set("20261101T031700Z-none")
        self.assertEqual(fields(old / "RESTORE_CHECK")["private_format"], "tar")
        new = self.predeploy()
        self.assertEqual(fields(new / "MANIFEST")["private_link_source"], "none")

    def test_a_tree_set_older_than_a_newer_archive_set_is_still_the_one_shared(self):
        tree_set = self.predeploy()
        self.make_tar_set("20991231T000000Z-none")  # newest by name, an archive: never a source
        second = self.predeploy()
        self.assertEqual(fields(second / "MANIFEST")["private_link_source"], tree_set.name)
        self.assertEqual(fields(second / "MANIFEST")["private_copied_files"], "0")

    def test_a_set_without_a_restore_proof_is_never_shared(self):
        # A set whose database proof failed has its tree but no RESTORE_CHECK: the older proven set is used instead.
        first = self.predeploy()
        second = self.predeploy()
        (second / "RESTORE_CHECK").unlink()
        third = self.predeploy()
        self.assertEqual(fields(third / "MANIFEST")["private_link_source"], first.name)

    def test_the_snapshot_shares_with_the_set_the_token_names_not_merely_the_newest_proven_one(self):
        first = self.predeploy()
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        # A newer proven tree set appears after the re-verification (here: a copy of the first, proven in full).
        newer = self.backups / "20991231T000000Z-none"
        subprocess.run(f"tar -C '{self.backups}' -cf - '{first.name}' | tar -C '{self.root}' -xf - && mv '{self.root / first.name}' '{newer}'",
                       shell=True, check=True)
        (newer / "RESTORE_CHECK").unlink()
        run = self.run_helper("restore-check", str(newer))
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        second = self.predeploy(preverify=False)
        manifest = fields(second / "MANIFEST")
        self.assertEqual((manifest["private_link_source"], manifest["private_copied_files"]), (first.name, "0"))

    def test_without_this_operations_re_verification_nothing_is_shared(self):
        self.predeploy()
        second = self.predeploy(preverify=False)
        self.assertEqual(fields(second / "MANIFEST")["private_link_source"], "none")
        self.assertEqual(fields(second / "MANIFEST")["private_linked_files"], "0")

    def test_a_stale_token_is_refused(self):
        first = self.predeploy()
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        lines = self.token().read_text().splitlines()
        header = json.loads(lines[0])
        header["verified_ns"] -= 7 * 3600 * 10**9  # older than the 6-hour link age
        self.token().write_text("\n".join([json.dumps(header, sort_keys=True)] + lines[1:]) + "\n")
        second = self.predeploy(preverify=False)
        manifest = fields(second / "MANIFEST")
        self.assertEqual((manifest["private_link_source"], manifest["private_linked_files"]), ("none", "0"))
        self.assertTrue(first.is_dir())

    def test_sharing_nothing_with_a_verified_source_is_reported(self):
        self.predeploy()
        for path in self.live.rglob("*"):
            if path.is_file():
                os.chmod(path, stat.S_IMODE(path.stat().st_mode))  # same mode: only the ctime moves
        time.sleep(2.1)
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        run = self.run_helper("predeploy")
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("nothing was shared with", run.stdout)


class Refusals(PrivateTreeBase):
    def test_the_live_store_must_hold_only_single_link_regular_files_with_representable_names(self):
        def symlink():
            (self.live / "link").symlink_to(self.live / ".gitignore")
            return self.live / "link"

        def hardlink():
            os.link(self.live / "contracts/c1.pdf", self.live / "contracts/alias.pdf")
            return self.live / "contracts/alias.pdf"

        def fifo():
            os.mkfifo(self.live / "pipe")
            return self.live / "pipe"

        def newline():
            return self.write("bad\nname", b"x")

        for make in (symlink, hardlink, fifo, newline):
            with self.subTest(make.__name__):
                path = make()
                run = self.run_helper("predeploy")
                self.assertNotEqual(run.returncode, 0)
                self.assertIn("private snapshot failed", run.stderr)
                self.assertFalse((self.backups / ".last").exists())
                self.assertEqual(self.sets(), [])
                for failed in self.backups.glob("*.FAILED"):
                    shutil.rmtree(failed)
                path.unlink()
                time.sleep(1.1)

    def test_a_tampered_tree_fails_the_restore_check_and_keeps_no_success_claim(self):
        def content(bk):
            self.corrupt(bk / "private/masters/track-one/master.wav", b"EVIL")

        def extra(bk):
            (bk / "private/extra.bin").write_bytes(b"x")
            os.chmod(bk / "private/extra.bin", 0o600)

        def missing(bk):
            (bk / "private/contracts/c1.pdf").unlink()

        def chmod(bk):
            os.chmod(bk / "private/contracts/c1.pdf", 0o600)  # 0400 recorded

        def extra_dir(bk):
            (bk / "private/empty").mkdir(mode=0o700)

        def missing_dir(bk):
            (bk / "private/processing").rmdir()

        def manifest_line(bk):
            with open(bk / "private.sha256", "ab") as handle:
                handle.write(b"0" * 64 + b"  ./ghost.bin\n")

        for change, reason in ((content, "hash mismatch"), (extra, "names differ"), (missing, "names differ"),
                               (chmod, "index recorded"), (extra_dir, "directories differ"), (missing_dir, "directories differ"),
                               (manifest_line, "names differ")):
            with self.subTest(change.__name__):
                bk = self.predeploy(preverify=False, VASEY_BACKUP_LINK=0)  # its own inodes: nothing shared with the others
                self.assertEqual(fields(bk / "RESTORE_CHECK")["result"], "RESTORE_VERIFIED")
                change(bk)
                run = self.run_helper("restore-check", str(bk))
                self.assertNotEqual(run.returncode, 0, run.stdout)
                self.assertIn(reason, run.stderr)
                self.assertFalse((bk / "RESTORE_CHECK").exists())

    def test_a_widened_live_mode_fails_the_proof_unless_strict_modes_are_off(self):
        # 2026-11-11: two group-writable kit lock files made the deploy's proof refuse the tree. The tree keeps that rule.
        self.write("kits/k1/.write-lock", b"").chmod(0o660)
        run = self.run_helper("predeploy")
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("set tree fail (1 entries outside 0600/0400 files", run.stderr)
        self.assertFalse((self.backups / self.sets()[-1] / "RESTORE_CHECK").exists())
        time.sleep(1.1)
        relaxed = self.predeploy(VASEY_RESTORE_STRICT_MODES=0)
        proof = fields(relaxed / "RESTORE_CHECK")
        self.assertEqual(proof["result"], "RESTORE_VERIFIED")
        self.assertTrue(proof["modes_and_ownership"].startswith("fail (1 entries"), proof["modes_and_ownership"])

    def test_corruption_found_before_the_quiesce_withdraws_every_set_sharing_it_and_the_deploy_refuses(self):
        # Review F1: S1 and S2 share an inode; it is rewritten in place (mtime kept). The re-verification of S2 must not leave S1
        # (same inode, older) to be shared instead, and S1 must lose its proof too. An independent set keeps its proof.
        independent = self.predeploy(preverify=False, VASEY_BACKUP_LINK=0)
        first = self.predeploy(VASEY_BACKUP_LINK=0)  # its own copies, then shared with the next set
        second = self.predeploy()
        self.assertEqual(fields(second / "MANIFEST")["private_link_source"], first.name)
        rel = "masters/track-one/master.wav"
        self.assertEqual(inodes(first / "private")[rel], inodes(second / "private")[rel])
        self.assertNotEqual(inodes(independent / "private")[rel], inodes(second / "private")[rel])
        self.corrupt(second / "private" / rel)
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15)
        self.assertNotEqual(run.returncode, 0, "a deploy refuses before its quiesce")
        self.assertIn("failed re-verification", run.stderr)
        self.assertIn("nothing was stopped", run.stderr)
        self.assertFalse((second / "RESTORE_CHECK").exists())
        self.assertFalse((first / "RESTORE_CHECK").exists(), "the older set shares the corrupt inode")
        self.assertTrue((independent / "RESTORE_CHECK").exists(), "a set with its own copy keeps its proof")
        self.assertFalse(self.token().exists())
        # The next deploy (or tonight's run) re-verifies the newest set that still holds a proof, the independent one, which
        # shares nothing with the live identities recorded later; whatever it shares was just re-hashed.
        third = self.predeploy()
        self.assertEqual(fields(third / "MANIFEST")["private_link_source"], independent.name)
        self.assertEqual(tree(third / "private"), tree(self.live))
        self.assertNotEqual(inodes(third / "private")[rel], inodes(second / "private")[rel])
        self.assertEqual(fields(third / "RESTORE_CHECK")["result"], "RESTORE_VERIFIED")

    def test_a_shared_inode_rewritten_after_the_re_verification_is_copied_not_trusted(self):
        # Review F6: root rewrites the verified inode in place after preverify, mtime restored. Its ctime moved: it is copied.
        first = self.predeploy()
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        rel = "masters/track-one/master.wav"
        self.corrupt(first / "private" / rel)
        second = self.predeploy(preverify=False)
        self.assertEqual(fields(second / "MANIFEST")["private_link_source"], first.name)
        self.assertEqual(fields(second / "MANIFEST")["private_copied_files"], "1")
        self.assertEqual(tree(second / "private"), tree(self.live))
        self.assertEqual(fields(second / "RESTORE_CHECK")["result"], "RESTORE_VERIFIED")

    def test_a_failed_restore_check_invalidates_the_token_that_names_its_set(self):
        first = self.predeploy()
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertTrue(self.token().exists())
        (first / "private/extra.bin").write_bytes(b"x")
        os.chmod(first / "private/extra.bin", 0o600)
        self.assertNotEqual(self.run_helper("restore-check", str(first)).returncode, 0)
        self.assertFalse(self.token().exists())
        (first / "private/extra.bin").unlink()
        second = self.predeploy(preverify=False)
        self.assertEqual(fields(second / "MANIFEST")["private_linked_files"], "0")
        # Also when only the database side of the proof fails (nothing about the tree to withdraw): no token outlives the proof.
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn(f'"set": "{second.name}"', self.token().read_text().splitlines()[0])
        (second / "database.sql.sha256").write_text("0" * 64 + "  database.sql\n")
        self.assertNotEqual(self.run_helper("restore-check", str(second)).returncode, 0)
        self.assertFalse(self.token().exists())

    def test_ship_pending_skips_a_set_whose_proof_was_withdrawn_and_ships_the_others(self):
        # Review F7: rsync to a local directory (no network), the default transport.
        dest = self.root / "dest"
        dest.mkdir(mode=0o700)
        key = self.root / "ssh-key"
        key.write_text("synthetic")
        settings = dict(VASEY_BACKUP_DEST=dest, VASEY_BACKUP_SSH_KEY=key)
        first = self.predeploy(**settings)
        second = self.predeploy(**settings)
        (second / "RESTORE_CHECK").unlink()
        run = self.run_helper("ship-pending", **settings)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn(f"pending set {second.name} has no verified restore proof; skipped", run.stdout)
        self.assertTrue((dest / first.name / "private.sha256").is_file(), "the other set still ships")
        self.assertFalse((dest / second.name).exists())
        marker = fields(self.backups / f".offhost-pending.{second.name}")
        self.assertEqual(marker["last_result"], "skipped")
        self.assertFalse((self.backups / f".offhost-pending.{first.name}").exists())


class SpaceCheck(PrivateTreeBase):
    MARGIN_PCT, MARGIN_BYTES, DB = 20, 268_435_456, 40_000

    def need(self, copy, ship=0):
        base = copy + self.DB + max(self.DB, ship)
        return base + base * self.MARGIN_PCT // 100 + self.MARGIN_BYTES

    def check(self, free, preverify=True, **extra):
        arguments = ("space-check", "--preverify") if preverify else ("space-check",)
        return self.run_helper(*arguments, T_FREE=free, T_DB_BYTES=self.DB, **extra)

    def test_only_the_bytes_the_snapshot_will_copy_are_counted(self):
        total = sum((self.live / f).stat().st_size for f in self.live_files())
        # No set to share with: the whole store.
        self.assertEqual(self.check(self.need(total)).returncode, 0)
        self.assertNotEqual(self.check(self.need(total) - 1).returncode, 0)
        self.predeploy()
        self.write("masters/track-three/master.wav", b"RIFF" + b"d" * 30_000)
        total += 30_004
        run = self.check(self.need(30_004))
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn(f"(30004 of {total} private bytes to copy;", run.stdout)
        self.assertNotEqual(self.check(self.need(30_004) - 1).returncode, 0)
        # Sharing turned off, or no token (this operation did not re-verify): the whole store again.
        self.assertNotEqual(self.check(self.need(30_004), VASEY_BACKUP_LINK=0).returncode, 0)
        # An encrypted shipment stages the WHOLE set after the deploy, shared files included.
        hardened = dict(VASEY_BACKUP_REMOTE="vasey-backup:bucket")
        self.assertEqual(self.check(self.need(30_004, ship=total + self.DB), **hardened).returncode, 0)
        self.assertNotEqual(self.check(self.need(30_004, ship=total + self.DB) - 1, **hardened).returncode, 0)

    def test_the_estimate_matches_what_the_snapshot_copies(self):
        self.predeploy()
        self.write("masters/track-one/stems.zip", b"PK" + b"e" * 4000)  # rewritten (new size)
        self.write("new.bin", b"n" * 123)
        time.sleep(2.1)
        run = self.check(10**15)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("(4125 of", run.stdout)
        second = self.predeploy(preverify=False)
        self.assertEqual(fields(second / "MANIFEST")["private_copied_bytes"], str(4002 + 123))


@unittest.skipUnless(IS_ROOT, "set files are checked for this user's ownership")
class EngineFallbacks(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="va-private-engine-")
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.live = self.root / "live"
        (self.live / "a").mkdir(parents=True, mode=0o700)
        self.live.chmod(0o700)
        for name, size in (("a/one", 1000), ("a/two", 2000), ("three", 3000)):
            (self.live / name).write_bytes(os.urandom(size))
            (self.live / name).chmod(0o600)
        time.sleep(2.1)

    def engine(self, *arguments):
        return subprocess.run([sys.executable, "-I", str(ENGINE), *map(str, arguments)], capture_output=True, text=True)

    def verified_set(self, parent, strict=1):
        parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        bk = parent / "20261110T000000Z-none"
        bk.mkdir(mode=0o700)
        self.assertEqual(self.engine("snapshot", "--live", self.live, "--set", bk).returncode, 0)
        token = parent / ".link-token"
        run = self.engine("preverify", bk, "--token", token, "--backups", parent, "--strict-modes", strict)
        self.assertEqual(run.returncode, 0, run.stderr)
        return bk, token

    def new_set(self, parent):
        parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        bk = parent / "20261111T000000Z-none"
        bk.mkdir(mode=0o700)
        return bk

    def snap(self, target, source, token):
        run = self.engine("snapshot", "--live", self.live, "--set", target, "--link-from", source, "--link-token", token)
        self.assertEqual(run.returncode, 0, run.stderr)
        return dict(line.split("=", 1) for line in run.stdout.splitlines())

    def second_filesystem(self):
        mount = self.root / "other-fs"
        mount.mkdir()
        if subprocess.run(["mount", "-t", "tmpfs", "-o", "size=16m,mode=0700", "tmpfs", str(mount)], capture_output=True).returncode == 0:
            self.addCleanup(subprocess.run, ["umount", str(mount)], capture_output=True)
            return mount
        shm = Path("/dev/shm")
        if shm.is_dir() and shm.stat().st_dev != self.root.stat().st_dev:
            other = Path(tempfile.mkdtemp(prefix="va-private-engine-", dir=shm))
            self.addCleanup(shutil.rmtree, other)
            return other
        self.skipTest("no second filesystem: tmpfs cannot be mounted and /dev/shm shares the device")

    def test_a_previous_set_on_another_filesystem_is_never_linked(self):
        other = self.second_filesystem()
        self.assertNotEqual(other.stat().st_dev, self.root.stat().st_dev)
        source, token = self.verified_set(other / "backups")
        target = self.new_set(self.root / "backups")
        result = self.snap(target, source, token)
        self.assertEqual((result["linked_files"], result["copied_files"], result["link_source"]), ("0", "3", "none"))
        self.assertIn("another filesystem", result["link_refused"])
        self.assertEqual(tree(target / "private"), tree(self.live))

    def test_a_link_the_kernel_refuses_falls_back_to_a_copy(self):
        backups = self.root / "backups"
        source, token = self.verified_set(backups)
        target = self.new_set(backups)
        original = engine.os.link

        def refuse(*args, **kwargs):
            raise OSError(errno.EXDEV, "cross-device link")

        engine.os.link = refuse
        try:
            with contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(engine.main(["snapshot", "--live", str(self.live), "--set", str(target), "--link-from", str(source),
                                              "--link-token", str(token)]), 0)
        finally:
            engine.os.link = original
        index = [json.loads(l) for l in (target / "private.index").read_text().splitlines()[1:]]
        self.assertEqual({e["how"] for e in index}, {"copy"})
        self.assertEqual(tree(target / "private"), tree(self.live))

    def test_a_link_that_lands_on_another_inode_refuses_the_snapshot(self):
        # The source name is swapped between the decision and the link: the new name is not the verified inode.
        backups = self.root / "backups"
        source, token = self.verified_set(backups)
        target = self.new_set(backups)
        original = engine.os.link

        def swap_then_link(src, dst, *args, **kwargs):
            directory = kwargs["src_dir_fd"]
            data = os.read(os.open(src, os.O_RDONLY, dir_fd=directory), 1 << 20)
            twin = os.open("twin", os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=directory)
            os.write(twin, data)
            os.close(twin)
            os.replace("twin", src, src_dir_fd=directory, dst_dir_fd=directory)
            return original(src, dst, *args, **kwargs)

        engine.os.link = swap_then_link
        try:
            with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()) as err:
                self.assertEqual(engine.main(["snapshot", "--live", str(self.live), "--set", str(target), "--link-from", str(source),
                                              "--link-token", str(token)]), 1)
        finally:
            engine.os.link = original
        self.assertIn("not the verified inode", err.getvalue())

    def test_a_source_file_owned_by_someone_else_is_never_linked(self):
        backups = self.root / "backups"
        parent = backups
        parent.mkdir(parents=True, mode=0o700)
        bk = parent / "20261110T000000Z-none"
        bk.mkdir(mode=0o700)
        self.assertEqual(self.engine("snapshot", "--live", self.live, "--set", bk).returncode, 0)
        os.chown(bk / "private/three", 65534, 65534)
        token = parent / ".link-token"
        run = self.engine("preverify", bk, "--token", token, "--backups", parent, "--strict-modes", 0)
        self.assertEqual(run.returncode, 0, run.stderr)
        result = self.snap(self.new_set(backups), bk, token)
        self.assertEqual((result["linked_files"], result["copied_files"]), ("2", "1"))

    def test_a_change_recorded_too_close_to_the_previous_snapshot_is_copied(self):
        backups = self.root / "backups"
        source, token = self.verified_set(backups)
        index = (source / "private.index").read_text().splitlines()
        header = json.loads(index[0])
        newest = max(json.loads(l)["src"][2] for l in index[1:])
        header["started_ns"] = newest + 1_000_000_000  # the files changed one second before that snapshot started
        (source / "private.index").write_text("\n".join([json.dumps(header, sort_keys=True)] + index[1:]) + "\n")
        result = self.snap(self.new_set(backups), source, token)
        self.assertEqual(result["linked_files"], "0")

    def test_a_token_for_another_set_is_refused(self):
        backups = self.root / "backups"
        source, token = self.verified_set(backups)
        lines = token.read_text().splitlines()
        header = json.loads(lines[0])
        header["set"] = "20261109T000000Z-none"
        token.write_text("\n".join([json.dumps(header, sort_keys=True)] + lines[1:]) + "\n")
        result = self.snap(self.new_set(backups), source, token)
        self.assertEqual(result["linked_files"], "0")
        self.assertIn("names another set", result["link_refused"])

    def test_a_file_changed_after_the_listing_is_refused_before_anything_is_read_or_written(self):
        out = self.root / "out"
        out.mkdir()
        walked = os.lstat(self.live / "three")
        time.sleep(0.01)
        with open(self.live / "three", "ab") as handle:
            handle.write(b"more")
        src, dst = os.open(self.live, os.O_RDONLY | os.O_DIRECTORY), os.open(out, os.O_RDONLY | os.O_DIRECTORY)
        try:
            with self.assertRaises(engine.Refused):
                engine.copy_file(src, "three", dst, walked)
        finally:
            os.close(src)
            os.close(dst)
        self.assertEqual(list(out.iterdir()), [], "nothing is created for a file that is not the listed inode")

    def test_a_file_swapped_for_a_fifo_after_the_listing_is_refused_without_blocking(self):
        out = self.root / "out"
        out.mkdir()
        walked = os.lstat(self.live / "three")
        (self.live / "three").unlink()
        os.mkfifo(self.live / "three")
        src, dst = os.open(self.live, os.O_RDONLY | os.O_DIRECTORY), os.open(out, os.O_RDONLY | os.O_DIRECTORY)
        started = time.monotonic()
        try:
            with self.assertRaises(engine.Refused):
                engine.copy_file(src, "three", dst, walked)
        finally:
            os.close(src)
            os.close(dst)
        self.assertLess(time.monotonic() - started, 5)
        self.assertEqual(list(out.iterdir()), [])

    def test_a_directory_swapped_for_a_symlink_after_the_listing_is_never_followed(self):
        outside = self.root / "outside"
        outside.mkdir(mode=0o700)
        (outside / "secret").write_bytes(b"SECRET-OUTSIDE-THE-STORE")
        target = self.new_set(self.root / "backups")
        original = engine._open_dir

        def swap(parent_fd, name):
            if name == "a" and not (self.live / "a").is_symlink():
                os.rename(self.live / "a", self.root / "moved-a")
                os.symlink(outside, self.live / "a")
            return original(parent_fd, name)

        engine._open_dir = swap
        try:
            with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()) as err:
                self.assertEqual(engine.main(["snapshot", "--live", str(self.live), "--set", str(target)]), 1)
        finally:
            engine._open_dir = original
        self.assertIn("changed during the walk", err.getvalue())
        found = [p for p in target.rglob("*") if p.is_file() and p.read_bytes() == b"SECRET-OUTSIDE-THE-STORE"]
        self.assertEqual(found, [])

    def test_a_short_read_while_hashing_is_refused(self):
        directory = os.open(self.live, os.O_RDONLY | os.O_DIRECTORY)
        st = os.lstat(self.live / "three")
        original = engine.os.readv
        engine.os.readv = lambda fd, buffers: 0
        try:
            with self.assertRaises(engine.Refused):
                engine.hash_file(directory, "three", (st.st_dev, st.st_ino))
        finally:
            engine.os.readv = original
            os.close(directory)

    def test_the_token_records_each_verified_inode(self):
        source, token = self.verified_set(self.root / "backups")
        entries = [json.loads(l) for l in token.read_text().splitlines()[1:]]
        self.assertEqual(len(entries), 3)
        for entry in entries:
            st = os.lstat(source / "private" / entry["p"][2:])
            self.assertEqual(entry["id"], [st.st_dev, st.st_ino, st.st_size, st.st_mtime_ns, stat.S_IMODE(st.st_mode), st.st_uid,
                                           st.st_ctime_ns])
        self.assertFalse(list(source.glob("private.verified*")), "nothing is written into the set by the re-verification")


class Wiring(unittest.TestCase):
    """Where the re-verification runs: before every quiesce, never inside the maintenance window."""

    def test_prepare_refresh_and_the_nightly_run_reverify_before_they_quiesce_and_activate_does_not(self):
        ctl = (REPO / "ops/staging/bin/vasey-staging-ctl").read_text()
        for action in ("prepare", "refresh"):
            body = ctl[ctl.index(f"cmd_{action}() {{"):]
            body = body[:body.index("\n}\n")]
            self.assertLess(body.index("cmd_space_check --preverify"), body.index("cmd_quiesce"), action)
            self.assertLess(body.index("cmd_space_check --preverify"), body.index(f"operation_begin {action}"), action)
        activate = ctl[ctl.index("cmd_activate() {"):]
        activate = activate[:activate.index("\n}\n")]
        self.assertNotIn("--preverify", activate, "activate runs while prepare's quiesce holds the site down")
        self.assertIn('"$BACKUP_BIN" space-check ${1:+"$1"}', ctl)
        nightly = SOURCE[SOURCE.index("  nightly)"):SOURCE.index("  predeploy)")]
        self.assertLess(nightly.index("preverify_link_source ||"), nightly.index('"$CTL" quiesce'))
        predeploy = SOURCE[SOURCE.index("  predeploy)"):SOURCE.index("  space-check)")]
        self.assertIn('restore_check "$(cat "$VASEY_BACKUP_DIR/.last")" incremental', predeploy)
        self.assertNotIn("preverify", "\n".join(l for l in predeploy.splitlines() if not l.lstrip().startswith("#")))

    def test_the_engine_is_installed_and_pinned_with_the_other_helpers(self):
        provision = (REPO / "ops/staging/provision.sh").read_text()
        deploy = (REPO / "ops/staging/forge-deploy.sh").read_text()
        self.assertIn('install -m 0644 -o root -g root "$KIT/private-snapshot.py" /usr/local/libexec/vasey-staging/private-snapshot.py', provision)
        self.assertIn("required private-snapshot.py /usr/local/libexec/vasey-staging/private-snapshot.py", deploy)
        self.assertIn("PRIVATE_SNAPSHOT=/usr/local/libexec/vasey-staging/private-snapshot.py", SOURCE)



class OperationalErrors(PrivateTreeBase):
    """Review round 3, R1: an error about the host or the run is not evidence about a backup and withdraws nothing."""

    def eio_engine(self):
        # ENOMEM: an error about the host. (EIO reading a set file is a finding about that file since 2026-11-11, Q1.)
        wrapper = self.root / "engine-with-enomem.py"
        wrapper.write_text(
            "import errno, os, runpy, sys\n"
            "def failing(fd, buffers):\n"
            "    raise OSError(errno.ENOMEM, 'synthetic out of memory')\n"
            "os.readv = failing\n"
            f"sys.argv = [{str(ENGINE)!r}] + sys.argv[1:]\n"
            f"runpy.run_path({str(ENGINE)!r}, run_name='__main__')\n")
        return wrapper

    def assert_operational_refusal(self, run, *sets):
        self.assertEqual(run.returncode, 3, run.stdout + run.stderr)
        self.assertIn("an operational error, not a corruption finding", run.stdout)
        self.assertIn("No restore proof was withdrawn", run.stdout)
        self.assertIn("operational refusal", run.stderr)
        self.assertNotIn("failed its private file re-verification", run.stdout)
        self.assertNotIn("withdrew the restore proof", run.stdout)
        for bk in sets:
            self.assertEqual(fields(bk / "RESTORE_CHECK")["result"], "RESTORE_VERIFIED", bk.name)
        self.assertFalse(self.token().is_file())

    def test_a_token_that_cannot_be_written_refuses_the_deploy_and_withdraws_nothing(self):
        first = self.predeploy()
        second = self.predeploy()
        self.token().mkdir()  # stands in for ENOSPC or EIO while writing the token
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15)
        self.assert_operational_refusal(run, first, second)
        self.assertIn("token could not be written", run.stdout)
        self.token().rmdir()
        self.assertEqual(list(self.backups.glob(".link-token.new.*")), [], "no temporary token is left behind")
        # Once the cause is gone the next deploy re-verifies and shares as usual.
        third = self.predeploy()
        self.assertEqual(fields(third / "MANIFEST")["private_link_source"], second.name)

    def test_an_operational_os_error_during_the_re_hash_refuses_the_deploy_and_withdraws_nothing(self):
        first = self.predeploy()
        second = self.predeploy()
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15, PRIVATE_SNAPSHOT=self.eio_engine())
        self.assert_operational_refusal(run, first, second)
        self.assertIn("ENOMEM", run.stdout)
        self.assertIn("the proof could not run", run.stdout)

    def test_a_real_mismatch_still_withdraws_and_is_reported_as_corruption(self):
        first = self.predeploy()
        self.corrupt(first / "private/masters/track-one/master.wav")
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
        self.assertIn("failed its private file re-verification", run.stdout)
        self.assertIn("corruption refusal", run.stderr)
        self.assertNotIn("operational error", run.stdout)
        self.assertFalse((first / "RESTORE_CHECK").exists())

    def test_the_engine_exit_codes_tell_the_two_apart(self):
        first = self.predeploy()
        token = self.root / "token-dir"
        token.mkdir()
        run = subprocess.run([sys.executable, "-I", str(ENGINE), "preverify", str(first), "--token", str(token),
                              "--backups", str(self.backups)], capture_output=True, text=True)
        self.assertEqual(run.returncode, 3, run.stderr)
        self.assertNotIn("withdraw=", run.stdout)
        self.corrupt(first / "private/contracts/c1.pdf", b"X")
        run = subprocess.run([sys.executable, "-I", str(ENGINE), "preverify", str(first), "--token", str(self.root / "token"),
                              "--backups", str(self.backups)], capture_output=True, text=True)
        self.assertEqual(run.returncode, 1, run.stderr)
        self.assertIn(f"withdraw={first.name}", run.stdout)


class NarrowWithdrawals(PrivateTreeBase):
    """Review round 3, R2: a mode or owner change withdraws only the sets that hold the changed file."""

    def test_a_widened_file_in_one_set_only_withdraws_that_set(self):
        first = self.predeploy()
        self.write("new.bin", b"n" * 10)
        time.sleep(2.1)
        second = self.predeploy()
        os.chmod(second / "private/new.bin", 0o640)  # only in the second set
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
        self.assertFalse((second / "RESTORE_CHECK").exists())
        self.assertTrue((first / "RESTORE_CHECK").exists(), "the older set does not hold that file")

    def test_a_widened_mode_recorded_in_one_set_only_withdraws_that_set(self):
        # The mode was already wide when the snapshot copied it (strict modes off then): the strict re-proof refuses it as a
        # mode finding, and only the set holding that file loses its proof.
        first = self.predeploy()
        self.write("new.bin", b"n" * 10).chmod(0o640)
        time.sleep(2.1)
        second = self.predeploy(VASEY_RESTORE_STRICT_MODES=0)
        self.assertEqual(fields(second / "MANIFEST")["private_link_source"], first.name)
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
        self.assertIn("set tree fail", run.stdout)
        self.assertFalse((second / "RESTORE_CHECK").exists())
        self.assertTrue((first / "RESTORE_CHECK").exists(), "the older set does not hold that file")

    def test_a_widened_shared_file_withdraws_every_set_that_holds_it(self):
        first = self.predeploy()
        second = self.predeploy()
        os.chmod(second / "private/masters/track-one/stems.zip", 0o640)  # the same inode is in the first set
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
        self.assertFalse((second / "RESTORE_CHECK").exists())
        self.assertFalse((first / "RESTORE_CHECK").exists())


class TokenAndWalkerChecks(PrivateTreeBase):
    """Review round 3, R3 and R4."""

    def test_a_token_whose_set_lost_its_proof_shares_nothing(self):
        first = self.predeploy()
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertTrue(self.token().is_file())
        (first / "RESTORE_CHECK").unlink()
        second = self.predeploy(preverify=False)
        manifest = fields(second / "MANIFEST")
        self.assertEqual((manifest["private_link_source"], manifest["private_linked_files"]), ("none", "0"))


@unittest.skipUnless(IS_ROOT, "set files are checked for this user's ownership")
class EngineRound3(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="va-private-engine3-")
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.live = self.root / "live"
        for sub in ("a", "b"):
            (self.live / sub).mkdir(parents=True, mode=0o700)
            (self.live / sub / "file").write_bytes(sub.encode() * 100)
            (self.live / sub / "file").chmod(0o600)
        self.live.chmod(0o700)
        time.sleep(2.1)

    def test_a_directory_swapped_for_another_real_directory_after_the_listing_is_refused(self):
        # O_NOFOLLOW does not catch this one: only the inode comparison with the listing does.
        target = self.root / "set"
        target.mkdir(mode=0o700)
        original = engine._open_dir

        def swap(parent_fd, name):
            if name == "a" and not (self.root / "moved-a").exists():
                os.rename(self.live / "a", self.root / "moved-a")
                os.rename(self.live / "b", self.live / "a")
            return original(parent_fd, name)

        engine._open_dir = swap
        try:
            with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()) as err:
                self.assertEqual(engine.main(["snapshot", "--live", str(self.live), "--set", str(target)]), 1)
        finally:
            engine._open_dir = original
        self.assertIn("changed during the walk", err.getvalue())

    def test_a_linked_inode_whose_size_or_mtime_moved_since_the_decision_refuses_the_snapshot(self):
        backups = self.root / "backups"
        backups.mkdir(mode=0o700)
        source = backups / "20261110T000000Z-none"
        source.mkdir(mode=0o700)
        run = subprocess.run([sys.executable, "-I", str(ENGINE), "snapshot", "--live", str(self.live), "--set", str(source)],
                             capture_output=True, text=True)
        self.assertEqual(run.returncode, 0, run.stderr)
        token = backups / ".link-token"
        run = subprocess.run([sys.executable, "-I", str(ENGINE), "preverify", str(source), "--token", str(token),
                              "--backups", str(backups)], capture_output=True, text=True)
        self.assertEqual(run.returncode, 0, run.stderr)
        target = backups / "20261111T000000Z-none"
        target.mkdir(mode=0o700)
        original = engine.os.link

        def touch_then_link(src, dst, *args, **kwargs):
            os.utime(src, ns=(0, 10**9), dir_fd=kwargs["src_dir_fd"], follow_symlinks=False)  # same inode, another mtime
            return original(src, dst, *args, **kwargs)

        engine.os.link = touch_then_link
        try:
            with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()) as err:
                self.assertEqual(engine.main(["snapshot", "--live", str(self.live), "--set", str(target), "--link-from", str(source),
                                              "--link-token", str(token)]), 1)
        finally:
            engine.os.link = original
        self.assertIn("not the verified inode", err.getvalue())

def wrapped_engine(root, body, name="engine-wrapper.py"):
    """An engine run through a wrapper that first applies `body` (a failure injection), then runs the real file."""
    wrapper = Path(root) / name
    wrapper.write_text("import errno, os, runpy, sys\n" + body + "\n"
                       f"sys.argv = [{str(ENGINE)!r}] + sys.argv[1:]\n"
                       f"runpy.run_path({str(ENGINE)!r}, run_name='__main__')\n")
    return wrapper


class FollowUps(PrivateTreeBase):
    """2026-11-11 follow-ups after PR #335 went live: G1 (early space gate), Q1-Q3."""

    def check(self, *arguments, **extra):
        return self.run_helper("space-check", *arguments, T_DB_BYTES=40_000, **extra)

    def need(self, copy, ship=0, db=40_000):
        base = copy + db + max(db, ship)
        return base + base * 20 // 100 + 268_435_456

    def test_g1_a_deploy_that_fits_only_with_sharing_is_not_refused_by_the_early_gate(self):
        total = sum((self.live / f).stat().st_size for f in self.live_files())
        self.predeploy()
        self.write("masters/new/master.wav", b"RIFF" + b"z" * 20_000)
        total += 20_004
        free = self.need(20_004)  # enough with sharing, far too little for a full copy
        self.assertLess(free, self.need(total))
        run = self.check("--preverify", T_FREE=free)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("estimate: sharing with the re-verified set", run.stdout)
        # Without the re-hash (what the early gate used to run) the same deploy is refused: it counts the whole store.
        (self.token()).unlink()
        run = self.check(T_FREE=free)
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("estimate: no re-verified set to share with: the whole private store is counted", run.stderr)

    def test_g1_a_deploy_that_does_not_fit_even_with_sharing_is_refused_naming_the_estimate(self):
        self.predeploy()
        self.write("masters/new/master.wav", b"RIFF" + b"z" * 20_000)
        run = self.check("--preverify", T_FREE=self.need(20_004) - 1)
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("not enough free disk space for the pre-deploy snapshot", run.stderr)
        self.assertIn("estimate: sharing with the re-verified set", run.stderr)
        self.assertIn("20004 of", run.stderr)
        self.assertIn("nothing was stopped", run.stderr)
        hardened = self.check("--preverify", T_FREE=self.need(20_004) - 1, VASEY_BACKUP_REMOTE="vasey-backup:bucket")
        self.assertIn("one encrypted copy of the whole set", hardened.stderr)

    def test_g1_prepare_reuses_the_deploys_token_and_re_hashes_only_without_one(self):
        self.predeploy()
        run = self.check("--preverify", T_FREE=10**15)
        self.assertIn("re-verified the private files of", run.stdout)
        run = self.check("--preverify-if-needed", T_FREE=10**15)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertNotIn("re-verified the private files of", run.stdout, "the deploy's token is reused, not re-hashed again")
        self.assertIn("estimate: sharing with the re-verified set", run.stdout)
        self.token().unlink()
        run = self.check("--preverify-if-needed", T_FREE=10**15)
        self.assertIn("re-verified the private files of", run.stdout, "without a token, prepare re-hashes itself")

    def test_q1_a_truncated_index_is_a_finding_about_that_set_only(self):
        first = self.predeploy()
        second = self.predeploy()
        (second / "private.index").write_text('{"format": "vasey-private-index-v2", "started_ns": ')
        run = self.check("--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
        self.assertIn("cannot be parsed", run.stdout)
        self.assertFalse((second / "RESTORE_CHECK").exists())
        self.assertTrue((first / "RESTORE_CHECK").exists(), "no inode of another set is implicated")

    def test_q1_an_io_error_reading_a_shared_file_withdraws_every_set_holding_it(self):
        independent = self.predeploy(preverify=False, VASEY_BACKUP_LINK=0)
        first = self.predeploy(VASEY_BACKUP_LINK=0)
        second = self.predeploy()
        eio = wrapped_engine(self.root, "def failing(fd, buffers):\n    raise OSError(errno.EIO, 'synthetic EIO')\nos.readv = failing\n")
        run = self.check("--preverify", T_FREE=10**15, PRIVATE_SNAPSHOT=eio)
        self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
        self.assertIn("I/O error reading a set file", run.stdout)
        self.assertFalse((second / "RESTORE_CHECK").exists())
        self.assertFalse((first / "RESTORE_CHECK").exists(), "it holds the same inode")
        self.assertTrue((independent / "RESTORE_CHECK").exists())

    def test_q2_a_crash_of_the_engine_is_operational_and_withdraws_nothing(self):
        first = self.predeploy()
        crash = wrapped_engine(self.root, "import os as _os\n_real = _os.scandir\n"
                               "def deep(*a, **k):\n    raise RecursionError('maximum recursion depth exceeded')\n_os.scandir = deep\n")
        run = self.check("--preverify", T_FREE=10**15, PRIVATE_SNAPSHOT=crash)
        self.assertEqual(run.returncode, 3, run.stdout + run.stderr)
        self.assertIn("stopped unexpectedly (RecursionError)", run.stdout)
        self.assertIn("an operational error, not a corruption finding", run.stdout)
        self.assertTrue((first / "RESTORE_CHECK").exists())

    def test_q2_exit_1_without_named_sets_is_not_taken_as_corruption(self):
        first = self.predeploy()
        bare = wrapped_engine(self.root, "sys.stderr.write('private-snapshot: something odd\\n'); sys.exit(1)\n")
        run = self.check("--preverify", T_FREE=10**15, PRIVATE_SNAPSHOT=bare)
        self.assertEqual(run.returncode, 3, run.stdout + run.stderr)
        self.assertNotIn("withdrew the proof of: none", run.stdout)
        self.assertTrue((first / "RESTORE_CHECK").exists())

    def test_q3_a_widened_mode_on_a_shared_file_withdraws_its_holders_and_spares_the_rest(self):
        # S0 shares the ordinary files with S1/S2 but not the widened one; S1 and S2 hold it. The strict re-proof refuses the mode
        # (not an identity change: the mode was recorded that way), and only the sets holding that inode lose their proof.
        s0 = self.predeploy()
        self.write("kits/k2/.write-lock", b"").chmod(0o660)
        time.sleep(2.1)
        loose = dict(VASEY_RESTORE_STRICT_MODES=0)
        s1 = self.predeploy(**loose)
        s2 = self.predeploy(**loose)
        held = "kits/k2/.write-lock"
        self.assertEqual(inodes(s1 / "private")[held], inodes(s2 / "private")[held])
        self.assertTrue(set(inodes(s0 / "private").values()) & set(inodes(s2 / "private").values()), "S0 shares other files")
        run = self.check("--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
        self.assertIn("set tree fail", run.stdout)
        self.assertFalse((s2 / "RESTORE_CHECK").exists())
        self.assertFalse((s1 / "RESTORE_CHECK").exists())
        self.assertTrue((s0 / "RESTORE_CHECK").exists(), "S0 does not hold the widened file")

    def test_q3_a_chmod_of_one_shared_file_in_s2_withdraws_s1_too(self):
        s0 = self.predeploy(VASEY_BACKUP_LINK=0)
        self.write("masters/only-later.wav", b"RIFF" + b"q" * 50)
        time.sleep(2.1)
        s1 = self.predeploy(VASEY_BACKUP_LINK=0)
        s2 = self.predeploy()
        os.chmod(s2 / "private/masters/track-one/stems.zip", 0o640)  # the same inode is in S1
        run = self.check("--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
        self.assertFalse((s2 / "RESTORE_CHECK").exists())
        self.assertFalse((s1 / "RESTORE_CHECK").exists())
        self.assertTrue((s0 / "RESTORE_CHECK").exists())


class ReviewBatch337(PrivateTreeBase):
    """Unanswered review findings on PR #335/#337 (2026-11-12): the rsync copy's modes, the token's age, and which errors are
    findings about which sets."""

    def check(self, *arguments, **extra):
        return self.run_helper("space-check", *arguments, T_DB_BYTES=40_000, **extra)

    def linked_pair(self):
        """S1 (its own copies) and S2 (sharing every unchanged file with S1), both proven."""
        s1 = self.predeploy(VASEY_BACKUP_LINK=0)
        s2 = self.predeploy()
        self.assertEqual(fields(s2 / "MANIFEST")["private_link_source"], s1.name)
        return s1, s2

    # Finding 1: the plain rsync shipment.
    def test_f1_an_rsync_off_host_copy_keeps_the_recorded_modes_and_passes_the_full_proof(self):
        dest = self.root / "dest"
        dest.mkdir(mode=0o700)
        key = self.root / "ssh-key"
        key.write_text("synthetic")
        _, s2 = self.linked_pair()
        run = self.run_helper("ship", str(s2), VASEY_BACKUP_DEST=dest, VASEY_BACKUP_SSH_KEY=key)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        copy = dest / s2.name
        self.assertEqual(stat.S_IMODE(copy.stat().st_mode), 0o700, "the copy is reachable by its owner only")
        self.assertEqual(stat.S_IMODE((copy / "private/.gitignore").stat().st_mode), 0o644)
        self.assertEqual(stat.S_IMODE((copy / "private/contracts/c1.pdf").stat().st_mode), 0o400)
        self.assertEqual(tree(copy / "private", seconds=True), tree(s2 / "private", seconds=True))
        run = self.run_helper("restore-check", str(copy), VASEY_BACKUP_DIR=dest)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertEqual(fields(copy / "RESTORE_CHECK")["private_proof"], "full")
        # The proof still notices a real permission change on a private file of the copy.
        os.chmod(copy / "private/contracts/c1.pdf", 0o600)
        run = self.run_helper("restore-check", str(copy), VASEY_BACKUP_DIR=dest)
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("index recorded", run.stderr)

    def test_f1_a_set_directory_that_is_not_owner_only_is_not_shipped(self):
        dest = self.root / "dest"
        dest.mkdir(mode=0o700)
        key = self.root / "ssh-key"
        key.write_text("synthetic")
        s1 = self.predeploy()
        s1.chmod(0o755)
        run = self.run_helper("ship", str(s1), VASEY_BACKUP_DEST=dest, VASEY_BACKUP_SSH_KEY=key)
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("owner-only", run.stderr)
        self.assertFalse((dest / s1.name).exists())

    # Finding 2: a token older than the link age.
    def age_token(self, hours):
        lines = self.token().read_text().splitlines()
        header = json.loads(lines[0])
        header["verified_ns"] -= int(hours * 3600 * 10**9)
        self.token().write_text("\n".join([json.dumps(header, sort_keys=True)] + lines[1:]) + "\n")

    def test_f2_a_stale_token_is_not_reused_the_set_is_re_hashed_and_shared(self):
        self.predeploy()
        self.write("masters/new/master.wav", b"RIFF" + b"z" * 20_000)
        run = self.check("--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.age_token(7)  # an aborted deploy left it 7 hours ago; the link age is 6
        run = self.check("--preverify-if-needed", T_FREE=10**15)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("re-verified the private files of", run.stdout, "a stale token must not stand in for a re-hash")
        self.assertIn("estimate: sharing with the re-verified set", run.stdout)
        self.assertIn("(20004 of", run.stdout)
        second = self.predeploy(preverify=False)
        self.assertEqual(fields(second / "MANIFEST")["private_copied_files"], "1")

    def test_f2_the_estimate_names_a_basis_only_when_something_is_shared(self):
        self.predeploy()
        run = self.check("--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        lines = self.token().read_text().splitlines()
        self.token().write_text(lines[0] + "\n{not json\n")  # the header still names a proven set; the body is unusable
        run = self.check(T_FREE=10**15)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertNotIn("sharing with the re-verified set", run.stdout)
        total = sum((self.live / f).stat().st_size for f in self.live_files())
        self.assertIn(f"({total} of {total} private bytes to copy; estimate: the re-verification token of", run.stdout)
        self.assertIn("was refused: the whole private store is counted", run.stdout)

    # Finding 3a: a set's own manifests.
    def test_f3a_an_unreadable_own_manifest_withdraws_that_set_only(self):
        def empty_index(bk):
            (bk / "private.index").write_bytes(b"")

        def empty_sha256(bk):
            (bk / "private.sha256").write_bytes(b"")

        def truncated_sha256(bk):
            data = (bk / "private.sha256").read_bytes()
            (bk / "private.sha256").write_bytes(data[:40])

        def malformed_entry(bk):
            lines = (bk / "private.index").read_text().splitlines()
            entry = json.loads(lines[1])
            del entry["how"]
            (bk / "private.index").write_text("\n".join([lines[0], json.dumps(entry, sort_keys=True)] + lines[2:]) + "\n")

        for damage in (empty_index, empty_sha256, truncated_sha256, malformed_entry):
            with self.subTest(damage.__name__):
                for name in self.sets():
                    shutil.rmtree(self.backups / name)
                s1, s2 = self.linked_pair()
                damage(s2)
                run = self.check("--preverify", T_FREE=10**15)
                self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
                self.assertFalse((s2 / "RESTORE_CHECK").exists())
                self.assertTrue((s1 / "RESTORE_CHECK").exists(), "no inode S1 holds was shown to be bad")
                time.sleep(1.1)

    # Finding 3b: one rule for I/O errors.
    def test_f3b_an_io_error_opening_the_sets_tree_or_listing_a_directory_is_operational(self):
        for label, body in (
                ("open private/", "_open = os.open\n"
                                  "def failing(path, *a, **k):\n"
                                  "    if isinstance(path, str) and path.endswith('/private'):\n"
                                  "        raise OSError(errno.EIO, 'synthetic EIO')\n"
                                  "    return _open(path, *a, **k)\nos.open = failing\n"),
                ("open a directory", "_open = os.open\n"
                                     "def failing(path, flags, *a, **k):\n"
                                     "    if path == 'masters' and flags & os.O_DIRECTORY:\n"
                                     "        raise OSError(errno.EIO, 'synthetic EIO')\n"
                                     "    return _open(path, flags, *a, **k)\nos.open = failing\n"),
                ("list a directory", "_scandir = os.scandir\n"
                                     "def failing(*a, **k):\n"
                                     "    raise OSError(errno.EUCLEAN, 'synthetic EUCLEAN')\nos.scandir = failing\n")):
            with self.subTest(label):
                for name in self.sets():
                    shutil.rmtree(self.backups / name)
                s1, s2 = self.linked_pair()
                engine_file = wrapped_engine(self.root, body)
                run = self.check("--preverify", T_FREE=10**15, PRIVATE_SNAPSHOT=engine_file)
                self.assertEqual(run.returncode, 3, run.stdout + run.stderr)
                self.assertIn("an operational error, not a corruption finding", run.stdout)
                self.assertIn(f"restore-check {s2}", run.stdout)
                self.assertTrue((s2 / "RESTORE_CHECK").exists())
                self.assertTrue((s1 / "RESTORE_CHECK").exists())
                time.sleep(1.1)

    def test_f3b_ebadmsg_or_euclean_reading_a_shared_file_is_a_finding_for_every_holder(self):
        for code in ("EBADMSG", "EUCLEAN"):
            with self.subTest(code):
                for name in self.sets():
                    shutil.rmtree(self.backups / name)
                independent = self.predeploy(preverify=False, VASEY_BACKUP_LINK=0)
                s1, s2 = self.linked_pair()
                failing = wrapped_engine(self.root, f"def failing(fd, buffers):\n    raise OSError(errno.{code}, 'synthetic')\n"
                                                    "os.readv = failing\n")
                run = self.check("--preverify", T_FREE=10**15, PRIVATE_SNAPSHOT=failing)
                self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
                self.assertIn("I/O error reading a set file", run.stdout)
                self.assertFalse((s2 / "RESTORE_CHECK").exists())
                self.assertFalse((s1 / "RESTORE_CHECK").exists())
                self.assertTrue((independent / "RESTORE_CHECK").exists())
                time.sleep(1.1)

    def test_f3b_the_engine_maps_errno_classes_the_same_way_everywhere(self):
        s1 = self.predeploy()
        for errname, expected in (("EIO", 3), ("EBADMSG", 3), ("EUCLEAN", 3), ("ENOENT", 1), ("ELOOP", 1)):
            with self.subTest(errname):
                body = ("_open = os.open\n"
                        "def failing(path, *a, **k):\n"
                        "    if isinstance(path, str) and path.endswith('/private'):\n"
                        f"        raise OSError(errno.{errname}, 'synthetic')\n"
                        "    return _open(path, *a, **k)\nos.open = failing\n")
                run = subprocess.run([sys.executable, "-I", str(wrapped_engine(self.root, body)), "verify", str(s1), "--full",
                                      "--backups", str(self.backups)], capture_output=True, text=True)
                self.assertEqual(run.returncode, expected, run.stdout + run.stderr)
                self.assertEqual("withdraw=" in run.stdout, expected == 1, run.stdout)
        # A content error reading the set's own manifest is a finding about that set alone.
        s2 = self.predeploy()
        body = ("_open = os.open\n"
                "def failing(path, *a, **k):\n"
                "    if isinstance(path, str) and path.endswith('/private.sha256'):\n"
                "        raise OSError(errno.EUCLEAN, 'synthetic')\n"
                "    return _open(path, *a, **k)\nos.open = failing\n")
        run = subprocess.run([sys.executable, "-I", str(wrapped_engine(self.root, body)), "verify", str(s2), "--full",
                              "--backups", str(self.backups)], capture_output=True, text=True)
        self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
        self.assertEqual(run.stdout.split(), [f"withdraw={s2.name}"], "S1 shares its inodes but none was shown to be bad")

    # Finding 4: the restore-check path (verify --full) with a shared inode.
    def test_f4_a_corrupt_or_chmodded_shared_file_found_by_restore_check_withdraws_both_holders(self):
        rel = "masters/track-one/stems.zip"
        for damage in ("corrupt", "chmod"):
            with self.subTest(damage):
                for name in self.sets():
                    shutil.rmtree(self.backups / name)
                independent = self.predeploy(preverify=False, VASEY_BACKUP_LINK=0)
                s1, s2 = self.linked_pair()
                self.assertEqual(inodes(s1 / "private")[rel], inodes(s2 / "private")[rel])
                if damage == "corrupt":
                    self.corrupt(s1 / "private" / rel)
                else:
                    os.chmod(s1 / "private" / rel, 0o640)
                run = self.run_helper("restore-check", str(s1))
                self.assertNotEqual(run.returncode, 0)
                self.assertFalse((s1 / "RESTORE_CHECK").exists())
                self.assertFalse((s2 / "RESTORE_CHECK").exists(), "S2 holds the same inode")
                self.assertTrue((independent / "RESTORE_CHECK").exists())
                time.sleep(1.1)


class DeployGate(unittest.TestCase):
    """G1 and Q4 in ops/staging/forge-deploy.sh: one authoritative space gate, before anything is stopped, reported truthfully."""

    DEPLOY = (REPO / "ops/staging/forge-deploy.sh").read_text()
    CTL = (REPO / "ops/staging/bin/vasey-staging-ctl").read_text()

    def test_the_early_gate_is_the_authoritative_one_and_runs_before_anything_is_stopped(self):
        self.assertNotIn('"${CTL[@]}" space-check ||', self.DEPLOY, "a plain early check counts a full copy")
        for anchor in ('"${CTL[@]}" prepare', '"${CTL[@]}" refresh'):
            action = self.DEPLOY.index(anchor)
            gate = self.DEPLOY.rindex('"${CTL[@]}" space-check --preverify', 0, action)
            started = self.DEPLOY.rindex("ACTIVATION_STARTED=1", 0, action)
            self.assertLess(gate, started, anchor)
            self.assertNotIn("ACTIVATION_STARTED=1", self.DEPLOY[self.DEPLOY.rindex("\n", 0, gate):gate])
        for action in ("prepare", "refresh"):
            body = self.CTL[self.CTL.index(f"cmd_{action}() {{"):]
            body = body[:body.index("\n}\n")]
            check = body.index("cmd_space_check --preverify-if-needed")
            for later in (f"operation_begin {action}", "cmd_quiesce"):
                self.assertLess(check, body.index(later), (action, later))
            if action == "prepare":
                self.assertLess(check, body.index("cmd_allocate"))
        self.assertIn('[ "$#" = 2 ] && [ "$2" = --preverify ]', self.CTL)
        self.assertIn('space-check) cmd_space_check "${2:-}" ;;', self.CTL)

    def run_deploy_tail(self, fail_at):
        """Runs the real new-release branch of forge-deploy.sh from the early gate through `ctl prepare`, with its real on_exit,
        against a stub ctl that fails at FAIL_AT."""
        source = self.DEPLOY
        on_exit = source[source.index("on_exit() {"):source.index("trap on_exit EXIT")]
        start = source.index("# Prepare quiesces, and activation then takes a snapshot")
        tail = source[start:source.index('[ "$(stat -c %d:%i "$REL/storage/app/private")"', start)]
        with tempfile.TemporaryDirectory() as directory:
            script = ("set -Eeuo pipefail\nACTIVATION_STARTED=0\nENV_SNAPSHOT=''\n"
                      "die() { echo \"forge-deploy: ERROR: $*\" >&2; exit 1; }\nstep() { :; }\n"
                      "ctl_stub() { echo \"$1\" >> \"$TRACE\"; [ \"$1\" != \"$FAIL_AT\" ]; }\nCTL=(ctl_stub)\n"
                      + on_exit + "trap on_exit EXIT\n" + tail + "\necho REACHED-AFTER-PREPARE\n")
            trace = Path(directory) / "trace"
            run = subprocess.run(["bash", "-c", script], capture_output=True, text=True,
                                 env={"PATH": "/usr/bin:/bin", "TRACE": str(trace), "FAIL_AT": fail_at, "ROOT": directory,
                                      "STAMP": "20261111T000000Z", "SHA": "a" * 40, "RUNTIME_ENV": "/dev/null",
                                      "EVIDENCE": str(Path(directory) / "evidence")})
            return run, (trace.read_text().split() if trace.exists() else [])

    def test_a_refusal_by_the_gate_says_nothing_was_touched(self):
        run, calls = self.run_deploy_tail("space-check")
        self.assertNotEqual(run.returncode, 0)
        self.assertEqual(calls, ["space-check"])
        self.assertIn("refused before the quiesce by the backup space/re-verification gate", run.stderr)
        self.assertIn("before the quiesce: the served release was not touched", run.stderr)
        self.assertNotIn("unconfirmed", run.stderr)

    def test_a_failure_once_prepare_started_still_says_state_is_unconfirmed(self):
        run, calls = self.run_deploy_tail("prepare")
        self.assertNotEqual(run.returncode, 0)
        self.assertEqual(calls, ["space-check", "prepare"])
        self.assertIn("service and writer state is unconfirmed", run.stderr)
        self.assertIn("establish quiesce before recovery", run.stderr)
        self.assertNotIn("the served release was not touched", run.stderr)

if __name__ == "__main__":
    unittest.main()
