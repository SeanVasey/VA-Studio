#!/usr/bin/env python3
"""New owned synthetic server; save subprocess outcomes before fallible probes."""
import argparse
import datetime
import json
from pathlib import Path
import subprocess
import time

parser = argparse.ArgumentParser()
parser.add_argument('--stage', choices=['start', 'ready'], required=True)
args = parser.parse_args()
output = Path('/workspace/runtime/account-features-http-evidence-20261015')
socket_dir = Path('/workspace/runtime/mysql-account-features-run')
container = 'va-studio-private-mysql-account-features-20261015'
volume = 'va-studio-private-mysql-account-features-data-20261015'
database = 'vaseyaudio_account_features'

def save(name, record):
    with (output / name).open('x') as stream:
        json.dump(record, stream, indent=2); stream.write('\n')

def execute(argv, receipt):
    result = subprocess.run(argv, text=True, capture_output=True)
    # Persist the SQL/Docker exit and output first. A later filesystem error cannot erase them.
    record = {'recorded_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
              'argv': argv, 'process_exit': result.returncode,
              'stdout': result.stdout, 'stderr': result.stderr}
    save(receipt, record)
    result.check_returncode()
    return result

if args.stage == 'start':
    assert not (output / 'mysql-server-launch.json').exists()
    names = subprocess.check_output(['docker', 'ps', '-a', '--format', '{{.Names}}'], text=True).splitlines()
    volumes = subprocess.check_output(['docker', 'volume', 'ls', '--format', '{{.Name}}'], text=True).splitlines()
    assert container not in names and volume not in volumes and not socket_dir.exists()
    socket_dir.mkdir(mode=0o755)
    argv = ['docker', 'run', '--detach', '--name', container, '--publish', '127.0.0.1:33521:3306',
            '--mount', 'type=volume,source=' + volume + ',target=/var/lib/mysql',
            '--mount', 'type=bind,source=' + str(socket_dir) + ',target=/var/run/account-features',
            '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env', 'MYSQL_ROOT_HOST=%',
            'mysql:8.4', '--socket=/var/run/account-features/mysql.sock', '--default-time-zone=+00:00']
    execute(argv, 'mysql-server-launch.json')
    print('New owned server launch recorded. Run ready stage after startup; no SQL was issued.')
else:
    launch = json.loads((output / 'mysql-server-launch.json').read_text())
    assert launch['process_exit'] == 0
    info = json.loads(subprocess.check_output(['docker', 'inspect', container], text=True))[0]
    assert info['Id'] == launch['stdout'].strip() and info['State']['Running']
    assert not (output / 'mysql-schema-create.json').exists(), 'Never repeat schema creation'
    # TCP is disabled during the image's temporary initializer. Wait only for the actual final daemon.
    deadline = time.monotonic() + 35
    attempts = 0
    while True:
        attempts += 1
        probe = subprocess.run(['docker', 'exec', container, 'mysql', '-h127.0.0.1', '-uroot',
                                '--batch', '--execute', 'SELECT VERSION();'], text=True, capture_output=True)
        if probe.returncode == 0:
            break
        if time.monotonic() >= deadline:
            save('mysql-readiness-wait.json', {'attempts': attempts, 'process_exit': probe.returncode,
                 'stdout': probe.stdout, 'stderr': probe.stderr, 'sql_issued': False})
            raise SystemExit('Final daemon not yet ready; no schema SQL issued.')
        time.sleep(1)
    execute(['docker', 'exec', '--user', 'root', container, 'chmod', '0755', '/var/run/account-features'],
            'mysql-socket-mode.json')
    execute(['docker', 'exec', container, 'mysql', '--socket=/var/run/account-features/mysql.sock', '-uroot',
             '--batch', '--execute', 'CREATE DATABASE ' + database + ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'],
            'mysql-schema-create.json')
    observed = execute(['docker', 'exec', container, 'mysql', '--socket=/var/run/account-features/mysql.sock', '-uroot',
                        '--batch', '--database', database, '--execute',
                        'SELECT VERSION() AS version, DATABASE() AS database_name, @@session.time_zone AS time_zone, @@session.sql_mode AS sql_mode;'],
                       'mysql-server-version.json')
    host_probe = {'recorded_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'attempts': attempts,
                  'container': container, 'private_datadir_volume': volume, 'host_port': 33521,
                  'host_socket': str(socket_dir / 'mysql.sock'), 'schema': database,
                  'scope': 'New owned synthetic local server; prior datadirs untouched. No provider/host acceptance.'}
    try:
        host_probe['socket_accessible'] = (socket_dir / 'mysql.sock').exists()
    except OSError as error:
        host_probe['socket_accessible'] = False; host_probe['probe_error'] = str(error)
    save('mysql-server-ready.json', host_probe)
    assert host_probe['socket_accessible']
    print(observed.stdout)
