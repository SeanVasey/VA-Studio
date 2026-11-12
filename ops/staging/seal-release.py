#!/usr/bin/env python3
"""Seal code without following links or retaining application-writable file descriptors.

Two build owners exist. The legacy build runs as the application account inside the maintenance pause, so `seal` trusts
files owned by root or the application. The isolated build (`seal-built`) runs as a dedicated build account while the site
is up: then only root or that account may own anything in the candidate, the release directory stays root 0700 until the
whole walk is done, the private mount target is handed to the application, and the other runtime directories are held
root-owned and read-only (`release-runtime` hands them to the application later, under the activation's quiesce)."""
import os
from pathlib import Path
import pwd
import grp
import secrets
import stat
import sys

ROOT_UID = 0
ROOT_GID = 0
RUNTIME = frozenset(("bootstrap/cache", "storage/framework", "storage/logs", "storage/app/private"))
DIRECTORY_FLAGS = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
PRIVATE_TARGET = "storage/app/private"
HELD = tuple(sorted(RUNTIME - {PRIVATE_TARGET}))


def runtime_path(relative):
    return any(relative == name or relative.startswith(name + "/") for name in RUNTIME)


def checked_link(root, relative):
    target = (root / relative).resolve(strict=True)
    try:
        resolved = target.relative_to(root).as_posix()
    except ValueError as error:
        raise ValueError("code symlink leaves the release") from error
    if resolved == ".env":
        raise ValueError("code symlink points to the protected environment")
    if (relative == "public" or relative.startswith("public/")) and not (
            resolved == "public" or resolved.startswith("public/")):
        raise ValueError("public symlink leaves the public tree")
    if runtime_path(resolved):
        raise ValueError("code symlink points into writable runtime storage")


def copy_file(parent, name, relative, app_uid, app_gid, owner):
    source = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
    staged = ".sealed-" + secrets.token_hex(16)
    output = None
    try:
        before = os.fstat(source)
        if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_uid not in (ROOT_UID, owner):
            raise ValueError("code requires a singly linked regular file with a trusted owner")
        if before.st_mode & (stat.S_ISUID | stat.S_ISGID):
            raise ValueError("privileged executable modes are forbidden")
        output = os.open(staged, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
        os.fchown(output, ROOT_UID, app_gid if relative == ".env" else ROOT_GID)
        while data := os.read(source, 1024 * 1024):
            remaining = memoryview(data)
            while remaining:
                written = os.write(output, remaining)
                if written <= 0:
                    raise OSError("short sealed-file write")
                remaining = remaining[written:]
        after = os.fstat(source)
        if (before.st_size, before.st_mtime_ns, before.st_ctime_ns, before.st_nlink) != (
                after.st_size, after.st_mtime_ns, after.st_ctime_ns, after.st_nlink):
            raise ValueError("source changed during sealing")
        mode = 0o440 if relative == ".env" else (0o555 if before.st_mode & 0o111 else 0o444)
        os.fchmod(output, mode)
        os.fsync(output)
        os.close(output)
        output = None
        # Fresh inode: an old writable application descriptor cannot change the installed file.
        os.replace(staged, name, src_dir_fd=parent, dst_dir_fd=parent)
        os.fsync(parent)
    finally:
        os.close(source)
        if output is not None:
            os.close(output)
        try:
            os.unlink(staged, dir_fd=parent)
        except FileNotFoundError:
            pass


def adopt(descriptor, owner, uid, gid, held):
    """Give every entry below a runtime directory, then the directory itself, to uid:gid (postorder, no links followed).

    Every entry must belong to `owner`; until the directory itself changes hands nobody else can rename inside it. Held
    entries (root-owned) also lose group/world write, so the running application can read but not change them."""
    info = os.fstat(descriptor)
    if info.st_uid != owner:
        raise ValueError("runtime directory has an unexpected owner")
    for name in sorted(os.listdir(descriptor)):
        entry = os.stat(name, dir_fd=descriptor, follow_symlinks=False)
        if entry.st_uid != owner or entry.st_mode & (stat.S_ISUID | stat.S_ISGID):
            raise ValueError("runtime entry has an unexpected owner or mode")
        if stat.S_ISLNK(entry.st_mode):
            os.chown(name, uid, gid, dir_fd=descriptor, follow_symlinks=False)
            continue
        if stat.S_ISDIR(entry.st_mode):
            child = os.open(name, DIRECTORY_FLAGS, dir_fd=descriptor)
        elif stat.S_ISREG(entry.st_mode):
            child = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=descriptor)
        else:
            raise ValueError("special files are forbidden in runtime directories")
        try:
            opened = os.fstat(child)
            if (opened.st_dev, opened.st_ino) != (entry.st_dev, entry.st_ino) or opened.st_dev != info.st_dev:
                raise ValueError("runtime entry changed or crosses a filesystem boundary")
            if stat.S_ISDIR(entry.st_mode):
                adopt(child, owner, uid, gid, held)
            else:
                if opened.st_nlink != 1:
                    raise ValueError("runtime file must be singly linked")
                os.fchown(child, uid, gid)
                if held:
                    os.fchmod(child, stat.S_IMODE(opened.st_mode) & ~0o022 | 0o444)
        finally:
            os.close(child)
    os.fchown(descriptor, uid, gid)
    if held:
        os.fchmod(descriptor, stat.S_IMODE(info.st_mode) & ~0o022 | 0o755)


def verify_held(descriptor):
    """A held runtime tree: root-owned, nothing group/world writable, no links to other inodes, no special files."""
    info = os.fstat(descriptor)
    if info.st_uid != ROOT_UID or info.st_mode & 0o022:
        raise ValueError("held runtime directory is not protected")
    for name in os.listdir(descriptor):
        entry = os.stat(name, dir_fd=descriptor, follow_symlinks=False)
        if entry.st_uid != ROOT_UID or entry.st_mode & (0o022 | stat.S_ISUID | stat.S_ISGID):
            raise ValueError("held runtime entry is not protected")
        if stat.S_ISDIR(entry.st_mode):
            child = os.open(name, DIRECTORY_FLAGS, dir_fd=descriptor)
            try:
                if os.fstat(child).st_dev != info.st_dev:
                    raise ValueError("held runtime crosses a filesystem boundary")
                verify_held(child)
            finally:
                os.close(child)
        elif stat.S_ISREG(entry.st_mode):
            if entry.st_nlink != 1:
                raise ValueError("held runtime file must be singly linked")
        elif not stat.S_ISLNK(entry.st_mode):
            raise ValueError("special files are forbidden in runtime directories")


def walk(root, descriptor, relative, app_uid, app_gid, sealing, build_uid=None):
    info = os.fstat(descriptor)
    # The account that built the candidate. The legacy build (inside the pause) is the application itself.
    owner = app_uid if build_uid is None else build_uid
    if info.st_uid not in ((ROOT_UID, owner) if sealing else (ROOT_UID, app_uid)):
        raise ValueError("unexpected directory owner")
    if relative in RUNTIME:
        if sealing and build_uid is not None:
            if info.st_uid != build_uid:
                raise ValueError("runtime directory must be owned by the build account")
            if relative == PRIVATE_TARGET:
                adopt(descriptor, build_uid, app_uid, app_gid, False)  # the mount target; attach requires it empty
                os.fchmod(descriptor, 0o700)
            else:
                adopt(descriptor, build_uid, ROOT_UID, ROOT_GID, True)  # held until the activation's quiesce
            return
        if info.st_uid == ROOT_UID and relative != PRIVATE_TARGET and not sealing:
            verify_held(descriptor)
            return
        if info.st_uid != app_uid:
            raise ValueError("runtime directory must be owned by the application")
        if sealing:
            os.fchmod(descriptor, 0o700)
        elif info.st_mode & 0o077:
            raise ValueError("runtime directory must remain private")
        return  # In particular, never traverse or change retained private bind-mounted files.
    if sealing:
        os.fchown(descriptor, ROOT_UID, ROOT_GID)
        # Close parent rename authority before opening its children. An isolated build keeps the release itself 0700 until
        # the whole tree is sealed: the live application must not reach any not-yet-sealed directory the build left open.
        os.fchmod(descriptor, 0o700 if build_uid is not None and relative == "" else 0o755)
    elif info.st_uid != ROOT_UID or info.st_mode & 0o022:
        raise ValueError("code directory is not sealed")
    for name in sorted(os.listdir(descriptor)):
        child = name if not relative else relative + "/" + name
        entry = os.stat(name, dir_fd=descriptor, follow_symlinks=False)
        if child == ".env" and not stat.S_ISREG(entry.st_mode):
            raise ValueError("environment must be a regular file")
        if child == ".env" and sealing and build_uid is not None:
            raise ValueError("an isolated build never holds the environment")
        if stat.S_ISDIR(entry.st_mode):
            nested = os.open(name, DIRECTORY_FLAGS, dir_fd=descriptor)
            try:
                if child not in RUNTIME and os.fstat(nested).st_dev != info.st_dev:
                    raise ValueError("unexpected code filesystem boundary")
                walk(root, nested, child, app_uid, app_gid, sealing, build_uid)
            finally:
                os.close(nested)
        elif stat.S_ISLNK(entry.st_mode):
            checked_link(root, child)
            if sealing:
                os.chown(name, ROOT_UID, ROOT_GID, dir_fd=descriptor, follow_symlinks=False)
            elif entry.st_uid != ROOT_UID:
                raise ValueError("code symlink is not sealed")
        elif stat.S_ISREG(entry.st_mode):
            if sealing:
                copy_file(descriptor, name, child, app_uid, app_gid, owner)
            else:
                mode = stat.S_IMODE(entry.st_mode)
                allowed = (0o440,) if child == ".env" else (0o444, 0o555)
                if entry.st_uid != ROOT_UID or entry.st_nlink != 1 or mode not in allowed:
                    raise ValueError("code file is not sealed")
                if child == ".env" and entry.st_gid != app_gid:
                    raise ValueError("protected environment has the wrong application group")
        else:
            raise ValueError("special files are forbidden in code")


def run(root, app_uid, app_gid, sealing, build_uid=None):
    root = Path(root)
    if not root.is_absolute() or root.resolve(strict=True) != root:
        raise ValueError("release must be canonical")
    if build_uid is not None and build_uid in (ROOT_UID, app_uid):
        raise ValueError("the build account must be distinct from root and the application")
    descriptor = os.open(root, DIRECTORY_FLAGS)
    try:
        walk(root, descriptor, "", app_uid, app_gid, sealing, build_uid)
        if sealing and build_uid is not None:
            os.fchmod(descriptor, 0o755)  # every directory below is sealed or held: open the release for reading
    finally:
        os.close(descriptor)
    if sealing:
        run(root, app_uid, app_gid, False)


def seal_release(root, app_uid, app_gid, build_uid=None):
    run(root, app_uid, app_gid, True, build_uid)


def release_runtime(root, app_uid, app_gid):
    """Hand held runtime directories of a sealed isolated build to the application (no-op for a legacy build)."""
    run(root, app_uid, app_gid, False)
    root = Path(root)
    descriptor = os.open(root, DIRECTORY_FLAGS)
    try:
        for relative in HELD:
            parent = os.dup(descriptor)
            try:
                for part in relative.split("/")[:-1]:
                    nested = os.open(part, DIRECTORY_FLAGS, dir_fd=parent)
                    os.close(parent)
                    parent = nested
                held = os.open(relative.split("/")[-1], DIRECTORY_FLAGS, dir_fd=parent)
                try:
                    if os.fstat(held).st_uid == ROOT_UID:
                        verify_held(held)
                        adopt(held, ROOT_UID, app_uid, app_gid, False)
                        os.fchmod(held, 0o700)
                finally:
                    os.close(held)
            finally:
                os.close(parent)
    finally:
        os.close(descriptor)
    run(root, app_uid, app_gid, False)


def stage_environment(base, source, target, app_uid, app_gid):
    base, source = Path(base), Path(source)
    if not base.is_absolute() or base.resolve(strict=True) != base:
        raise ValueError("candidate base must be canonical")
    relative = source.relative_to(base)
    if not relative.parts or any(part in (".", "..") for part in relative.parts):
        raise ValueError("candidate must be inside evidence")
    descriptor = os.open(base, DIRECTORY_FLAGS)
    data_descriptor = output = None
    try:
        for part in relative.parts[:-1]:
            info = os.fstat(descriptor)
            if info.st_uid not in (ROOT_UID, app_uid) or info.st_mode & 0o022:
                raise ValueError("untrusted candidate ancestry")
            nested = os.open(part, DIRECTORY_FLAGS, dir_fd=descriptor)
            os.close(descriptor)
            descriptor = nested
        info = os.fstat(descriptor)
        if info.st_uid not in (ROOT_UID, app_uid) or info.st_mode & 0o022:
            raise ValueError("untrusted candidate ancestry")
        data_descriptor = os.open(relative.parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=descriptor)
        before = os.fstat(data_descriptor)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != app_uid or before.st_nlink != 1
                or stat.S_IMODE(before.st_mode) != 0o600 or before.st_size > 1048576):
            raise ValueError("candidate must be one private application-owned file")
        data = bytearray()
        while chunk := os.read(data_descriptor, 65536):
            data.extend(chunk)
            if len(data) > 1048576:
                raise ValueError("candidate too large")
        after = os.fstat(data_descriptor)
        if (before.st_size, before.st_mtime_ns, before.st_ctime_ns, before.st_nlink) != (
                after.st_size, after.st_mtime_ns, after.st_ctime_ns, after.st_nlink):
            raise ValueError("candidate changed during capture")
        output = os.open(target, os.O_WRONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        destination = os.fstat(output)
        if (not stat.S_ISREG(destination.st_mode) or destination.st_uid != ROOT_UID
                or destination.st_nlink != 1 or stat.S_IMODE(destination.st_mode) != 0o600):
            raise ValueError("target must be a fresh protected temporary file")
        os.ftruncate(output, 0)
        remaining = memoryview(data)
        while remaining:
            written = os.write(output, remaining)
            if written <= 0:
                raise OSError("short environment write")
            remaining = remaining[written:]
        os.fchown(output, ROOT_UID, app_gid)
        os.fchmod(output, 0o440)
        os.fsync(output)
    finally:
        os.close(descriptor)
        if data_descriptor is not None:
            os.close(data_descriptor)
        if output is not None:
            os.close(output)


def main():
    if os.geteuid() != 0:
        raise ValueError("root is required")
    if len(sys.argv) == 7 and sys.argv[1] == "stage-env":
        app_uid = pwd.getpwnam(sys.argv[5]).pw_uid
        if app_uid == 0:
            raise ValueError("application account must be nonroot")
        stage_environment(sys.argv[2], sys.argv[3], sys.argv[4], app_uid, grp.getgrnam(sys.argv[6]).gr_gid)
        return
    if len(sys.argv) == 6 and sys.argv[1] == "seal-built":
        app_uid = pwd.getpwnam(sys.argv[3]).pw_uid
        build_uid = pwd.getpwnam(sys.argv[5]).pw_uid
        if app_uid == 0 or build_uid in (0, app_uid):
            raise ValueError("application and build accounts must be nonroot and distinct")
        seal_release(sys.argv[2], app_uid, grp.getgrnam(sys.argv[4]).gr_gid, build_uid)
        return
    if len(sys.argv) != 5 or sys.argv[1] not in ("seal", "verify", "release-runtime"):
        raise ValueError("root seal/verify requires one release and the configured account")
    app_uid = pwd.getpwnam(sys.argv[3]).pw_uid
    if app_uid == 0:
        raise ValueError("application account must be nonroot")
    if sys.argv[1] == "release-runtime":
        release_runtime(sys.argv[2], app_uid, grp.getgrnam(sys.argv[4]).gr_gid)
        return
    run(sys.argv[2], app_uid, grp.getgrnam(sys.argv[4]).gr_gid, sys.argv[1] == "seal")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, KeyError, RuntimeError):
        print("staging-seal: release refused; no code or private contents printed", file=sys.stderr)
        sys.exit(1)
