#!/usr/bin/env python3
"""Own the isolated inner daemon in the producer's PID/cgroup namespace."""
import argparse
import hashlib
import importlib.util
import os
from pathlib import Path
import signal
import subprocess
import time
from network import daemon_environment

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('ledger_controller_runtime', HERE / 'runtime-producer.py')
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)
require = runtime.require


def daemon_command(outer=False):
    # Explicit dockerd avoids the official entrypoint's default TCP/TLS listeners.
    # Inner subnets are deliberately distinct from the usual outer172.17 bridge.
    return ['/usr/local/bin/dockerd-entrypoint.sh', 'dockerd',
            '--host=unix:///var/run/docker.sock', '--data-root=/var/lib/docker',
            '--exec-root=/run/docker', '--pidfile=/run/dockerd.pid',
            '--exec-opt=native.cgroupdriver=cgroupfs', '--storage-driver=vfs',
            '--bip=' + ('10.251.240.1/24' if outer else '172.31.240.1/24'),
            '--default-address-pool=base=' + ('10.250.0.0/16' if outer else '172.30.0.0/16') + ',size=24',
            '--log-level=error']


class Controller:
    def __init__(self, outer=False):
        require(type(outer) is bool and os.geteuid() == 0 and (outer or os.getpid() == 1), 'private-controller-init-required')
        self.outer = outer
        self.directory = Path('/run/ledger-outer-controller' if outer else '/run/ledger-controller')
        self.directory.mkdir(mode=0o700)
        self.directory.chmod(0o700)
        self.process = None
        self.log = None
        self.started = False
        self.closed = False

    def start(self, probe=None):
        require(self.process is None, 'no-controller-retry')
        require(hashlib.sha256(Path('/usr/local/libexec/docker/cli-plugins/docker-buildx').read_bytes()).hexdigest()
                == '696bc104bac3bb708eff1af3f8bbc09fda0fd88f5757c1f9b404a35117889224', 'published-pinned-buildx-binary')
        require(not Path('/var/run/docker.sock').exists() and not Path('/var/run/docker.sock').is_symlink()
                and not Path('/run/dockerd.pid').exists(), 'fresh-private-daemon-required')
        existing = subprocess.run(['/usr/bin/pgrep', '-x', 'dockerd'], capture_output=True, timeout=5, check=False)
        require(existing.returncode == 1 and existing.stdout == existing.stderr == b'', 'competing-daemon-refused')
        self.log = (self.directory / 'daemon.private').open('xb')
        os.chmod(self.directory / 'daemon.private', 0o600)

        # Never constrain the daemon's file size: that would also truncate image
        # layers/container data. Daemon bodies are discarded; bounded CLI/API
        # outcomes and actual namespace/profile/exit checks carry the evidence.
        self.process = subprocess.Popen(daemon_command(self.outer), stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            env=daemon_environment())
        deadline = time.monotonic() + 55
        probe = probe or runtime.Producer()
        probe.end = probe.limit = deadline
        # Wait on the newly owned private Unix socket, never an outer-host daemon.
        while time.monotonic() < deadline:
            require(self.process.poll() is None, 'owned-daemon-ended')
            if Path('/var/run/docker.sock').is_socket():
                break
            time.sleep(.05)
        require(Path('/var/run/docker.sock').is_socket(), 'owned-daemon-readiness')
        pid = int(Path('/run/dockerd.pid').read_text().strip())
        require(pid > 1 and os.readlink('/proc/' + str(pid) + '/ns/pid')
                == os.readlink('/proc/self/ns/pid'), 'daemon-producer-pid-namespace')
        # This checks actual inner engine/API/cgroup profile before any admission.
        version = runtime.load(probe.command(['docker', 'version', '--format',
            '{"Version":{{json .Server.Version}},"ApiVersion":{{json .Server.APIVersion}},"GitCommit":{{json .Server.GitCommit}},"Os":{{json .Server.Os}},"Arch":{{json .Server.Arch}}}'], 'controller-version').encode())
        require(version == {'Version': '28.4.0', 'ApiVersion': '1.51', 'GitCommit': '249d679',
                            'Os': 'linux', 'Arch': 'amd64'}, 'inner-engine-profile')
        cgroup = runtime.load(probe.command(['docker', 'info', '--format',
            '{"CgroupVersion":{{json .CgroupVersion}},"CgroupDriver":{{json .CgroupDriver}},"OomKillDisable":{{json .OomKillDisable}}}'], 'controller-cgroup').encode())
        require(cgroup == {'CgroupVersion': '2', 'CgroupDriver': 'cgroupfs', 'OomKillDisable': False}, 'inner-cgroup-profile')
        require((self.directory / 'daemon.private').stat().st_size < 65536, 'daemon-log-cap')
        self.started = True

    def close(self):
        if self.process is not None:
            if self.process.poll() is None:
                self.process.send_signal(signal.SIGTERM)  # Sole unreaped direct docker-init child.
            try:
                status = self.process.wait(timeout=30)
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.wait(timeout=5)
                raise runtime.Refusal('daemon-clean-shutdown-unverified') from None
            require(status == 0, 'daemon-actual-exit')
            require((self.directory / 'daemon.private').stat().st_size < 65536, 'daemon-log-cap')
            self.closed = True
        if self.log is not None:
            self.log.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--expected-sha', required=True)
    parser.add_argument('--run-id', required=True, type=int)
    parser.add_argument('--run-attempt', required=True, type=int)
    args = parser.parse_args()
    require(runtime.SHA.fullmatch(args.expected_sha) and args.run_id > 0 and args.run_attempt > 0,
            'controller-source-and-run')
    snapshot_spec = importlib.util.spec_from_file_location('ledger_controller_snapshot', HERE / 'source-snapshot.py')
    snapshot = importlib.util.module_from_spec(snapshot_spec)
    snapshot_spec.loader.exec_module(snapshot)
    snapshot.verify(Path('/candidate'), args.expected_sha)
    controller = Controller()
    try:
        controller.start()
        # Imported only from the sealed reviewed source; no arbitrary command API.
        from suite import run
        run(args.expected_sha, args.run_id, args.run_attempt)
    finally:
        controller.close()


if __name__ == '__main__':
    try:
        main()
    except Exception:
        raise SystemExit('Ledger isolated controller refused.') from None
