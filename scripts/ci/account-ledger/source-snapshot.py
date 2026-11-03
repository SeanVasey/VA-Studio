#!/usr/bin/env python3
"""Seal actual reviewed Git objects under /run; never copy ambient working files."""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile
import time

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('ledger_runtime_producer', HERE / 'runtime-producer.py')
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)
require = runtime.require
MAX_SOURCE = 1024 * 1024 * 1024
MAX_FILE = 64 * 1024 * 1024


def object_id(kind, raw):
    return hashlib.sha1(kind.encode() + b' ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()


def safe_name(name):
    require(type(name) is str and len(name.encode()) <= 1024 and name
            and not name.startswith('/') and '\\' not in name
            and all(part not in ('', '.', '..', '.git') for part in name.split('/'))
            and all(ord(char) >= 32 and ord(char) != 127 for char in name), 'source-path')
    return name


def listing(raw):
    require(len(raw) <= 8 * 1024 * 1024 and raw.endswith(b'\0'), 'source-list-bound')
    files = {}
    for line in raw[:-1].split(b'\0'):
        metadata, encoded = line.split(b'\t', 1)
        mode, kind, oid = metadata.decode('ascii').split(' ')
        name = safe_name(encoded.decode('utf-8', 'strict'))
        require(mode in ('100644', '100755') and kind == 'blob'
                and re.fullmatch('[0-9a-f]{40}', oid) and name not in files, 'source-object')
        require(name not in ('.env', '.env.testing', '.ledger-source.json'), 'ambient-source-environment')
        files[name] = {'mode': mode, 'git_oid': oid}
    require(0 < len(files) <= 50000, 'source-count')
    return files


def tree_id(files):
    root = {}
    for name, entry in files.items():
        current = root
        parts = safe_name(name).split('/')
        for part in parts[:-1]:
            require(part not in current or type(current[part]) is dict, 'source-path-collision')
            current = current.setdefault(part, {})
        require(parts[-1] not in current, 'source-path-collision')
        current[parts[-1]] = (entry['mode'], entry['git_oid'])

    def build(node):
        entries = []
        for name, value in node.items():
            directory = type(value) is dict
            mode, oid = ('40000', build(value)) if directory else value
            entries.append(((name + ('/' if directory else '')).encode(),
                            mode.encode() + b' ' + name.encode() + b'\0' + bytes.fromhex(oid)))
        return object_id('tree', b''.join(row for unused, row in sorted(entries)))

    return build(root)


def command(root, arguments, destination, limit):
    with tempfile.TemporaryFile() as errors:
        result = subprocess.run(['/usr/bin/git', '-c', 'safe.directory=' + str(root), '-C', str(root),
                                 *arguments], stdout=destination, stderr=errors, timeout=30,
                                env={'PATH': '/usr/bin:/bin', 'HOME': '/nonexistent', 'GIT_CONFIG_NOSYSTEM': '1'},
                                check=False)
        require(result.returncode == 0 and errors.tell() == 0 and destination.tell() <= limit,
                'source-git-transport')
    destination.seek(0)


def snapshot(checkout, expected, output):
    require(os.geteuid() == 0 and runtime.SHA.fullmatch(expected), 'root-source-producer')
    os.umask(0o022)  # Public sealed source remains readable inside the UID1000 container.
    require(output.parent == Path('/run') and re.fullmatch('va-ledger-[a-f0-9]{32}', output.name), 'root-run-staging')
    runtime.trusted_path(output.parent, directory=True)
    with tempfile.TemporaryFile() as commit, tempfile.TemporaryFile() as index, tempfile.TemporaryFile() as archive:
        command(checkout, ['cat-file', 'commit', expected], commit, 1024 * 1024)
        raw_commit = commit.read()
        require(object_id('commit', raw_commit) == expected, 'actual-commit-object')
        first = raw_commit.split(b'\n', 1)[0].decode('ascii')
        require(re.fullmatch('tree [0-9a-f]{40}', first), 'actual-commit-tree')
        expected_tree = first.removeprefix('tree ')
        command(checkout, ['ls-tree', '-r', '-z', '--full-tree', expected], index, 8 * 1024 * 1024)
        files = listing(index.read())
        require(tree_id(files) == expected_tree, 'actual-source-tree')
        command(checkout, ['archive', '--format=tar', expected], archive, MAX_SOURCE)
        output.mkdir(mode=0o755)
        total = 0
        seen = set()
        deadline = time.monotonic() + 30
        with tarfile.open(fileobj=archive, mode='r|') as stream:
            for member in stream:
                require(time.monotonic() < deadline, 'source-extraction-deadline')
                name = safe_name(member.name.rstrip('/'))
                target = output / name
                if member.isdir():
                    require(any(path.startswith(name + '/') for path in files), 'unknown-source-directory')
                    target.mkdir(mode=0o755, parents=True, exist_ok=True)
                    continue
                require(member.isfile() and name in files and name not in seen
                        and 0 <= member.size <= MAX_FILE, 'source-archive-member')
                total += member.size
                require(total <= MAX_SOURCE, 'source-total-bound')
                source = stream.extractfile(member)
                raw = source.read(MAX_FILE + 1)
                require(len(raw) == member.size and object_id('blob', raw) == files[name]['git_oid'], 'actual-source-blob')
                target.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
                fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                             0o555 if files[name]['mode'] == '100755' else 0o444)
                with os.fdopen(fd, 'wb') as destination:
                    destination.write(raw)
                files[name]['sha256'] = hashlib.sha256(raw).hexdigest()
                seen.add(name)
        require(seen == set(files), 'source-archive-completeness')
        witness = {'schema': 1, 'candidate_sha': expected, 'tree_oid': expected_tree, 'files': files}
        path = output / '.ledger-source.json'
        path.write_text(json.dumps(witness, separators=(',', ':'), sort_keys=True) + '\n')
        path.chmod(0o444)
    return output


def verify(output, expected):
    runtime.trusted_path(output, directory=True)
    path = runtime.trusted_path(output / '.ledger-source.json')
    require(path.stat().st_size <= 8 * 1024 * 1024, 'source-witness-bound')
    value = json.loads(path.read_bytes(), object_pairs_hook=runtime.unique)
    runtime.closed(value, ('schema', 'candidate_sha', 'tree_oid', 'files'))
    require(type(value['schema']) is int and value['schema'] == 1 and value['candidate_sha'] == expected
            and tree_id(value['files']) == value['tree_oid'], 'source-witness-binding')
    for name in ('.env', '.env.testing'):
        require(not (output / name).exists() and not (output / name).is_symlink(), 'ambient-source-environment')
    deadline = time.monotonic() + 30
    for name, item in value['files'].items():
        require(time.monotonic() < deadline, 'source-verification-deadline')
        runtime.closed(item, ('mode', 'git_oid', 'sha256'))
        source = runtime.trusted_path(output / safe_name(name))
        require(source.stat().st_size <= MAX_FILE, 'source-file-bound')
        raw = source.read_bytes()
        require(hashlib.sha256(raw).hexdigest() == item['sha256']
                and object_id('blob', raw) == item['git_oid'], 'source-content-moved')
    return value


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--checkout', type=Path, required=True)
    parser.add_argument('--expected-sha', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    try:
        snapshot(args.checkout, args.expected_sha, args.output)
        verify(args.output, args.expected_sha)
    except Exception:
        raise SystemExit('Ledger source snapshot refused.') from None
