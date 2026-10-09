#!/usr/bin/env python3
"""Independent state write/removal failures; all domain commands are scripted."""
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[4]
U1 = '11111111-1111-4111-8111-111111111111'
STAGES = [('reconcile', 'vasey:reconcile-test-payments'),
          ('finalize', 'vasey:finalize-test-payments'),
          ('contracts', 'vasey:issue-test-contracts'),
          ('activate', 'vasey:activate-test-fulfillment')]
cases = [(stage, command, action) for stage, command in STAGES for action in ['save', 'clear']]
cases.append(('reconcile', 'vasey:reconcile-test-payments', 'timestamp'))
failed_checks = 0
for stage, command, action in cases:
    with tempfile.TemporaryDirectory(prefix='va-review-state-operation-') as temporary:
        base = Path(temporary)
        app = base / 'app'
        app.mkdir(mode=0o700)
        (app / 'artisan').touch()
        state = base / 'state'
        state.mkdir(mode=0o700)
        broken_path = state / ('reconcile.last' if action == 'timestamp' else stage + '.cursor')
        broken_path.mkdir(mode=0o700)
        fake = base / 'fake-php'
        fake.write_text('''#!/usr/bin/env bash
[[ "$2" == "$REVIEW_COMMAND" && "$REVIEW_ACTION" == save ]] || exit 0
printf '%s retry\nNEXT_AFTER=%s\n' "$REVIEW_UUID" "$REVIEW_UUID"
''')
        fake.chmod(0o700)
        env = {'PATH': os.environ.get('PATH', '/usr/bin:/bin'), 'APP_ROOT': str(app),
               'PHP_BIN': str(fake), 'STATE_DIR': str(state), 'PAGE_LIMIT': '1',
               'CONTRACT_PAGE_LIMIT': '1', 'MAX_PAGES': '1', 'RECONCILE_INTERVAL_SECONDS': '0',
               'REVIEW_COMMAND': command, 'REVIEW_ACTION': action, 'REVIEW_UUID': U1}
        result = subprocess.run(['bash', str(ROOT / 'scripts/ops/run-test-commerce-pipeline.sh')],
                                cwd=ROOT, env=env, capture_output=True, text=True, timeout=30)
        passed = result.returncode == 1
        print(('PASS' if passed else 'FAIL') + ' ' + stage + ' ' + action + ': runner exit=' + str(result.returncode))
        failed_checks += not passed
print('RESULT: ' + str(failed_checks) + ' of ' + str(len(cases)) + ' state failure checks failed')
raise SystemExit(1 if failed_checks else 0)
