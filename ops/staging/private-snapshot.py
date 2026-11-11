#!/usr/bin/env python3
"""The private-store part of a backup set, as a directory tree that shares unchanged files with the previous set.

Installed by provision.sh into the kit's libexec directory (root:root 0644, the same file for staging and production) and run
only by the backup helper, as root, with `python3 -I`:

    preverify SET --token TOKEN --backups DIR [--strict-modes 0|1]
        Re-hashes every file of SET (the site is still up). Success writes TOKEN, outside every set: the identity of each
        verified inode, which is what a snapshot may then share. A proof failure (content, identity, names, directories, modes)
        prints `withdraw=NAME` for SET and for every set in DIR that holds an inode that failed, and exits 1. An operational
        error (I/O, no space, an unreadable or unparseable file, a token that cannot be written) withdraws nothing and exits 3.

Exit status of every command: 0 success, 1 the set or the store failed a check (Refused), 3 operational error (OSError,
ValueError): the helper must not treat 3 as evidence about a backup's content.
    snapshot --live PRIVATE --set SET [--link-from SOURCE_SET --link-token TOKEN] [--link-max-age-hours H]
        Writes SET/private/ (a copy of PRIVATE), SET/private.sha256, SET/private.dirs and SET/private.index.
    verify SET [--full] [--backups DIR] [--strict-modes 0|1]
        Proves SET/private/ against its manifests. Without --full (the proof inside the maintenance window, right after the
        snapshot) it re-hashes every file the snapshot copied and requires every shared file to be the exact inode the snapshot
        linked (device, inode, size, mtime, mode, owner and ctime). With --full (a manual re-proof, a set moved to another disk
        or extracted from an off-host copy) it re-hashes every file and compares size, mode and the mtime in whole seconds only,
        because a copy has new inodes and a tar archive keeps whole seconds. --backups prints `withdraw=NAME` lines as above.
    estimate --live PRIVATE [--link-from SOURCE_SET --link-token TOKEN] [--link-max-age-hours H]
        Prints the bytes a snapshot would copy (the rest it would link), without writing anything.

Why a tree with hard links. A deploy snapshot used to tar the whole private store (~38 GB on 2026-11-11) and the restore proof
extracted and re-hashed all of it, about 21 of the 22 minutes the site was down. The store is mostly write-once media, but not
entirely: reservation counters, lock files and Flysystem `put` rewrite files in place (same inode). So:

  * Nothing in a set is ever a hard link to the LIVE store. Every file of the live store that changed since the previous set is
    COPIED (a new inode, owned by root, in a root-only 0700 tree), so a later in-place write to a live file cannot reach any
    snapshot. The live walk refuses any live file with more than one link, as the tar snapshot did.
  * Every directory and file is opened relative to its parent's descriptor with O_NOFOLLOW and must be the inode the listing
    reported, so a directory swapped for a symlink (or a file for a FIFO) after the listing is refused, never followed or
    waited on.
  * A live file whose identity (device, inode, size, mtime and ctime in nanoseconds) is exactly what the previous set recorded
    when it copied it is unchanged: every write, truncate, chmod, utime or replacement of a file changes its ctime, which no
    unprivileged process can set back. Such a file is not copied again; the new set gets a hard link to the previous SET's
    inode. Hard links are only ever made between two set trees of the same backup directory, on one filesystem.
  * A file is linked only from the one set that the current operation re-hashed in full before its quiesce (the TOKEN names
    it), and only while that inode still has exactly the identity, ctime included, that the re-hash recorded. Any write to it
    since, by anyone, changes its ctime. Linking and unlinking change it too, which only makes a file be copied again. Anything
    else (no token, another filesystem, a recent change, a stale token) is copied: the fallback is always the full copy.
  * Deleting a set (retention) unlinks only its own names: an inode shared with a newer set keeps its data until the last
    name is gone, so pruning can never change a newer set.

The manifests keep the formats of the tar sets: private.sha256 is exactly what `sha256sum` would print for `./path` names in
byte order, and private.dirs the sorted directory list, so the runbook's `sha256sum --check` works on a restored tree.
Directory mtimes are not preserved (tar sets kept them); file mtimes are.
"""
import argparse
import errno
import hashlib
import json
import os
import re
import stat
import sys
import time

INDEX_FORMAT = "vasey-private-index-v2"
TOKEN_FORMAT = "vasey-private-link-token-v1"
SET_NAME = re.compile(r"^20[0-9]{6}T[0-9]{6}Z-([0-9a-f]{12}|none)$")
# A live file whose ctime is this close to (or after) the start of the snapshot that recorded it is never trusted as unchanged:
# a writer the quiesce did not stop (a manual console command) could have changed it within the same timestamp tick.
RACY_NS = 2_000_000_000
FUTURE_SLACK_S = 300
CHUNK = 1 << 20
DIR_FLAGS = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
# O_NONBLOCK: a name swapped for a FIFO after the listing must be refused at fstat, not block the quiesced host forever.
FILE_FLAGS = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC


class Refused(Exception):
    pass


EXIT_REFUSED = 1
EXIT_OPERATIONAL = 3


class Operational(Exception):
    """An error about the host or the run, not about a backup's content: it never withdraws a proof."""


class Mismatch(Refused):
    """Content or identity of particular set inodes is wrong (as opposed to an unreadable or malformed set)."""

    def __init__(self, message, inodes):
        super().__init__(message)
        self.inodes = inodes


def key(rel):
    return os.fsencode(rel)


def identity(st):
    return [st.st_dev, st.st_ino, st.st_size, st.st_mtime_ns, st.st_ctime_ns]


def full_identity(st):
    """What a shared inode must still be: any write, chmod, chown, utime, link or unlink changes the ctime."""
    return [st.st_dev, st.st_ino, st.st_size, st.st_mtime_ns, stat.S_IMODE(st.st_mode), st.st_uid, st.st_ctime_ns]


def check_name(rel):
    # sha256sum escapes these, which the restore check (and `cut -c67-` in older sets) cannot represent: fail closed.
    if "\n" in rel or "\\" in rel:
        raise Refused("a private file or directory name contains a newline or a backslash; rename it, then retry")


def parent_of(rel):
    return rel.rsplit("/", 1)[0]


def _open_dir(parent_fd, name):
    return os.open(name, DIR_FLAGS, dir_fd=parent_fd)


def _open_file(parent_fd, name):
    return os.open(name, FILE_FLAGS, dir_fd=parent_fd)


def open_root(path, what):
    try:
        return os.open(path, DIR_FLAGS)
    except OSError as error:
        raise Refused(f"{what} is missing, not a directory or a symlink ({errno.errorcode.get(error.errno, error.errno)})")


class Walker:
    """Depth-first walk under a directory descriptor. Each subdirectory is opened from its parent's descriptor with O_NOFOLLOW
    and must be the inode the listing reported. Callbacks: on_dir(rel, stat, fd), on_file(rel, stat, parent_fd, name),
    on_leave(rel). The live store may hold only directories and single-link regular files."""

    def __init__(self, live, on_dir, on_file, on_leave=None):
        self.live, self.on_dir, self.on_file, self.on_leave = live, on_dir, on_file, on_leave or (lambda rel: None)

    def run(self, root_fd):
        self.on_dir(".", os.fstat(root_fd), root_fd)
        self._walk(root_fd, ".")
        self.on_leave(".")

    def _walk(self, dir_fd, rel):
        with os.scandir(dir_fd) as listing:
            entries = sorted(listing, key=lambda e: key(e.name))
        for entry in entries:
            child = rel + "/" + entry.name
            check_name(child)
            st = entry.stat(follow_symlinks=False)
            if stat.S_ISDIR(st.st_mode):
                try:
                    fd = _open_dir(dir_fd, entry.name)
                except OSError:
                    raise Refused("a private directory changed during the walk (replaced by a link or removed); nothing was published")
                try:
                    opened = os.fstat(fd)
                    if (opened.st_dev, opened.st_ino) != (st.st_dev, st.st_ino) or not stat.S_ISDIR(opened.st_mode):
                        raise Refused("a private directory changed during the walk; nothing was published")
                    self.on_dir(child, opened, fd)
                    self._walk(fd, child)
                    self.on_leave(child)
                finally:
                    os.close(fd)
            elif stat.S_ISREG(st.st_mode) and (st.st_nlink == 1 or not self.live):
                self.on_file(child, st, dir_fd, entry.name)
            else:
                raise Refused("unmanifested entry (symlink, hard link or special file) in "
                              + ("private storage" if self.live else "the set's private tree"))


def read_jsonl(path, expected_format):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(fd, "r", encoding="utf-8") as handle:
        lines = handle.read().splitlines()
    if not lines:
        raise Refused(f"{os.path.basename(path)} is empty")
    header = json.loads(lines[0])
    if not isinstance(header, dict) or header.get("format") != expected_format:
        raise Refused(f"{os.path.basename(path)} has an unknown format")
    entries = {}
    for line in lines[1:]:
        entry = json.loads(line)
        if not isinstance(entry, dict) or not isinstance(entry.get("p"), str) or entry["p"] in entries:
            raise Refused(f"{os.path.basename(path)} has a malformed or duplicate entry")
        entries[entry["p"]] = entry
    return header, entries


def write_atomic(path, data):
    temporary = f"{path}.new.{os.getpid()}"
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    try:
        try:
            view = memoryview(data)
            while view:
                view = view[os.write(fd, view):]
            os.fsync(fd)
        finally:
            os.close(fd)
        os.replace(temporary, path)
    except BaseException:
        try:
            os.unlink(temporary)
        except OSError:
            pass
        raise


def report(fields):
    """Prints key=value lines (sorted) for the backup helper: MANIFEST and RESTORE_CHECK take them as they are."""
    for name in sorted(fields):
        value = "none" if fields[name] is None else fields[name]
        print(f"{name}={value}")


def jsonl(header, entries):
    return ("\n".join([json.dumps(header, sort_keys=True)] + [json.dumps(e, sort_keys=True) for e in entries]) + "\n").encode()


def hash_file(parent_fd, name, expected):
    """SHA-256 of a set file opened under its directory descriptor, refusing anything but the expected regular inode."""
    fd = _open_file(parent_fd, name)
    try:
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or (st.st_dev, st.st_ino) != expected:
            raise Refused("a set file changed while it was verified")
        digest, buffer, total = hashlib.sha256(), bytearray(CHUNK), 0
        while True:
            n = os.readv(fd, [buffer])
            if not n:
                break
            digest.update(memoryview(buffer)[:n])
            total += n
        if total != st.st_size:
            raise Refused("a set file changed size while it was verified")
        return digest.hexdigest()
    finally:
        os.close(fd)


def copy_file(src_dir_fd, name, dst_dir_fd, walked):
    """Copies one live file into the set as a new inode, hashing it on the way. A name that is no longer the listed regular
    inode is refused before anything is read or created."""
    fd = _open_file(src_dir_fd, name)
    try:
        before = os.fstat(fd)
        if not stat.S_ISREG(before.st_mode) or identity(before) != identity(walked):
            raise Refused("a private file changed during the snapshot (a writer is still active); nothing was published")
        out = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=dst_dir_fd)
        try:
            digest, buffer, total = hashlib.sha256(), bytearray(CHUNK), 0
            while True:
                n = os.readv(fd, [buffer])
                if not n:
                    break
                view = memoryview(buffer)[:n]
                digest.update(view)
                while view:
                    view = view[os.write(out, view):]
                total += n
            if identity(os.fstat(fd)) != identity(walked) or total != walked.st_size:
                raise Refused("a private file changed during the snapshot (a writer is still active); nothing was published")
            os.fchmod(out, stat.S_IMODE(walked.st_mode))
            os.utime(out, ns=(walked.st_atime_ns, walked.st_mtime_ns))
            written = os.fstat(out)
        finally:
            os.close(out)
    finally:
        os.close(fd)
    if (written.st_dev, written.st_ino) == (walked.st_dev, walked.st_ino):
        raise Refused("a set file is the live inode")  # unreachable with O_EXCL; asserted because everything depends on it
    return {"h": digest.hexdigest(), "how": "copy", "id": full_identity(written)}


class Source:
    """The set this operation re-hashed in full (named by its token). Any problem disables linking: the snapshot copies all."""

    def __init__(self, path, token_path, target_dev, max_age_hours, now):
        self.path, self.name, self.reason = path, os.path.basename(path.rstrip("/")), None
        self.index, self.token, self.started_ns = {}, {}, 0
        self.root_fd = self.dir_fd = self.dir_rel = None
        try:
            header, token = read_jsonl(token_path, TOKEN_FORMAT)
            if header.get("set") != self.name:
                raise Refused("the re-verification token names another set")
            age = now - int(header["verified_ns"]) / 1e9
            if not -FUTURE_SLACK_S <= age <= max_age_hours * 3600:
                raise Refused("the re-verification token is stale")
            self.root_fd = open_root(os.path.join(path, "private"), "its private tree")
            if os.fstat(self.root_fd).st_dev != target_dev:
                raise Refused("it is on another filesystem (hard links cannot cross filesystems)")
            index_header, self.index = read_jsonl(os.path.join(path, "private.index"), INDEX_FORMAT)
            self.started_ns, self.token = int(index_header["started_ns"]), token
        except (OSError, ValueError, KeyError, TypeError, Refused) as error:
            self.reason = str(error) if isinstance(error, Refused) else f"its index or token is unreadable ({type(error).__name__})"
            self.index, self.token = {}, {}
            self.close()

    def close(self):
        if self.dir_fd is not None and self.dir_fd != self.root_fd:
            os.close(self.dir_fd)
        if self.root_fd is not None:
            os.close(self.root_fd)
        self.dir_fd = self.root_fd = self.dir_rel = None

    def _directory(self, rel):
        """Descriptor of the source tree's directory rel, opened component by component with O_NOFOLLOW (one cached)."""
        if rel == self.dir_rel:
            return self.dir_fd
        if self.dir_fd is not None and self.dir_fd != self.root_fd:
            os.close(self.dir_fd)
        self.dir_rel = self.dir_fd = None
        fd = self.root_fd
        try:
            for part in [p for p in rel.split("/")[1:] if p]:
                child = _open_dir(fd, part)
                if fd != self.root_fd:
                    os.close(fd)
                fd = child
        except OSError:
            if fd != self.root_fd:
                os.close(fd)
            raise
        self.dir_rel, self.dir_fd = rel, fd
        return fd

    def candidate(self, rel, live_st):
        """(source dir fd, name, stat, hash) when the live file is provably unchanged since the source set copied it and the
        source inode is exactly what this operation's re-hash recorded; else None."""
        recorded, verified = self.index.get(rel), self.token.get(rel)
        if recorded is None or verified is None or self.root_fd is None:
            return None
        try:
            if recorded["src"] != [live_st.st_dev, live_st.st_ino, live_st.st_ctime_ns] or recorded["s"] != live_st.st_size \
                    or recorded["m"] != live_st.st_mtime_ns or int(recorded["src"][2]) >= self.started_ns - RACY_NS:
                return None
            parent, name = self._directory(parent_of(rel)), rel.rsplit("/", 1)[1]
            st = os.stat(name, dir_fd=parent, follow_symlinks=False)
        except (OSError, KeyError, TypeError, ValueError):
            return None
        if not stat.S_ISREG(st.st_mode) or st.st_uid != os.geteuid() or full_identity(st) != verified.get("id") \
                or stat.S_IMODE(st.st_mode) != stat.S_IMODE(live_st.st_mode):
            return None
        return parent, name, st, recorded["h"]


def snapshot(args):
    started_ns = time.time_ns()
    setdir = os.path.abspath(args.set)
    set_fd = open_root(setdir, "the set directory")
    source = None
    try:
        set_st = os.fstat(set_fd)
        if set_st.st_uid != os.geteuid() or stat.S_IMODE(set_st.st_mode) != 0o700:
            raise Refused("the set directory must be an owner-only directory of this user")
        if args.link_from and args.link_token:
            source = Source(args.link_from, args.link_token, set_st.st_dev, args.link_max_age_hours, time.time())
        live_fd = open_root(os.path.abspath(args.live), "private root")
        os.mkdir("private", 0o700, dir_fd=set_fd)
        targets = {".": _open_dir(set_fd, "private")}
        entries, dirs = [], []
        counts = {"copied_files": 0, "copied_bytes": 0, "linked_files": 0, "linked_bytes": 0}

        def on_dir(rel, st, fd):
            dirs.append(rel)
            if rel != ".":
                parent, name = targets[parent_of(rel)], rel.rsplit("/", 1)[1]
                os.mkdir(name, 0o700, dir_fd=parent)
                targets[rel] = _open_dir(parent, name)
            os.fchmod(targets[rel], stat.S_IMODE(st.st_mode))

        def on_leave(rel):
            if rel != ".":
                os.close(targets.pop(rel))

        def on_file(rel, st, parent_fd, name):
            destination = targets[parent_of(rel)]
            record, shared = None, source.candidate(rel, st) if source is not None and source.reason is None else None
            if shared is not None:
                source_dir, source_name, source_st, digest = shared
                try:
                    os.link(source_name, name, src_dir_fd=source_dir, dst_dir_fd=destination, follow_symlinks=False)
                except OSError as error:
                    if error.errno not in (errno.EXDEV, errno.EMLINK, errno.ENOENT, errno.EPERM, errno.EACCES):
                        raise
                    shared = None
                if shared is not None:
                    linked = os.stat(name, dir_fd=destination, follow_symlinks=False)
                    if [linked.st_dev, linked.st_ino, linked.st_size, linked.st_mtime_ns] \
                            != [source_st.st_dev, source_st.st_ino, source_st.st_size, source_st.st_mtime_ns]:
                        raise Refused("a linked set file is not the verified inode")
                    record = {"h": digest, "how": "link"}
                    counts["linked_files"] += 1
                    counts["linked_bytes"] += st.st_size
            if record is None:
                record = copy_file(parent_fd, name, destination, st)
                counts["copied_files"] += 1
                counts["copied_bytes"] += st.st_size
            record.update({"p": rel, "s": st.st_size, "m": st.st_mtime_ns, "mode": stat.S_IMODE(st.st_mode),
                           "src": [st.st_dev, st.st_ino, st.st_ctime_ns]})
            entries.append(record)

        try:
            Walker(True, on_dir, on_file, on_leave).run(live_fd)
            # Each link changed the shared inode's ctime: record every set file's identity once the tree is complete. The
            # proof inside the window requires exactly this identity for each shared file (nothing links or unlinks between).
            for entry in entries:  # this snapshot's own root-only tree, created above
                entry["id"] = full_identity(os.stat(entry["p"][2:], dir_fd=targets["."], follow_symlinks=False))
        finally:
            for fd in targets.values():
                os.close(fd)
            os.close(live_fd)
        if counts["copied_files"]:
            os.sync()  # the copies are the new rollback point: on disk before anything migrates
        entries.sort(key=lambda e: key(e["p"]))
        link_source = source.name if source is not None and source.reason is None else None
        header = {"format": INDEX_FORMAT, "started_ns": started_ns, "files": len(entries), "dirs": len(dirs),
                  "link_source": link_source, **counts}
        write_atomic(os.path.join(setdir, "private.index"), jsonl(header, entries))
        write_atomic(os.path.join(setdir, "private.sha256"), b"".join(e["h"].encode() + b"  " + key(e["p"]) + b"\n" for e in entries))
        write_atomic(os.path.join(setdir, "private.dirs"), b"".join(key(d) + b"\n" for d in sorted(dirs, key=key)))
        summary = dict(counts, files=len(entries), dirs=len(dirs), link_source=link_source)
        if source is not None and source.reason is not None:
            summary["link_refused"] = f"{source.name}: {source.reason}"
        report(summary)
    finally:
        if source is not None:
            source.close()
        os.close(set_fd)


def estimate(args):
    live_fd = open_root(os.path.abspath(args.live), "private root")
    source = None
    try:
        if args.link_from and args.link_token:
            backups_dev = os.stat(os.path.dirname(os.path.abspath(args.link_from))).st_dev
            source = Source(args.link_from, args.link_token, backups_dev, args.link_max_age_hours, time.time())
        totals = {"copy_bytes": 0, "total_bytes": 0, "files": 0}

        def on_file(rel, st, parent_fd, name):
            totals["files"] += 1
            totals["total_bytes"] += st.st_size
            if source is None or source.reason is not None or source.candidate(rel, st) is None:
                totals["copy_bytes"] += st.st_size

        Walker(True, lambda rel, st, fd: None, on_file).run(live_fd)
        report(dict(totals, link_source=source.name if source is not None and source.reason is None else None))
    finally:
        os.close(live_fd)
        if source is not None:
            source.close()


def parse_sha256(path):
    entries = {}
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(fd, "rb") as handle:
        for line in handle.read().splitlines():
            digest, sep, name = line.partition(b"  ")
            if not sep or len(digest) != 64 or any(c not in b"0123456789abcdef" for c in digest):
                raise Refused("private.sha256 has a malformed line")
            rel = os.fsdecode(name)
            if rel in entries:
                raise Refused("private.sha256 names a file twice")
            entries[rel] = digest.decode()
    return entries


def prove(setdir, full, strict_modes):
    """Verifies a set tree. Returns (summary, {rel: full identity}). Raises Mismatch naming the inodes whose content or
    identity is wrong, or Refused when the set is malformed as a whole."""
    _, index = read_jsonl(os.path.join(setdir, "private.index"), INDEX_FORMAT)
    manifest = parse_sha256(os.path.join(setdir, "private.sha256"))
    fd = os.open(os.path.join(setdir, "private.dirs"), os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(fd, "rb") as handle:
        listed_dirs = [os.fsdecode(line) for line in handle.read().splitlines()]
    uid, gid = os.geteuid(), os.getegid()
    files, dirs, widened, bad, identities, widened_inodes = {}, [], [], {}, {}, set()
    counts = {"rehashed_files": 0, "rehashed_bytes": 0, "trusted_linked_files": 0}

    def on_dir(rel, st, fd):
        dirs.append(rel)
        if stat.S_IMODE(st.st_mode) != 0o700 or (st.st_uid, st.st_gid) != (uid, gid):
            widened.append(rel)

    def on_file(rel, st, parent_fd, name):
        files[rel] = st
        entry = index.get(rel)
        if entry is None or rel not in manifest:
            return  # reported as a name difference below
        if stat.S_IMODE(st.st_mode) not in ((0o644,) if rel == "./.gitignore" else (0o600, 0o400)) \
                or (st.st_uid, st.st_gid) != (uid, gid):
            widened.append(rel)
            widened_inodes.add((st.st_dev, st.st_ino))
        try:
            if manifest[rel] != entry["h"]:
                raise Refused("the set's private.sha256 and private.index disagree")
            if full:
                same = [st.st_size, st.st_mtime_ns // 10**9, stat.S_IMODE(st.st_mode)] \
                    == [entry["s"], entry["m"] // 10**9, entry["mode"]]
            else:
                same = full_identity(st) == entry["id"] \
                    and [st.st_size, st.st_mtime_ns, stat.S_IMODE(st.st_mode)] == [entry["s"], entry["m"], entry["mode"]]
            how = entry["how"]
        except (KeyError, TypeError):
            raise Refused("the set's index has a malformed entry")
        if not same:
            bad[(st.st_dev, st.st_ino)] = rel
            return
        if full or how != "link":
            if hash_file(parent_fd, name, (st.st_dev, st.st_ino)) != entry["h"]:
                bad[(st.st_dev, st.st_ino)] = rel
                return
            counts["rehashed_files"] += 1
            counts["rehashed_bytes"] += st.st_size
        else:
            counts["trusted_linked_files"] += 1
        identities[rel] = full_identity(st)

    tree_fd = open_root(os.path.join(setdir, "private"), "the set's private tree")
    try:
        Walker(False, on_dir, on_file).run(tree_fd)
    finally:
        os.close(tree_fd)
    if set(files) != set(index) or set(files) != set(manifest):
        raise Refused("the set's private file names differ from its manifest")
    if sorted(dirs, key=key) != listed_dirs:
        raise Refused("the set's private directories differ from its manifest")
    if bad:
        raise Mismatch("restored file hash mismatch, or a set file is not the inode, size, time or mode its index recorded "
                       f"({len(bad)} file(s))", set(bad))
    mode_result = "pass"
    if widened:
        mode_result = f"fail ({len(widened)} entries outside 0600/0400 files, 0700 dirs or this user's ownership)"
        if strict_modes:
            # Only the sets holding a widened file are affected (a directory is never shared between sets).
            raise Mismatch(f"set tree {mode_result}", widened_inodes)
    return dict(counts, files=len(files), dirs=len(dirs), modes_and_ownership=mode_result,
                proof="full" if full else "incremental"), identities


def set_inodes(setdir):
    inodes = set()
    fd = open_root(os.path.join(setdir, "private"), "a set's private tree")
    try:
        Walker(False, lambda rel, st, d: None, lambda rel, st, parent_fd, name: inodes.add((st.st_dev, st.st_ino))).run(fd)
    finally:
        os.close(fd)
    return inodes


def withdrawals(setdir, backups, error):
    """The sets whose proof no longer holds: this one, and every tree set in backups that shares a failed inode with it (all of
    this set's inodes when the failure is not about particular files)."""
    names = {os.path.basename(setdir.rstrip("/"))}
    if backups is None:
        return names
    suspect = error.inodes if isinstance(error, Mismatch) else None
    if suspect is None:
        try:
            suspect = set_inodes(setdir)
        except (OSError, Refused):
            suspect = set()
    if not suspect:
        return names
    for name in sorted(os.listdir(backups)):
        other = os.path.join(backups, name)
        if name in names or not SET_NAME.match(name) or os.path.islink(other) or not os.path.isdir(os.path.join(other, "private")):
            continue
        try:
            if set_inodes(other) & suspect:
                names.add(name)
        except (OSError, Refused):
            # Not evidence about that set's content: it keeps its proof, and its own re-proof decides.
            continue
    return names


def proved(setdir, full, strict_modes, backups):
    """prove() for verify and preverify: a proof failure prints the withdrawals; anything else is operational."""
    try:
        return prove(setdir, full, strict_modes)
    except Refused as error:
        for name in sorted(withdrawals(setdir, backups, error)):
            print(f"withdraw={name}")
        raise
    except (OSError, ValueError) as error:
        raise Operational(f"the proof could not run ({describe(error)}); this says nothing about the set's content") from error


def verify(args):
    summary, _ = proved(os.path.abspath(args.set), args.full, args.strict_modes, args.backups)
    report(summary)


def preverify(args):
    setdir = os.path.abspath(args.set)
    summary, identities = proved(setdir, True, args.strict_modes, args.backups)
    header = {"format": TOKEN_FORMAT, "set": os.path.basename(setdir.rstrip("/")), "verified_ns": time.time_ns()}
    try:
        write_atomic(args.token, jsonl(header, [{"p": rel, "id": ident}
                                                for rel, ident in sorted(identities.items(), key=lambda item: key(item[0]))]))
    except OSError as error:
        raise Operational(f"the set verified, but its re-verification token could not be written ({describe(error)})") from error
    report(summary)


def describe(error):
    # A path can name private content: report the kind of failure and its errno only.
    code = errno.errorcode.get(error.errno, "") if isinstance(error, OSError) and error.errno else ""
    return type(error).__name__ + (f", {code}" if code else "")


def hours(value):
    number = float(value)
    if not 0 <= number <= 24 * 365:
        raise argparse.ArgumentTypeError("hours must be between 0 and 8760")
    return number


def main(argv):
    parser = argparse.ArgumentParser(prog="private-snapshot.py")
    commands = parser.add_subparsers(dest="command", required=True)
    for name in ("snapshot", "estimate"):
        command = commands.add_parser(name)
        command.add_argument("--live", required=True)
        command.add_argument("--link-from")
        command.add_argument("--link-token")
        command.add_argument("--link-max-age-hours", type=hours, default=6.0)
        if name == "snapshot":
            command.add_argument("--set", required=True)
    command = commands.add_parser("verify")
    command.add_argument("set")
    command.add_argument("--full", action="store_true")
    command.add_argument("--backups")
    command.add_argument("--strict-modes", type=int, choices=(0, 1), default=1)
    command = commands.add_parser("preverify")
    command.add_argument("set")
    command.add_argument("--token", required=True)
    command.add_argument("--backups", required=True)
    command.add_argument("--strict-modes", type=int, choices=(0, 1), default=1)
    args = parser.parse_args(argv)
    try:
        {"snapshot": snapshot, "estimate": estimate, "verify": verify, "preverify": preverify}[args.command](args)
    except Refused as error:
        print(f"private-snapshot: {error}", file=sys.stderr)
        return EXIT_REFUSED
    except Operational as error:
        print(f"private-snapshot: operational error: {error}", file=sys.stderr)
        return EXIT_OPERATIONAL
    except (OSError, ValueError) as error:
        print(f"private-snapshot: operational error: {args.command} failed ({describe(error)})", file=sys.stderr)
        return EXIT_OPERATIONAL
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
