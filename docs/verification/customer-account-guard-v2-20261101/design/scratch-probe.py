"""One synthetic A/B; never writes to the source database."""
import subprocess, json, time, re, pathlib, hashlib
OUT=pathlib.Path('/workspace/account-lock-evidence')
DB='account_admission_ab_20261101_sol61'
SOURCE='vaseyaudio_production_identity'
BASE=['docker','exec','-i','va-member-mysql-20261101','mysql','-uroot','--batch','--raw','-N']
def run(sql, db=None, check=True):
    p=subprocess.run(BASE+([db] if db else []),input=sql,text=True,capture_output=True)
    if check and p.returncode: raise RuntimeError(p.stderr)
    return {'exit':p.returncode,'stdout':p.stdout,'stderr':p.stderr}
def start(sql):
    p=subprocess.Popen(BASE+[DB],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    p.stdin.write(sql);p.stdin.close();p.stdin=None
    return p
report={'base':'29ce932b7279953a011dd18041ddd4c1df66f306','version':run('SELECT VERSION(),@@transaction_isolation,@@SESSION.sql_mode;'),'source':SOURCE,'scratch':DB}
# Read source DDL/body; only scratch DDL is executed.
ddl=run('SHOW CREATE TABLE customer_accounts',SOURCE)['stdout'].split('\t',1)[1].strip()
body=run("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='customer_accounts_insert'",SOURCE)['stdout'].strip()
report['source_ddl']=ddl;report['source_trigger']=body
lines=[line for line in ddl.splitlines() if 'FOREIGN KEY' not in line]
clone='\n'.join(lines);clone=re.sub(r',\n\)', '\n)',clone);clone=re.sub(r' AUTO_INCREMENT=\d+','',clone)
original='EXISTS (SELECT 1 FROM customer_accounts WHERE id=NEW.id OR public_id=NEW.public_id OR user_id=NEW.user_id OR owner_key=NEW.owner_key)'
indexes={'id':'PRIMARY','public_id':'customer_accounts_public_id_unique','user_id':'customer_accounts_user_id_unique','owner_key':'customer_accounts_owner_key_unique'}
split='('+' OR '.join(f'EXISTS (SELECT 1 FROM customer_accounts FORCE INDEX (`{idx}`) WHERE {col}=NEW.{col})' for col,idx in indexes.items())+')'
assert original in body
run('CREATE DATABASE `'+DB+'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
run('CREATE TABLE users (id bigint unsigned PRIMARY KEY,is_admin tinyint NOT NULL,email_verified_at timestamp NULL) ENGINE=InnoDB;'+clone+';INSERT INTO users VALUES (1,0,NOW()),(2,0,NOW()),(3,0,NOW());',DB)
def values(i): return f"({i},'{i:08x}-0000-0000-0000-000000000000',{i},'{i:064x}',1,1,NOW(),NOW())"
insert='INSERT INTO customer_accounts (id,public_id,user_id,owner_key,active,access_version,created_at,updated_at) VALUES '
run(insert+values(1),DB)
report['arms']=[]
for label,guard in [('original',body),('split_indexed',body.replace(original,split))]:
    if label!='original':run('DROP TRIGGER customer_accounts_insert',DB)
    run('DELIMITER $$\nCREATE TRIGGER customer_accounts_insert BEFORE INSERT ON customer_accounts FOR EACH ROW '+guard+'$$\nDELIMITER ;',DB)
    owner=start('START TRANSACTION;SELECT id FROM customer_accounts WHERE id=1 FOR UPDATE;DO SLEEP(5);ROLLBACK;')
    for _ in range(80):
        if run("SELECT COUNT(*) FROM performance_schema.data_locks WHERE OBJECT_SCHEMA='"+DB+"' AND OBJECT_NAME='customer_accounts' AND INDEX_NAME='PRIMARY' AND LOCK_DATA='1' AND LOCK_MODE='X,REC_NOT_GAP'")['stdout'].strip()=='1':break
        time.sleep(.025)
    else:raise RuntimeError('owner lock absent')
    began=time.monotonic();worker=start('SET SESSION innodb_lock_wait_timeout=3;'+insert+values(2)+';')
    arm={'name':label,'trigger':guard,'first_wait':None}
    while worker.poll() is None and time.monotonic()-began<4:
        wait=run("SELECT r.OBJECT_SCHEMA,r.OBJECT_NAME,r.INDEX_NAME,r.LOCK_TYPE,r.LOCK_MODE,r.LOCK_DATA,b.LOCK_MODE,b.LOCK_DATA,r.THREAD_ID,b.THREAD_ID FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks r ON r.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID JOIN performance_schema.data_locks b ON b.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID WHERE r.OBJECT_SCHEMA='"+DB+"'")['stdout']
        if wait:
            arm['first_wait']=wait
            arm['events']=run("SELECT THREAD_ID,EVENT_ID,NESTING_EVENT_ID,EVENT_NAME,OBJECT_TYPE,OBJECT_SCHEMA,OBJECT_NAME,SQL_TEXT FROM performance_schema.events_statements_current WHERE OBJECT_SCHEMA='"+DB+"' OR THREAD_ID IN (SELECT THREAD_ID FROM performance_schema.data_locks WHERE OBJECT_SCHEMA='"+DB+"')")
            arm['all_locks']=run("SELECT THREAD_ID,OBJECT_NAME,INDEX_NAME,LOCK_TYPE,LOCK_MODE,LOCK_STATUS,LOCK_DATA FROM performance_schema.data_locks WHERE OBJECT_SCHEMA='"+DB+"'")
            break
        time.sleep(.025)
    stdout,stderr=worker.communicate(timeout=6);arm['worker']={'exit':worker.returncode,'stdout':stdout,'stderr':stderr,'seconds':time.monotonic()-began}
    owner.communicate(timeout=7)
    # Successful control and collision/refusal checks, no foreign owner lock.
    run('DELETE FROM customer_accounts WHERE id=2',DB)
    arm['valid_insert']=run(insert+values(2),DB,False)
    arm['collisions']={}
    for col in indexes:
        candidate={'id':3,'public_id':'00000003-0000-0000-0000-000000000000','user_id':3,'owner_key':f'{3:064x}','active':1,'access_version':1,'created_at':'2026-11-01','updated_at':'2026-11-01'}
        candidate[col]={'id':1,'public_id':'00000001-0000-0000-0000-000000000000','user_id':1,'owner_key':f'{1:064x}'}[col]
        vals=','.join(str(v) if isinstance(v,int) else "'"+v+"'" for v in candidate.values())
        arm['collisions'][col]=run(insert+'('+vals+')',DB,False)
    arm['replace']=run('REPLACE'+insert[len('INSERT'):]+values(1),DB,False)
    arm['count']=run('SELECT COUNT(*) FROM customer_accounts',DB)
    run('DELETE FROM customer_accounts WHERE id=2',DB)
    report['arms'].append(arm)
    (OUT/'receipt.json').write_text(json.dumps(report,indent=2))
report['final_count']=run('SELECT COUNT(*) FROM customer_accounts',DB)
(OUT/'receipt.json').write_text(json.dumps(report,indent=2))
print(json.dumps({a['name']:{'wait':a['first_wait'],'worker':a['worker'],'valid':a['valid_insert']['exit'],'collisions':{k:v['exit'] for k,v in a['collisions'].items()},'replace':a['replace']['exit']} for a in report['arms']},indent=2))
