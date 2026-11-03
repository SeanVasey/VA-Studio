#!/usr/bin/env python3
"""Common manual-job bootstrap for GitHub/GitLab; no acceptance on outer daemon."""
import argparse
import importlib.util
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import time
from network import build_flags, container_flags, client_config_mount, INNER_CA

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('ledger_bootstrap_runtime', HERE / 'runtime-producer.py')
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)
require = runtime.require


class Outer(runtime.Producer):
    def __init__(self):
        super().__init__()
        self.docker = Path(shutil.which('docker') or '/missing/docker').resolve()
        runtime.trusted_path(self.docker)

    def command(self, args, *arguments, **keywords):
        require(args[0] == 'docker', 'outer-controller-entry')
        # Outer daemon is transport/build infrastructure only. All actual process,
        # cgroup, binary and native checks run inside the pinned inner controller.
        return runtime.Capture.command(self, [str(self.docker), *args[1:]], *arguments, **keywords)


def bootstrap(checkout, expected, run_id, attempt, platform, event, event_sha, start_local=False):
    require(os.geteuid() == 0 and runtime.SHA.fullmatch(expected) and run_id > 0 and attempt > 0
            and platform in ('github', 'gitlab'), 'manual-root-bootstrap')
    require(event in (('workflow_dispatch',) if platform == 'github' else ('web', 'api'))
            and event_sha == expected, 'exact-manual-event-required')
    actual = subprocess.run(['/usr/bin/git', '-c', 'safe.directory=' + str(checkout), '-C', str(checkout),
                             'rev-parse', 'HEAD'], capture_output=True, timeout=15, check=False)
    require(actual.returncode == 0 and actual.stderr == b'' and actual.stdout == (expected + '\n').encode(),
            'moved-checkout-refused')
    nonce = secrets.token_hex(16)
    source = Path('/run/va-ledger-' + nonce)
    spec = importlib.util.spec_from_file_location('ledger_bootstrap_snapshot', HERE / 'source-snapshot.py')
    snapshot = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(snapshot)
    snapshot.snapshot(checkout, expected, source)
    snapshot.verify(source, expected)
    # This non-Git dependency alias has one fixed target in every native container.
    # The controlled dependency installer excludes it from its fresh workspace.
    (source / 'vendor').symlink_to('/dependencies/vendor', target_is_directory=True)
    root = Path('/run/va-ledger-outer-' + nonce)
    root.mkdir(mode=0o700)
    safe = root / 'public'
    safe.mkdir(mode=0o755)
    safe.chmod(0o755)
    os.environ['PATH'] = '/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin'
    actor = Outer()
    # Explicit capability test: no skip/success or invented runner label if Docker
    # or privileged nested-controller support is unavailable.
    local = None
    if start_local:
        require(platform == 'gitlab', 'fresh-native-job-controller-only')
        controller_spec = importlib.util.spec_from_file_location('ledger_bootstrap_controller', HERE / 'controller.py')
        controller = importlib.util.module_from_spec(controller_spec)
        controller_spec.loader.exec_module(controller)
        local = controller.Controller(outer=True)
        try:
            local.start(probe=actor)
        except Exception:
            local.close()
            raise
    actor.command(['docker', 'info', '--format', '{{.OSType}}'], 'outer_docker_capability')
    actor.command(['docker', 'buildx', 'version'], 'outer_buildkit_capability')
    actor.end = time.monotonic() + 500
    actor.limit = actor.end - 20
    recipe = source / 'scripts/ci/account-ledger'
    runtime_image = actor.build(recipe)
    runtime_tag = 'va-ledger-controller-runtime:' + nonce
    actor.command(['docker', 'tag', runtime_image, runtime_tag], 'controlled_runtime_tag')
    actor.end = time.monotonic() + 500
    actor.limit = actor.end - 20
    controller_image = actor.command(['docker', 'build', '--quiet', '--no-cache', '--platform=linux/amd64', *build_flags(),
        '--build-arg', 'RUNTIME_IMAGE=' + runtime_tag, '--file', str(recipe / 'Controller.Dockerfile'),
        str(recipe)], 'pinned_controller_build', seconds=240).strip()
    require(re.fullmatch('sha256:[0-9a-f]{64}', controller_image), 'controller-built-id')
    # Bootstrap/build have a separate finite phase; native per-provider clocks
    # remain their original WORK480/cleanup20 and outer600/kill10 contracts.
    actor.end = time.monotonic() + 5700
    actor.limit = actor.end - 60
    container = None
    volume = 'va-ledger-controller-data-' + nonce
    result = False
    try:
        actor.command(['docker', 'volume', 'create', '--label', 'va.ledger.controller=' + nonce, volume], 'controller_volume')
        container = actor.command(['docker', 'create', '--pull=never', '--platform=linux/amd64',
            '--name', 'va-ledger-controller-' + nonce, '--label', 'va.ledger.controller=' + nonce,
            '--privileged', '--cgroupns=private', '--memory=6g', '--memory-swap=6g', '--cpus=2', '--pids-limit=2048',
            '--log-driver=none', '--restart=no', '--init=false', *container_flags(), *client_config_mount(),
            '--env', 'SSL_CERT_FILE=' + INNER_CA,
            '--mount', 'type=bind,src=' + str(source) + ',dst=/candidate,readonly',
            '--mount', 'type=bind,src=' + str(safe) + ',dst=/public',
            '--mount', 'type=volume,src=' + volume + ',dst=/var/lib/docker',
            controller_image, '--expected-sha', expected, '--run-id', str(run_id), '--run-attempt', str(attempt)],
            'controller_create').strip()
        require(re.fullmatch('[0-9a-f]{64}', container), 'controller-owned-id')
        require(actor.command(['docker', 'start', container], 'controller_start').strip() == container, 'controller-started-id')
        require(actor.command(['docker', 'wait', container], 'controller_actual_wait', seconds=5580) == '0\n',
                'controller-actual-exit')
        import json
        row = json.loads(actor.command(['docker', 'inspect', container], 'controller_final_state'))[0]
        require(row['Id'] == container and row['Image'] == controller_image and row['RestartCount'] == 0
                and row['State']['Status'] == 'exited' and row['State']['ExitCode'] == 0
                and row['State']['Pid'] == 0 and all(row['State'][key] is False for key in ('Running', 'Paused', 'Restarting', 'Dead', 'OOMKilled'))
                and row['HostConfig']['PidMode'] == '' and row['HostConfig']['NetworkMode'] != 'host'
                and row['NetworkSettings']['Ports'] == {}, 'outer-controller-final-state')
        expected_files = {case + '.json' for case in ('up-overlap', 'down-overlap', 'up-end', 'down-end')} | {'suite.json'}
        require({path.name for path in safe.iterdir()} == expected_files, 'complete-safe-ledger-artifact')
        for name in expected_files:
            path = safe / name
            runtime.trusted_path(path)
            require(path.stat().st_size <= 65536, 'safe-ledger-file-bound')
            target = checkout / ('ledger-' + name)
            require(not target.exists() and not target.is_symlink(), 'fresh-export-only')
            with target.open('xb') as output:
                output.write(path.read_bytes())
            os.chown(target, 0, 0)
            target.chmod(0o444)
        result = True
    finally:
        actor.limit = min(actor.end, time.monotonic() + 60)
        if container and re.fullmatch('[0-9a-f]{64}', container):
            # On failure this is owned teardown, never native quiet/lease proof.
            state = actor.command(['docker', 'inspect', '--format', '{{.State.Running}}', container], 'outer_teardown_state')
            if state == 'true\n':
                actor.command(['docker', 'kill', '--signal=KILL', container], 'outer_failed_teardown')
                require(actor.command(['docker', 'wait', container], 'outer_failed_wait') == '137\n', 'outer-failed-wait')
            actor.command(['docker', 'rm', container], 'outer_owned_remove')
            require(actor.command(['docker', 'ps', '-a', '--filter', 'id=' + container, '--format', '{{.ID}}'], 'outer_absent') == '', 'outer-owned-removal')
        actor.command(['docker', 'volume', 'rm', volume], 'outer_owned_volume_remove')
        if local is not None:
            local.close()
    require(result and not actor.receipt['transport_uncertain'], 'isolated-ledger-suite-unverified')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--expected-sha', required=True)
    parser.add_argument('--run-id', required=True, type=int)
    parser.add_argument('--run-attempt', required=True, type=int)
    parser.add_argument('--platform', required=True, choices=('github', 'gitlab'))
    parser.add_argument('--event', required=True)
    parser.add_argument('--event-sha', required=True)
    parser.add_argument('--start-local-daemon', action='store_true')
    args = parser.parse_args()
    try:
        bootstrap(Path.cwd(), args.expected_sha, args.run_id, args.run_attempt, args.platform, args.event, args.event_sha,
                  args.start_local_daemon)
    except Exception:
        raise SystemExit('Required supervised ledger suite refused; runtime/runner qualification remains blocked.') from None
