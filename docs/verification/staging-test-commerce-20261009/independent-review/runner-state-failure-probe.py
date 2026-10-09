#!/usr/bin/env python3
"""Independent cursor-persistence failure probe; local scripted artisan only."""
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[4]
with tempfile.TemporaryDirectory(prefix='va-review-state-failure-') as temporary:
    base = Path(temporary)
    app = base / 'app'
    app.mkdir(mode=0o700)
    (app / 'artisan').touch()
    state = base / 'state'
    state.mkdir(mode=0o700)
    # A non-file path models a failed cursor write without special privileges or disk exhaustion.
    (state / 'finalize.cursor').mkdir(mode=0o700)
    fake = base / 'fake-php'
    fake.write_text('''#!/usr/bin/env bash
[[ "$2" == vasey:finalize-test-payments ]] || exit 0
printf '%s\n' '11111111-1111-4111-8111-111111111111 retry' 'NEXT_AFTER=11111111-1111-4111-8111-111111111111'
''')
    fake.chmod(0o700)
    env = {'PATH': os.environ.get('PATH', '/usr/bin:/bin'), 'APP_ROOT': str(app),
           'PHP_BIN': str(fake), 'STATE_DIR': str(state), 'PAGE_LIMIT': '1',
           'CONTRACT_PAGE_LIMIT': '1', 'MAX_PAGES': '1', 'RECONCILE_INTERVAL_SECONDS': '0'}
    result = subprocess.run(['bash', str(ROOT / 'scripts/ops/run-test-commerce-pipeline.sh')],
                            cwd=ROOT, env=env, capture_output=True, text=True, timeout=30)
    print('Cursor write failure: runner exit=' + str(result.returncode))
    for line in result.stdout.splitlines():
        if 'pipeline finalize ' in line or 'sweep end status=' in line:
            print(line)
    print('Cursor remains a directory=' + str((state / 'finalize.cursor').is_dir()))
    print('Stderr was nonempty=' + str(bool(result.stderr)))
    assert result.returncode == 1, 'A failed cursor save must fail the sweep rather than claim successful persistence'
    assert 'resuming after the saved cursor' not in result.stdout
print('RESULT: cursor persistence failure is observable and does not claim saved progress')
