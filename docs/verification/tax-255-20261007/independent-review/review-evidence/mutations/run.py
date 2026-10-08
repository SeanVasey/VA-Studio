import subprocess, sys, os, importlib.util, datetime
W='/home/user/VA-Studio-review-tax255-aux'
E='/home/user/VA-Studio-review-tax255/docs/verification/tax-255-20261007/independent-review/review-evidence/mutations'
os.makedirs(E, exist_ok=True)
spec=importlib.util.spec_from_file_location('m', os.path.join(os.path.dirname(__file__),'mutations.py')); m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
only=set(sys.argv[1:])
ledger=open(E+'/mutation-ledger.txt','a')
def now(): return datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
assert subprocess.run(['git','diff','--quiet','--','app','database'],cwd=W).returncode==0
head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=W,text=True).strip()
for entry in m.M:
    mid,path,old,new,tests=entry[:5]; extra=entry[5] if len(entry)>5 else None
    if only and mid not in only: continue
    src=open(os.path.join(W,path)).read()
    pairs=[(old,new)]+([extra] if extra else [])
    ok=True
    for o,n in pairs:
        c=src.count(o)
        if c!=1: ledger.write(f"{now()} {mid} HARNESS-ERROR pattern count {c} (not applied)\n"); ok=False; break
        src=src.replace(o,n)
    if not ok: continue
    open(os.path.join(W,path),'w').write(src)
    diff=subprocess.check_output(['git','diff','--','app','database'],cwd=W,text=True)
    open(f"{E}/{mid}.diff",'w').write(diff)
    assert diff.strip(), mid
    cmd=['php','-r','$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";','--','--colors=never','--log-junit',f"{E}/{mid}.junit.xml"]
    # PHPUnit accepts one path argument; run each selection file and aggregate.
    results=[]
    with open(f"{E}/{mid}.txt",'w') as out:
        for t in tests:
            p=subprocess.run(cmd[:-1]+[f"{E}/{mid}.{os.path.basename(t).replace('.php','')}.junit.xml", t],cwd=W,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
            out.write(f"===== {t} exit={p.returncode}\n"+p.stdout+"\n")
            last=[l for l in p.stdout.splitlines() if l.startswith(('Tests:','OK ('))]
            results.append((os.path.basename(t),p.returncode,last[-1] if last else '?'))
    subprocess.run(['git','checkout','--','app','database'],cwd=W,check=True)
    clean=subprocess.run(['git','diff','--quiet','--','app','database'],cwd=W).returncode
    red=[r for r in results if r[1]!=0]
    ledger.write(f"{now()} {mid} on {head[:8]} {'RED' if red else 'GREEN (survived)'}; " + '; '.join(f"{a} exit={b} [{c}]" for a,b,c in results) + f"; reverted, git diff --quiet -- app database exit={clean}\n")
    ledger.flush()
    assert clean==0
