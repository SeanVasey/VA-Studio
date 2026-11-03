#!/usr/bin/env python3
"""Independent locked dependency installation; no candidate scripts or plugins."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import time
from network import container_flags, INNER_CA

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('ledger_dependency_runtime', HERE / 'runtime-producer.py')
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)
require = runtime.require


def locked_packages(lock):
    require(type(lock) is dict and type(lock.get('packages')) is list and type(lock.get('packages-dev')) is list,
            'locked-package-schema')
    packages = {}
    for package in lock['packages'] + lock['packages-dev']:
        name = package['name']
        require(type(name) is str and name not in packages and re.fullmatch('[a-z0-9_.-]+/[a-z0-9_.-]+', name), 'locked-package-name')
        packages[name] = (package['version'], package.get('source'), package.get('dist'))
    require(packages, 'empty-locked-packages')
    return packages


def verify_packages(lock, installed):
    expected = locked_packages(lock)
    require(type(installed) is dict and installed.get('dev') is True and type(installed.get('packages')) is list,
            'development-dependencies-required')
    actual = {}
    for package in installed['packages']:
        name = package['name']
        require(name not in actual, 'duplicate-installed-package')
        actual[name] = (package['version'], package.get('source'), package.get('dist'))
    require(actual == expected, 'installed-package-lock-mismatch')


def seal_tree(root):
    """Called only after the install container is actually removed; no live writer."""
    for base, directories, files in os.walk(root, followlinks=False):
        for name in directories + files:
            path = Path(base) / name
            if path.is_symlink():
                require(path.resolve().is_relative_to(root.resolve()), 'dependency-symlink-escape')
                os.lchown(path, 0, 0)
                continue
            info = path.stat()
            os.chown(path, 0, 0)
            path.chmod(0o555 if path.is_dir() or info.st_mode & 0o111 else 0o444)
    os.chown(root, 0, 0)
    root.chmod(0o555)


def install(source_root, expected_sha, runtime_descriptor, output, nonce):
    require(os.geteuid() == 0 and re.fullmatch('[0-9a-f]{32}', nonce), 'root-dependency-producer')
    require(output.parent == Path('/run') and output.name == 'va-ledger-dependencies-' + nonce, 'root-dependency-staging')
    runtime.trusted_path(output.parent, directory=True)
    runtime.trusted_path(runtime_descriptor)
    require(runtime_descriptor.stat().st_size <= 65536, 'runtime-descriptor-bound')
    document = runtime.load(runtime_descriptor.read_bytes())
    require(document.get('kind') == 'runtime-qualification-only' and document.get('native_admitted') is False
            and document.get('requested_candidate_sha') == expected_sha and document.get('candidate_source_verified') is True
            and re.fullmatch('sha256:[0-9a-f]{64}', document.get('runtime_image', '')),
            'independent-runtime-required')
    snapshot_spec = importlib.util.spec_from_file_location('ledger_dependency_snapshot', HERE / 'source-snapshot.py')
    snapshot = importlib.util.module_from_spec(snapshot_spec)
    snapshot_spec.loader.exec_module(snapshot)
    witness = snapshot.verify(source_root, expected_sha)
    require(document['candidate_tree_oid'] == witness['tree_oid'] and HERE == source_root / 'scripts/ci/account-ledger', 'dependency-source-binding')
    output.mkdir(mode=0o755)
    output.chmod(0o755)
    workspace = output / 'workspace'
    shutil.copytree(source_root, workspace, symlinks=True,
                    ignore=lambda directory, names: ('vendor',) if Path(directory) == source_root else ())
    os.chown(workspace, 1000, 1000)
    workspace.chmod(0o755)
    require(not (workspace / 'vendor').exists() and not (workspace / 'vendor').is_symlink(), 'fresh-dependency-directory')
    state = output / 'state'
    state.mkdir(mode=0o700)
    owner = runtime.Producer(state)
    name = 'va-ledger-dependencies-' + nonce
    container = None
    complete = False
    cleaned = False
    try:
        container = owner.command(['docker', 'create', '--pull=never', '--platform=linux/amd64', '--name', name,
            '--label', 'va.ledger.dependencies=' + nonce, '--network=bridge', '--read-only', '--user=1000:1000',
            '--cap-drop=ALL', '--security-opt=no-new-privileges:true', '--memory=512m', '--memory-swap=512m',
            '--cpus=1', '--pids-limit=64', '--log-driver=none', '--init=false', '--restart=no', *container_flags(),
            '--env', 'COMPOSER_CAFILE=' + INNER_CA, '--env', 'SSL_CERT_FILE=' + INNER_CA,
            '--env', 'CURL_CA_BUNDLE=' + INNER_CA,
            '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,size=256m,mode=1777',
            '--env', 'HOME=/tmp', '--env', 'COMPOSER_HOME=/tmp/composer-home',
            '--env', 'COMPOSER_CACHE_DIR=/tmp/composer-cache',
            '--mount', 'type=bind,src=' + str(workspace) + ',dst=/dependencies',
            '--workdir=/dependencies', '--entrypoint=/bin/sleep', document['runtime_image'], '480'], 'dependencies_create').strip()
        require(re.fullmatch('[0-9a-f]{64}', container), 'dependencies-created-id')
        owner.receipt['created_id'] = container
        owner.persist()
        require(owner.command(['docker', 'start', container], 'dependencies_start').strip() == container, 'dependencies-started-id')
        owner.command(['docker', 'exec', '--user=1000:1000', container, '/usr/bin/php8.4', '-d', 'memory_limit=512M',
            '/usr/bin/composer', 'install', '--no-interaction', '--quiet', '--no-progress', '--prefer-dist',
            '--no-plugins', '--no-scripts'], 'locked_dependency_install', seconds=360)
        owner.command(['docker', 'exec', '--user=1000:1000', container, '/usr/bin/php8.4', '-d', 'memory_limit=512M',
            '/usr/bin/composer', 'audit', '--locked', '--no-interaction', '--no-plugins', '--no-scripts',
            '--format=json'], 'locked_dependency_audit', seconds=60)
        complete = True
    finally:
        owner.limit = min(owner.end, time.monotonic() + 20)
        if container and re.fullmatch('[0-9a-f]{64}', container):
            owner.command(['docker', 'kill', '--signal=KILL', container], 'dependencies_stop')
            require(owner.command(['docker', 'wait', container], 'dependencies_wait') == '137\n', 'dependencies-wait')
            owner.command(['docker', 'rm', container], 'dependencies_remove')
            require(owner.command(['docker', 'ps', '-a', '--filter', 'id=' + container, '--format', '{{.ID}}'], 'dependencies_removed') == '', 'dependencies-removal')
            cleaned = True
    require(complete and cleaned and not owner.receipt['transport_uncertain'], 'dependencies-incomplete')
    seal_tree(workspace)
    snapshot.verify(workspace, expected_sha)
    lock = json.loads((workspace / 'composer.lock').read_bytes())
    installed = json.loads((workspace / 'vendor/composer/installed.json').read_bytes())
    verify_packages(lock, installed)
    # Existing independently retained critical framework/PHPUnit source pins still
    # apply to these controlled outputs; generated Composer metadata is separately
    # bound through the sealed complete inventory and original loader map checks.
    pins = json.loads((source_root / 'docs/verification/account-migrator-evidence-20261102/pins.json').read_bytes())
    for name, digest in pins['sha256'].items():
        if name.startswith('vendor/'):
            require(hashlib.sha256((workspace / name).read_bytes()).hexdigest() == digest, 'retained-dependency-source-pin')
    inventory = {}
    for path in sorted((workspace / 'vendor').rglob('*')):
        if path.is_file():
            inventory[path.relative_to(workspace).as_posix()] = hashlib.sha256(path.read_bytes()).hexdigest()
    raw = (json.dumps(inventory, sort_keys=True, separators=(',', ':')) + '\n').encode()
    require(0 < len(inventory) <= 50000 and len(raw) <= 8 * 1024 * 1024, 'dependency-inventory-bound')
    manifest = output / 'vendor-files.json'
    manifest.write_bytes(raw)
    manifest.chmod(0o444)
    receipt = {'schema': 1, 'kind': 'controlled-locked-dependencies', 'native_admitted': False,
               'candidate_sha': expected_sha, 'candidate_tree_oid': witness['tree_oid'],
               'composer_lock_sha256': hashlib.sha256((source_root / 'composer.lock').read_bytes()).hexdigest(),
               'runtime_image': document['runtime_image'], 'vendor_manifest_sha256': hashlib.sha256(raw).hexdigest(),
               'install_container_removed': True, 'locked_package_set': True, 'composer_audit_passed': True}
    target = output / 'dependency-qualified.json'
    target.write_text(json.dumps(receipt, separators=(',', ':')) + '\n')
    target.chmod(0o444)
    return workspace, receipt
