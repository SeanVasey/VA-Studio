#!/usr/bin/env python3
"""Independent HttpOnly profile gate probes; synthetic values, no provider I/O."""

import json
import os
from pathlib import Path
import re
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[4]
PHP = Path('/workspace/.va-studio-toolchain/standalone/bin/php8.4')
SCRIPT = ROOT / 'scripts/ops/validate-test-commerce-profile.php'
REVIEWED = '1b2d0aa85b00b55d36e71eae66d61340dfc0b820'
VALUES = {
    '<STAGING_HOST>': 'httponly-review.synthetic.invalid',
    '<acct_ID>': 'acct_HttpOnlyReviewSyntheticOnly',
    '<sk_test_KEY>': 'sk_test_' + 'HttpOnlyReviewSyntheticOnly0000',
    '<whsec_SECRET>': 'whsec_' + 'HttpOnlyReviewSyntheticOnly0000',
    '<SELLER_LEGAL_NAME>': 'HttpOnly Review Synthetic Seller',
    '<ASSENT_TEXT>': 'HttpOnly review synthetic test assent only.',
}
FILLED = (ROOT / 'ops/staging/test-commerce/env.test-commerce.example').read_text()
for placeholder, value in VALUES.items():
    FILLED = FILLED.replace(placeholder, value)
PATH = os.environ.get('PATH', '/usr/bin:/bin')
COUNT = 0


def set_value(profile, value):
    updated, count = re.subn(r'^SESSION_HTTP_ONLY=.*$',
                             lambda _: 'SESSION_HTTP_ONLY=' + value, profile,
                             count=1, flags=re.MULTILINE)
    assert count == 1
    return updated


MISSING = re.sub(r'^SESSION_HTTP_ONLY=.*\n', '', FILLED, flags=re.MULTILINE)
WRAPPER = r'''<?php
$adapter = $argv[1];
$inputs = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
$script = $argv[3];
$profile = $argv[4];
$options = array_slice($argv, 5);
foreach ($inputs as $name => $value) {
    putenv($name);
    unset($_ENV[$name], $_SERVER[$name]);
    if ($adapter === 'getenv' || $adapter === 'all') { putenv($name.'='.$value); }
    if ($adapter === '_ENV' || $adapter === 'all') { $_ENV[$name] = $value; }
    if ($adapter === '_SERVER' || $adapter === 'all') { $_SERVER[$name] = $value; }
}
$argv = [$script, ...$options, $profile];
require $script;
'''


def execute(directory, wrapper, label, profile, valid, adapter='all', inputs=None):
    global COUNT
    path = directory / (label + '.env')
    path.write_text(profile)
    path.chmod(0o600)
    # A valid profile is never given provider probe arguments.
    options = [] if valid else ['--probe', '--i-understand-this-calls-stripe']
    result = subprocess.run([str(PHP), str(wrapper), adapter,
                             json.dumps(inputs or {}), str(SCRIPT), str(path),
                             *options], cwd=ROOT, env={'PATH': PATH},
                            capture_output=True, text=True, timeout=30)
    output = result.stdout + result.stderr
    checks = {match[2]: match[1] == 'PASS'
              for match in re.finditer(r'^(PASS|FAIL) ([a-z_.0-9]+) - ', output, re.MULTILINE)}
    assert result.stderr == '', label + ': unexpected stderr'
    assert all(value not in output for value in [*VALUES.values(), str(directory)]), label + ': value/path exposure'
    if valid:
        assert result.returncode == 0 and len(checks) == 36 and all(checks.values()), label
        assert checks['runtime.session_http_only'] is True, label
    else:
        assert result.returncode == 1 and len(checks) == 37, label
        assert checks['runtime.session_http_only'] is False, label
        assert checks['stripe.probe_account'] is False, label
        assert 'Probe not attempted:' in output and 'No Stripe request was made.' in output, label
    COUNT += 1
    failed = ','.join(name for name, ok in checks.items() if not ok)
    print('PASS ' + label + ': exit=' + str(result.returncode)
          + ', checks=' + str(len(checks)) + ', failed=' + (failed or 'none'))


assert subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip() == REVIEWED
print('Reviewed source: ' + REVIEWED)
print(subprocess.check_output([str(PHP), '-r', 'echo "PHP ".PHP_VERSION." SAPI ".PHP_SAPI."\n";'],
                              env={'PATH': PATH}, text=True).strip())
with tempfile.TemporaryDirectory(prefix='va-httponly-review-') as temporary:
    directory = Path(temporary)
    directory.chmod(0o700)
    wrapper = directory / 'adapter-wrapper.php'
    wrapper.write_text(WRAPPER)
    wrapper.chmod(0o600)
    cases = (
        ('explicit-true', FILLED, True),
        ('missing-uses-safe-default', MISSING, True),
        ('quoted-true', set_value(FILLED, '"true"'), True),
        ('false-refuses-probe', set_value(FILLED, 'false'), False),
        ('quoted-false-refuses-probe', set_value(FILLED, '"false"'), False),
        ('parenthesized-false-refuses-probe', set_value(FILLED, '(false)'), False),
        ('empty-refuses-probe', set_value(FILLED, ''), False),
        ('unresolved-interpolation-refuses-probe', set_value(FILLED, '"${REVIEW_HTTP}"'), False),
        ('file-interpolation-true', 'REVIEW_HTTP=true\n' + set_value(FILLED, '"${REVIEW_HTTP}"'), True),
        ('file-interpolation-false-refuses-probe', 'REVIEW_HTTP=false\n' + set_value(FILLED, '"${REVIEW_HTTP}"'), False),
        ('default-ignores-inherited-false', MISSING, True),
    )
    for label, profile, valid in cases:
        inputs = {'SESSION_HTTP_ONLY': 'false'} if label == 'default-ignores-inherited-false' else {}
        execute(directory, wrapper, label, profile, valid, inputs=inputs)
    for adapter in ('getenv', '_ENV', '_SERVER'):
        for label, profile in (
                ('false-mask', set_value(FILLED, 'false')),
                ('quoted-false-mask', set_value(FILLED, '"false"')),
                ('interpolated-false-mask', 'REVIEW_HTTP=false\n' + set_value(FILLED, '"${REVIEW_HTTP}"'))):
            execute(directory, wrapper, adapter + '-' + label, profile, False,
                    adapter, {'SESSION_HTTP_ONLY': 'true', 'REVIEW_HTTP': 'true'})
        execute(directory, wrapper, adapter + '-explicit-true-ignores-false',
                FILLED, True, adapter, {'SESSION_HTTP_ONLY': 'false'})
        execute(directory, wrapper, adapter + '-missing-default-ignores-false',
                MISSING, True, adapter, {'SESSION_HTTP_ONLY': 'false'})
        execute(directory, wrapper, adapter + '-inherited-interpolation-refused',
                set_value(FILLED, '"${REVIEW_HTTP}"'), False, adapter, {'REVIEW_HTTP': 'true'})
        execute(directory, wrapper, adapter + '-local-interpolation-ignores-export',
                'REVIEW_HTTP=true\n' + set_value(FILLED, '"${REVIEW_HTTP}"'),
                True, adapter, {'REVIEW_HTTP': 'false'})
print('RESULT: ' + str(COUNT) + ' independent HttpOnly/default/interpolation/adapter/privacy checks passed; no provider request attempted')
