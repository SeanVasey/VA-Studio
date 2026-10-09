#!/usr/bin/env python3
"""Independent subprocess probes; synthetic input only, no provider request."""

import os
from pathlib import Path
import re
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[4]
PHP = Path('/workspace/.va-studio-toolchain/standalone/bin/php8.4')
SCRIPT = ROOT / 'scripts/ops/validate-test-commerce-profile.php'
TEMPLATE = (ROOT / 'ops/staging/test-commerce/env.test-commerce.example').read_text()
SYNTHETIC = {
    '<STAGING_HOST>': 'independent.synthetic.invalid',
    '<acct_ID>': 'acct_IndependentReviewSyntheticOnly',
    '<sk_test_KEY>': 'sk_test_' + 'IndependentReviewSyntheticOnly0000',
    '<whsec_SECRET>': 'whsec_IndependentReviewSyntheticOnly0000',
    '<SELLER_LEGAL_NAME>': 'Independent Synthetic Seller',
    '<ASSENT_TEXT>': 'Independent synthetic test assent only.',
}
FILLED = TEMPLATE
for placeholder, replacement in SYNTHETIC.items():
    FILLED = FILLED.replace(placeholder, replacement)
LIVE_MARKER = 'sk_' + 'live_' + 'IndependentSyntheticOnly0000'
PROBE = ['--probe', '--i-understand-this-calls-stripe']


def execute(directory, label, profile, exported=None, args=None):
    env = {'PATH': os.environ.get('PATH', '/usr/bin:/bin')}
    env.update(exported or {})
    path = directory / (label + '.env')
    path.write_text(profile)
    path.chmod(0o600)
    result = subprocess.run([str(PHP), str(SCRIPT), *(args or []), str(path)],
                            cwd=ROOT, env=env, capture_output=True, text=True, timeout=30)
    output = result.stdout + result.stderr
    checks = {match[2]: match[1] == 'PASS'
              for match in re.finditer(r'^(PASS|FAIL) ([a-z_.0-9]+) - ', output, re.MULTILINE)}
    assert all(value not in output for value in [*SYNTHETIC.values(), LIVE_MARKER]), label + ': value exposure'
    print(label + ': exit=' + str(result.returncode) + ', checks=' + str(len(checks))
          + ', failed=' + ','.join(name for name, ok in checks.items() if not ok))
    print(next(line for line in output.splitlines() if line.startswith('RESULT:')))
    return result.returncode, checks, output


with tempfile.TemporaryDirectory(prefix='va-independent-profile-') as temporary:
    directory = Path(temporary)
    directory.chmod(0o700)
    status, checks, _ = execute(directory, 'filled-baseline', FILLED)
    assert status == 0 and len(checks) == 35 and all(checks.values())

    status, checks, _ = execute(directory, 'exported-debug-wins', FILLED, {'APP_DEBUG': 'true'})
    assert status == 1 and checks['runtime.app_debug_off'] is False

    status, checks, output = execute(directory, 'exported-production-no-probe', FILLED,
                                     {'APP_ENV': 'production'}, PROBE)
    assert status == 1 and checks['runtime.app_env_local'] is False
    assert checks['stripe.probe_account'] is False and 'No Stripe request was made.' in output

    status, checks, output = execute(directory, 'exported-live-mode-no-probe', FILLED,
                                     {'STRIPE_MODE': 'live'}, PROBE)
    assert status == 1 and checks['stripe.mode_test'] is False
    assert checks['stripe.probe_account'] is False and 'No Stripe request was made.' in output

    status, checks, output = execute(directory, 'exported-live-key-no-probe', FILLED,
                                     {'STRIPE_TEST_SECRET_KEY': LIVE_MARKER}, PROBE)
    assert status == 1 and checks['stripe.secret_key_test'] is False
    assert checks['stripe.probe_account'] is False and 'No Stripe request was made.' in output

    cache = directory / 'foreign-cache.php'
    marker = directory / 'foreign-cache-executed'
    cache.write_text("<?php file_put_contents('" + str(marker) + "', 'executed'); return [];\n")
    status, checks, _ = execute(directory, 'exported-cache-ignored', FILLED,
                                {'APP_CONFIG_CACHE': str(cache)})
    assert status == 0 and all(checks.values()) and not marker.exists()

    status, checks, _ = execute(directory, 'profile-cache-ignored', FILLED + '\nAPP_CONFIG_CACHE=' + str(cache) + '\n')
    assert status == 0 and all(checks.values()) and not marker.exists()

    status, checks, _ = execute(directory, 'unrelated-live-value-refused',
                                FILLED + '\nINDEPENDENT_UNUSED_MATERIAL=' + LIVE_MARKER + '\n')
    assert status == 1 and checks['profile.no_live_keys'] is False

    status, checks, _ = execute(directory, 'comment-live-material-observation',
                                FILLED + '\n# Synthetic discarded marker: ' + LIVE_MARKER + '\n')
    # Observation: comments are not dotenv values. This does not permit live provider I/O.
    print('OBSERVATION: live-marker comment classified as file-wide clean=' + str(checks['profile.no_live_keys']))
    assert status == 0 and checks['profile.no_live_keys'] is True

print('RESULT: 9 independent profile isolation/privacy probes passed; no provider request attempted')
