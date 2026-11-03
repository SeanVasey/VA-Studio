"""SOURCE ONLY: one fresh owned account fixture. Separate explicit ROOT lease required.
Derived from pinned cbda custody bootstrap; no F14 cell/Domain/production acceptance.
"""
import datetime,hashlib,json,os,re,secrets,shutil,stat,time
from pathlib import Path
from capture import Capture,Refusal
import ownership,private_archive
from admin import Admin
HERE=Path(__file__).resolve().parent
SPEC=json.loads((HERE/'RUNTIME-SPEC.json').read_text())
PHP_PROJECTION='{"Id":{{json .Id}},"Name":{{json .Name}},"Image":{{json .Image}},"Created":{{json .Created}},"State":{"Running":{{json .State.Running}},"Paused":{{json .State.Paused}},"Restarting":{{json .State.Restarting}},"Dead":{{json .State.Dead}},"OOMKilled":{{json .State.OOMKilled}}},"NetworkMode":{{json .HostConfig.NetworkMode}},"Networks":{ {{range $k,$v := .NetworkSettings.Networks}}{{json $k}}:{{json $v.NetworkID}}{{end}} }}'
CGROUP=['memory.current','memory.peak','memory.max','memory.swap.current','memory.swap.max','memory.events','memory.events.local']
GRANT_SQL="GRANT ALL PRIVILEGES ON `vaseyaudio_production_identity`.* TO 'account_fixture'@'%'"
PROFILE='SELECT VERSION(),@@server_uuid,@@port,@@log_bin,@@binlog_format,@@global.log_bin_trust_function_creators,@@global.general_log,@@global.slow_query_log,@@event_scheduler,@@local_infile'
AUDIT="SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND COMMAND<>'Daemon';SELECT COUNT(*) FROM information_schema.INNODB_TRX;SELECT COUNT(*) FROM performance_schema.data_locks;SELECT COUNT(*) FROM performance_schema.data_lock_waits;SELECT COUNT(*) FROM performance_schema.metadata_locks WHERE LOCK_STATUS='PENDING'"
def need(ok):
    if not ok:raise Refusal('PROVISION_SOURCE_CONTRACT_REFUSED')

def named_php_absent(text):
    """Finite comm-prefix observation; Docker Top launches no PHP helper to subtract."""
    if type(text) is not str or not text.isascii() or len(text)>65536 or not text.endswith('\n'):
        return False
    rows=text[:-1].split('\n')
    if len(rows)<2 or rows[0].split()!=['PID','PPID','COMMAND']:
        return False
    seen=set()
    for row in rows[1:]:
        fields=row.split()
        if len(fields)!=3 or not re.fullmatch('[1-9][0-9]*',fields[0]) or not re.fullmatch('0|[1-9][0-9]*',fields[1]) or not re.fullmatch('[!-~]+',fields[2]) or fields[0] in seen:
            return False
        seen.add(fields[0])
        if fields[2].lower().startswith('php'):
            return False
    return True

class Provision(Capture):
    def __init__(self):
        os.umask(0o077);need(os.geteuid()==SPEC['controller_uid']);self.end=time.monotonic()+240;self.limit=self.end
        self.directory=Path(SPEC['ownership_directory']);self.directory.mkdir(mode=0o700,exist_ok=False)
        self.private=Path(SPEC['private_directory']);self.private.mkdir(mode=0o700,exist_ok=False)
        self.receipt={'inherited_factory_manifest_sha256':SPEC['inherited_factory_manifest_sha256'],'status':'PREPARING','commands':[],'intents':[],'stages':[],'transport_uncertain':False,'memory_audit_failed':False,'first_failure':None,'created_ids':[],'bootstrap_removed':False,'main_ready':False,'lease_returnable':False,'source_only_claim':'NEW_OWNED_PHYSICAL_FIXTURE_NOT_ACCOUNT_ACCEPTANCE'}
        self.container=None;self.anchor=None;self.process_anchor=None;self.bootstrap_id=None;self.root_password=secrets.token_hex(24)+'Aa9!';self.tcp_password=secrets.token_hex(24)+'Aa9!'
        fd=os.open(self.private/'canary',os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        with os.fdopen(fd,'w') as f:f.write('ACCOUNT_PRIVATE_CANARY');f.flush();os.fsync(f.fileno())
        self.write_private('root.cnf','[client]\nuser=root\npassword='+self.root_password+'\nprotocol=SOCKET\nsocket=/run/fixture/mysql.sock\n')
        self.persist()
    def write_private(self,name,content):
        need(name in ['root.cnf']);p=self.private/name
        fd=os.open(p,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        with os.fdopen(fd,'w') as f:f.write(content);f.flush();os.fsync(f.fileno())
    def persist(self):
        data=(json.dumps(self.receipt,indent=2)+'\n').encode();need(len(data)<=245760 and len(self.receipt['stages'])<=60 and len(self.receipt['commands'])<=256)
        p=self.directory/'provision.partial'
        with p.open('wb') as f:os.chmod(p,0o600);f.write(data);f.flush();os.fsync(f.fileno())
        os.replace(p,self.directory/'provision.json')
        fd=os.open(self.directory,os.O_RDONLY|os.O_DIRECTORY)
        try:os.fsync(fd)
        finally:os.close(fd)
    def record(self,stage,facts):
        if 'fixed_command' in facts:self.receipt['intents'].append({'stage':stage,**facts})
        elif 'owned_cli_pid' in facts:self.receipt['active_cli']={'stage':stage,**facts}
        elif 'exit' in facts:self.receipt['active_cli']=None
        else:self.receipt['stages'].append({'stage':stage,'facts':facts})
        self.persist()
    def php_guard(self):
        row=json.loads(self.command(['docker','inspect','--format',PHP_PROJECTION,SPEC['php_id']],'php_exact_identity'))
        self.record('php_profile',ownership.php(row,SPEC))
        probe=json.loads(self.command(['docker','exec','--user','1000:1000',SPEC['php_id'],SPEC['php_runtime_binary'],str(HERE/'php_host_guard.php')],'php_runtime_custody_profile'));need(set(probe)=={'runtime_profile_match','private_custody_unreadable','docker_socket_unreadable','pidns'} and all(probe[k] is True for k in ['runtime_profile_match','private_custody_unreadable','docker_socket_unreadable']) and type(probe['pidns']) is str and probe['pidns']!=os.readlink('/proc/self/ns/pid'))
        paths={SPEC['php_runtime_binary']:SPEC['php_runtime_binary_sha256'],**{'/usr/lib/php/20240924/'+k+'.so':v for k,v in SPEC['php_modules'].items()}}
        script='; '.join('sha256sum '+p for p in paths)
        rows=self.command(['docker','exec','--user','1000:1000',SPEC['php_id'],'sh','-ec',script],'php_binary_module_pins').splitlines()
        need(len(rows)==len(paths) and all(row.split('  ',1)==[digest,p] for row,(p,digest) in zip(rows,paths.items())))
        need(named_php_absent(self.command(['docker','top',SPEC['php_id'],'-eo','pid,ppid,comm'],'php_absent')))
    def preflight(self):
        from dependency_audit import audit
        result=audit();need(result['status']=='PASS_SOURCE_DEPENDENCY_PREFLIGHT');self.record('source_preflight',result)
        self.php_guard()
        capacity=self.command(['docker','info','--format','{{.NCPU}}|{{.MemTotal}}'],'host_capacity').strip().split('|');need(len(capacity)==2 and int(capacity[0])>=2 and int(capacity[1])>=3221225472)
        mem=self.command(['docker','exec',SPEC['php_id'],'cat','/proc/meminfo'],'host_memavailable');m=re.search(r'^MemAvailable:\s+([0-9]+) kB$',mem,re.M);need(m and int(m[1])>=3145728)
        need(shutil.disk_usage(self.private).free>=268435456)
        daemon=json.loads(self.command(['docker','version','--format','{"Version":{{json .Server.Version}},"ApiVersion":{{json .Server.APIVersion}},"GitCommit":{{json .Server.GitCommit}},"Os":{{json .Server.Os}},"Arch":{{json .Server.Arch}}}'],'docker_phase_floor'))
        need(daemon=={'Version':'28.4.0','ApiVersion':'1.51','GitCommit':'249d679','Os':'linux','Arch':'amd64'})
        cg=json.loads(self.command(['docker','info','--format','{"CgroupVersion":{{json .CgroupVersion}},"CgroupDriver":{{json .CgroupDriver}},"OomKillDisable":{{json .OomKillDisable}}}'],'cgroup_floor'));need(cg=={'CgroupVersion':'2','CgroupDriver':'cgroupfs','OomKillDisable':False})
        image=json.loads(self.command(['docker','image','inspect','--format','{"Id":{{json .Id}},"RepoDigests":{{json .RepoDigests}}}',SPEC['image_id']],'image_pin'));need(image['Id']==SPEC['image_id'] and SPEC['repo_digest'] in image['RepoDigests'])
        need(self.command(['docker','ps','-a','--filter','name=^/'+SPEC['container_name']+'$','--format','{{.ID}}'],'fresh_container_name')=='')
        need(self.command(['docker','volume','ls','--filter','name=^'+SPEC['volume_name']+'$','--format','{{.Name}}'],'fresh_volume_name')=='')
        self.record('preflight_complete',{'new_objects_absent':True,'capacity_minima':True,'no_existing_network_mutation':True})
    def inspect(self):
        need(self.container is not None)
        rows=json.loads(self.command(['docker','inspect',self.container],'owned_new_inspect'));need(type(rows) is list and len(rows)==1 and rows[0]['Id']==self.container)
        return rows[0]
    def memory(self,stage):
        try:
            got={}
            for name in CGROUP:
                raw=self.command(['docker','exec',self.container,'cat','/sys/fs/cgroup/'+name],stage+'_'+name)
                if name in ['memory.events','memory.events.local']:
                    rows=[line.split(' ') for line in raw.splitlines()];need(rows and all(len(r)==2 and r[0] in ['low','high','max','oom','oom_kill','oom_group_kill','sock_throttled'] and re.fullmatch('0|[1-9][0-9]*',r[1]) for r in rows) and len({r[0] for r in rows})==len(rows));got[name]={k:int(v) for k,v in rows};need(all(k in got[name] and got[name][k]==0 for k in ['oom','oom_kill','oom_group_kill']))
                else:need(re.fullmatch('(0|[1-9][0-9]*)\n',raw));got[name]=int(raw)
            need(got['memory.max']==2147483648 and got['memory.swap.max']==0);self.record(stage,got)
        except BaseException:self.receipt['memory_audit_failed']=True;raise
    def deliver(self):
        payload=(self.private/'root.cnf').read_bytes();metadata=(self.private/'root.cnf').lstat();need(stat.S_ISREG(metadata.st_mode) and metadata.st_uid==SPEC['controller_uid'] and stat.S_IMODE(metadata.st_mode)==0o600 and metadata.st_nlink==1)
        archive=private_archive.build('root.cnf',payload);self.command(['docker','cp','--quiet','-',self.container+':/root'],'private_archive_delivery',input_bytes=archive,binary=True)
        back=self.command(['docker','cp','--quiet',self.container+':/root/root.cnf','-'],'private_archive_readback',binary=True);facts=private_archive.verify(back,'root.cnf',payload)
        del payload,archive,back;return facts
    def start(self,phase):
        need(phase in ['bootstrap','main'] and (self.bootstrap_id is None if phase=='bootstrap' else self.receipt['bootstrap_removed']))
        if phase=='bootstrap':
            need(self.command(['docker','volume','create','--label',SPEC['owner_label']+'='+SPEC['owner_value'],SPEC['volume_name']],'new_volume_create').strip()==SPEC['volume_name'])
            rows=json.loads(self.command(['docker','volume','inspect',SPEC['volume_name']],'new_volume_inspect'));need(len(rows)==1 and rows[0]['Name']==SPEC['volume_name'] and rows[0]['Labels'].get(SPEC['owner_label'])==SPEC['owner_value'] and rows[0]['Driver']=='local' and not rows[0].get('Options'))
        common="umask 077; printf 'ACCOUNT_PHASE "+phase+"\\n'; mkdir -p /run/fixture; chown mysql:mysql /run/fixture /var/lib/mysql; available_kib=$(df -Pk /var/lib/mysql | awk 'NR==2 {print $4}'); case \"$available_kib\" in \"\"|*[!0-9]*) exit 1;; esac; printf 'ACCOUNT_AVAILABLE_KIB %s\\n' \"$available_kib\"; test \"$available_kib\" -ge 3145728; "
        if phase=='bootstrap':common+="if mysqld --no-defaults --initialize-insecure --user=mysql --datadir=/var/lib/mysql >/run/fixture/init-private.log 2>&1; then printf 'ACCOUNT_INIT_EXIT 0\\n'; else exit 1; fi; "
        log='/var/lib/mysql/'+phase+'-private.log'
        flags=['--no-defaults','--user=mysql','--datadir=/var/lib/mysql','--socket=/run/fixture/mysql.sock','--pid-file=/run/fixture/mysql.pid','--log-error='+log,'--innodb-buffer-pool-size=64M','--mysqlx=OFF','--skip-name-resolve','--performance-schema=ON','--general-log=OFF','--slow-query-log=OFF','--event-scheduler=OFF','--skip-replica-start','--local-infile=ON','--performance-schema-instrument=statement/%=ON','--performance-schema-instrument=wait/lock/metadata/sql/mdl=ON','--performance-schema-consumer-events-statements-current=ON','--performance-schema-consumer-events-statements-history-long=ON']
        flags+=['--skip-networking','--skip-log-bin'] if phase=='bootstrap' else ['--bind-address=0.0.0.0','--port=3306','--log-bin=/var/lib/mysql/binlog','--binlog-format=ROW','--log-bin-trust-function-creators=ON']
        command=common+'exec mysqld '+' '.join(flags)
        args=['docker','create','--init=false','--name',SPEC['container_name'],'--label',SPEC['owner_label']+'='+SPEC['owner_value'],'--network','none' if phase=='bootstrap' else 'bridge','--memory','2g','--memory-swap','2g','--cpus','1','--pids-limit','256','--log-driver','local','--log-opt','max-size=16k','--log-opt','max-file=1','--log-opt','compress=false','--mount','type=volume,src='+SPEC['volume_name']+',dst=/var/lib/mysql']
        if phase=='main':args+=['--publish','127.0.0.1:'+str(SPEC['host_port'])+':3306']
        args+=['--entrypoint','sh',SPEC['image_id'],'-ec',command]
        self.receipt['creation_intent']=phase;self.persist();created=self.command(args,phase+'_create').strip();need(re.fullmatch('[0-9a-f]{64}',created));self.container=created;self.receipt['created_ids'].append(created)
        row=self.inspect();need(row['Config']['Cmd']==['-ec',command]);self.anchor=ownership.created(row,SPEC,phase,self.bootstrap_id)
        facts=self.deliver();self.command(['docker','start',self.container],phase+'_start');row=self.inspect();self.process_anchor=ownership.running(self.anchor,row,SPEC,phase)
        deadline=min(self.end,time.monotonic()+55)
        need(self.command(['docker','exec','--user','0:0',self.container,'stat','-c','%u|%a|%g','/root/root.cnf'],'private_archive_mode',seconds=min(12,deadline-time.monotonic()))=='0|600|0\n');self.record('private_archive_verified',facts)
        ready=False
        for attempt in range(16):
            need(time.monotonic()<deadline)
            self.command(['docker','exec',self.container,'sh','-ec','test -S /run/fixture/mysql.sock && grep -q "ready for connections" '+log],phase+'_readiness_'+str(attempt),seconds=min(3,deadline-time.monotonic()),accepted=(0,1))
            if self.receipt['commands'][-1]['exit']==0:ready=True;break
            time.sleep(min(3,max(0,deadline-time.monotonic())))
        need(ready)
        text=self.command(['docker','logs',self.container],phase+'_closed_startup_stages');lines=text.splitlines();need(len(lines)==(3 if phase=='bootstrap' else 2) and lines[0]=='ACCOUNT_PHASE '+phase and re.fullmatch('ACCOUNT_AVAILABLE_KIB [0-9]+',lines[1]) and int(lines[1].split()[1])>=3145728 and (phase!='bootstrap' or lines[2]=='ACCOUNT_INIT_EXIT 0'))
        self.memory(phase+'_before');self.record(phase+'_ready',{'created_running_identity':True,'actual_datadir_free_minimum_3GiB':True,'closed_startup_markers':True})
    def query(self,sql,expected,initial=False,private=False):
        need(self.container is not None)
        if re.search(r'\bGRANT\b',sql,re.I):need(sql==GRANT_SQL)
        client=Admin(self,initial)
        try:
            need(client.query('SET SESSION sql_log_bin=0')==[])
            need(client.query("UPDATE performance_schema.threads SET INSTRUMENTED='NO',HISTORY='NO' WHERE PROCESSLIST_ID=CONNECTION_ID() ")==[])
            need(client.query("SELECT INSTRUMENTED,HISTORY FROM performance_schema.threads WHERE PROCESSLIST_ID=CONNECTION_ID()")==['NO\tNO'])
            need(client.query('SELECT @@SESSION.sql_log_bin')==['0'])
            self.record('private_admin_suppression_verified',{'binlog_zero':True,'pfs_history_no':True,'before_secret_dispatch':True})
            need(client.query(sql)==expected)
        except Exception:
            client.failed=True;raise
        finally:
            sql=None;client.close()
    def bootstrap(self):
        self.start('bootstrap')
        self.query("ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '"+self.root_password+"'",[],initial=True,private=True)
        self.query("CREATE USER 'account_fixture'@'%' IDENTIFIED WITH caching_sha2_password BY '"+self.tcp_password+"' PASSWORD HISTORY 0 PASSWORD REUSE INTERVAL 0 DAY",[],private=True)
        self.query('CREATE DATABASE vaseyaudio_production_identity CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',[])
        self.query(GRANT_SQL,[])
        self.memory('bootstrap_after');row=self.inspect();ownership.running(self.anchor,row,SPEC,'bootstrap');self.bootstrap_id=self.container
        self.record('bootstrap_stop_intent',{'term_only':True,'continuation':False})
        self.command(['docker','kill','--signal=TERM',self.container],'bootstrap_term')
        need(self.command(['docker','wait',self.container],'bootstrap_actual_wait')=='0\n');ownership.stopped(self.anchor,self.inspect(),self.process_anchor)
        self.command(['docker','rm',self.container],'bootstrap_clean_remove')
        need(self.command(['docker','ps','-a','--filter','id='+self.container,'--format','{{.ID}}'],'bootstrap_removed_absence')=='')
        self.receipt['bootstrap_removed']=True;self.record('bootstrap_replacement_floor',{'actual_exit_zero':True,'removed_observed':True,'no_reuse_bootstrap_id':True})
        self.start('main');row=self.inspect();network=row['NetworkSettings']['Networks']['bridge'];ip=network['IPAddress'];need(re.fullmatch(r'[0-9]+(?:\.[0-9]+){3}',ip))
        args=['docker','exec','--user','0:0',self.container,'mysql','--defaults-file=/root/root.cnf','--batch','--raw','--skip-column-names','-e',PROFILE]
        fields=self.command(args,'main_authenticated_profile').strip().split('\t');need(len(fields)==10 and fields[0]=='8.4.11' and re.fullmatch('[0-9a-f-]{36}',fields[1]) and fields[1]!='48da0917-d649-11f1-99f5-f2985e3ab22b' and fields[2:]==['3306','1','ROW','1','0','0','OFF','1'])
        audits=self.command(['docker','exec','--user','0:0',self.container,'mysql','--defaults-file=/root/root.cnf','--batch','--raw','--skip-column-names','-e',AUDIT],'main_peer_resource_zero').splitlines();need(audits==['0']*5)
        self.memory('main_after');self.php_guard()
        secret=self.directory/'php-password';fd=os.open(secret,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        with os.fdopen(fd,'w') as f:f.write(self.tcp_password);f.flush();os.fsync(f.fileno())
        self.source_preflight();row=self.inspect();ownership.running(self.anchor,row,SPEC,'main')
        self.receipt['candidate_runtime']={'container_id':self.container,'bootstrap_id':self.bootstrap_id,'volume':SPEC['volume_name'],'server_uuid':fields[1],'ip':ip,'port':3306,'host_port':SPEC['host_port'],'main_identity':{k:row[k] for k in ['Id','Name','Image','Created']},'main_process_anchor':self.process_anchor}
        st=secret.lstat();self.receipt['php_secret_metadata']={'dev':st.st_dev,'ino':st.st_ino,'uid':st.st_uid,'mode':stat.S_IMODE(st.st_mode),'nlink':st.st_nlink};self.receipt['main_ready']=True;self.receipt['status']='OWNED_MAIN_READY_PENDING_ROOT_PROFILE_FREEZE_NOT_ACCOUNT_ACCEPTANCE';self.record('candidate_only',{'native_admission':False,'production_acceptance':False,'shared_bridge_not_isolated':True})
    def run(self):
        try:self.preflight();self.bootstrap()
        except BaseException as exc:
            self.receipt['status']='STOP_RETAIN_OWNED_STATE';self.receipt['first_failure']=type(exc).__name__;self.persist()
        finally:
            self.root_password=None;self.tcp_password=None
            try:self.persist()
            except BaseException:pass
        return self.receipt['status']=='OWNED_MAIN_READY_PENDING_ROOT_PROFILE_FREEZE_NOT_ACCOUNT_ACCEPTANCE' and self.receipt['first_failure'] is None and self.receipt['main_ready'] and not self.receipt['transport_uncertain'] and not self.receipt['memory_audit_failed']
if __name__=='__main__':
    try:
        from dependency_audit import audit
        need(audit()['status']=='PASS_SOURCE_DEPENDENCY_PREFLIGHT');p=Provision();ok=p.run();print(json.dumps({'provision_candidate_only':ok,'native_admitted':False,'production_acceptance':False}));raise SystemExit(0 if ok else 2)
    except Exception:print('{"provision_candidate_only":false,"native_admitted":false,"production_acceptance":false}');raise SystemExit(2)
