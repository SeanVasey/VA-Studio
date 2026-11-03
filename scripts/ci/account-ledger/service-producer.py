#!/usr/bin/env python3
"""Owned MySQL factory adapter; no process or private IO occurs at import.

The root suite coordinator must retain this owner through qualification, serial
providers and cleanup. A ready service is never by itself ledger acceptance.
"""
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import sys
import time

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('ledger_root_runtime', HERE / 'runtime-producer.py')
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)
require = runtime.require
PFS = ('threads', 'data_locks', 'data_lock_waits', 'metadata_locks',
       'events_statements_current', 'setup_consumers', 'setup_instruments')
PEER = 'vaseyaudio_production_identity-account-guard-peer'
USAGE = 'GRANT USAGE ON *.* TO `account_fixture`@`%`'
PROCESS = 'GRANT PROCESS ON *.* TO `account_fixture`@`%`'
SHOW = "SHOW GRANTS FOR 'account_fixture'@'%'"
INSTRUMENTATION = ("SELECT @@GLOBAL.performance_schema,"
    "(SELECT COUNT(*) FROM performance_schema.setup_consumers WHERE NAME IN ('global_instrumentation','thread_instrumentation','events_statements_current','events_statements_history_long')),"
    "(SELECT COUNT(*) FROM performance_schema.setup_consumers WHERE NAME IN ('global_instrumentation','thread_instrumentation','events_statements_current','events_statements_history_long') AND ENABLED='YES'),"
    "(SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME LIKE 'statement/%'),"
    "(SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME LIKE 'statement/%' AND ENABLED='YES'),"
    "(SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME IN ('statement/sql/select','statement/sql/create_trigger','statement/sql/drop_trigger')),"
    "(SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME IN ('statement/sql/select','statement/sql/create_trigger','statement/sql/drop_trigger') AND ENABLED='YES'),"
    "(SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME='wait/lock/metadata/sql/mdl'),"
    "(SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME='wait/lock/metadata/sql/mdl' AND ENABLED='YES')")
FACTS = ('identity_match', 'existing_observer_access', 'own_instrumented_thread', 'selected_permission_boundary',
         'full_eof', 'cursors_closed', 'pdo_closed')


def instrumentation(rows):
    require(type(rows) is list and len(rows) == 1 and re.fullmatch(r'[0-9]+(?:\t[0-9]+){8}', rows[0]), 'instrumentation-shape')
    values = tuple(map(int, rows[0].split('\t')))
    require(values[:3] == (1, 4, 4) and 3 <= values[3] == values[4] <= 10000
            and values[5:] == (3, 3, 1, 1), 'instrumentation-unqualified')
    return values


def grants(mode, after=False):
    require(type(mode) is int and mode in (0, 1), 'partial-revokes-mode')
    peer = PEER.replace('_', r'\_') if mode == 0 else PEER
    result = {USAGE, 'GRANT ALL PRIVILEGES ON `vaseyaudio_production_identity`.* TO `account_fixture`@`%`',
              'GRANT SELECT, CREATE, DROP, TRIGGER ON `' + peer + '`.* TO `account_fixture`@`%`'}
    result |= {'GRANT SELECT ON `performance_schema`.`' + name + '` TO `account_fixture`@`%`'
               for name in (PFS if after else PFS[:3])}
    if after:
        result.remove(USAGE)
        result.add(PROCESS)
    return frozenset(result)


def grant_set(rows, mode, after=False):
    require(type(rows) is list and all(type(row) is str for row in rows)
            and len(rows) == len(set(rows)) and frozenset(rows) == grants(mode, after), 'complete-grant-scope')
    return frozenset(rows)


def observer_delta(before, after, mode):
    require(before == grants(mode) and after == grants(mode, True)
            and before - after == {USAGE} and after - before == {PROCESS} | {
                'GRANT SELECT ON `performance_schema`.`' + table + '` TO `account_fixture`@`%`'
                for table in PFS[3:]} and len(before) == 6 and len(after) == 10, 'exact-observer-delta')


def load_factory():
    # Source must already have been authenticated/sealed by the root coordinator.
    # Helpers retain accepted transports and process-ownership checks.
    sys.path.insert(0, str(HERE / 'factory'))
    spec = importlib.util.spec_from_file_location('ledger_owned_factory', HERE / 'factory/provision.py')
    factory = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(factory)
    return factory


def create_owner(runtime_descriptor, source_root, expected_sha, directory, nonce):
    """Construct the real factory owner without starting it or reading secrets."""
    require(os.geteuid() == 0 and re.fullmatch('[0-9a-f]{32}', nonce), 'root-service-owner')
    runtime.trusted_path(directory.parent, directory=True)
    require(directory.parent == Path('/run') and directory.name == 'va-ledger-service-' + nonce,
            'root-service-staging')
    runtime.trusted_path(runtime_descriptor)
    require(runtime_descriptor.stat().st_size <= 65536 and runtime_descriptor.stat().st_mode & 0o777 == 0o444,
            'sealed-runtime-descriptor')
    data = runtime.load(runtime_descriptor.read_bytes())
    runtime.closed(data, ('schema', 'kind', 'native_admitted', 'requested_candidate_sha', 'policy_sha256',
                         'candidate_tree_oid', 'candidate_source_verified', 'source_manifest_sha256', 'runtime_image', 'facts'))
    runtime.closed(data['facts'], ('container_removed', 'bounded_transport', 'runtime_profile', 'built_image_id',
                                  'package_closure_sha256', 'package_closure', 'download_closure'))
    require(data.get('kind') == 'runtime-qualification-only' and data.get('native_admitted') is False
            and data.get('requested_candidate_sha') == expected_sha and data.get('candidate_source_verified') is True
            and re.fullmatch('sha256:[0-9a-f]{64}', data.get('runtime_image', '')),
            'independent-runtime-required')
    snapshot_spec = importlib.util.spec_from_file_location('ledger_service_snapshot', HERE / 'source-snapshot.py')
    snapshot = importlib.util.module_from_spec(snapshot_spec)
    snapshot_spec.loader.exec_module(snapshot)
    witness = snapshot.verify(source_root, expected_sha)
    require(data['candidate_tree_oid'] == witness['tree_oid']
            and HERE == source_root / 'scripts/ci/account-ledger', 'service-source-binding')
    require(data['policy_sha256'] == hashlib.sha256((HERE / 'runtime-policy.json').read_bytes()).hexdigest()
            and data['source_manifest_sha256'] == hashlib.sha256((HERE / 'source-manifest.json').read_bytes()).hexdigest()
            and data['facts']['built_image_id'] == data['runtime_image'], 'runtime-source-provenance')
    directory.mkdir(mode=0o700)
    directory.chmod(0o700)
    (directory / 'docker-config').mkdir(mode=0o700)
    os.environ['PATH'] = '/usr/bin:/bin'
    os.environ['DOCKER_CONFIG'] = str(directory / 'docker-config')
    factory = load_factory()
    selected = copy.deepcopy(factory.SPEC)
    selected.update(controller_uid=0, container_name='va-ledger-mysql-' + nonce,
                    volume_name='va-ledger-data-' + nonce, owner_label='va.account.ledger-ci',
                    owner_value=nonce, ownership_directory=str(directory / 'state'),
                    private_directory=str(directory / 'private'),
                    inherited_factory_manifest_sha256='23ba75c8f111e4f821de76c4718acbaa616eebc9d26abf9b5f137c9182df7efc')
    # Exact runtime expectations remain the reviewed public policy, never a hash
    # measured and admitted from an ambient executable/container.
    selected['php_image'] = data['runtime_image']
    factory.SPEC = selected

    class OwnedService(factory.Provision):
        def command(self, args, *arguments, **keywords):
            require(args[0] == 'docker', 'fixed-service-controller')
            return super().command(['/usr/bin/docker', *args[1:]], *arguments, **keywords)

        def php_guard(self):
            # This phase has no application runtime container yet. The independent
            # qualified immutable build is consumed by the later same-app probes.
            require(data['facts']['runtime_profile'] is True and data['facts']['container_removed'] is True
                    and data['facts']['bounded_transport'] is True, 'qualified-runtime-build')
            self.record('runtime_build_bound', {'independently_qualified_image': True, 'candidate_php_executed': False})

        def preflight(self):
            # Replaces only local dependency_audit/php-container bindings. All
            # actual creation, private bootstrap, replacement, PFS/server profile,
            # memory and ownership checks below remain the accepted factory.
            runtime.trusted_path(Path('/usr/bin/docker'))
            capacity = self.command(['docker', 'info', '--format', '{{.NCPU}}|{{.MemTotal}}'], 'host_capacity').strip().split('|')
            require(len(capacity) == 2 and int(capacity[0]) >= 2 and int(capacity[1]) >= 3221225472, 'service-capacity')
            memory = re.search(r'^MemAvailable:\s+([0-9]+) kB$', Path('/proc/meminfo').read_text(), re.M)
            require(memory and int(memory[1]) >= 3145728 and shutil.disk_usage(self.private).free >= 268435456,
                    'service-memory-disk')
            controller = json.loads(self.command(['docker', 'version', '--format',
                '{"Version":{{json .Server.Version}},"ApiVersion":{{json .Server.APIVersion}},"GitCommit":{{json .Server.GitCommit}},"Os":{{json .Server.Os}},"Arch":{{json .Server.Arch}}}'], 'docker_phase_floor'))
            require(controller == {'Version': '28.4.0', 'ApiVersion': '1.51', 'GitCommit': '249d679', 'Os': 'linux', 'Arch': 'amd64'}, 'service-controller')
            cgroup = json.loads(self.command(['docker', 'info', '--format',
                '{"CgroupVersion":{{json .CgroupVersion}},"CgroupDriver":{{json .CgroupDriver}},"OomKillDisable":{{json .OomKillDisable}}}'], 'cgroup_floor'))
            require(cgroup == {'CgroupVersion': '2', 'CgroupDriver': 'cgroupfs', 'OomKillDisable': False}, 'service-cgroup')
            bridge = self.command(['docker', 'network', 'inspect', '--format', '{{.Id}}', 'bridge'], 'existing_bridge').strip()
            require(re.fullmatch('[0-9a-f]{64}', bridge), 'service-bridge')
            selected['bridge_id'] = bridge  # Independent controller anchor, not namespace isolation.
            row = json.loads(self.command(['docker', 'image', 'inspect', '--format',
                '{"Id":{{json .Id}},"RepoDigests":{{json .RepoDigests}},"Os":{{json .Os}},"Architecture":{{json .Architecture}}}', selected['image_id']], 'image_pin'))
            runtime.image_matches(row, {'image_id': selected['image_id'], 'repo_digest': selected['repo_digest']})
            require(self.command(['docker', 'ps', '-a', '--filter', 'name=^/' + selected['container_name'] + '$', '--format', '{{.ID}}'], 'fresh_container_name') == '', 'fresh-service-container')
            require(self.command(['docker', 'volume', 'ls', '--filter', 'name=^' + selected['volume_name'] + '$', '--format', '{{.Name}}'], 'fresh_volume_name') == '', 'fresh-service-volume')
            self.php_guard()
            self.record('preflight_complete', {'new_objects_absent': True, 'capacity_minima': True,
                                               'no_existing_network_mutation': True})

        def source_preflight(self):
            # This is a service-only phase, with no candidate/vendor PHP. Do not
            # manufacture the old dependency-audit status from a source hash.
            # The composed coordinator must qualify locked dependencies separately.
            snapshot.verify(source_root, expected_sha)
            self.record('sealed_source_preflight', {'sealed_source_match': True, 'dependency_acceptance': False})

    owner = OwnedService()
    owner.receipt['candidate_sha'] = expected_sha
    owner.receipt['candidate_tree_oid'] = witness['tree_oid']
    owner.receipt['source_witness_sha256'] = hashlib.sha256((source_root / '.ledger-source.json').read_bytes()).hexdigest()
    owner.persist()
    owner.admin_class = factory.Admin
    owner.runtime_image = data['runtime_image']
    return owner, selected


def fixed_admin(owner, sql):
    """Internal fixed-source calls only; native rows/secrets never enter receipts."""
    client = owner.admin_class(owner)
    try:
        require(client.query('SET SESSION sql_log_bin=0') == [], 'admin-binlog')
        require(client.query("UPDATE performance_schema.threads SET INSTRUMENTED='NO',HISTORY='NO' WHERE PROCESSLIST_ID=CONNECTION_ID() ") == [], 'admin-suppression')
        require(client.query('SELECT INSTRUMENTED,HISTORY FROM performance_schema.threads WHERE PROCESSLIST_ID=CONNECTION_ID()') == ['NO\tNO'], 'admin-pfs')
        require(client.query('SELECT @@SESSION.sql_log_bin') == ['0'], 'admin-binlog-verified')
        return client.query(sql)
    except BaseException:
        client.failed = True
        raise
    finally:
        client.close()


def qualify_observer(owner, selected):
    """Exact same-account denied→scoped-grant→positive witness transition.

    This phase executes only the independently sealed PDO qualification source.
    It cannot issue candidate admission or run a ledger provider.
    """
    require(os.geteuid() == 0 and owner.receipt['main_ready'] is True
            and not owner.receipt['transport_uncertain'], 'owned-main-required')
    owner.end = time.monotonic() + 655
    owner.limit = owner.end - 30
    owner.receipt.update(observer_qualified=False, observer_runtime_cleanup_verified=False,
                         observer_mutation='NOT_DISPATCHED')
    owner.persist()
    row = owner.inspect()
    import ownership
    ownership.running(owner.anchor, row, selected, 'main')
    mode_rows = fixed_admin(owner, 'SELECT @@GLOBAL.partial_revokes')
    require(mode_rows in (['0'], ['1']), 'observer-mode')
    mode = int(mode_rows[0])
    original = frozenset((USAGE, 'GRANT ALL PRIVILEGES ON `vaseyaudio_production_identity`.* TO `account_fixture`@`%`'))
    initial = fixed_admin(owner, SHOW)
    require(len(initial) == 2 and frozenset(initial) == original, 'fresh-account-grants')
    baseline = '; '.join(line.replace('`account_fixture`@`%`', "'account_fixture'@'%'")
                         for line in sorted(grants(mode) - original))
    owner.receipt['observer_mutation'] = 'BASELINE_DISPATCHED_UNKNOWN'
    owner.persist()
    require(fixed_admin(owner, baseline) == [], 'baseline-grant-output')
    before = grant_set(fixed_admin(owner, SHOW), mode)
    instruments = instrumentation(fixed_admin(owner, INSTRUMENTATION))
    owner.receipt['observer_mutation'] = 'BASELINE_VERIFIED'
    owner.persist()
    base = owner.directory.parent
    admission = base / 'observer-admission'
    admission.mkdir(mode=0o750)
    os.chown(admission, 0, 1000)
    admission.chmod(0o750)
    secret = admission / 'fixture-password'
    shutil.copyfile(owner.directory / 'php-password', secret)
    os.chown(secret, 0, 1000)
    secret.chmod(0o440)
    metadata = secret.stat()
    document = {'schema': 1, 'kind': 'owned-observer-qualification-only', 'native_admitted': False,
                'container_id': owner.container, 'server_uuid': owner.receipt['candidate_runtime']['server_uuid'],
                'ip': owner.receipt['candidate_runtime']['ip'],
                'secret_metadata': {name: getattr(metadata, 'st_' + name)
                                    for name in ('dev', 'ino', 'uid', 'gid', 'mode', 'nlink', 'size')}}
    raw = (json.dumps(document, separators=(',', ':')) + '\n').encode()
    descriptor = admission / 'observer.json'
    descriptor.write_bytes(raw)
    os.chown(descriptor, 0, 1000)
    descriptor.chmod(0o444)
    pin = hashlib.sha256(raw).hexdigest()
    evidence = base / 'observer-evidence'
    evidence.mkdir(mode=0o700)
    os.chown(evidence, 1000, 1000)
    evidence.chmod(0o700)
    name = 'va-ledger-observer-' + selected['owner_value']
    container = None
    probes_passed = False
    cleanup = False
    try:
        container = owner.command(['docker', 'create', '--pull=never', '--platform=linux/amd64', '--name', name,
            '--label', 'va.ledger.observer=' + selected['owner_value'], '--network=bridge', '--read-only',
            '--user=1000:1000', '--cap-drop=ALL', '--security-opt=no-new-privileges:true', '--memory=512m',
            '--memory-swap=512m', '--cpus=1', '--pids-limit=64', '--log-driver=none', '--init=false', '--restart=no',
            '--mount', 'type=bind,src=' + str(HERE) + ',dst=/qualification,readonly',
            '--mount', 'type=bind,src=' + str(admission) + ',dst=/admission,readonly',
            '--mount', 'type=bind,src=' + str(evidence) + ',dst=/evidence',
            '--entrypoint=/bin/sleep', owner.runtime_image, '480'], 'observer_create').strip()
        require(re.fullmatch('[0-9a-f]{64}', container), 'observer-created-id')
        owner.receipt['observer_container_id'] = container
        owner.persist()
        require(owner.command(['docker', 'start', container], 'observer_start').strip() == container, 'observer-started-id')

        def probe(phase):
            require(phase in ('before', 'after'), 'observer-phase')
            output = owner.command(['docker', 'exec', '--user=1000:1000', container, '/usr/bin/timeout',
                '--signal=TERM', '--kill-after=10s', '30s', '/usr/bin/php8.4', '-d', 'memory_limit=512M',
                '/qualification/observer-probe.php', '/evidence', document['ip'], pin, phase],
                'observer_' + phase, seconds=45)
            require(len(output.encode()) <= 1024 and output.count('\n') == 1 and output.endswith('\n'), 'observer-output')
            facts = runtime.load(output.encode())
            keys = FACTS + (() if phase == 'before' else ('own_current_event', 'instrumentation_match', 'global_resource_access'))
            require(tuple(facts) == keys and all(value is True for value in facts.values()), 'same-account-observer-proof')
            owner.record('observer_' + phase + '_qualified', dict(facts))

        probe('before')
        delta = grants(mode, True) - before
        grant = '; '.join(line.replace('`account_fixture`@`%`', "'account_fixture'@'%'") for line in sorted(delta))
        owner.receipt['observer_mutation'] = 'DELTA_DISPATCHED_UNKNOWN'
        owner.persist()  # One dispatch only; partial/unknown mutation is never retried.
        require(fixed_admin(owner, grant) == [], 'observer-delta-output')
        after = grant_set(fixed_admin(owner, SHOW), mode, True)
        observer_delta(before, after, mode)
        require(fixed_admin(owner, 'SELECT @@GLOBAL.partial_revokes') == mode_rows
                and instrumentation(fixed_admin(owner, INSTRUMENTATION)) == instruments, 'observer-profile-moved')
        owner.receipt['observer_mutation'] = 'EXACT_DELTA_VERIFIED'
        owner.persist()
        probe('after')
        probes_passed = True
    finally:
        owner.limit = min(owner.end, time.monotonic() + 20)
        if container and re.fullmatch('[0-9a-f]{64}', container):
            owner.command(['docker', 'kill', '--signal=TERM', container], 'observer_stop')
            require(owner.command(['docker', 'wait', container], 'observer_wait') == '143\n', 'observer-wait')
            owner.command(['docker', 'rm', container], 'observer_remove')
            require(owner.command(['docker', 'ps', '-a', '--filter', 'id=' + container, '--format', '{{.ID}}'], 'observer_removed') == '', 'observer-removal')
            cleanup = True
    require(probes_passed and cleanup and not owner.receipt['transport_uncertain'], 'observer-qualification-incomplete')
    ownership.running(owner.anchor, owner.inspect(), selected, 'main')
    owner.memory('observer_after')
    owner.receipt.update(observer_qualified=True, observer_runtime_cleanup_verified=True)
    owner.persist()
    # The root suite coordinator still owns the live service. No descriptor here
    # authorizes candidate PHP, releases a lease or accepts any ledger provider.
    return {'same_account_observer_qualified': True, 'exact_six_to_ten_grants': True,
            'native_admitted': False, 'owned_service_live': True}
