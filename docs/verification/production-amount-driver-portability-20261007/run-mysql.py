#!/usr/bin/env python3
"""Run a command against a fresh local MySQL 8.4 instance with normal durability."""
import argparse
import base64
import json
import os
from pathlib import Path
import signal
import shutil
import socket
import subprocess
import sys
import tempfile
import time

RUNTIME = Path('/workspace/scratch/0c039e9e0645/runtime-recovery')
ROOT = Path(__file__).resolve().parent


def main():
    args = sys.argv[1:]
    if args[:1] == ['--']:
        args = args[1:]
    if not args:
        raise SystemExit('Usage: run-tests.py -- COMMAND [ARGS...]')
    env = dict(os.environ)
    env['PATH'] = str(RUNTIME / 'bin') + os.pathsep + env.get('PATH', '')
    env['PHPRC'] = str(RUNTIME / 'php.ini')
    env['PHP_INI_SCAN_DIR'] = ''
    env['LD_LIBRARY_PATH'] = str(RUNTIME / 'root/usr/lib/x86_64-linux-gnu') + os.pathsep + env.get('LD_LIBRARY_PATH', '')
    env.update(APP_ENV='testing', DB_CONNECTION='mysql', DB_HOST='127.0.0.1',
               DB_DATABASE='vaseyaudio_test', DB_USERNAME='root', DB_PASSWORD='',
               DB_SOCKET='', DB_URL='')
    env.setdefault('APP_KEY', 'base64:' + base64.b64encode(os.urandom(32)).decode())
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as port_probe:
        port_probe.bind(('127.0.0.1', 0))
        env['DB_PORT'] = str(port_probe.getsockname()[1])
    server = None
    child = None

    def interrupted(signum, frame):
        if child is not None and child.poll() is None:
            child.terminate()
        raise KeyboardInterrupt

    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    # Keep disposable database files outside shared source/worktree storage.
    # The source and complete durability settings remain unchanged.
    data_root = Path(os.environ.get('VA_MYSQL_DATA_ROOT', '/tmp'))
    with tempfile.TemporaryDirectory(prefix='va-mysql-', dir=data_root) as temporary:
        directory = Path(temporary)
        data = directory / 'data'
        data.mkdir(mode=0o700)
        exports = directory / 'exports'
        exports.mkdir(mode=0o700)
        log = directory / 'mysql.log'
        mysqld = [str(RUNTIME / 'bin/mysqld'), '--no-defaults', '--user=root',
                  '--basedir=' + str(RUNTIME / 'root/usr'), '--datadir=' + str(data)]
        mysql = [str(RUNTIME / 'bin/mysql'), '--no-defaults', '--protocol=tcp',
                 '--host=127.0.0.1', '--port=' + env['DB_PORT'], '--user=root',
                 '--batch', '--skip-column-names']
        with log.open('w') as output:
            init = subprocess.run(mysqld + ['--initialize-insecure'], env=env,
                                  stdout=output, stderr=subprocess.STDOUT, timeout=60)
            if init.returncode:
                raise RuntimeError('MySQL initialization failed: ' + log.read_text()[-3000:])
            server = subprocess.Popen(mysqld + [
                '--socket=', '--port=' + env['DB_PORT'], '--bind-address=127.0.0.1',
                '--pid-file=' + str(directory / 'mysql.pid'),
                '--secure-file-priv=' + str(exports), '--mysqlx=0',
                '--innodb-flush-log-at-trx-commit=1', '--sync-binlog=1',
            ], env=env, stdout=output, stderr=subprocess.STDOUT)
            try:
                deadline = time.monotonic() + 30
                while True:
                    ready = subprocess.run(mysql + ['--execute=SELECT 1'], env=env,
                                           capture_output=True, text=True)
                    if ready.returncode == 0:
                        break
                    if server.poll() is not None or time.monotonic() > deadline:
                        raise RuntimeError('MySQL startup failed: ' + log.read_text()[-3000:])
                    time.sleep(0.1)
                sql = ('CREATE DATABASE vaseyaudio_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; '
                       'SELECT VERSION(), @@innodb_flush_log_at_trx_commit, @@sync_binlog, '
                       '@@innodb_doublewrite, @@log_bin;')
                probe = subprocess.run(mysql + ['--execute=' + sql], env=env,
                                       check=True, capture_output=True, text=True)
                evidence = probe.stdout.strip().split('\t')
                if evidence != ['8.4.11', '1', '1', 'ON', '1']:
                    raise RuntimeError('Unexpected native MySQL runtime configuration: ' + repr(evidence))
                print(json.dumps({'mysql': evidence[0], 'innodb_flush_log_at_trx_commit': 1,
                                  'sync_binlog': 1, 'innodb_doublewrite': 'ON', 'log_bin': True,
                                  'transport': 'private loopback TCP', 'database': 'fresh disposable'}), flush=True)
                child = subprocess.Popen(args, env=env)
                return child.wait()
            finally:
                if child is not None and child.poll() is None:
                    child.terminate()
                    try:
                        child.wait(timeout=10)
                    except subprocess.TimeoutExpired:
                        child.kill()
                        child.wait()
                if server.poll() is None:
                    subprocess.run(mysql + ['--execute=SHUTDOWN'], env=env, capture_output=True, timeout=10)
                    try:
                        server.wait(timeout=15)
                    except subprocess.TimeoutExpired:
                        server.terminate()
                        server.wait(timeout=10)
                output.flush()
                diagnostic = os.environ.get('VA_MYSQL_DIAGNOSTIC_LOG')
                if diagnostic:
                    shutil.copyfile(log, diagnostic)


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        raise SystemExit(130)
