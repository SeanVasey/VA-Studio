import datetime,hashlib,json,pathlib,subprocess
out=pathlib.Path('/workspace/runtime/membership-catalog-currentness-evidence');receipt=out/'native-setup.json'
if receipt.exists():raise SystemExit('Existing owned setup receipt; inspect rather than recreate.')
container='va-studio-private-mysql-policy-20261013';database='vaseyaudio_member_catalog_20261013'
base=['docker','exec',container,'mysql','--socket=/run/mysql-policy/mysql.sock','-uroot','--batch','--skip-column-names']
def query(sql):return subprocess.check_output([*base,'-e',sql],text=True).strip()
ports=json.loads(subprocess.check_output(['docker','inspect','--format','{{json .HostConfig.PortBindings}}',container],text=True));mounts=json.loads(subprocess.check_output(['docker','inspect','--format','{{json .Mounts}}',container],text=True))
version,port,socket,datadir=query('SELECT VERSION(), @@port, @@socket, @@datadir').split('\t')
assert version=='8.4.11'
assert port=='3306' and socket=='/run/mysql-policy/mysql.sock' and datadir=='/var/lib/mysql/'
assert ports['3306/tcp']==[{'HostIp':'127.0.0.1','HostPort':'33413'}]
m={x['Destination']:x['Source'] for x in mounts};assert m['/var/lib/mysql']=='/workspace/runtime/mysql-policy-data';assert m['/run/mysql-policy']=='/workspace/runtime/mysql-policy-run'
assert query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '"+database+"'")=='0','Schema already exists; do not touch an unowned schema.'
query('CREATE DATABASE `'+database+'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
record={'captured_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'version':version,'container':container,'host_port':33413,'host_socket':'/workspace/runtime/mysql-policy-run/mysql.sock','host_datadir':'/workspace/runtime/mysql-policy-data','server_port':int(port),'server_socket':socket,'server_datadir':datadir,'database':database,'database_created':True,'scope':'Private synthetic MySQL only; separate owned schema, no provider/host acceptance','runner_sha256':hashlib.sha256(pathlib.Path(__file__).read_bytes()).hexdigest()}
receipt.write_text(json.dumps(record,indent=2)+'\n');print(json.dumps(record))
