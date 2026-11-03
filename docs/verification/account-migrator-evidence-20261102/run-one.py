#!/usr/bin/env python3
"""HELD SOURCE ONLY: cost integration + refreshed pins + exact review + lease precede use.
Independent PROCESS supervisor; no threads, /proc traversal, adoption or group signals.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets
import select
import signal
import socket
import struct
import sys
import tempfile
import time
from supervision import CASES, Supervision

PHP = '/usr/bin/php8.4'
ENTRY = '/workspace/account-guard-evidence-20261101/account-ledger-runtime-v1/parent-entry.php'
ENTRY_HASH = 'f9c5b502b8757f79a49ff6766036c0483d98e042f29acaf33ed3996696fde64d'
PHP_HASH = 'c984d1f078b3b0c457520723e36ab0fdf34d53a587495784228e2e52e7e23e44'
MAX_FRAME = 2048


def packet(sock, value):
    data = json.dumps(value, separators=(',', ':')).encode()
    if len(data) > MAX_FRAME or sock.send(data) != len(data):
        raise ValueError('short-or-oversize-frame')


def receive(sock, root_pid, nonce):
    data, ancillary, flags, _ = sock.recvmsg(MAX_FRAME, socket.CMSG_SPACE(struct.calcsize('3i')))
    if flags & (socket.MSG_TRUNC | socket.MSG_CTRUNC):
        raise ValueError('truncated-frame')
    credentials = [struct.unpack('3i', body) for level, kind, body in ancillary if level == socket.SOL_SOCKET and kind == socket.SCM_CREDENTIALS]
    if len(credentials) != 1 or credentials[0][0] != root_pid or credentials[0][1] != os.getuid():
        raise ValueError('unowned-sender')
    value = json.loads(data)
    if not isinstance(value, dict) or value.get('nonce') != nonce:
        raise ValueError('wrong-frame')
    return value


def engine(root, case, nonce, endpoint, gate):
    # This direct, unreaped child is already pidfd-bound before gate release.
    try:
        if os.read(gate, 1) != b'G':
            os._exit(125)
        os.close(gate)
        os.chdir(root)
        pins = json.loads(Path('docs/verification/account-migrator-evidence-20261102/pins.json').read_text())
        if sys.version.split()[0] != pins['supervisor_python_version'] or hashlib.sha256(Path(sys.executable).read_bytes()).hexdigest() != pins['supervisor_python_binary_sha256']:
            os._exit(125)
        if hashlib.sha256(Path(PHP).read_bytes()).hexdigest() != PHP_HASH or hashlib.sha256(Path(ENTRY).read_bytes()).hexdigest() != ENTRY_HASH:
            os._exit(125)
        evidence = Path(tempfile.mkdtemp(prefix='account-migrator-one-'))
        evidence.chmod(0o700)
        output = os.open(evidence / 'console.private', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        os.dup2(output, 1)
        os.dup2(output, 2)
        if output not in (1, 2):
            os.close(output)
        packet(endpoint, {'kind': 'setup', 'nonce': nonce, 'directory': str(evidence)})
        os.dup2(endpoint.fileno(), 7, inheritable=True)
        os.set_inheritable(7, True)
        if endpoint.fileno() != 7:
            endpoint.close()
        command = [PHP, '-d', 'memory_limit=512M', ENTRY, case, str(evidence), nonce, '--filter',
                   'test_normal_migrator_process_interruption_and_genuine_ledger_resume.*"' + case + '"',
                   '--stop-on-failure', '--stop-on-error', '--stop-on-warning', '--fail-on-risky',
                   '--fail-on-warning', '--fail-on-incomplete', '--fail-on-skipped', '--log-junit', str(evidence / 'junit.xml'),
                   'tests/Feature/ProductionIdentity/AccountAdmissionMigratorTest.php']
        os.execv(PHP, command)
    except BaseException:
        os._exit(125)


def report(directory, model, root_pid, fd, actions):
    # Reporter has NO signal/wait authority and can block only until cleanup deadline.
    try:
        p = Path(directory)
        def bounded(name, maximum):
            path = p / name
            if path.is_symlink() or path.stat().st_size > maximum:
                raise ValueError('report-file-bound')
            with path.open('rb') as stream:
                data = stream.read(maximum + 1)
            if len(data) > maximum:
                raise ValueError('report-file-bound')
            return data
        audit = json.loads(bounded('case.json', 8 * 1024 * 1024))
        accepted, lease = model.accept(bounded('junit.xml', 1024 * 1024), audit, root_pid)
        result = {'supervision': model.safe(), 'root_pid': root_pid, 'actions': actions,
                  'acceptance': False, 'provisional_acceptance': accepted,
                  'requires_authoritative_outer_exit_zero': True, 'lease_returnable': lease,
                  'cleanup': 'VERIFIED' if lease else 'UNVERIFIED'}
        path = p / 'launcher.json'
        with path.open('x') as output:
            os.chmod(path, 0o600)
            output.write(json.dumps(result, indent=2) + '\n')
            output.flush()
            os.fsync(output.fileno())
        os.write(fd, b'P' if accepted else b'F')
        os._exit(0)
    except BaseException:
        os._exit(125)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('case', choices=CASES)
    case = parser.parse_args().case
    if not all(hasattr(os, f) for f in ('pidfd_open', 'fork', 'waitpid')) or not hasattr(signal, 'pidfd_send_signal') or not hasattr(socket, 'SO_PASSCRED'):
        return 125
    root = str(Path(__file__).resolve().parents[3])
    nonce = secrets.token_hex(16)
    model = Supervision(time.monotonic(), case, nonce)
    parent, child = socket.socketpair(socket.AF_UNIX, socket.SOCK_DGRAM)
    parent.setsockopt(socket.SOL_SOCKET, socket.SO_PASSCRED, 1)
    parent.setblocking(False)
    child.setblocking(False)
    gate_r, gate_w = os.pipe2(os.O_CLOEXEC)
    pid = None
    root_fd = None
    root_reaped = False
    actions = []
    directory = None
    signalled = set()
    reporter_pid = reporter_fd = reporter_read = None
    report_result = b''
    reporter_status = None
    reporter_completion = None
    try:
        pid = os.fork()
        if pid == 0:
            parent.close()
            os.close(gate_w)
            engine(root, case, nonce, child, gate_r)
            os._exit(125)
        child.close()
        os.close(gate_r)
        # Sole reaper: PID cannot recycle before this owner reaps. Child is gate-blocked.
        root_fd = os.pidfd_open(pid, 0)
        os.write(gate_w, b'G')
        os.close(gate_w)
        while True:
            now = time.monotonic()
            if model.tick(now):
                break
            if not model.root_terminal:
                waited, status = os.waitpid(pid, os.WNOHANG)
                if waited == pid:
                    root_reaped = True # terminal authority latch BEFORE any fallible reducer/report step
                    model.root_exit(os.waitstatus_to_exitcode(status), time.monotonic())
                    actions.append({'kind': 'root-reaped', 'status': status})
                    os.close(root_fd)
                    root_fd = None
            if model.cleanup_end is None:
                readable, _, _ = select.select([parent], [], [], min(0.02, max(0, model.work_end - time.monotonic())))
                if readable:
                    value = receive(parent, pid, nonce)
                    if model.root_terminal:
                        raise ValueError('post-terminal-frame')
                    kind = value.get('kind')
                    token = value.get('token')
                    if kind == 'setup' and directory is None:
                        directory = value['directory']
                        if not isinstance(directory, str) or len(directory) > 512:
                            raise ValueError('setup-path')
                    elif kind == 'reserve':
                        if not isinstance(token, str) or len(token) != 32 or len(model.tokens) >= 16:
                            raise ValueError('token-bound')
                        if value.get('pid') == pid:
                            raise ValueError('root-is-not-child')
                        challenge = secrets.token_hex(16)
                        model.reserve(token, value.get('pid'), challenge, time.monotonic())
                        # QUARANTINED: no signal authority until fresh challenge answered.
                        model.bind(token, os.pidfd_open(value['pid'], 0))
                        packet(parent, {'kind': 'challenge', 'nonce': nonce, 'token': token, 'challenge': challenge})
                    elif kind == 'commit':
                        model.commit(token, value.get('challenge'), time.monotonic())
                        packet(parent, {'kind': 'ack', 'nonce': nonce, 'token': token})
                    elif kind == 'terminal':
                        model.terminal(token, value.get('status'))
                        os.close(model.tokens[token]['handle'])
                        model.tokens[token]['handle'] = None
                        packet(parent, {'kind': 'terminal-ack', 'nonce': nonce, 'token': token})
                    elif kind == 'complete':
                        model.complete()
                        packet(parent, {'kind': 'complete-ack', 'nonce': nonce})
                    elif kind == 'unknown':
                        model.fail('php-unknown-registration', time.monotonic())
                    else:
                        raise ValueError('protocol-kind')
                continue
            # ONE absolute cleanup clock. No output, filesystem, /proc or DB reads here.
            for handle in model.targets():
                if handle not in signalled:
                    try:
                        signal.pidfd_send_signal(handle, signal.SIGKILL)
                        actions.append({'kind': 'registered-pidfd-kill', 'handle': handle})
                    except ProcessLookupError:
                        pass
                    signalled.add(handle)
            if not model.root_terminal and root_fd is not None and root_fd not in signalled:
                signal.pidfd_send_signal(root_fd, signal.SIGKILL)
                signalled.add(root_fd)
            if model.root_terminal:
                if reporter_pid is None and directory is not None:
                    reporter_read, writer = os.pipe2(os.O_NONBLOCK | os.O_CLOEXEC)
                    reporter_pid = os.fork()
                    if reporter_pid == 0:
                        os.close(reporter_read)
                        parent.close()
                        # Reporter inherits NO target handles or gate/channel authority.
                        for entry in model.tokens.values():
                            if entry['handle'] is not None:
                                os.close(entry['handle'])
                        report(directory, model, pid, writer, actions)
                    os.close(writer)
                    reporter_fd = os.pidfd_open(reporter_pid, 0)  # unreaped direct reporter child
                if reporter_pid is not None and reporter_status is None:
                    try:
                        report_result += os.read(reporter_read, 2)
                    except BlockingIOError:
                        pass
                    waited, status = os.waitpid(reporter_pid, os.WNOHANG)
                    if waited:
                        reporter_status = os.waitstatus_to_exitcode(status)
                        try:
                            report_result += os.read(reporter_read, 2)
                        except BlockingIOError:
                            pass
                        os.close(reporter_fd)
                        reporter_fd = None
                if reporter_status is not None:
                    reporter_completion = (report_result, reporter_status)
                    break  # Final guard runs AFTER finally, with no intervening cleanup/I/O.
            select.select([], [], [], min(0.02, max(0, model.cleanup_end - time.monotonic())))
    except BaseException:
        model.fail('supervisor-exception', time.monotonic())
    finally:
        # Exception path gets SAME cleanup_end, never a fresh20s window. No report I/O.
        model.begin_cleanup(time.monotonic())
        while time.monotonic() < model.cleanup_end:
            for handle in model.targets():
                try:
                    signal.pidfd_send_signal(handle, signal.SIGKILL)
                except (OSError, ValueError):
                    pass
            if pid is not None and not root_reaped:
                try:
                    if root_fd is not None:
                        signal.pidfd_send_signal(root_fd, signal.SIGKILL)
                    else:
                        # Only pre-binding own unreaped direct child, NEVER a reaped PID.
                        os.kill(pid, signal.SIGKILL)
                    waited, status = os.waitpid(pid, os.WNOHANG)
                    if waited:
                        root_reaped = True
                        model.root_exit(os.waitstatus_to_exitcode(status), time.monotonic())
                        if root_fd is not None:
                            os.close(root_fd)
                            root_fd = None
                except (OSError, ValueError):
                    break  # unknown reaper/OS unavailable => finite UNVERIFIED
            if reporter_pid is not None and reporter_status is None:
                try:
                    if reporter_fd is not None:
                        signal.pidfd_send_signal(reporter_fd, signal.SIGKILL)
                    else:
                        os.kill(reporter_pid, signal.SIGKILL)  # sole unreaped direct reporter
                    waited, status = os.waitpid(reporter_pid, os.WNOHANG)
                    if waited:
                        reporter_status = os.waitstatus_to_exitcode(status)
                except OSError:
                    break
            if root_reaped and (reporter_pid is None or reporter_status is not None):
                break
            select.select([], [], [], min(0.01, max(0, model.cleanup_end - time.monotonic())))
    if reporter_completion is not None:
        return model.reporter_completion(*reporter_completion, time.monotonic)
    return 125  # STOP_CLEANUP_UNVERIFIED; absence of report is never acceptance/lease.


if __name__ == '__main__':
    os._exit(main())  # never wait on reporting, flush stdout or inherited interpreter threads
