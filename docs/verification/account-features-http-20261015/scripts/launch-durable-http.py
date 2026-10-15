import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import subprocess

p = argparse.ArgumentParser()
p.add_argument('--name', required=True)
p.add_argument('--driver', required=True, choices=['sqlite', 'mysql'])
p.add_argument('--timeout', type=int, default=1800)
p.add_argument('--filter')
p.add_argument('--continue-on-defect', action='store_true')
p.add_argument('files', nargs='+')
a = p.parse_args()
root = Path('/workspace/VA-Studio-tax-v2-committed-read')
output = Path('/workspace/runtime/account-features-http-evidence-20261015')
runner = output / 'run-focused-http.py'
assert not (output / a.name).exists(), 'Never overwrite an existing run'
argv = ['python3', str(runner), '--name', a.name, '--driver', a.driver, '--timeout', str(a.timeout)]
if a.continue_on_defect:
    argv += ['--continue-on-defect']
if a.filter:
    argv += ['--filter', a.filter]
argv += a.files
launch = output / ('launch-' + a.name + '.json')
log_path = output / ('launch-' + a.name + '.log')
assert not launch.exists() and not log_path.exists()
state = {'head': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip(),
         'status': subprocess.check_output(['git', 'status', '--porcelain=v1'], cwd=root, text=True)}
assert state['status'] == '', 'Freeze source before launching'
with log_path.open('xb') as log:
    proc = subprocess.Popen(argv, cwd=root, stdin=subprocess.DEVNULL, stdout=log,
                            stderr=subprocess.STDOUT, start_new_session=True)
    stat = Path('/proc') / str(proc.pid) / 'stat'
    ticks = stat.read_text().rsplit(')', 1)[1].split()[19]
    record = {'launched_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
              'pid': proc.pid, 'process_start_ticks': ticks, 'uid': os.getuid(),
              'argv': argv, 'cwd': str(root), 'source': state,
              'runner_sha256': hashlib.sha256(runner.read_bytes()).hexdigest(),
              'launcher_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
              'supervisor_log': str(log_path),
              'scope': 'Detached local recorder and its test process; one root-owned workload. No original missing exit is inferred.'}
    with launch.open('x') as stream:
        json.dump(record, stream, indent=2, sort_keys=True)
        stream.write('\n')
print(json.dumps(record, indent=2))
