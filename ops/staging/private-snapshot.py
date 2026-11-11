#!/usr/bin/env python3
"""The private-store part of a backup set, as a directory tree that shares unchanged files with the previous set.

Installed by provision.sh into the kit's libexec directory (root:root 0644, the same file for staging and production) and run
only by the backup helper, as root, with `python3 -I`:

    snapshot --live PRIVATE --set SET [--link-from SOURCE_SET] [--link-max-age-hours H]
        Writes SET/private/ (a copy of PRIVATE), SET/private.sha256, SET/private.dirs and SET/private.index.
    verify SET [--rehash-older-than-hours H] [--strict-modes 0|1]
        Proves SET/private/ against its manifests and writes SET/private.verified.
    estimate --live PRIVATE [--link-from SOURCE_SET] [--link-max-age-hours H]
        Prints the bytes a snapshot would copy (the rest it would link), without writing anything.

Why a tree with hard links. A deploy snapshot used to tar the whole private store (~38 GB on 2026-11-11) and the restore proof
extracted and re-hashed all of it, about 21 of the 22 minutes the site was down. The store is mostly write-once media, but not
entirely: reservation counters, lock files and Flysystem `put` rewrite files in place (same inode). So:

  * Nothing in a set is ever a hard link to the LIVE store. Every file of the live store that changed since the previous set is
    COPIED (a new inode, owned by root, in a root-only 0700 tree), so a later in-place write to a live file cannot reach any
    snapshot. The live walk refuses any live file with more than one link, as the tar snapshot did.
  * A live file whose identity (device, inode, size, mtime and ctime in nanoseconds) is exactly what the previous set recorded
    when it copied it is unchanged: every write, truncate, chmod, utime or replacement of a file changes its ctime, which no
    unprivileged process can set back. Such a file is not copied again; the new set gets a hard link to the previous SET's
    inode. Hard links are only ever made between two set trees of the same backup directory, on one filesystem.
  * A file is linked only when the previous set's copy was fully re-hashed against its manifest recently (private.verified,
    by default minutes earlier: the backup helper re-verifies before every quiesce) and its inode still has the identity that
    verification recorded. Anything else (no previous set, another filesystem, a recent change, a stale or failed
    verification) is copied, so the fallback is always the full copy, never a guess.
  * Deleting a set (retention) unlinks only its own names: an inode shared with a newer set keeps its data until the last
    name is gone, so pruning can never change a newer set.

The manifests keep the formats of the tar sets: private.sha256 is exactly what `sha256sum` would print for `./path` names in
byte order, and private.dirs the sorted directory list, so the runbook's `sha256sum --check` works on a restored tree.
"""
import argparse
import errno
import hashlib
import json
import os
import stat
import sys
import time

INDEX_FORMAT = "vasey-private-index-v1"
VERIFIED_FORMAT = "vasey-private-verified-v1"
# A live file whose ctime is this close to (or after) the start of the snapshot that recorded it is never trusted as unchanged:
# a writer the quiesce did not stop (a manual console command) could have changed it within the same timestamp tick.
RACY_NS = 2_000_000_000
# A verification timestamp further in the future than this (a clock stepped back) is not trusted.
FUTURE_SLACK_S = 300
CHUNK = 1 << 20


class Refused(Exception):
    pass


def key(rel):
    return os.fsencode(rel)


def identity(st):
    return [st.st_dev, st.st_ino, st.st_size, st.st_mtime_ns, st.st_ctime_ns]


def check_name(rel):
    # sha256sum escapes these, which the restore check (and `cut -c67-` in older sets) cannot represent: fail closed.
    if "\n" in rel or "\\" in rel:
        raise Refused("a private file or directory name contains a newline or a backslash; rename it, then retry")


def walk(root, live):
    """Returns ({rel: stat} for regular files, {rel: stat} for directories) under root, never following a link.

    The live store must hold only regular files with a single link and directories, as the tar snapshot required."""
    top = os.lstat(root)
    if not stat.S_ISDIR(top.st_mode):
        raise Refused(f"{'private root' if live else 'set tree'} missing or not a directory")
    files, dirs = {}, {".": top}
    pending = [(root, ".")]
    while pending:
        path, rel = pending.pop()
        with os.scandir(path) as entries:
            for entry in entries:
                child = rel + "/" + entry.name
                check_name(child)
                st = entry.stat(follow_symlinks=False)
                if stat.S_ISDIR(st.st_mode):
                    dirs[child] = st
                    pending.append((entry.path, child))
                elif stat.S_ISREG(st.st_mode) and (st.st_nlink == 1 or not live):
                    files[child] = st
                else:
                    raise Refused("unmanifested entry (symlink, hard link or special file) in "
                                  + ("private storage" if live else "the set's private tree"))
    return files, dirs


def read_jsonl(path, expected_format):
    with open(path, "r", encoding="utf-8") as handle:
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
        view = memoryview(data)
        while view:
            view = view[os.write(fd, view):]
        os.fsync(fd)
    finally:
        os.close(fd)
    os.replace(temporary, path)


def report(fields):
    """Prints key=value lines (sorted) for the backup helper: MANIFEST and RESTORE_CHECK take them as they are."""
    for name in sorted(fields):
        value = "none" if fields[name] is None else fields[name]
        print(f"{name}={value}")


def jsonl(header, entries):
    return ("\n".join([json.dumps(header, sort_keys=True)] + [json.dumps(e, sort_keys=True) for e in entries]) + "\n").encode()


def hash_file(path, expected):
    """SHA-256 of a set file, refusing a path that is not the expected inode (an alias or a swapped name)."""
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        st = os.fstat(fd)
        if (st.st_dev, st.st_ino, st.st_size) != expected:
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


class Source:
    """The previous set whose verified inodes the new set may share. Any problem disables linking (full copy)."""

    def __init__(self, path, target_dev, max_age_hours, now):
        self.path, self.name, self.reason = path, os.path.basename(path.rstrip("/")), None
        self.index, self.verified, self.started_ns = {}, {}, 0
        self.oldest, self.now = now - max_age_hours * 3600, now
        try:
            tree = os.lstat(os.path.join(path, "private"))
            if not stat.S_ISDIR(tree.st_mode):
                raise Refused("it has no private tree")
            if tree.st_dev != target_dev:
                raise Refused("it is on another filesystem (hard links cannot cross filesystems)")
            header, self.index = read_jsonl(os.path.join(path, "private.index"), INDEX_FORMAT)
            _, self.verified = read_jsonl(os.path.join(path, "private.verified"), VERIFIED_FORMAT)
            self.started_ns = int(header["started_ns"])
        except (OSError, ValueError, KeyError, TypeError, Refused) as error:
            self.reason = str(error) if isinstance(error, Refused) else f"its index or verification is unreadable ({type(error).__name__})"
            self.index, self.verified = {}, {}

    def candidate(self, rel, live_st):
        """The source set's stat for rel when the live file is provably unchanged and the source copy is verified, else None."""
        recorded, verified = self.index.get(rel), self.verified.get(rel)
        if recorded is None or verified is None:
            return None
        try:
            if recorded["src"] != [live_st.st_dev, live_st.st_ino, live_st.st_ctime_ns] or recorded["s"] != live_st.st_size \
                    or recorded["m"] != live_st.st_mtime_ns or int(recorded["src"][2]) >= self.started_ns - RACY_NS:
                return None
            if verified["h"] != recorded["h"] or not (self.oldest <= float(verified["vt"]) <= self.now + FUTURE_SLACK_S):
                return None
            st = os.lstat(os.path.join(self.path, "private", rel))
        except (OSError, KeyError, TypeError, ValueError):
            return None
        if not stat.S_ISREG(st.st_mode) or st.st_uid != os.geteuid() \
                or [st.st_dev, st.st_ino, st.st_size, st.st_mtime_ns, stat.S_IMODE(st.st_mode)] \
                != [verified.get("dev"), verified.get("ino"), verified.get("s"), verified.get("m"), verified.get("mode")] \
                or stat.S_IMODE(st.st_mode) != stat.S_IMODE(live_st.st_mode):
            return None
        return st


def copy_file(src_path, dst_path, walked, now):
    fd = os.open(src_path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        before = os.fstat(fd)
        if identity(before) != identity(walked):
            raise Refused("a private file changed during the snapshot (a writer is still active); nothing was published")
        out = os.open(dst_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
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
    return {"h": digest.hexdigest(), "dev": written.st_dev, "ino": written.st_ino, "how": "copy", "vt": now}


def snapshot(args):
    started_ns, now = time.time_ns(), time.time()
    live = os.path.abspath(args.live)
    files, dirs = walk(live, live=True)
    setdir = os.path.abspath(args.set)
    set_st = os.lstat(setdir)
    if not stat.S_ISDIR(set_st.st_mode) or set_st.st_uid != os.geteuid() or stat.S_IMODE(set_st.st_mode) != 0o700:
        raise Refused("the set directory must be an owner-only directory of this user")
    tree = os.path.join(setdir, "private")
    os.mkdir(tree, 0o700)
    source = Source(args.link_from, set_st.st_dev, args.link_max_age_hours, now) if args.link_from else None
    for rel in sorted(dirs, key=key):
        path = tree if rel == "." else os.path.join(tree, rel[2:])
        if rel != ".":
            os.mkdir(path, 0o700)
        os.chmod(path, stat.S_IMODE(dirs[rel].st_mode))
    entries, counts = [], {"copied_files": 0, "copied_bytes": 0, "linked_files": 0, "linked_bytes": 0}
    for rel in sorted(files, key=key):
        st, dst = files[rel], os.path.join(tree, rel[2:])
        record, shared = None, source.candidate(rel, st) if source is not None else None
        if shared is not None:
            try:
                os.link(os.path.join(source.path, "private", rel[2:]), dst, follow_symlinks=False)
            except OSError as error:
                if error.errno not in (errno.EXDEV, errno.EMLINK, errno.ENOENT, errno.EPERM, errno.EACCES):
                    raise
                shared = None
            if shared is not None:
                linked = os.lstat(dst)
                if (linked.st_dev, linked.st_ino) != (shared.st_dev, shared.st_ino):
                    raise Refused("a linked set file is not the verified inode")
                verified = source.verified[rel]
                record = {"h": verified["h"], "dev": linked.st_dev, "ino": linked.st_ino, "how": "link", "vt": float(verified["vt"])}
                counts["linked_files"] += 1
                counts["linked_bytes"] += st.st_size
        if record is None:
            record = copy_file(os.path.join(live, rel[2:]), dst, st, now)
            counts["copied_files"] += 1
            counts["copied_bytes"] += st.st_size
        record.update({"p": rel, "s": st.st_size, "m": st.st_mtime_ns, "mode": stat.S_IMODE(st.st_mode),
                       "src": [st.st_dev, st.st_ino, st.st_ctime_ns]})
        entries.append(record)
    if counts["copied_files"]:
        os.sync()  # the copies are the new rollback point: on disk before anything migrates
    header = {"format": INDEX_FORMAT, "started_ns": started_ns, "files": len(entries), "dirs": len(dirs),
              "link_source": source.name if source is not None and source.reason is None else None, **counts}
    write_atomic(os.path.join(setdir, "private.index"), jsonl(header, entries))
    write_atomic(os.path.join(setdir, "private.sha256"), b"".join(e["h"].encode() + b"  " + key(e["p"]) + b"\n" for e in entries))
    write_atomic(os.path.join(setdir, "private.dirs"), b"".join(key(d) + b"\n" for d in sorted(dirs, key=key)))
    summary = dict(counts, files=len(entries), dirs=len(dirs), link_source=header["link_source"])
    if source is not None and source.reason is not None:
        summary["link_refused"] = f"{source.name}: {source.reason}"
    report(summary)


def estimate(args):
    now = time.time()
    files, _ = walk(os.path.abspath(args.live), live=True)
    backups = os.path.dirname(os.path.abspath(args.link_from)) if args.link_from else None
    source = Source(args.link_from, os.lstat(backups).st_dev, args.link_max_age_hours, now) if backups else None
    copy = sum(st.st_size for rel, st in files.items() if source is None or source.candidate(rel, st) is None)
    report({"copy_bytes": copy, "total_bytes": sum(st.st_size for st in files.values()), "files": len(files),
            "link_source": source.name if source is not None and source.reason is None else None})


def parse_sha256(path):
    entries = {}
    with open(path, "rb") as handle:
        for line in handle.read().splitlines():
            digest, sep, name = line.partition(b"  ")
            if not sep or len(digest) != 64 or any(c not in b"0123456789abcdef" for c in digest):
                raise Refused("private.sha256 has a malformed line")
            rel = os.fsdecode(name)
            if rel in entries:
                raise Refused("private.sha256 names a file twice")
            entries[rel] = digest.decode()
    return entries


def verify(args):
    now = time.time()
    setdir = os.path.abspath(args.set)
    _, index = read_jsonl(os.path.join(setdir, "private.index"), INDEX_FORMAT)
    manifest = parse_sha256(os.path.join(setdir, "private.sha256"))
    with open(os.path.join(setdir, "private.dirs"), "rb") as handle:
        listed_dirs = [os.fsdecode(line) for line in handle.read().splitlines()]
    files, dirs = walk(os.path.join(setdir, "private"), live=False)
    if set(files) != set(index) or set(files) != set(manifest):
        raise Refused("the set's private file names differ from its manifest")
    if sorted(dirs, key=key) != listed_dirs:
        raise Refused("the set's private directories differ from its manifest")
    uid, gid = os.geteuid(), os.getegid()
    widened = [rel for rel, st in files.items()
               if stat.S_IMODE(st.st_mode) not in ((0o644,) if rel == "./.gitignore" else (0o600, 0o400))
               or (st.st_uid, st.st_gid) != (uid, gid)]
    widened += [rel for rel, st in dirs.items() if stat.S_IMODE(st.st_mode) != 0o700 or (st.st_uid, st.st_gid) != (uid, gid)]
    oldest_allowed = now - args.rehash_older_than_hours * 3600
    rehashed = rehashed_bytes = trusted = 0
    oldest = now
    verified = []
    for rel in sorted(files, key=key):
        st, entry = files[rel], index[rel]
        try:
            if [st.st_dev, st.st_ino, st.st_size, st.st_mtime_ns, stat.S_IMODE(st.st_mode)] \
                    != [entry["dev"], entry["ino"], entry["s"], entry["m"], entry["mode"]] or manifest[rel] != entry["h"]:
                raise Refused("a set file is not the inode, size, time or mode its index recorded")
            vt = float(entry["vt"])
        except (KeyError, TypeError, ValueError):
            raise Refused("the set's index has a malformed entry")
        if args.rehash_older_than_hours == 0 or entry.get("how") != "link" or not (oldest_allowed <= vt <= now + FUTURE_SLACK_S):
            if hash_file(os.path.join(setdir, "private", rel[2:]), (st.st_dev, st.st_ino, st.st_size)) != entry["h"]:
                raise Refused("restored file hash mismatch in the set's private tree")
            vt = now
            rehashed += 1
            rehashed_bytes += st.st_size
        else:
            trusted += 1
        oldest = min(oldest, vt)
        verified.append({"p": rel, "h": entry["h"], "s": st.st_size, "m": st.st_mtime_ns, "mode": stat.S_IMODE(st.st_mode),
                         "dev": st.st_dev, "ino": st.st_ino, "vt": vt})
    mode_result = "pass"
    if widened:
        mode_result = f"fail ({len(widened)} entries outside 0600/0400 files, 0700 dirs or this user's ownership)"
        if args.strict_modes:
            raise Refused(f"set tree {mode_result}")
    write_atomic(os.path.join(setdir, "private.verified"),
                 jsonl({"format": VERIFIED_FORMAT, "verified_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(now))}, verified))
    report({"files": len(files), "dirs": len(dirs), "rehashed_files": rehashed, "rehashed_bytes": rehashed_bytes,
            "trusted_linked_files": trusted, "oldest_hash_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(oldest)),
            "modes_and_ownership": mode_result})


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
        command.add_argument("--link-max-age-hours", type=hours, default=6.0)
        if name == "snapshot":
            command.add_argument("--set", required=True)
    command = commands.add_parser("verify")
    command.add_argument("set")
    command.add_argument("--rehash-older-than-hours", type=hours, default=0.0)
    command.add_argument("--strict-modes", type=int, choices=(0, 1), default=1)
    args = parser.parse_args(argv)
    try:
        {"snapshot": snapshot, "estimate": estimate, "verify": verify}[args.command](args)
    except Refused as error:
        print(f"private-snapshot: {error}", file=sys.stderr)
        return 1
    except (OSError, ValueError) as error:
        # A path can name private content: report the kind of failure and its errno only.
        print(f"private-snapshot: {args.command} failed ({type(error).__name__}"
              f"{', ' + errno.errorcode.get(error.errno, '') if isinstance(error, OSError) and error.errno else ''})", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
