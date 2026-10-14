import datetime
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tarfile
import xml.etree.ElementTree as ET

root = Path('/workspace/VA-Studio-paid-delivery-a2')
runtime = Path('/workspace/runtime/paid-delivery-a2-evidence-20261014')
packet = root / 'docs/verification/paid-delivery-a2-20261014'

def sha(data):
    return hashlib.sha256(data).hexdigest()

def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()

def read(path):
    return json.loads(path.read_text())

def git(*args):
    return subprocess.check_output(['git', *args], cwd=root)

head = git('rev-parse', 'HEAD').decode().strip()
assert git('status', '--porcelain=v1').decode() == '', 'Freeze source before packaging'
members = {}
records = []
bundles = set()
launches = sorted(runtime.glob('launch-*.json'))
assert launches, 'No owned run launches'
for launch in launches:
    name = launch.stem.removeprefix('launch-')
    run = runtime / name
    assert (run / 'result.json').is_file(), 'Recorder has not closed: ' + name
    command, result = read(run / 'command.json'), read(run / 'result.json')
    assert int((run / 'process.exit').read_text()) == result['process_exit']
    assert sha((run / 'log.txt').read_bytes()) == result['log_sha256']
    for gate in ['source_unchanged', 'source_head_unchanged', 'source_status_unchanged', 'runner_unchanged', 'autoload_unchanged']:
        assert result[gate] is True, (name, gate)
    xml = ET.parse(run / 'junit.xml').getroot()
    cases = list(xml.iter('testcase'))
    counts = {'tests': len(cases), 'assertions': sum(int(c.get('assertions', 0)) for c in cases),
              'failures': sum(c.find('failure') is not None for c in cases),
              'errors': sum(c.find('error') is not None for c in cases),
              'skipped': sum(c.find('skipped') is not None for c in cases)}
    assert counts == result['junit'], name
    bundle = runtime / result['bundle']
    identity, manifest = read(bundle / 'identity.json'), read(bundle / 'manifest.json')
    assert identity['head'] == command['source_before']['head'] == result['source_after']['head']
    assert sha(canonical(manifest)) == identity['manifest_sha256'] == command['before_digest'] == result['after_digest']
    assert sha(canonical(read(bundle / 'runtime.json'))) == identity['runtime_sha256']
    assert sha(canonical(read(bundle / 'public-environment.json'))) == identity['public_environment_sha256']
    assert sha((bundle / 'runner.py').read_bytes()) == identity['runner_sha256'] == result['runner_after_sha256']
    assert launch and read(launch)['runner_sha256'] == identity['runner_sha256']
    assert len(manifest) == command['source_input_count'] == result['source_input_count']
    assert not command['overlays'] and command['before_git_equal'] is True
    for path in [launch, runtime / ('launch-' + name + '.log'), *sorted(run.iterdir())]:
        assert path.is_file()
        members[str(path.relative_to(runtime))] = path.read_bytes()
    bundles.add(result['bundle'])
    records.append({'name': name, 'head': identity['head'], 'bundle': result['bundle'],
                    'selection': command['selected_files'], 'filter': command['filter'], 'junit': counts,
                    'process_exit': result['process_exit'], 'verifier_exit': result['verifier_exit']})

bindings = []
for name in sorted(bundles):
    bundle = runtime / name
    identity, manifest = read(bundle / 'identity.json'), read(bundle / 'manifest.json')
    subprocess.run(['git', 'merge-base', '--is-ancestor', identity['head'], head], cwd=root, check=True)
    proc = subprocess.Popen(['git', 'cat-file', '--batch'], cwd=root, stdin=subprocess.PIPE, stdout=subprocess.PIPE)
    for path, expected in manifest.items():
        proc.stdin.write((identity['head'] + ':' + path + '\n').encode()); proc.stdin.flush()
        header = proc.stdout.readline().split()
        assert len(header) == 3 and header[1] == b'blob', path
        data = proc.stdout.read(int(header[2]))
        assert proc.stdout.read(1) == b'\n' and sha(data) == expected, path
    proc.stdin.close(); assert proc.wait() == 0
    for path in sorted(bundle.iterdir()):
        members[str(path.relative_to(runtime))] = path.read_bytes()
    bindings.append({'bundle': name, 'head': identity['head'], 'git_bound_input_count': len(manifest),
                     'route_input_count': sum(p.startswith('routes/') for p in manifest)})

for pattern in ['pint-*.json', 'mysql-server-*.json', 'recorder-scope-v2.json', 'run-focused*.py', 'launch-durable*.py']:
    for path in sorted(runtime.glob(pattern)):
        members[str(path.relative_to(runtime))] = path.read_bytes()
index = {'created_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'source_anchor': head,
         'records': records, 'source_bindings': bindings,
         'members': [{'path': p, 'sha256': sha(b), 'size': len(b)} for p, b in sorted(members.items())],
         'scope': 'Actual recorded invocations; selections overlap and are not one suite. Original failures retained. V1 excludes routes; V2 includes routes. No missing exit reconstructed.'}
archive = packet / 'recorded-evidence.tar.gz'
assert not archive.exists() and not (packet / 'recorded-evidence-index.json').exists()
with tarfile.open(archive, 'w:gz') as tar:
    for name, data in sorted(members.items()):
        info = tarfile.TarInfo(name); info.size = len(data); info.mode = 0o644
        tar.addfile(info, io.BytesIO(data))
with tarfile.open(archive, 'r:gz') as tar:
    assert sorted(tar.getnames()) == sorted(members)
    for item in tar.getmembers():
        assert sha(tar.extractfile(item).read()) == sha(members[item.name]), item.name
index['archive_sha256'] = sha(archive.read_bytes())
index['archive_size'] = archive.stat().st_size
(packet / 'recorded-evidence-index.json').write_text(json.dumps(index, indent=2, sort_keys=True) + '\n')
print(json.dumps({'phpunit_records': len(records), 'source_bindings': len(bindings), 'archived_members': len(members),
                  'archive_size': index['archive_size'], 'archive_sha256': index['archive_sha256']}, indent=2))
