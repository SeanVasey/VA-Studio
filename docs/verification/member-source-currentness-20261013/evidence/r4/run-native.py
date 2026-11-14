#!/usr/bin/env python3
"""Run only after root/catalog explicitly release the private native instance slot."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys

root = Path('/workspace/VA-Studio-member-policy-admission')
out = Path('/workspace/runtime/member-policy-admission-evidence')
expected = '15a65e0761382a9ad50c4b279605a644a4eeb285'
label = 'native-four'
if any(out.glob(label + '.*')):
    raise SystemExit('Existing native evidence is retained; refusing overwrite.')
head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
status = subprocess.check_output(['git', 'status', '--porcelain'], cwd=root, text=True)
if head != expected or status:
    raise SystemExit('Native selection requires the exact frozen clean source.')
env = os.environ.copy()
env.update({'APP_BASE_PATH':str(root), 'APP_ENV':'testing', 'APP_DEBUG':'false', 'DB_URL':'',
            'CACHE_STORE':'array', 'SESSION_DRIVER':'array', 'QUEUE_CONNECTION':'sync',
            'DB_CONNECTION':'mysql', 'DB_HOST':'127.0.0.1', 'DB_PORT':'33413', 'DB_USERNAME':'root',
            'DB_PASSWORD':'', 'DB_DATABASE':'vaseyaudio_member_policy_admission',
            'DB_SOCKET':'/workspace/runtime/mysql-policy-run/mysql.sock', 'XDEBUG_MODE':'off'})
setup = r'''
require 'vendor/autoload.php';
$path = (new ReflectionClass(App\Domain\Grants\Member\MemberGrantPolicy::class))->getFileName();
if ($path !== getcwd().'/app/Domain/Grants/Member/MemberGrantPolicy.php') {
    throw new LogicException('Private autoload does not resolve the owned application.');
}
$socket = getenv('DB_SOCKET');
if ($socket !== '/workspace/runtime/mysql-policy-run/mysql.sock') {
    throw new LogicException('Unexpected private socket.');
}
$pdo = new PDO('mysql:unix_socket='.$socket, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$metadata = $pdo->query('SELECT VERSION() AS mysql_version, @@port AS server_port, @@socket AS server_socket, @@datadir AS datadir')->fetch(PDO::FETCH_ASSOC);
if ($metadata['mysql_version'] !== '8.4.11' || $metadata['server_socket'] !== '/run/mysql-policy/mysql.sock'
    || $metadata['datadir'] !== '/var/lib/mysql/' || (int) $metadata['server_port'] !== 3306) {
    throw new LogicException('Unexpected private server identity; no schema mutation performed.');
}
$pdo->exec('CREATE DATABASE IF NOT EXISTS `vaseyaudio_member_policy_admission` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$metadata += ['php_version' => PHP_VERSION, 'owned_schema' => getenv('DB_DATABASE'), 'published_port' => 33413,
    'client_socket' => $socket, 'private_datadir_mount' => '/workspace/runtime/mysql-policy-data', 'policy_path' => $path];
echo json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
'''
setup_result = subprocess.run(['php', '-r', setup], cwd=root, env=env, capture_output=True, text=True)
(out/(label+'.setup.txt')).write_text(setup_result.stdout+setup_result.stderr)
(out/(label+'.setup.exit')).write_text(str(setup_result.returncode)+'\n')
if setup_result.returncode:
    print('Native setup failed; exact output retained.')
    raise SystemExit(setup_result.returncode)
metadata = json.loads(setup_result.stdout)
(out/(label+'.metadata.json')).write_text(json.dumps(metadata,indent=2)+'\n')
methods = [
 'test_native_verified_member_provenance_requires_and_binds_actual_mysql_despite_rehearsal_membership',
 'test_each_callback_boundary_checks_the_frame_before_resolving_later_factories',
 'test_terminal_proof_reads_raw_capability_maps_without_virtual_container_getters',
 'test_commit_and_reopen_on_the_same_pdo_cannot_reuse_an_original_admission',
]
cmd = ['php', 'vendor/bin/phpunit', '--do-not-cache-result', '--colors=never', '--stop-on-defect',
       '--filter', '('+'|'.join(methods)+')$', '--log-junit', str(out/(label+'.xml')),
       'tests/Feature/ProductionMemberOriginals/MemberGrantSourceAdmissionTest.php']
names = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode().rstrip('\0').split('\0')
hashes = {n:hashlib.sha256((root/n).read_bytes()).hexdigest() for n in names if (root/n).is_file()}
source = {'head':head,'status':status,'cwd':str(root),'argv':cmd,'methods':methods,'source_file_count':len(hashes),'files':hashes,
          'environment':{k:env[k] for k in ['APP_BASE_PATH','APP_ENV','APP_DEBUG','DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_SOCKET','DB_URL','CACHE_STORE','SESSION_DRIVER','QUEUE_CONNECTION']}}
(out/(label+'.source.json')).write_text(json.dumps(source,indent=2)+'\n')
with (out/(label+'.txt')).open('w') as stream:
    result = subprocess.run(cmd,cwd=root,env=env,stdout=stream,stderr=subprocess.STDOUT)
(out/(label+'.exit')).write_text(str(result.returncode)+'\n')
changed = [n for n,h in hashes.items() if not (root/n).is_file() or hashlib.sha256((root/n).read_bytes()).hexdigest()!=h]
(out/(label+'.source-check.json')).write_text(json.dumps({'source_file_count':len(hashes),'changed':changed},indent=2)+'\n')
print((out/(label+'.txt')).read_text())
print(json.dumps({'exit':result.returncode,'source_file_count':len(hashes),'changed':changed}))
raise SystemExit(result.returncode or bool(changed))
