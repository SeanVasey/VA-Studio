from pathlib import Path
import hashlib
import json
import subprocess
import sys

root = Path('/workspace/VA-Studio-billing-c3-baseline')
evidence = Path('/workspace/runtime/billing-retrieval-records-evidence/baseline')
mode = sys.argv[1]
names = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode().rstrip('\0').split('\0')
files = {name: hashlib.sha256((root / name).read_bytes()).hexdigest() for name in names if (root / name).is_file()}
receipt = {
    'head': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root).decode().strip(),
    'status': subprocess.check_output(['git', 'status', '--porcelain'], cwd=root).decode(),
    'tracked_file_count': len(files),
    'files': files,
    'probe_sha256': hashlib.sha256((evidence / 'BillingPreC3RegressionTest.php').read_bytes()).hexdigest(),
    'command_sha256': hashlib.sha256((evidence / 'run.sh').read_bytes()).hexdigest(),
    'private_autoload_sha256': hashlib.sha256((root / 'vendor/autoload.php').read_bytes()).hexdigest(),
}
(evidence / f'source-{mode}.json').write_text(json.dumps(receipt, indent=2) + '\n')
assert receipt['head'] == '45d1299406821627f4ba02af186e324a70c6ade0'
assert receipt['status'] == ''
if mode == 'after':
    before = json.loads((evidence / 'source-before.json').read_text())
    assert before == receipt, 'Source or probe changed during the focused baseline run.'
    print(f"All {len(files)} tracked source hashes and external probe/command hashes unchanged; baseline worktree clean.")
