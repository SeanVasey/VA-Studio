#!/usr/bin/env python3
"""Review-only probes for file authority across all Laravel dotenv adapters.

All identifiers and key markers are synthetic. Optional-probe arguments are used
only with profiles that must fail before provider access. Valid profiles never
request a provider probe. The wrapper only selects inherited adapters; it does
not change the validator or Laravel bootstrap.
"""

import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[4]
PHP = Path('/workspace/.va-studio-toolchain/standalone/bin/php8.4')
SCRIPT = ROOT / 'scripts/ops/validate-test-commerce-profile.php'
RUNNER = ROOT / 'scripts/ops/run-test-commerce-pipeline.sh'
REVIEWED = '8f3d1cef024cabb6044f86c45f75ccb6fdc00550'
VALUES = {
    '<STAGING_HOST>': 'masking-review.synthetic.invalid',
    '<acct_ID>': 'acct_MaskingReviewSyntheticOnly',
    '<sk_test_KEY>': 'sk_test_' + 'MaskingReviewSyntheticOnly0000',
    '<whsec_SECRET>': 'whsec_' + 'MaskingReviewSyntheticOnly0000',
    '<SELLER_LEGAL_NAME>': 'Masking Review Synthetic Seller',
    '<ASSENT_TEXT>': 'Masking review synthetic test assent only.',
}
FILLED = (ROOT / 'ops/staging/test-commerce/env.test-commerce.example').read_text()
for placeholder, value in VALUES.items():
    FILLED = FILLED.replace(placeholder, value)
LIVE = 'sk_' + 'live_' + 'MaskingReviewSyntheticOnly0000'
PROBE = ['--probe', '--i-understand-this-calls-stripe']
PATH = os.environ.get('PATH', '/usr/bin:/bin')
COUNT = 0


def set_value(profile, name, value):
    updated, count = re.subn(r'^' + re.escape(name) + r'=.*$',
                             lambda _: name + '=' + value, profile,
                             count=1, flags=re.MULTILINE)
    return updated if count else updated + '\n' + name + '=' + value + '\n'


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


def execute(directory, wrapper, label, profile, adapter='all', inputs=None,
            probe=False, expected=None, pass_profile=False):
    global COUNT
    path = directory / (label + '.env')
    path.write_text(profile)
    path.chmod(0o600)
    result = subprocess.run([str(PHP), str(wrapper), adapter,
                             json.dumps(inputs or {}), str(SCRIPT), str(path),
                             *(PROBE if probe else [])], cwd=ROOT,
                            env={'PATH': PATH}, capture_output=True, text=True,
                            timeout=30)
    output = result.stdout + result.stderr
    checks = {match[2]: match[1] == 'PASS'
              for match in re.finditer(r'^(PASS|FAIL) ([a-z_.0-9]+) - ',
                                       output, re.MULTILINE)}
    assert result.stderr == '', label + ': unexpected stderr'
    assert all(value not in output for value in [*VALUES.values(), LIVE,
                                                 str(directory)]), label + ': value/path exposure'
    if pass_profile:
        assert result.returncode == 0 and len(checks) == 35 and all(checks.values()), label
    else:
        assert result.returncode == 1 and checks.get(expected) is False, label
        if probe:
            assert checks.get('stripe.probe_account') is False, label
            assert 'Probe not attempted:' in output and 'No Stripe request was made.' in output, label
    COUNT += 1
    failed = ','.join(name for name, ok in checks.items() if not ok)
    print('PASS ' + label + ': exit=' + str(result.returncode)
          + ', checks=' + str(len(checks)) + ', failed=' + (failed or 'none'))


assert subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip() == REVIEWED
print('Reviewed source: ' + REVIEWED)
print(subprocess.check_output([str(PHP), '-r', 'echo "PHP ".PHP_VERSION." SAPI ".PHP_SAPI."\n";'],
                              env={'PATH': PATH}, text=True).strip())
with tempfile.TemporaryDirectory(prefix='va-masking-review-') as temporary:
    directory = Path(temporary)
    directory.chmod(0o700)
    wrapper = directory / 'adapter-wrapper.php'
    wrapper.write_text(WRAPPER)
    wrapper.chmod(0o600)
    execute(directory, wrapper, 'filled-baseline', FILLED, pass_profile=True)
    masking = (
        ('environment', 'APP_ENV', 'production', 'local', 'runtime.app_env_local'),
        ('debug', 'APP_DEBUG', 'true', 'false', 'runtime.app_debug_off'),
        ('mode', 'STRIPE_MODE', 'live', 'test', 'stripe.mode_test'),
        ('account', 'STRIPE_ACCOUNT_ID', 'invalid-file-account', VALUES['<acct_ID>'], 'stripe.account'),
        ('key', 'STRIPE_TEST_SECRET_KEY', 'invalid-file-key', VALUES['<sk_test_KEY>'], 'stripe.secret_key_test'),
        ('webhook', 'STRIPE_WEBHOOK_SECRET', 'invalid-file-secret', VALUES['<whsec_SECRET>'], 'stripe.webhook_receiver'),
        ('origin', 'APP_URL', 'https://different.synthetic.invalid', 'https://masking-review.synthetic.invalid', 'runtime.app_url_equals_return_origin'),
        ('queue', 'QUEUE_CONNECTION', 'sync', 'database', 'runtime.queue_database'),
        ('cookie', 'SESSION_SECURE_COOKIE', 'false', 'true', 'runtime.session_secure_cookie'),
        ('checkout', 'STRIPE_TEST_CHECKOUT_ENABLED', 'false', 'true', 'commerce.checkout'),
        ('production', 'PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED', 'true', 'false', 'boundary.out_of_profile_families_off'),
    )
    invalid_exports = {'APP_ENV': 'production', 'APP_DEBUG': 'true',
                       'STRIPE_MODE': 'live', 'STRIPE_ACCOUNT_ID': 'invalid-export-account',
                       'STRIPE_TEST_SECRET_KEY': LIVE, 'PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED': 'true'}
    for adapter in ('getenv', '_ENV', '_SERVER'):
        for label, name, bad, safe, check in masking:
            execute(directory, wrapper, adapter + '-mask-' + label,
                    set_value(FILLED, name, bad), adapter, {name: safe},
                    probe=True, expected=check)
        execute(directory, wrapper, adapter + '-invalid-exports-ignored', FILLED,
                adapter, invalid_exports, pass_profile=True)
        execute(directory, wrapper, adapter + '-file-interpolation-wins',
                'REVIEW_DEBUG=false\n' + set_value(FILLED, 'APP_DEBUG', '"${REVIEW_DEBUG}"'),
                adapter, {'REVIEW_DEBUG': 'true'}, pass_profile=True)
        execute(directory, wrapper, adapter + '-unsafe-file-interpolation-refused',
                'REVIEW_DEBUG=true\n' + set_value(FILLED, 'APP_DEBUG', '"${REVIEW_DEBUG}"'),
                adapter, {'REVIEW_DEBUG': 'false'}, probe=True,
                expected='runtime.app_debug_off')
        execute(directory, wrapper, adapter + '-inherited-interpolation-refused',
                set_value(FILLED, 'APP_DEBUG', '"${REVIEW_DEBUG}"'),
                adapter, {'REVIEW_DEBUG': 'false'}, probe=True,
                expected='runtime.app_debug_off')

    marker = directory / 'foreign-cache-executed'
    cache = directory / 'foreign-cache.php'
    cache.write_text('<?php file_put_contents(' + json.dumps(str(marker))
                     + ', "executed"); return [];\n')
    for adapter in ('getenv', '_ENV', '_SERVER'):
        execute(directory, wrapper, adapter + '-foreign-cache-ignored', FILLED,
                adapter, {'APP_CONFIG_CACHE': str(cache)}, pass_profile=True)
        assert not marker.exists(), 'foreign cache executed'
    execute(directory, wrapper, 'file-foreign-cache-ignored',
            set_value(FILLED, 'APP_CONFIG_CACHE', str(cache)), pass_profile=True)
    assert not marker.exists(), 'profile foreign cache executed'

    # The runner uses the wall clock; deliberately pre-age only its test stamp.
    # No 60-second sleep or clock replacement is required for the actual script.
    app = directory / 'app'
    state = directory / 'state'
    app.mkdir(mode=0o700)
    state.mkdir(mode=0o700)
    (app / 'artisan').touch()
    calls = directory / 'runner-calls'
    fake = directory / 'fake-php'
    fake.write_text('#!/usr/bin/env bash\nprintf "%s\\n" "$2" >> "$REVIEW_CALLS"\n')
    fake.chmod(0o700)
    base_env = {'PATH': PATH, 'APP_ROOT': str(app), 'PHP_BIN': str(fake),
                'STATE_DIR': str(state), 'REVIEW_CALLS': str(calls),
                'COMMAND_TIMEOUT_SECONDS': '10', 'MAX_PAGES': '1'}
    for label, age, interval, expected_calls in (
            ('default-rechecks-after-61s', 61, None, 1),
            ('default-skips-fresh-stamp', 0, None, 0),
            ('explicit-900-retains-custom-cadence', 61, '900', 0),
            ('explicit-zero-runs-every-sweep', 0, '0', 1)):
        (state / 'reconcile.last').write_text(str(int(time.time()) - age))
        calls.write_text('')
        env = dict(base_env)
        if interval is not None:
            env['RECONCILE_INTERVAL_SECONDS'] = interval
        result = subprocess.run(['bash', str(RUNNER)], cwd=ROOT, env=env,
                                capture_output=True, text=True, timeout=30)
        count = calls.read_text().splitlines().count('vasey:reconcile-test-payments')
        assert result.returncode == 0 and result.stderr == '' and count == expected_calls, label
        if expected_calls == 0:
            assert 'reconcile not due (interval ' + (interval or '60') + 's)' in result.stdout, label
        COUNT += 1
        print('PASS ' + label + ': actual runner exit=0, reconcile calls=' + str(count))

service = (ROOT / 'ops/staging/test-commerce/vasey-test-commerce-pipeline.service').read_text()
timer = (ROOT / 'ops/staging/test-commerce/vasey-test-commerce-pipeline.timer').read_text()
assert re.search(r'^Environment=RECONCILE_INTERVAL_SECONDS=60$', service, re.MULTILINE)
assert re.search(r'^OnUnitInactiveSec=60s$', timer, re.MULTILINE)
COUNT += 1
print('PASS service-and-timer-one-minute-configuration: inspected; units not installed')
print('RESULT: ' + str(COUNT) + ' independent masking/interpolation/cache/privacy/cadence checks passed; no provider request attempted')
