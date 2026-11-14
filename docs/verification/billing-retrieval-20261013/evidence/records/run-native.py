#!/usr/bin/env python3
"""Only execute after coordinator releases the private native instance slot."""
import hashlib, json, os, pathlib, subprocess, sys

root = pathlib.Path('/workspace/VA-Studio-membership-ledger')
out = pathlib.Path('/workspace/runtime/billing-retrieval-records-evidence')
label = sys.argv[1]
if label not in {'native-producer-canary', 'native-producer-remainder'}:
    raise SystemExit('Unknown fixed selection')
env = os.environ.copy()
env.update({'APP_BASE_PATH': str(root), 'APP_ENV': 'testing', 'APP_DEBUG': 'false', 'DB_URL': '',
            'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array', 'QUEUE_CONNECTION': 'sync',
            'DB_CONNECTION': 'mysql', 'DB_HOST': '127.0.0.1', 'DB_PORT': '33413', 'DB_USERNAME': 'root',
            'DB_PASSWORD': '', 'DB_DATABASE': 'vaseyaudio_billing_retrieval_producer',
            'DB_SOCKET': '/workspace/runtime/mysql-policy-run/mysql.sock', 'XDEBUG_MODE': 'off'})
tracked = subprocess.check_output(['git', 'ls-files', 'app', 'bootstrap', 'config', 'database', 'tests', 'resources/contracts', 'composer.json', 'composer.lock', 'phpunit.xml'], cwd=root, text=True).splitlines()
hashes = {p: hashlib.sha256((root/p).read_bytes()).hexdigest() for p in tracked if (root/p).is_file()}
selection = ['tests/Feature/ProductionMembershipBilling/BillingIssuedRetrievalTest.php']
if label == 'native-producer-canary':
    selection += ['--filter', 'test_database_end_follows_provider_reads_and_worker_time_cannot_change_paid_time_or_freshness']
else:
    selection += ['tests/Feature/ProductionMembershipBilling/BillingLegacyObservationTransitionTest.php', '--filter', '(?!.*test_database_end_follows_provider_reads_and_worker_time_cannot_change_paid_time_or_freshness)^.*$']
cmd = ['php', 'vendor/bin/phpunit', *selection, '--do-not-cache-result', '--colors=never', '--stop-on-error', '--stop-on-failure', '--stop-on-risky', '--log-junit', str(out/(label+'.xml'))]
source = {'cwd': str(root), 'head': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip(),
          'argv': cmd, 'environment': {k: env[k] for k in ['APP_BASE_PATH','APP_ENV','APP_DEBUG','DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_SOCKET','DB_URL','CACHE_STORE','SESSION_DRIVER','QUEUE_CONNECTION']},
          'source_file_count': len(hashes), 'sha256': hashes}
(out/(label+'.source.json')).write_text(json.dumps(source, indent=2)+'\n')
with (out/(label+'.txt')).open('w') as stream:
    result = subprocess.run(cmd, cwd=root, env=env, stdout=stream, stderr=subprocess.STDOUT)
(out/(label+'.exit')).write_text(str(result.returncode)+'\n')
changed = [p for p,h in hashes.items() if not (root/p).is_file() or hashlib.sha256((root/p).read_bytes()).hexdigest()!=h]
(out/(label+'.source-check.json')).write_text(json.dumps({'source_file_count':len(hashes),'changed':changed},indent=2)+'\n')
print((out/(label+'.txt')).read_text())
print(json.dumps({'exit':result.returncode,'source_file_count':len(hashes),'changed':changed}))
raise SystemExit(result.returncode or bool(changed))
