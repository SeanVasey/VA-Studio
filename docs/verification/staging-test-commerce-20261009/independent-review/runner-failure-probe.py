#!/usr/bin/env python3
"""Independent paging reset, timeout, redaction and lock probes; no provider or DB."""
import fcntl
import os
from pathlib import Path
import stat
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[4]
SCRIPT = ROOT / 'scripts/ops/run-test-commerce-pipeline.sh'
U1 = '11111111-1111-4111-8111-111111111111'
U2 = '22222222-2222-4222-8222-222222222222'
STAGES = [
    ('reconcile', 'vasey:reconcile-test-payments'),
    ('finalize', 'vasey:finalize-test-payments'),
    ('contracts', 'vasey:issue-test-contracts'),
    ('activate', 'vasey:activate-test-fulfillment'),
]
MARKER = 'IndependentPrivateDiagnosticMarker0000'
FAKE = '''#!/usr/bin/env bash
printf '%s\n' "${*:2}" >> "$REVIEW_CALLS"
[[ "$2" == "$REVIEW_COMMAND" ]] || exit 0
case "$REVIEW_MODE" in
  failure) printf '%s\n' "$REVIEW_MARKER"; printf '%s\n' "$REVIEW_MARKER" >&2; exit 7 ;;
  timeout) printf '%s\n' "$REVIEW_MARKER"; printf '%s\n' "$REVIEW_MARKER" >&2; sleep 10 ;;
  short|full) printf '%s retry\nNEXT_AFTER=%s\n' "$REVIEW_U2" "$REVIEW_U2" ;;
  empty) exit 0 ;;
esac
'''


def setup(base, stage, command, mode, limit='1'):
    app = base / 'app'
    app.mkdir(mode=0o700)
    (app / 'artisan').touch()
    state = base / 'state'
    state.mkdir(mode=0o700)
    fake = base / 'fake-php'
    fake.write_text(FAKE)
    fake.chmod(0o700)
    env = {'PATH': os.environ.get('PATH', '/usr/bin:/bin'), 'APP_ROOT': str(app),
           'PHP_BIN': str(fake), 'STATE_DIR': str(state), 'PAGE_LIMIT': limit,
           'CONTRACT_PAGE_LIMIT': limit, 'MAX_PAGES': '1',
           'COMMAND_TIMEOUT_SECONDS': '1', 'RECONCILE_INTERVAL_SECONDS': '0',
           'REVIEW_CALLS': str(base / 'calls.log'), 'REVIEW_COMMAND': command,
           'REVIEW_MODE': mode, 'REVIEW_MARKER': MARKER, 'REVIEW_U2': U2}
    return env, state / (stage + '.cursor')


def sweep(env):
    result = subprocess.run(['bash', str(SCRIPT)], cwd=ROOT, env=env,
                            capture_output=True, text=True, timeout=15)
    assert MARKER not in result.stdout + result.stderr, 'Private diagnostic reached output'
    return result


probes = 0
for stage, command in STAGES:
    with tempfile.TemporaryDirectory(prefix='va-review-failure-') as temporary:
        env, cursor = setup(Path(temporary), stage, command, 'failure')
        cursor.write_text(U1)
        failed = sweep(env)
        assert failed.returncode == 1 and not cursor.exists()
        calls = Path(env['REVIEW_CALLS']).read_text().splitlines()
        assert len(calls) == 5, 'A failed stage skipped later stages'
        assert '--after=' + U1 in next(line for line in calls if line.startswith(command + ' '))
        env['REVIEW_MODE'] = 'empty'
        resumed = sweep(env)
        assert resumed.returncode == 0
        selected = [line for line in Path(env['REVIEW_CALLS']).read_text().splitlines()
                    if line.startswith(command + ' ')]
        assert '--after=' not in selected[1]
        if stage == 'reconcile':
            assert (cursor.parent / 'reconcile.last').exists()
        print('PASS ' + stage + ': failure clears saved cursor, later stages continue, next sweep restarts')
        probes += 1

    with tempfile.TemporaryDirectory(prefix='va-review-short-') as temporary:
        env, cursor = setup(Path(temporary), stage, command, 'short', '2')
        cursor.write_text(U1)
        result = sweep(env)
        assert result.returncode == 0 and not cursor.exists()
        print('PASS ' + stage + ': successful short page clears saved cursor')
        probes += 1

    with tempfile.TemporaryDirectory(prefix='va-review-invalid-') as temporary:
        env, cursor = setup(Path(temporary), stage, command, 'empty')
        cursor.write_text(MARKER)
        result = sweep(env)
        assert result.returncode == 0 and not cursor.exists()
        selected = next(line for line in Path(env['REVIEW_CALLS']).read_text().splitlines()
                        if line.startswith(command + ' '))
        assert '--after=' not in selected
        print('PASS ' + stage + ': malformed saved cursor is ignored without exposing its contents')
        probes += 1

with tempfile.TemporaryDirectory(prefix='va-review-timeout-') as temporary:
    env, cursor = setup(Path(temporary), 'activate', 'vasey:activate-test-fulfillment', 'timeout')
    cursor.write_text(U1)
    result = sweep(env)
    assert result.returncode == 1 and not cursor.exists()
    assert 'activate timed out after 1s' in result.stdout
    print('PASS activate: one-second timeout fails sweep, clears cursor, redacts diagnostics')
    probes += 1

with tempfile.TemporaryDirectory(prefix='va-review-lock-') as temporary:
    env, cursor = setup(Path(temporary), 'activate', 'vasey:activate-test-fulfillment', 'empty')
    with (cursor.parent / 'pipeline.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        result = sweep(env)
        assert result.returncode == 0 and 'another sweep holds the lock; skipped' in result.stdout
        assert not Path(env['REVIEW_CALLS']).exists()
    print('PASS lock: held lock skips all artisan work')
    probes += 1

with tempfile.TemporaryDirectory(prefix='va-review-mode-') as temporary:
    env, cursor = setup(Path(temporary), 'activate', 'vasey:activate-test-fulfillment', 'full')
    result = sweep(env)
    assert result.returncode == 0 and cursor.read_text() == U2
    assert stat.S_IMODE(cursor.stat().st_mode) == 0o600
    assert stat.S_IMODE(cursor.parent.stat().st_mode) == 0o700
    print('PASS state: fresh cursor mode 0600 and state directory mode 0700')
    probes += 1

print('RESULT: ' + str(probes) + ' independent runner failure/reset/privacy probes passed')
