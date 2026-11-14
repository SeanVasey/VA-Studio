import datetime, hashlib, json, subprocess, sys
from pathlib import Path
root=Path('/workspace/VA-Studio-tax-v2-committed-read')
out=Path('/workspace/runtime/b4-http-staging')
name=sys.argv[1]; argv=sys.argv[2:]
state=lambda:{'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'status':subprocess.check_output(['git','status','--porcelain=v1'],cwd=root,text=True)}
before=state();assert before['status']==''
log=out/(name+'.log');result=out/(name+'.json');assert not log.exists() and not result.exists()
started=datetime.datetime.now(datetime.timezone.utc).isoformat()
with log.open('xb') as stream:
 proc=subprocess.run(argv,cwd=root,stdout=stream,stderr=subprocess.STDOUT)
record={'started_utc':started,'finished_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'argv':argv,'cwd':str(root),'source_before':before,'source_after':state(),'process_exit':proc.returncode,'log_sha256':hashlib.sha256(log.read_bytes()).hexdigest()}
record['source_unchanged']=record['source_before']==record['source_after']
with result.open('x') as stream:json.dump(record,stream,indent=2);stream.write('\n')
print(json.dumps(record,indent=2));sys.exit(proc.returncode if record['source_unchanged'] else 1)
