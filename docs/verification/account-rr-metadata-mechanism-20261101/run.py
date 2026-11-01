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
PACKET = ROOT / 'docs/verification/account-rr-metadata-mechanism-20261101'
EVIDENCE = Path('/workspace/account-guard-evidence-20261101')
RUNTIME = 'va-pcc-runtime-20261101'
SERVER = 'va-account-guard-mysql-20261101'
IMAGE = 'sha256:4aae07a2c195abb9b3e92449443b39b51212f555a35ff8f93da0e82683f45de8'


def command(args):
    result = subprocess.run(args, text=True, capture_output=True, check=False)
    if result.returncode:
        raise RuntimeError('Preflight/cleanup command failed: ' + args[0])
    return result.stdout


def preflight(expected):
    if not re.fullmatch('[a-f0-9]{40}', expected):
        raise RuntimeError('Exact reviewed SHA required; no native SQL issued')
    if command(['git', '-C', str(ROOT), 'rev-parse', 'HEAD']).strip() != expected:
        raise RuntimeError('SHA moved; no native SQL issued')
    if command(['git', '-C', str(ROOT), 'status', '--porcelain']).strip():
        raise RuntimeError('Source dirty; no native SQL issued')
    fmt = ('{"running":{{.State.Running}},"image":"{{.Config.Image}}",'
           '"image_id":"{{.Image}}","hostname":"{{.Config.Hostname}}","memory":{{.HostConfig.Memory}},'
           '"swap":{{.HostConfig.MemorySwap}},"cpu":{{.HostConfig.NanoCpus}},'
           '"ip":"{{.NetworkSettings.IPAddress}}","ports":{{json .NetworkSettings.Ports}},'
           '"mounts":{{json .Mounts}}}')
    server = json.loads(command(['docker', 'inspect', SERVER, '--format', fmt]))
    bindings = server['ports'].get('3306/tcp')
    data = [m for m in server['mounts'] if m['Destination'] == '/var/lib/mysql']
    if (not server['running'] or server['image'] != 'mysql:8.4.11' or server['image_id'] != IMAGE
            or server['memory'] != 2147483648 or server['swap'] != 2147483648
            or server['cpu'] != 1000000000
            or bindings != [{'HostIp': '127.0.0.1', 'HostPort': '33089'}]
            or len(data) != 1 or data[0]['Source'] != str(EVIDENCE / 'mysql-data')):
        raise RuntimeError('Wrong owned native instance/configuration; no native SQL issued')
    if command(['docker', 'inspect', RUNTIME, '--format', '{{.State.Running}}']).strip() != 'true':
        raise RuntimeError('Existing runtime unavailable; no native SQL issued')
    return server


def cleanup_audit(directory):
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
    result = subprocess.run(['docker', 'exec', '-i', SERVER, 'mysql', '-uroot', '-N', '--batch'],
                            input=sql, text=True, capture_output=True, check=False)
    (directory / 'cleanup-audit.raw').write_text(result.stdout + result.stderr)
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


def run(expected):
    server = preflight(expected)
    os.umask(0o077)
    token = secrets.token_hex(6)
    run_name = 'rrns-' + time.strftime('%Y%m%dT%H%M%SZ', time.gmtime()) + '-' + token
    directory = EVIDENCE / run_name
    directory.mkdir(mode=0o700, exist_ok=False)
    manifest = {'expected_sha': expected, 'token': token, 'run_name': run_name,
                'schemas': ['va_rrns_20261101_' + token, 'va_rrns_20261101_' + token + '-peer'],
                'ip': server['ip'], 'server': SERVER, 'configuration': server,
                'primitive_hash': hashlib.sha256((ROOT / 'app/Domain/Customers/ProductionIdentity/AccountAdmissionGuards.php').read_bytes()).hexdigest(),
                'source_hashes': {name: hashlib.sha256((PACKET / name).read_bytes()).hexdigest()
                                  for name in ('probe.php', 'run.py')}}
    path = directory / 'manifest.json'
    with path.open('x') as output:
        json.dump(manifest, output, indent=2)
    args = ['docker', 'exec', '-i', '--user', '1000:1000', RUNTIME, 'php', str(PACKET / 'probe.php'), str(path)]
    (directory / 'command.json').write_text(json.dumps(args))
    process = subprocess.Popen(args, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, bufsize=0)
    selector = selectors.DefaultSelector()
    selector.register(process.stdout, selectors.EVENT_READ)
    deadline = time.monotonic() + 120
    cancelled = False
    abort_sent = False
    buffer = b''
    transport_errors = []
    code = 2
    try:
        with (directory / 'ordered-stream.raw').open('wb') as raw:
            while True:
                now = time.monotonic()
                if now > deadline and not cancelled:
                    (directory / 'cancel').write_text('Host watchdog; retain partial state, no query retry')
                    cancelled = True
                if now > deadline + 15 and not abort_sent:
                    # Signal registered actual PHP PIDs INSIDE runtime, never kill only docker exec CLI.
                    abort = subprocess.run(['docker', 'exec', '--user', '1000:1000', RUNTIME, 'php',
                                            str(PACKET / 'probe.php'), '--abort', str(path)],
                                           capture_output=True, text=True, check=False, timeout=10)
                    (directory / 'abort-transport.txt').write_text(abort.stdout + abort.stderr)
                    abort_sent = True
                events = selector.select(timeout=0.2)
                if events:
                    data = os.read(process.stdout.fileno(), 65536)
                    if not data:
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
                            (directory / 'cancel').write_text('Unattributed/non-JSON transport; no retry')
                            cancelled = True
                elif process.poll() is not None:
                    remaining = process.stdout.read()
                    if remaining:
                        raw.write(remaining)
                        raw.flush()
                        buffer += remaining
                    break
            if buffer:
                transport_errors.append(buffer.decode('utf-8', errors='replace'))
        code = process.wait(timeout=10)
    except Exception as error:
        transport_errors.append(type(error).__name__ + ': ' + str(error))
        (directory / 'cancel').write_text('Transport exception; no SQL retry')
        cancelled = True
        if not abort_sent:
            try:
                abort = subprocess.run(['docker', 'exec', '--user', '1000:1000', RUNTIME, 'php',
                                        str(PACKET / 'probe.php'), '--abort', str(path)],
                                       capture_output=True, text=True, check=False, timeout=10)
                (directory / 'abort-transport.txt').write_text(abort.stdout + abort.stderr)
            except Exception as abort_error:
                (directory / 'abort-transport.txt').write_text(type(abort_error).__name__)
            abort_sent = True
        try:
            code = process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            code = 2  # Never kill only the Docker client and call server cleanup successful.
    try:
        audit = cleanup_audit(directory)
    except Exception as error:
        audit = False
        (directory / 'cleanup-audit-error.txt').write_text(type(error).__name__ + ': ' + str(error))
    (directory / 'launcher-result.json').write_text(json.dumps({'driver_exit': code, 'cleanup_verified': audit,
                                                               'transport_errors': transport_errors,
                                                               'watchdog_cancelled': cancelled,
                                                               'scratch_state_retained': True}, indent=2))
    print(json.dumps({'receipt_directory': str(directory), 'driver_exit': code, 'cleanup_verified': audit,
                      'scratch_state_retained': True, 'runtime_acceptance': False}))
    return code if code else (0 if audit and not transport_errors and not cancelled else 2)


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit('Usage: run.py EXACT_REVIEWED_SHA; no SQL issued')
    raise SystemExit(run(sys.argv[1]))
