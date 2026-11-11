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


class SnapshotAndProof(PrivateTreeBase):
    def test_a_first_snapshot_without_an_earlier_set_copies_everything_and_proves_it(self):
        first = self.predeploy()
        manifest, proof = fields(first / "MANIFEST"), fields(first / "RESTORE_CHECK")
        files = len(self.live_files())
        self.assertEqual(manifest["private_format"], "tree")
        self.assertEqual(manifest["private_link_source"], "none")
        self.assertEqual((manifest["private_copied_files"], manifest["private_linked_files"]), (str(files), "0"))
        self.assertEqual(proof["result"], "RESTORE_VERIFIED")
        self.assertEqual((proof["private_format"], proof["private_rehashed_files"], proof["private_trusted_linked_files"]),
                         ("tree", str(files), "0"))
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

    def test_a_second_snapshot_links_unchanged_files_to_the_previous_set_and_copies_only_new_ones(self):
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
        st = target.stat()
        with open(target, "r+b") as handle:
            handle.write(b"XX")
        os.utime(target, ns=(st.st_atime_ns, st.st_mtime_ns))
        self.assertEqual((target.stat().st_ino, target.stat().st_size, target.stat().st_mtime_ns), (st.st_ino, st.st_size, st.st_mtime_ns))
        self.assertEqual((tree(first / "private"), tree(second / "private")), before, "a live write changed a snapshot")
        time.sleep(2.1)
        third = self.predeploy()
        self.assertEqual(fields(third / "MANIFEST")["private_copied_files"], "1")
        self.assertEqual(digest(third / "private/masters/track-one/stems.zip"), digest(target))
        self.assertNotEqual(digest(third / "private/masters/track-one/stems.zip"), digest(second / "private/masters/track-one/stems.zip"))
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
        self.assertEqual((proof["result"], proof["private_rehashed_files"]), ("RESTORE_VERIFIED", str(len(self.live_files()))))

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

    def test_shipping_a_linked_set_sends_the_whole_set(self):
        # encrypt_set tars the set directory and sizes it with du: both must see every byte, shared files included.
        self.predeploy()
        second = self.predeploy()
        logical = sum(p.stat().st_size for p in second.rglob("*") if p.is_file())
        du = int(subprocess.run(["du", "-sb", "--", str(second)], capture_output=True, text=True).stdout.split()[0])
        self.assertGreaterEqual(du, logical)
        archive = self.root / "set.tar"
        subprocess.run(["tar", "--create", f"--file={archive}", f"--directory={self.backups}", "--", second.name], check=True)
        listing = subprocess.run(["tar", "--list", "--verbose", f"--file={archive}"], capture_output=True, text=True).stdout
        self.assertFalse([line for line in listing.splitlines() if line.startswith("h")], "a hard link entry would lose content")
        out = self.root / "unpacked"
        out.mkdir()
        subprocess.run(["tar", "--extract", f"--file={archive}", f"--directory={out}"], check=True)
        self.assertEqual(tree(out / second.name / "private", seconds=True), tree(self.live, seconds=True))

    def test_linking_can_be_turned_off(self):
        self.predeploy()
        second = self.predeploy(VASEY_BACKUP_LINK=0)
        self.assertEqual(fields(second / "MANIFEST")["private_linked_files"], "0")
        self.assertEqual(fields(second / "MANIFEST")["private_copied_files"], str(len(self.live_files())))

    def test_an_archive_set_from_before_the_change_is_still_proven(self):
        name = "20261101T031700Z-none"
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
        self.assertEqual(fields(old / "RESTORE_CHECK")["private_format"], "tar")
        # It is never a link source: the next snapshot copies everything.
        new = self.predeploy()
        self.assertEqual(fields(new / "MANIFEST")["private_link_source"], "none")


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
            target = bk / "private/masters/track-one/master.wav"
            st = target.stat()
            with open(target, "r+b") as handle:
                handle.write(b"EVIL")
            os.utime(target, ns=(st.st_atime_ns, st.st_mtime_ns))  # same inode, size and mtime: only the bytes say so

        def extra(bk):
            (bk / "private/extra.bin").write_bytes(b"x")
            os.chmod(bk / "private/extra.bin", 0o600)

        def missing(bk):
            (bk / "private/contracts/c1.pdf").unlink()

        def chmod(bk):
            os.chmod(bk / "private/contracts/c1.pdf", 0o644)

        def swapped(bk):
            target = bk / "private/masters/track-one/stems.zip"
            data, st = target.read_bytes(), target.stat()
            twin = target.with_name("stems.zip.twin")
            twin.write_bytes(data)
            twin.chmod(0o600)
            os.utime(twin, ns=(st.st_atime_ns, st.st_mtime_ns))
            os.replace(twin, target)  # identical bytes, size, mode and mtime on another inode: not the recorded file

        for change, reason in ((content, "hash mismatch"), (extra, "names differ"), (missing, "names differ"),
                               (chmod, "index recorded"), (swapped, "index recorded")):
            with self.subTest(change.__name__):
                bk = self.predeploy(preverify=False, VASEY_BACKUP_LINK=0)  # its own inodes: nothing shared with the others
                self.assertEqual(fields(bk / "RESTORE_CHECK")["result"], "RESTORE_VERIFIED")
                change(bk)
                run = self.run_helper("restore-check", str(bk))
                self.assertNotEqual(run.returncode, 0, run.stdout)
                self.assertIn(reason, run.stderr)
                self.assertFalse((bk / "RESTORE_CHECK").exists())
                self.assertFalse((bk / "private.verified").exists())

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

    def test_a_corrupted_shared_file_is_caught_before_the_quiesce_and_never_propagated(self):
        first = self.predeploy()
        corrupt = first / "private/masters/track-one/master.wav"
        st = corrupt.stat()
        with open(corrupt, "r+b") as handle:
            handle.write(b"ROT!")
        os.utime(corrupt, ns=(st.st_atime_ns, st.st_mtime_ns))  # the identity a link decision sees is unchanged
        run = self.run_helper("space-check", "--preverify", T_FREE=10**15)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("failed its private file re-verification", run.stdout)
        self.assertFalse((first / "RESTORE_CHECK").exists(), "a failed reproof keeps no success claim")
        second = self.predeploy(preverify=False)
        self.assertEqual(fields(second / "MANIFEST")["private_link_source"], "none")
        self.assertEqual(tree(second / "private"), tree(self.live))
        self.assertNotEqual(inodes(second / "private")["masters/track-one/master.wav"], (st.st_dev, st.st_ino))

    def test_without_the_pre_quiesce_reverification_a_stale_verification_is_copied_not_trusted(self):
        first = self.predeploy()
        verified = first / "private.verified"
        lines = verified.read_text().splitlines()
        stale = time.time() - 7 * 3600  # older than the default link age (0 + 6 hours)
        verified.write_text("\n".join([lines[0]] + [json.dumps(dict(json.loads(l), vt=stale), sort_keys=True) for l in lines[1:]]) + "\n")
        second = self.predeploy(preverify=False)
        self.assertEqual(fields(second / "MANIFEST")["private_linked_files"], "0")
        self.assertEqual(fields(second / "RESTORE_CHECK")["result"], "RESTORE_VERIFIED")


class SpaceCheck(PrivateTreeBase):
    MARGIN_PCT, MARGIN_BYTES, DB = 20, 268_435_456, 40_000

    def need(self, copy, ship=0):
        base = copy + self.DB + max(self.DB, ship)
        return base + base * self.MARGIN_PCT // 100 + self.MARGIN_BYTES

    def check(self, free, **extra):
        return self.run_helper("space-check", T_FREE=free, T_DB_BYTES=self.DB, **extra)

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
        self.assertIn(f"(30004 of {total} private bytes to copy)", run.stdout)
        self.assertNotEqual(self.check(self.need(30_004) - 1).returncode, 0)
        # Sharing turned off: the whole store again.
        self.assertNotEqual(self.check(self.need(30_004), VASEY_BACKUP_LINK=0).returncode, 0)
        # An encrypted shipment stages the WHOLE set after the deploy, shared files included.
        hardened = dict(VASEY_BACKUP_REMOTE="vasey-backup:bucket")
        self.assertEqual(self.check(self.need(30_004, ship=total + self.DB), **hardened).returncode, 0)
        self.assertNotEqual(self.check(self.need(30_004, ship=total + self.DB) - 1, **hardened).returncode, 0)

    def test_the_estimate_matches_what_the_snapshot_copies(self):
        self.predeploy()
        self.write("masters/track-one/stems.zip", b"PK" + b"e" * 4000)  # replaced (new inode and size)
        self.write("new.bin", b"n" * 123)
        time.sleep(2.1)
        source = self.backups / self.sets()[-1]
        estimate = subprocess.run([sys.executable, "-I", str(ENGINE), "estimate", "--live", str(self.live), "--link-from", str(source)],
                                  capture_output=True, text=True)
        self.assertEqual(estimate.returncode, 0, estimate.stderr)
        second = self.predeploy(preverify=False)
        estimated = dict(line.split("=", 1) for line in estimate.stdout.splitlines())
        self.assertEqual(estimated["copy_bytes"], fields(second / "MANIFEST")["private_copied_bytes"])
        self.assertEqual(estimated["copy_bytes"], str(4002 + 123))


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

    def verified_set(self, parent):
        parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        bk = parent / "20261110T000000Z-none"
        bk.mkdir(mode=0o700)
        self.assertEqual(self.engine("snapshot", "--live", self.live, "--set", bk).returncode, 0)
        self.assertEqual(self.engine("verify", bk).returncode, 0)
        return bk

    def new_set(self, parent):
        parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        bk = parent / "20261111T000000Z-none"
        bk.mkdir(mode=0o700)
        return bk

    def test_a_previous_set_on_another_filesystem_is_never_linked(self):
        shm = Path("/dev/shm")
        if not shm.is_dir() or shm.stat().st_dev == self.root.stat().st_dev:
            self.skipTest("no second filesystem")
        other = Path(tempfile.mkdtemp(prefix="va-private-engine-", dir=shm))
        self.addCleanup(shutil.rmtree, other)
        source = self.verified_set(other)
        target = self.new_set(self.root / "backups")
        run = self.engine("snapshot", "--live", self.live, "--set", target, "--link-from", source)
        self.assertEqual(run.returncode, 0, run.stderr)
        result = dict(line.split("=", 1) for line in run.stdout.splitlines())
        self.assertEqual((result["linked_files"], result["copied_files"], result["link_source"]), ("0", "3", "none"))
        self.assertIn("another filesystem", result["link_refused"])
        self.assertEqual(tree(target / "private"), tree(self.live))

    def test_a_link_the_kernel_refuses_falls_back_to_a_copy(self):
        backups = self.root / "backups"
        source = self.verified_set(backups)
        target = self.new_set(backups)
        original = engine.os.link

        def refuse(*args, **kwargs):
            raise OSError(errno.EXDEV, "cross-device link")

        engine.os.link = refuse
        try:
            with contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(engine.main(["snapshot", "--live", str(self.live), "--set", str(target), "--link-from", str(source)]), 0)
        finally:
            engine.os.link = original
        index = [json.loads(l) for l in (target / "private.index").read_text().splitlines()[1:]]
        self.assertEqual({e["how"] for e in index}, {"copy"})
        self.assertEqual(tree(target / "private"), tree(self.live))

    def test_a_change_recorded_too_close_to_the_previous_snapshot_is_copied(self):
        backups = self.root / "backups"
        source = self.verified_set(backups)
        index = (source / "private.index").read_text().splitlines()
        header = json.loads(index[0])
        newest = max(json.loads(l)["src"][2] for l in index[1:])
        header["started_ns"] = newest + 1_000_000_000  # the files changed one second before that snapshot started
        (source / "private.index").write_text("\n".join([json.dumps(header, sort_keys=True)] + index[1:]) + "\n")
        target = self.new_set(backups)
        run = self.engine("snapshot", "--live", self.live, "--set", target, "--link-from", source)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("linked_files=0\n", run.stdout)

    def test_a_file_that_changes_while_it_is_copied_refuses_the_snapshot(self):
        target = self.root / "out"
        walked = os.lstat(self.live / "three")
        time.sleep(0.01)
        with open(self.live / "three", "ab") as handle:
            handle.write(b"more")
        with self.assertRaises(engine.Refused):
            engine.copy_file(str(self.live / "three"), str(target), walked, time.time())

    def test_verification_records_what_the_next_snapshot_trusts(self):
        backups = self.root / "backups"
        source = self.verified_set(backups)
        verified = [json.loads(l) for l in (source / "private.verified").read_text().splitlines()[1:]]
        for entry in verified:
            st = os.lstat(source / "private" / entry["p"][2:])
            self.assertEqual([entry["dev"], entry["ino"], entry["s"], entry["m"]], [st.st_dev, st.st_ino, st.st_size, st.st_mtime_ns])
            self.assertEqual(entry["h"], digest(source / "private" / entry["p"][2:]))


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
        self.assertLess(nightly.index("space_check --preverify"), nightly.index('"$CTL" quiesce'))
        predeploy = SOURCE[SOURCE.index("  predeploy)"):SOURCE.index("  space-check)")]
        self.assertIn('restore_check "$(cat "$VASEY_BACKUP_DIR/.last")" "$(link_max_age_hours)"', predeploy)
        self.assertNotIn("preverify", "\n".join(l for l in predeploy.splitlines() if not l.lstrip().startswith("#")))

    def test_the_engine_is_installed_and_pinned_with_the_other_helpers(self):
        provision = (REPO / "ops/staging/provision.sh").read_text()
        deploy = (REPO / "ops/staging/forge-deploy.sh").read_text()
        self.assertIn('install -m 0644 -o root -g root "$KIT/private-snapshot.py" /usr/local/libexec/vasey-staging/private-snapshot.py', provision)
        self.assertIn("required private-snapshot.py /usr/local/libexec/vasey-staging/private-snapshot.py", deploy)
        self.assertIn("PRIVATE_SNAPSHOT=/usr/local/libexec/vasey-staging/private-snapshot.py", SOURCE)


if __name__ == "__main__":
    unittest.main()
