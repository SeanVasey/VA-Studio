#!/usr/bin/env python3
import datetime
import hashlib
import json
import os
from pathlib import Path
import subprocess
import time
ROOT=Path('/workspace/VA-Studio-billing-retrieval')
OUT=Path('/workspace/runtime/billing-retrieval-quality-final/pint-v2')
PRIOR=OUT.parent
BASE='6c871b9c5b510c1926437bbdf6e96d661e2c2f30'
def git(*args):
 return subprocess.check_output(['git',*args],cwd=ROOT).decode().strip()
def sha(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def save(name,v): (OUT/name).write_text(json.dumps(v,indent=2,sort_keys=True)+'\n')
prior=json.loads((PRIOR/'sources-before.json').read_text())
before={p:sha(ROOT/p) for p in prior}
head=git('rev-parse','HEAD')
changed_php_argv=['git','diff','--name-only','--diff-filter=ACM','-z',BASE,'--','*.php']
files=[p for p in subprocess.check_output(changed_php_argv,cwd=ROOT).decode().split('\0') if p]
prior_paths=(PRIOR/'changed-php-files.txt').read_text().splitlines()
assert files==prior_paths
assert len(files)==56
changed=[p for p in before if before[p]!=prior[p]]
assert changed==['tests/Feature/ProductionMembershipBilling/BillingRetrievalAttemptsTest.php']
carry_paths=[p for p in before if p.startswith(('scripts/ci/','.github/workflows/')) or p in ('composer.json','composer.lock','phpunit.xml','.gitlab-ci.yml')]
carry={'original_head':json.loads((PRIOR/'metadata.json').read_text())['head'],'current_head':head,'policy_and_script_paths':{p:before[p] for p in carry_paths},'unchanged_policy_and_script_count':len(carry_paths),'all_source_inventory_changes':changed,'policy_changed':any(prior[p]!=before[p] for p in carry_paths),'original_results_sha256':sha(PRIOR/'results.json'),'original_tests':154,'status':'carried with unchanged CI policy/script/workflow bytes; not rerun'}
assert carry['policy_changed'] is False
save('policy-carry.json',carry)
save('sources-before.json',before)
(ROOT/'tests/Feature/ProductionMembershipBilling/BillingRetrievalAttemptsTest.php').is_file()
(OUT/'source-delta.patch').write_text(git('diff',carry['original_head'],head,'--',changed[0])+'\n')
argv=['vendor/bin/pint','--test',*files]
command={'argv':argv,'cwd':str(ROOT),'head':head,'base':BASE,'changed_php_discovery_argv':changed_php_argv,'changed_php_count':len(files),'runner_sha256':sha(Path(__file__)),'started_at_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'status_before':git('status','--short'),'pint_proxy_realpath':str((ROOT/'vendor/bin/pint').resolve()),'pint_proxy_sha256':sha(ROOT/'vendor/bin/pint'),'autoload_realpath':str((ROOT/'vendor/autoload.php').resolve()),'autoload_sha256':sha(ROOT/'vendor/autoload.php'),'php_version':subprocess.check_output(['php','--version']).decode(),'environment_setup':'source /workspace/.va-studio-toolchain/activate.sh'}
save('pint.command.json',command)
start=time.monotonic()
with (OUT/'pint.log').open('wb') as f: result=subprocess.run(argv,cwd=ROOT,stdout=f,stderr=subprocess.STDOUT)
(OUT/'pint.exit').write_text(str(result.returncode)+'\n')
after={p:sha(ROOT/p) for p in prior}
save('sources-after.json',after)
source_check={'before_count':len(before),'after_count':len(after),'changed':[p for p in before if before[p]!=after[p]],'head_before':head,'head_after':git('rev-parse','HEAD'),'status_after':git('status','--short')}
save('source-check.json',source_check)
final={**command,'exit':result.returncode,'duration_seconds':round(time.monotonic()-start,6),'completed_at_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'log_sha256':sha(OUT/'pint.log'),'sources_unchanged':source_check['changed']==[]}
save('pint.result.json',final)
print(json.dumps({'head':head,'pint_exit':result.returncode,'selected_php_files':len(files),'source_count':len(before),'changed':source_check['changed'],'ci_policy_script_hashes_carried':len(carry_paths)}),flush=True)
print((OUT/'pint.log').read_text(),flush=True)
raise SystemExit(0 if result.returncode==0 and not source_check['changed'] else 1)
