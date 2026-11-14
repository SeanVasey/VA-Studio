from pathlib import Path
import argparse,datetime,hashlib,json,os,re,subprocess,time
p=argparse.ArgumentParser();p.add_argument('--name',required=True);p.add_argument('argv',nargs=argparse.REMAINDER);a=p.parse_args();argv=a.argv[1:] if a.argv[:1]==['--'] else a.argv
r=Path('/workspace/VA-Studio-member-originals');o=Path('/workspace/runtime/member-originals-quality-evidence');frozen=json.loads((o/'source-before.json').read_text());env={'PYTHONDONTWRITEBYTECODE':'1','APP_ENV':'testing','APP_DEBUG':'false','DB_CONNECTION':'mysql','DB_HOST':'127.0.0.1','DB_PORT':'1','DB_SOCKET':'','DB_DATABASE':'vaseyaudio_discovery_only','DB_USERNAME':'synthetic_discovery_only','DB_PASSWORD':'','DB_URL':'','CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync'}
def hashes():return {p:hashlib.sha256((r/p).read_bytes()).hexdigest() for p in frozen['sources']}
assert hashes()==frozen['sources'];head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=r,text=True).strip();assert head==frozen['head']
record={'name':a.name,'head':head,'cwd':str(r),'argv':argv,'environment':env,'source_before_sha256':hashlib.sha256((o/'source-before.json').read_bytes()).hexdigest(),'runner_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),'started_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()}
if argv[0]=='vendor/bin/pint':record['selected_source_hashes']={s:hashlib.sha256((r/s).read_bytes()).hexdigest() for s in argv[2:]}
(o/(a.name+'.command.json')).write_text(json.dumps(record,indent=2)+'\n');started=time.monotonic()
with (o/(a.name+'.log')).open('wb') as log:
 try:run=subprocess.run(argv,cwd=r,env={**os.environ,**env},stdout=log,stderr=subprocess.STDOUT,timeout=600);code=run.returncode
 except subprocess.TimeoutExpired:log.write(b'\nRUNNER TIMEOUT after600seconds\n');code=124
(o/(a.name+'.exit')).write_text(str(code)+'\n');after=hashes();result={'exit':code,'duration_seconds':round(time.monotonic()-started,3),'completed_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'source_count':len(after),'source_unchanged':after==frozen['sources'],'head_after':subprocess.check_output(['git','rev-parse','HEAD'],cwd=r,text=True).strip()}
s=(o/(a.name+'.log')).read_text();m=re.search(r'Ran (\d+) tests? in',s)
if m:result['selftest_count']=int(m.group(1))
if argv[0]=='vendor/bin/pint':result['selected_php_files']=len(argv)-2;result['selected_sources_unchanged']=record['selected_source_hashes']=={p:hashlib.sha256((r/p).read_bytes()).hexdigest() for p in record['selected_source_hashes']}
(o/(a.name+'.result.json')).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result));assert result['source_unchanged'] and result['head_after']==head
raise SystemExit(code)
