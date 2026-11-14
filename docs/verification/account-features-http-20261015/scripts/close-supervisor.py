import datetime,json,os,sys
from pathlib import Path
root=Path('/workspace/runtime/account-features-http-evidence-20261015')
name=sys.argv[1]
a=json.loads((root/('launch-'+name+'.json')).read_text())
p=Path('/proc')/str(a['pid'])
s=(p/'stat').read_text().rsplit(')',1)[1].split()
assert s[0]=='Z' and s[19]==a['process_start_ticks'] and p.stat().st_uid==a['uid']
raw=int(s[49])
r={'pid':a['pid'],'uid':a['uid'],'process_start_ticks':a['process_start_ticks'],'state':s[0],'raw_wait_status':raw,'observed_os_exit':os.waitstatus_to_exitcode(raw),'identity_matched':True,'observed_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()}
q=root/name/'supervisor-os-exit.json'
with q.open('x') as stream:json.dump(r,stream,indent=2);stream.write('\n')
print(json.dumps(r))
