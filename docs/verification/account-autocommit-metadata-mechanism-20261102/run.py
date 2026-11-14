"""One root-scheduled exact-SHA raw metadata experiment. No arbitrary endpoint/schema/SQL input."""
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import selectors
import subprocess
import sys
import time

ROOT = Path('/workspace/VA-Studio-account-guard-v2')
PACKET = ROOT / 'docs/verification/account-autocommit-metadata-mechanism-20261102'
EVIDENCE = Path('/workspace/account-guard-evidence-20261101')
RUNTIME = 'va-pcc-runtime-20261101'
SERVER = 'va-account-guard-mysql-20261101'
IMAGE = 'sha256:4aae07a2c195abb9b3e92449443b39b51212f555a35ff8f93da0e82683f45de8'


PREFLIGHT_TIMEOUT = 5
AUDIT_TIMEOUT = 5
ABORT_TIMEOUT = 10
CANCEL_AFTER = 120
ABORT_AFTER = 135
HARD_AFTER = 150


class CommandTimeout(RuntimeError):
    pass


def decoded(value):
    if value is None:
        return ''
    return value.decode('utf-8', errors='replace') if isinstance(value, bytes) else value


def bounded_command(args, directory, label, timeout, *, run_fn=None, **options):
    """Bound CLI wait only; a timed-out Docker CLI never proves an inner PHP client ended."""
    run_fn = run_fn or subprocess.run
    try:
        result = run_fn(args, text=True, capture_output=True, check=False, timeout=timeout, **options)
    except subprocess.TimeoutExpired as error:
        stdout, stderr = error.stdout or b'', error.stderr or b''
        status = {'status': 'TIMEOUT', 'timeout_seconds': timeout, 'returncode': None,
                  'inner_process_cleanup_proven': False}
        if directory is not None:
            (directory / (label + '.stdout')).write_bytes(stdout if isinstance(stdout, bytes) else stdout.encode('utf-8'))
            (directory / (label + '.stderr')).write_bytes(stderr if isinstance(stderr, bytes) else stderr.encode('utf-8'))
            (directory / (label + '.status.json')).write_text(json.dumps(status, indent=2))
        raise CommandTimeout(label + ' deadline exceeded; inner cleanup unverified') from error
    if directory is not None:
        (directory / (label + '.stdout')).write_text(decoded(result.stdout))
        (directory / (label + '.stderr')).write_text(decoded(result.stderr))
        (directory / (label + '.status.json')).write_text(json.dumps(
            {'status': 'EXITED', 'returncode': result.returncode, 'timeout_seconds': timeout}, indent=2))
    return result


def command(args, directory=None, label='preflight', *, run_fn=None):
    result = bounded_command(args, directory, label, PREFLIGHT_TIMEOUT, run_fn=run_fn)
    if result.returncode:
        raise RuntimeError('Preflight command failed: ' + label)
    return result.stdout


def preflight(expected, directory=None):
    if not re.fullmatch('[a-f0-9]{40}', expected):
        raise RuntimeError('Exact reviewed SHA required; no native SQL issued')
    if command(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], directory, 'preflight-head').strip() != expected:
        raise RuntimeError('SHA moved; no native SQL issued')
    if command(['git', '-C', str(ROOT), 'status', '--porcelain'], directory, 'preflight-status').strip():
        raise RuntimeError('Source dirty; no native SQL issued')
    fmt = ('{"running":{{.State.Running}},"image":"{{.Config.Image}}",'
           '"image_id":"{{.Image}}","hostname":"{{.Config.Hostname}}","memory":{{.HostConfig.Memory}},'
           '"swap":{{.HostConfig.MemorySwap}},"cpu":{{.HostConfig.NanoCpus}},'
           '"ip":"{{.NetworkSettings.IPAddress}}","ports":{{json .NetworkSettings.Ports}},'
           '"mounts":{{json .Mounts}}}')
    server = json.loads(command(['docker', 'inspect', SERVER, '--format', fmt], directory, 'preflight-server'))
    bindings = server['ports'].get('3306/tcp')
    data = [m for m in server['mounts'] if m['Destination'] == '/var/lib/mysql']
    if (not server['running'] or server['image'] != 'mysql:8.4.11' or server['image_id'] != IMAGE
            or server['memory'] != 2147483648 or server['swap'] != 2147483648
            or server['cpu'] != 1000000000
            or bindings != [{'HostIp': '127.0.0.1', 'HostPort': '33089'}]
            or len(data) != 1 or data[0]['Source'] != str(EVIDENCE / 'mysql-data')):
        raise RuntimeError('Wrong owned native instance/configuration; no native SQL issued')
    if command(['docker', 'inspect', RUNTIME, '--format', '{{.State.Running}}'], directory, 'preflight-runtime').strip() != 'true':
        raise RuntimeError('Existing runtime unavailable; no native SQL issued')
    return server


def cleanup_audit(directory, *, run_fn=None):
    clients = {}
    paths = list(directory.glob('worker-*-ready.json'))
    paths += [directory / 'owned-clients.json']
    for path in paths:
        if path.exists():
            value = json.loads(path.read_text())
            if 'connection_id' in value:
                clients[path.name] = value
            else:
                clients.update(value)
    ids = sorted({v['connection_id'] for v in clients.values() if isinstance(v.get('connection_id'), int) and v['connection_id'] > 0})
    threads = sorted({v['thread_id'] for v in clients.values() if isinstance(v.get('thread_id'), int) and v['thread_id'] > 0})
    if not ids:
        (directory / 'cleanup-audit.json').write_text(json.dumps({'status': 'INCONCLUSIVE', 'reason': 'No owned connection identities captured'}))
        return False
    il = ','.join(map(str, ids))
    tl = ','.join(map(str, threads)) or '0'
    # Only owned identities, including resources in mysql DD/global schemas. Never app/all-session dumps.
    sql = (f"SELECT 'clients',COUNT(*) FROM information_schema.PROCESSLIST WHERE ID IN ({il});"
           f"SELECT 'transactions',COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id IN ({il});"
           f"SELECT 'metadata_locks',COUNT(*) FROM performance_schema.metadata_locks WHERE OWNER_THREAD_ID IN ({tl});"
           f"SELECT 'data_locks',COUNT(*) FROM performance_schema.data_locks WHERE THREAD_ID IN ({tl});")
    (directory / 'cleanup-audit.sql').write_text(sql)
    try:
        result = bounded_command(['docker', 'exec', '-i', SERVER, 'mysql', '-uroot', '-N', '--batch'],
                                 directory, 'cleanup-audit', AUDIT_TIMEOUT, run_fn=run_fn, input=sql)
    except CommandTimeout:
        (directory / 'cleanup-audit.raw').write_bytes((directory / 'cleanup-audit.stdout').read_bytes() + (directory / 'cleanup-audit.stderr').read_bytes())
        (directory / 'cleanup-audit.json').write_text(json.dumps(
            {'status': 'STOP_CLEANUP_UNVERIFIED', 'reason': 'Server audit deadline',
             'owned_clients': clients, 'inner_process_cleanup_proven': False}, indent=2))
        return False
    (directory / 'cleanup-audit.raw').write_text(decoded(result.stdout) + decoded(result.stderr))
    counts = {}
    if result.returncode == 0:
        for line in result.stdout.splitlines():
            key, value = line.split('\t')
            counts[key] = int(value)
    spawned = {json.loads(p.read_text())['role'] for p in directory.glob('worker-*-spawn.json')}
    ready = {json.loads(p.read_text())['role'] for p in directory.glob('worker-*-ready.json')}
    success = (spawned == ready and set(counts) == {'clients', 'transactions', 'metadata_locks', 'data_locks'}
               and all(v == 0 for v in counts.values()) and bool(threads))
    (directory / 'cleanup-audit.json').write_text(json.dumps({'status': 'VERIFIED' if success else 'FAILED_OR_INCONCLUSIVE',
                                                            'counts': counts, 'owned_clients': clients, 'all_spawned_clients_identified': spawned == ready,
                                                            'verification_connection_is_not_runtime_authority': True}, indent=2))
    return success


def supervise(process, directory, path, *, clock=None, selector=None, run_fn=None, read_fn=None):
    """Finite even when stdout never closes and abort finds no registered target. No unknown PID kill."""
    clock = clock or time.monotonic
    selector = selector or selectors.DefaultSelector()
    read_fn = read_fn or os.read
    selector.register(process.stdout, selectors.EVENT_READ)
    started = clock()
    cancel_at, abort_at, hard_at = started + CANCEL_AFTER, started + ABORT_AFTER, started + HARD_AFTER
    cancelled = False
    abort_sent = False
    abort_unverified = False
    hard_timeout = False
    stream_eof = False
    buffer = b''
    transport_errors = []
    with (directory / 'ordered-stream.raw').open('wb') as raw:
        try:
            while True:
                now = clock()
                if now >= hard_at:
                    hard_timeout = True
                    transport_errors.append('Final host deadline; owned PHP termination unverified')
                    break
                if now >= cancel_at and not cancelled:
                    (directory / 'cancel').write_text('Host watchdog; preserve partial state; no retry')
                    cancelled = True
                if now >= abort_at and not abort_sent:
                    abort_sent = True  # Exactly one attempt, even if its CLI times out.
                    try:
                        result = bounded_command(
                            ['docker', 'exec', '--user', '1000:1000', RUNTIME, 'php',
                             str(PACKET / 'probe.php'), '--abort', str(path)],
                            directory, 'abort-transport', min(ABORT_TIMEOUT, hard_at - now), run_fn=run_fn)
                        abort_unverified = result.returncode != 0
                    except Exception as error:
                        abort_unverified = True
                        transport_errors.append(type(error).__name__ + ': ' + str(error))
                    continue  # Recheck the hard clock after any blocking abort CLI.
                events = selector.select(timeout=min(0.2, hard_at - now))
                if events:
                    data = read_fn(process.stdout.fileno(), 65536)
                    if not data:
                        stream_eof = True
                        break
                    raw.write(data)
                    raw.flush()
                    buffer += data
                    while b'\n' in buffer:
                        line, buffer = buffer.split(b'\n', 1)
                        try:
                            event = json.loads(line)
                            if not isinstance(event, dict):
                                raise ValueError('Not an ordered event object')
                        except (ValueError, UnicodeDecodeError):
                            transport_errors.append(line.decode('utf-8', errors='replace'))
                            (directory / 'cancel').write_text('Unattributed transport; no retry')
                            cancelled = True
                # Never call stdout.read() or an unbounded wait when the Docker process exits:
                # another process can still hold its stdout open. The final clock remains authoritative.
        except Exception as error:
            transport_errors.append(type(error).__name__ + ': ' + str(error))
            (directory / 'cancel').write_text('Transport exception; termination unverified; no retry')
            cancelled = True
            abort_unverified = True
        finally:
            selector.close()
        if buffer:
            transport_errors.append(buffer.decode('utf-8', errors='replace'))
    code = process.poll()
    if stream_eof and code is None and clock() < hard_at:
        try:
            code = process.wait(timeout=min(1, hard_at - clock()))
        except subprocess.TimeoutExpired:
            transport_errors.append('EOF observed but Docker exit not proved within bounded final wait')
    if clock() >= hard_at:
        hard_timeout = True
    termination_proven = stream_eof and code is not None and not hard_timeout and not abort_unverified
    result = {'driver_exit': code, 'watchdog_cancelled': cancelled, 'abort_attempted': abort_sent,
              'hard_deadline_reached': hard_timeout, 'stream_eof': stream_eof,
              'termination_proven': termination_proven, 'transport_errors': transport_errors,
              'elapsed_seconds': clock() - started}
    (directory / 'supervisor-result.json').write_text(json.dumps(result, indent=2))
    return result


def final_result(supervision, audit):
    verified = bool(audit and supervision['termination_proven'])
    clean_completion = (verified and supervision['driver_exit'] == 0
                        and not supervision['transport_errors'] and not supervision['watchdog_cancelled'])
    outcome = ('COMPLETED_MECHANISM_ONLY' if clean_completion else
               'STOP_CLEANUP_VERIFIED' if verified else 'STOP_CLEANUP_UNVERIFIED')
    return {**supervision, 'outcome': outcome, 'cleanup_verified': verified,
            'lease_returnable': verified, 'scratch_state_retained': True, 'runtime_acceptance': False}


def run(expected):
    if not re.fullmatch('[a-f0-9]{40}', expected):
        raise RuntimeError('Exact reviewed SHA required; no native SQL issued')
    os.umask(0o077)
    token = secrets.token_hex(6)
    run_name = 'acmeta-' + time.strftime('%Y%m%dT%H%M%SZ', time.gmtime()) + '-' + token
    directory = EVIDENCE / run_name
    directory.mkdir(mode=0o700, exist_ok=False)
    (directory / 'preflight-request.json').write_text(json.dumps({'expected_sha': expected, 'native_started': False}))
    try:
        server = preflight(expected, directory)
    except Exception as error:
        result = {'outcome': 'STOP_PREFLIGHT_NO_SQL', 'error': type(error).__name__ + ': ' + str(error),
                  'native_started': False, 'cleanup_verified': None, 'scratch_state_retained': True}
        (directory / 'launcher-result.json').write_text(json.dumps(result, indent=2))
        print(json.dumps({'receipt_directory': str(directory), **result}))
        return 2
    manifest = {'expected_sha': expected, 'token': token, 'run_name': run_name,
                'schemas': ['va_acmeta_20261102_' + token, 'va_acmeta_20261102_' + token + '-peer'],
                'ip': server['ip'], 'server': SERVER, 'configuration': server,
                'primitive_hash': hashlib.sha256((ROOT / 'app/Domain/Customers/ProductionIdentity/AccountAdmissionGuards.php').read_bytes()).hexdigest(),
                'source_hashes': {name: hashlib.sha256((PACKET / name).read_bytes()).hexdigest()
                                  for name in ('probe.php', 'run.py')}}
    path = directory / 'manifest.json'
    with path.open('x') as output:
        json.dump(manifest, output, indent=2)
    args = ['docker', 'exec', '-i', '--user', '1000:1000', RUNTIME, 'php', str(PACKET / 'probe.php'), str(path)]
    (directory / 'command.json').write_text(json.dumps(args))
    try:
        process = subprocess.Popen(args, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, bufsize=0)
        supervision = supervise(process, directory, path)
    except Exception as error:
        (directory / 'cancel').write_text('Launcher setup/transport failure; no unknown PID kill')
        supervision = {'driver_exit': None, 'termination_proven': False, 'watchdog_cancelled': True,
                       'abort_attempted': False, 'hard_deadline_reached': False, 'stream_eof': False,
                       'transport_errors': [type(error).__name__ + ': ' + str(error)]}
        (directory / 'supervisor-result.json').write_text(json.dumps(supervision, indent=2))
    try:
        audit = cleanup_audit(directory)
    except Exception as error:
        audit = False
        (directory / 'cleanup-audit-error.txt').write_text(type(error).__name__ + ': ' + str(error))
    result = final_result(supervision, audit)
    (directory / 'launcher-result.json').write_text(json.dumps(result, indent=2))
    print(json.dumps({'receipt_directory': str(directory), **result}))
    return 0 if result['outcome'] == 'COMPLETED_MECHANISM_ONLY' else 2


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit('Usage: run.py EXACT_REVIEWED_SHA; no SQL issued')
    raise SystemExit(run(sys.argv[1]))
