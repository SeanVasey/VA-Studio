#!/usr/bin/env python3
"""Seal code without following links or retaining application-writable file descriptors."""
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


def runtime_path(relative):
    return any(relative == name or relative.startswith(name + "/") for name in RUNTIME)


def checked_link(root, relative):
    target = (root / relative).resolve(strict=True)
    try:
        resolved = target.relative_to(root).as_posix()
    except ValueError as error:
        raise ValueError("code symlink leaves the release") from error
    if runtime_path(resolved):
        raise ValueError("code symlink points into writable runtime storage")


def copy_file(parent, name, relative, app_uid, app_gid):
    source = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
    staged = ".sealed-" + secrets.token_hex(16)
    output = None
    try:
        before = os.fstat(source)
        if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_uid not in (ROOT_UID, app_uid):
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


def walk(root, descriptor, relative, app_uid, app_gid, sealing):
    info = os.fstat(descriptor)
    if info.st_uid not in (ROOT_UID, app_uid):
        raise ValueError("unexpected directory owner")
    if relative in RUNTIME:
        if info.st_uid != app_uid:
            raise ValueError("runtime directory must be owned by the application")
        if sealing:
            os.fchmod(descriptor, 0o700)
        elif info.st_mode & 0o077:
            raise ValueError("runtime directory must remain private")
        return  # In particular, never traverse or change retained private bind-mounted files.
    if sealing:
        os.fchown(descriptor, ROOT_UID, ROOT_GID)
        os.fchmod(descriptor, 0o755)  # Close parent rename authority before opening its children.
    elif info.st_uid != ROOT_UID or info.st_mode & 0o022:
        raise ValueError("code directory is not sealed")
    for name in sorted(os.listdir(descriptor)):
        child = name if not relative else relative + "/" + name
        entry = os.stat(name, dir_fd=descriptor, follow_symlinks=False)
        if child == ".env" and not stat.S_ISREG(entry.st_mode):
            raise ValueError("environment must be a regular file")
        if stat.S_ISDIR(entry.st_mode):
            nested = os.open(name, DIRECTORY_FLAGS, dir_fd=descriptor)
            try:
                if child not in RUNTIME and os.fstat(nested).st_dev != info.st_dev:
                    raise ValueError("unexpected code filesystem boundary")
                walk(root, nested, child, app_uid, app_gid, sealing)
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
                copy_file(descriptor, name, child, app_uid, app_gid)
            else:
                mode = stat.S_IMODE(entry.st_mode)
                allowed = (0o440,) if child == ".env" else (0o444, 0o555)
                if entry.st_uid != ROOT_UID or entry.st_nlink != 1 or mode not in allowed:
                    raise ValueError("code file is not sealed")
                if child == ".env" and entry.st_gid != app_gid:
                    raise ValueError("protected environment has the wrong application group")
        else:
            raise ValueError("special files are forbidden in code")


def run(root, app_uid, app_gid, sealing):
    root = Path(root)
    if not root.is_absolute() or root.resolve(strict=True) != root:
        raise ValueError("release must be canonical")
    descriptor = os.open(root, DIRECTORY_FLAGS)
    try:
        walk(root, descriptor, "", app_uid, app_gid, sealing)
    finally:
        os.close(descriptor)
    if sealing:
        run(root, app_uid, app_gid, False)


def seal_release(root, app_uid, app_gid):
    run(root, app_uid, app_gid, True)


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
    if len(sys.argv) != 5 or sys.argv[1] not in ("seal", "verify"):
        raise ValueError("root seal/verify requires one release and the configured account")
    app_uid = pwd.getpwnam(sys.argv[3]).pw_uid
    if app_uid == 0:
        raise ValueError("application account must be nonroot")
    run(sys.argv[2], app_uid, grp.getgrnam(sys.argv[4]).gr_gid, sys.argv[1] == "seal")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, KeyError, RuntimeError):
        print("staging-seal: release refused; no code or private contents printed", file=sys.stderr)
        sys.exit(1)
