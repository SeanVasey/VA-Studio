from pathlib import Path
import hashlib
import json
import subprocess
import sys

phase, mode = sys.argv[1:]
sources = {
    'old': ('/workspace/VA-Studio-billing-c3-baseline', '45d1299406821627f4ba02af186e324a70c6ade0'),
    'new': ('/workspace/VA-Studio-billing-award-upgrade', '5dc1169156287bfa22ae91339aaeccbfe13674f7'),
}
directory, expected = sources[phase]
root = Path(directory)
evidence = Path(__file__).parent
names = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode().rstrip('\0').split('\0')
files = {name: hashlib.sha256((root / name).read_bytes()).hexdigest() for name in names if (root / name).is_file()}
programs = ['OldWriterAwardTest.php', 'NewWriterAwardReplayTest.php', 'AwardUpgradeRuntime.php', 'run-phase.sh', 'source_receipt.py']
receipt = {
    'head': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root).decode().strip(),
    'status': subprocess.check_output(['git', 'status', '--porcelain'], cwd=root).decode(),
    'tracked_file_count': len(files),
    'files': files,
    'programs': {name: hashlib.sha256((evidence / name).read_bytes()).hexdigest() for name in programs},
    'private_autoload_sha256': hashlib.sha256((root / 'vendor/autoload.php').read_bytes()).hexdigest(),
}
path = evidence / f'{phase}-source-{mode}.json'
path.write_text(json.dumps(receipt, indent=2) + '\n')
assert receipt['head'] == expected
assert receipt['status'] == ''
if mode == 'after':
    before = json.loads((evidence / f'{phase}-source-before.json').read_text())
    assert before == receipt, 'Source or runtime programs changed during phase execution.'
    print(f'{phase}: all {len(files)} tracked source hashes and external programs unchanged; worktree clean.')
