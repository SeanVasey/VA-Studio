import subprocess, sys, os, importlib.util, datetime
W='/home/user/VA-Studio-review-tax255b-aux'
E='/home/user/VA-Studio-review-tax255b/docs/verification/tax-255-20261007/independent-review/review-evidence/addendum1/mutations'
NATIVE='/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-tax255b-native.sh'
NE='/home/user/VA-Studio-review-tax255b/docs/verification/tax-255-20261007/independent-review/review-evidence/addendum1/native'
os.makedirs(E, exist_ok=True)
spec=importlib.util.spec_from_file_location('m', os.path.join(os.path.dirname(__file__),'mutations.py')); m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
only=set(sys.argv[1:])
def now(): return datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
def log(s):
    with open(E+'/mutation-ledger.txt','a') as f: f.write(s+'\n')
assert subprocess.run(['git','diff','--quiet','--','app','database'],cwd=W).returncode==0
head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=W,text=True).strip()
PHP=['php','-r','$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";','--','--colors=never']
for mid,path,old,new,mode,tests in m.M:
    if only and mid not in only: continue
    src=open(os.path.join(W,path)).read(); c=src.count(old)
    if c!=1: log(f"{now()} {mid} HARNESS-ERROR pattern count {c} (not applied)"); continue
    open(os.path.join(W,path),'w').write(src.replace(old,new))
    diff=subprocess.check_output(['git','diff','--','app','database'],cwd=W,text=True); assert diff.strip()
    open(f"{E}/{mid}.diff",'w').write(diff)
    results=[]
    try:
        if mode=='sqlite':
            with open(f"{E}/{mid}.txt",'w') as out:
                for t in tests:
                    f,_,flt=t.partition('::'); name=os.path.basename(f).replace('.php','')
                    cmd=PHP+['--log-junit',f"{E}/{mid}.{name}.junit.xml"]+(['--filter',flt] if flt else [])+[f]
                    p=subprocess.run(cmd,cwd=W,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
                    out.write(f"===== {t} exit={p.returncode}\n"+p.stdout+"\n")
                    last=[l for l in p.stdout.splitlines() if l.startswith(('Tests:','OK ('))]
                    results.append((name+(('['+flt+']') if flt else ''),p.returncode,last[-1] if last else '?'))
        else:
            for t in tests:
                subprocess.run([NATIVE,W,'m',t],check=False)
                f,_,flt=t.partition('::'); label='m-'+os.path.basename(f).replace('.php','').replace('ProductionTaxCheckout','') 
                # read back the runner's last end line for this stream
                lines=[l for l in open(NE+'/run-ledger.txt') if ' end m-' in l]
                last=lines[-1].strip() if lines else '?'
                rc=int(last.split('exit=')[1].split()[0]) if 'exit=' in last else -999
                results.append((flt,rc,last.split(' exit=')[1] if 'exit=' in last else last))
    finally:
        subprocess.run(['git','checkout','--','app','database'],cwd=W,check=True)
    clean=subprocess.run(['git','diff','--quiet','--','app','database'],cwd=W).returncode
    log(f"{now()} {mid} on {head[:8]} [{mode}]; " + '; '.join(f"{a} exit={b} [{c}]" for a,b,c in results) + f"; reverted, git diff --quiet -- app database exit={clean}")
    assert clean==0
