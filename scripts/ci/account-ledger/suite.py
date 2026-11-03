#!/usr/bin/env python3
"""ROOT coordinator: provision, qualify, run four serial providers, require cleanup."""
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import stat
import time

HERE = Path(__file__).resolve().parent
CASES = ('up-overlap', 'down-overlap', 'up-end', 'down-end')
CLASS = 'Tests\\Supervised\\ProductionIdentity\\AccountAdmissionMigratorTest'
METHOD = 'test_normal_migrator_process_interruption_and_genuine_ledger_resume'


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


runtime = module('ledger_suite_runtime', 'runtime-producer.py')
service = module('ledger_suite_service', 'service-producer.py')
dependencies = module('ledger_suite_dependencies', 'dependency-producer.py')
snapshot = module('ledger_suite_snapshot', 'source-snapshot.py')
acceptance = module('ledger_suite_acceptance', 'acceptance.py')
require = runtime.require


def public(path, value):
    runtime.trusted_path(path.parent, directory=True)
    raw = (json.dumps(value, separators=(',', ':')) + '\n').encode()
    require(len(raw) <= 65536, 'safe-receipt-bound')
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o444)
    try:
        os.fchmod(fd, 0o444)
        with os.fdopen(fd, 'wb', closefd=False) as output:
            output.write(raw)
            output.flush()
            os.fsync(fd)
    finally:
        os.close(fd)


def private(path, maximum):
    before = path.lstat()
    require(stat.S_ISREG(before.st_mode) and before.st_uid == 1000 and before.st_nlink == 1
            and stat.S_IMODE(before.st_mode) == 0o600 and before.st_size <= maximum, 'private-result-custody')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        opened = os.fstat(stream.fileno())
        raw = stream.read(maximum + 1)
        after = os.fstat(stream.fileno())
    keys = ('st_dev', 'st_ino', 'st_mode', 'st_uid', 'st_gid', 'st_nlink', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
    require(len(raw) <= maximum and all(getattr(before, key) == getattr(opened, key) == getattr(after, key)
                                      for key in keys), 'private-result-moved')
    return raw


def bind_admission(directory, owner, selected, source, deps, case):
    require(owner.receipt['observer_qualified'] is True and owner.receipt['observer_runtime_cleanup_verified'] is True
            and not owner.receipt['transport_uncertain'] and not owner.receipt['memory_audit_failed'], 'qualified-owned-service')
    directory.mkdir(mode=0o750)
    os.chown(directory, 0, 1000)
    directory.chmod(0o750)
    secret = directory / 'fixture-password'
    shutil.copyfile(owner.directory / 'php-password', secret)
    os.chown(secret, 0, 1000)
    secret.chmod(0o440)
    info = secret.stat()
    descriptor = {'schema': 1, 'kind': 'required-supervised-ledger-job', 'native_admitted': True,
        'candidate_sha': source['candidate_sha'], 'case': case, 'source_tree_oid': source['tree_oid'],
        'binding': {'candidate_root': '/candidate', 'canonical_root': '/dependencies', 'vendor_realpath': '/dependencies/vendor'},
        'service': {'container_id': owner.container, 'server_uuid': owner.receipt['candidate_runtime']['server_uuid'],
                    'ip': owner.receipt['candidate_runtime']['ip'], 'runtime_image': owner.runtime_image},
        'runtime': {key: runtime.policy(runtime.load((HERE / 'runtime-policy.json').read_bytes()))['runtime'][key]
                    for key in ('php_version', 'php_binary_sha256', 'python_version', 'python_binary_sha256')},
        'qualification': {'controller_profile': True, 'runtime_profile': True, 'source_verified': True,
                          'same_account_observer': True, 'locked_dependencies': True},
        'dependency': {'manifest_sha256': deps['vendor_manifest_sha256'], 'composer_lock_sha256': deps['composer_lock_sha256']},
        'secret_metadata': {name: getattr(info, 'st_' + name) for name in ('dev', 'ino', 'uid', 'gid', 'mode', 'nlink', 'size')}}
    target = directory / 'job.json'
    public(target, descriptor)
    os.chown(target, 0, 1000)
    return hashlib.sha256(target.read_bytes()).hexdigest()


def verify_vendor(workspace, receipt):
    manifest = workspace.parent / 'vendor-files.json'
    runtime.trusted_path(manifest)
    require(manifest.stat().st_size <= 8 * 1024 * 1024, 'vendor-manifest-bound')
    raw = manifest.read_bytes()
    require(hashlib.sha256(raw).hexdigest() == receipt['vendor_manifest_sha256'], 'sealed-vendor-manifest')
    entries = json.loads(raw, object_pairs_hook=runtime.unique)
    require(0 < len(entries) <= 50000, 'vendor-file-count')
    actual = {path.relative_to(workspace).as_posix() for path in (workspace / 'vendor').rglob('*') if path.is_file()}
    require(actual == set(entries), 'complete-vendor-inventory')
    for name, digest in entries.items():
        require(name.startswith('vendor/') and '..' not in name.split('/')
                and (workspace / name).resolve().is_relative_to(workspace.resolve())
                and hashlib.sha256((workspace / name).read_bytes()).hexdigest() == digest, 'sealed-vendor-content')


def clone_auditor(owner, directory):
    require(not owner.receipt['transport_uncertain'] and not owner.receipt['memory_audit_failed'], 'sticky-service-failure')
    audit = object.__new__(type(owner))
    audit.__dict__ = copy.copy(owner.__dict__)
    audit.receipt = copy.deepcopy(owner.receipt)
    # Each original outer provider also had a fresh bounded command receipt.
    # Preserve every sticky outcome and immutable service anchor, reset log arrays only.
    for key in ('commands', 'intents', 'stages'):
        audit.receipt[key] = []
    audit.directory = directory
    directory.mkdir(mode=0o700)
    audit.end = time.monotonic() + 655
    audit.limit = audit.end - 30
    return audit


def run_case(owner, selected, workspace, deps, source, case, root, run_id, attempt):
    require(case in CASES and source['candidate_sha'] == deps['candidate_sha'], 'closed-case-source')
    verify_vendor(workspace, deps)
    snapshot.verify(Path('/candidate'), source['candidate_sha'])
    base = root / case
    base.mkdir(mode=0o700)
    evidence = base / 'evidence'
    evidence.mkdir(mode=0o700)
    os.chown(evidence, 1000, 1000)
    evidence.chmod(0o700)
    launcher = evidence / 'launcher'
    launcher.mkdir(mode=0o700)
    os.chown(launcher, 1000, 1000)
    admission = base / 'admission'
    pin = bind_admission(admission, owner, selected, source, deps, case)
    state = base / 'state'
    state.mkdir(mode=0o700)
    actor = runtime.Producer(state)
    container = None
    audit = clone_auditor(owner, base / 'audit')
    cleanup = {name: False for name in ('state', 'profile', 'resources', 'php', 'memory', 'source')}
    passed = False
    checks = None
    cleanup_in_time = False
    result = None
    proof = None
    try:
        container = actor.command(['docker', 'create', '--pull=never', '--platform=linux/amd64',
            '--name', 'va-ledger-case-' + secrets.token_hex(16), '--network=bridge', '--read-only', '--user=1000:1000',
            '--cap-drop=ALL', '--security-opt=no-new-privileges:true', '--memory=512m', '--memory-swap=512m',
            '--cpus=1', '--pids-limit=128', '--log-driver=none', '--restart=no', '--init=false',
            '--mount', 'type=bind,src=/candidate,dst=/candidate,readonly',
            '--mount', 'type=bind,src=' + str(workspace) + ',dst=/dependencies,readonly',
            '--mount', 'type=bind,src=' + str(admission) + ',dst=/admission,readonly',
            '--mount', 'type=bind,src=' + str(evidence) + ',dst=/evidence', '--workdir=/candidate',
            '--env', 'TMPDIR=/evidence/launcher', '--env', 'VA_ACCOUNT_LEDGER_SOURCE_SHA=' + source['candidate_sha'],
            '--env', 'VA_ACCOUNT_LEDGER_RESULT_DIR=/evidence', '--env', 'VA_ACCOUNT_LEDGER_ADMISSION_PIN=' + pin,
            '--entrypoint=/bin/sleep', owner.runtime_image, '655'], 'case_create').strip()
        require(re.fullmatch('[0-9a-f]{64}', container), 'case-created-id')
        actor.receipt['created_id'] = container
        actor.persist()
        require(actor.command(['docker', 'start', container], 'case_start').strip() == container, 'case-started-id')
        anchor = json.loads(actor.command(['docker', 'inspect', container], 'case_anchor'))[0]
        require(anchor['Id'] == container and anchor['Image'] == owner.runtime_image
                and anchor['State']['Running'] is True and anchor['HostConfig']['PidMode'] == ''
                and anchor['HostConfig']['Privileged'] is False, 'case-container-anchor')

        def state_check():
            import ownership
            ownership.running(audit.anchor, audit.inspect(), selected, 'main')
            row = json.loads(actor.command(['docker', 'inspect', container], 'case_state'))[0]
            require(all(row[key] == anchor[key] for key in ('Id', 'Name', 'Image', 'Created', 'Config', 'HostConfig', 'Mounts'))
                    and row['State']['Pid'] == anchor['State']['Pid'] and row['State']['StartedAt'] == anchor['State']['StartedAt']
                    and row['State']['Running'] is True and row['State']['Status'] == 'running'
                    and row['RestartCount'] == 0 and all(row['State'][key] is False for key in ('Paused', 'Restarting', 'Dead', 'OOMKilled')),
                    'case-state-moved')

        def profile_check():
            rows = service.fixed_admin(audit, 'SELECT VERSION(),@@server_uuid,@@port,@@GLOBAL.transaction_isolation,@@SESSION.transaction_isolation,@@SESSION.sql_mode,@@SESSION.autocommit,@@SESSION.foreign_key_checks')
            require(rows == ['\t'.join(['8.4.11', owner.receipt['candidate_runtime']['server_uuid'], '3306',
                'REPEATABLE-READ', 'REPEATABLE-READ', 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', '1', '1'])], 'case-server-profile')

        def resources_check():
            raw = actor.command(['docker', 'exec', '--user=1000:1000', container, '/usr/bin/php8.4',
                '-d', 'memory_limit=512M', '/candidate/scripts/ci/account-ledger/loader/audit.php'], 'whole_server_audit')
            require(runtime.load(raw.encode()) == {'whole_server_resources_zero': True, 'strict_foreground': True,
                                                   'full_eof': True, 'pdo_closed': True}, 'whole-server-audit')

        def php_check():
            require(service.load_factory().named_php_absent(actor.command(['docker', 'top', container,
                     '-eo', 'pid,ppid,comm'], 'case_php_zero')), 'case-php-not-zero')

        def memory_check():
            audit.memory('case_mysql_memory')
            for name in ('memory.events', 'memory.events.local'):
                raw = actor.command(['docker', 'exec', container, '/bin/cat', '/sys/fs/cgroup/' + name], 'case_memory')
                values = dict(line.split() for line in raw.splitlines())
                require(all(key in values and values[key] == '0' for key in ('oom', 'oom_kill', 'oom_group_kill')), 'case-oom')
            require(actor.command(['docker', 'exec', container, '/bin/cat', '/sys/fs/cgroup/memory.max'], 'case_memory_max') == '536870912\n'
                    and actor.command(['docker', 'exec', container, '/bin/cat', '/sys/fs/cgroup/memory.swap.max'], 'case_swap_max') == '0\n', 'case-memory-limits')

        def source_check():
            snapshot.verify(Path('/candidate'), source['candidate_sha'])
            verify_vendor(workspace, deps)

        checks = dict(state=state_check, profile=profile_check, resources=resources_check,
                      php=php_check, memory=memory_check, source=source_check)
        for action in checks.values():
            action()
        # Independent setup/preflight precede the original bounded native envelope.
        actor.end = audit.end = time.monotonic() + 655
        actor.limit = audit.limit = actor.end - 30
        actor.command(['docker', 'exec', '--user=1000:1000', container, '/usr/bin/timeout',
            '--signal=TERM', '--kill-after=10s', '600s', '/usr/bin/python3.13', '-I',
            '/candidate/scripts/ci/account-ledger/run-one.py', case], 'supervised_provider', seconds=615)
        invocation = actor.receipt['commands'][-1]
        require(invocation['exit'] == 0 and invocation['reaped'] is True and invocation['capture_complete'] is True
                and invocation['stdout_bytes'] == invocation['stderr_bytes'] == 0, 'authoritative-outer-exit')
        directories = list(launcher.iterdir())
        require(len(directories) == 1 and directories[0].is_dir() and not directories[0].is_symlink()
                and re.fullmatch('account-migrator-one-[a-z0-9_]{8}', directories[0].name), 'one-private-provider-directory')
        directory = directories[0]
        report_raw = private(directory / 'launcher.json', 65536)
        report = json.loads(report_raw, object_pairs_hook=runtime.unique)
        require(report.get('acceptance') is False and report.get('provisional_acceptance') is True
                and report.get('requires_authoritative_outer_exit_zero') is True and report.get('lease_returnable') is True
                and report.get('cleanup') == 'VERIFIED' and report.get('supervision', {}).get('case') == case,
                'original-supervisor-refused')
        xml = private(directory / 'junit.xml', 1024 * 1024)
        require(b'<!DOCTYPE' not in xml and b'<!ENTITY' not in xml, 'junit-entities')
        console = private(directory / 'console.private', 65536)
        selection = {'key': case, 'class': CLASS, 'method': METHOD + ' with data set "' + case + '"', 'result_kind': 'empty'}
        result = acceptance.evaluate_junit(xml, console, b'', 0, selection)
        require(result['positive'], 'strict-positive-junit')
        acceptance.validate_origins(private(directory / 'origins.json', 4096), source['candidate_sha'], selection)
        def origins(phase):
            values = []
            paths = list(directory.glob('origin-*-' + phase + '.json'))
            require(len(paths) <= 4, 'origin-record-bound')
            for path in paths:
                match = re.fullmatch('origin-([1-9][0-9]*)-' + phase + r'\.json', path.name)
                value = json.loads(private(path, 4096), object_pairs_hook=runtime.unique)
                require(match is not None and value.get('pid') == int(match[1]), 'origin-pid-filename')
                values.append(value)
            return values
        lineage = acceptance.validate_lineage(origins('bound'), origins('final'), report, case, source['candidate_sha'])
        proof = {'junit_sha256': hashlib.sha256(xml).hexdigest(), 'launcher_sha256': hashlib.sha256(report_raw).hexdigest(),
                 'parent_worker_restart_origins': True, 'lineage': lineage, 'job_descriptor_sha256': pin,
                 'runtime_image': owner.runtime_image, 'mysql_image': selected['repo_digest'],
                 'controller_engine': 'docker@sha256:b0ea6e8d57b28d04a6999591fdd0f10de8717e956f4f4b6128d2a8cf57b27eb9',
                 'server_uuid_sha256': hashlib.sha256(owner.receipt['candidate_runtime']['server_uuid'].encode()).hexdigest()}
        passed = True
    finally:
        # Always audit before teardown, including a failed/uncertain native CLI.
        # No retry and no post-kill reinterpretation can redeem a failed outcome.
        cleanup_end = min(actor.end, time.monotonic() + 30)
        actor.limit = audit.limit = cleanup_end
        if checks is not None:
            for name, action in checks.items():
                try:
                    action()
                    cleanup[name] = True
                except Exception:
                    cleanup[name] = False
        cleanup_in_time = time.monotonic() < cleanup_end
        actor.receipt['six_cleanup_checks'] = dict(cleanup)
        actor.persist()
        # Teardown cannot turn a failed quiet/orphan audit into a passing receipt.
        actor.limit = min(actor.end, time.monotonic() + 20)
        if container and re.fullmatch('[0-9a-f]{64}', container):
            actor.command(['docker', 'kill', '--signal=KILL', container], 'case_teardown')
            require(actor.command(['docker', 'wait', container], 'case_wait') == '137\n', 'case-teardown-wait')
            actor.command(['docker', 'rm', container], 'case_remove')
            require(actor.command(['docker', 'ps', '-a', '--filter', 'id=' + container, '--format', '{{.ID}}'], 'case_removed') == '', 'case-removal')
    require(passed and cleanup_in_time and all(cleanup.values()) and not actor.receipt['transport_uncertain']
            and not audit.receipt['transport_uncertain'] and not audit.receipt['memory_audit_failed'], 'provider-unverified')
    return {'schema': 1, 'kind': 'required-supervised-ledger-case', 'candidate_sha': source['candidate_sha'],
            'run_id': run_id, 'run_attempt': attempt, 'case': case, 'assertions': result['assertions'],
            'outer_exit': 0, 'outer_reaped': True, 'strict_no_fesw': True, 'proof': proof,
            'cleanup': cleanup, 'lease_returnable': True, 'case_container_removed': True}


def run(expected_sha, run_id, attempt):
    require(os.geteuid() == 0 and Path('/public').is_dir(), 'root-suite-coordinator')
    source = snapshot.verify(Path('/candidate'), expected_sha)
    selection = json.loads((HERE / 'selection.json').read_bytes())
    runtime.closed(selection, ('schema', 'required_job', 'file', 'class', 'method', 'providers', 'ordinary_discovery',
                               'execution', 'native_engine', 'worker_php', 'supervisor_python', 'normalized_test_sha256'))
    require(selection['providers'] == list(CASES) and selection['class'] == CLASS and selection['method'] == METHOD
            and selection['required_job'] == 'backend-ledger' and selection['ordinary_discovery'] is False,
            'required-closed-selection')
    require({path.relative_to('/candidate').as_posix() for path in Path('/candidate/tests/Supervised').rglob('*.php')}
            == {selection['file']}, 'no-uninventoried-supervised-tests')
    normalized = (Path('/candidate') / selection['file']).read_text().replace(
        'namespace Tests\\Supervised\\ProductionIdentity;', 'namespace Tests\\Feature\\ProductionIdentity;').replace(
        "$GLOBALS['accountMigratorQualifiedRuntime']['php_binary_sha256'] ?? null, hash_file('sha256', PHP_BINARY)",
        "$pins['php_binary_sha256'], hash_file('sha256', PHP_BINARY)")
    require(hashlib.sha256(normalized.encode()).hexdigest() == selection['normalized_test_sha256'],
            'original-four-provider-assertions-moved')
    nonce = secrets.token_hex(16)
    state = Path('/run/va-ledger-suite-' + nonce)
    state.mkdir(mode=0o700)
    runtime_state = state / 'runtime'
    runtime_state.mkdir(mode=0o700)
    policy = runtime.policy(runtime.load((HERE / 'runtime-policy.json').read_bytes()))
    facts = runtime.Producer(runtime_state).qualify(policy, HERE, secrets.token_hex(16))
    runtime.publish(runtime_state, {'schema': 1, 'kind': 'runtime-qualification-only', 'native_admitted': False,
        'requested_candidate_sha': expected_sha, 'candidate_tree_oid': source['tree_oid'], 'candidate_source_verified': True,
        'policy_sha256': hashlib.sha256((HERE / 'runtime-policy.json').read_bytes()).hexdigest(),
        'source_manifest_sha256': hashlib.sha256((HERE / 'source-manifest.json').read_bytes()).hexdigest(),
        'runtime_image': facts['built_image_id'], 'facts': facts})
    workspace, deps = dependencies.install(Path('/candidate'), expected_sha, runtime_state / 'runtime-qualified.json',
        Path('/run/va-ledger-dependencies-' + nonce), nonce)
    owner, selected = service.create_owner(runtime_state / 'runtime-qualified.json', Path('/candidate'), expected_sha,
                                           Path('/run/va-ledger-service-' + nonce), nonce)
    require(owner.run(), 'owned-service-provisioning')
    service.qualify_observer(owner, selected)
    receipts = []
    try:
        for case in CASES:
            receipt = run_case(owner, selected, workspace, deps, source, case, state, run_id, attempt)
            public(Path('/public') / (case + '.json'), receipt)
            receipts.append(receipt)
    finally:
        # Owned server shutdown follows, never substitutes for the per-provider audits.
        owner.end = owner.limit = time.monotonic() + 60
        import ownership
        ownership.running(owner.anchor, owner.inspect(), selected, 'main')
        owner.command(['docker', 'kill', '--signal=TERM', owner.container], 'mysql_owned_stop')
        require(owner.command(['docker', 'wait', owner.container], 'mysql_owned_wait') == '0\n', 'mysql-clean-exit')
        ownership.stopped(owner.anchor, owner.inspect(), owner.process_anchor)
        owner.command(['docker', 'rm', owner.container], 'mysql_owned_remove')
        require(owner.command(['docker', 'ps', '-a', '--filter', 'id=' + owner.container, '--format', '{{.ID}}'], 'mysql_owned_absent') == '', 'mysql-owned-removal')
        owner.command(['docker', 'volume', 'rm', selected['volume_name']], 'mysql_owned_volume_remove')
    require(tuple(receipt['case'] for receipt in receipts) == CASES, 'all-four-serial-providers-required')
    public(Path('/public') / 'suite.json', {'schema': 1, 'kind': 'required-supervised-ledger-suite',
        'candidate_sha': expected_sha, 'run_id': run_id, 'run_attempt': attempt, 'cases': list(CASES),
        'assertions': sum(receipt['assertions'] for receipt in receipts), 'service_removed': True,
        'owned_volume_removed': True, 'controller_shutdown_required': True})
