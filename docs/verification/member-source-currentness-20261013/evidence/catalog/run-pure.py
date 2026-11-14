from pathlib import Path
import datetime,hashlib,json,os,subprocess,sys,time,xml.etree.ElementTree as ET
root=Path('/workspace/VA-Studio-member-catalog-currentness');out=Path('/workspace/runtime/membership-catalog-currentness-evidence');name=sys.argv[1]
paths=['app/Domain/Memberships/Production/MembershipCatalogDefinition.php','app/Domain/Memberships/Production/MembershipCatalogEvidence.php','tests/Unit/MembershipCatalogDefinitionTest.php','composer.lock','phpunit.xml']
def hashes():return {p:hashlib.sha256((root/p).read_bytes()).hexdigest() for p in paths}
def save(suffix,data):(out/(name+suffix)).write_text(json.dumps(data,indent=2,sort_keys=True)+'\n')
argv=['php','-d','memory_limit=256M','-r','$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";','--','tests/Unit/MembershipCatalogDefinitionTest.php','--log-junit',str(out/(name+'.xml'))]
before=hashes();meta={'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'status':subprocess.check_output(['git','status','--short'],cwd=root,text=True),'argv':argv,'cwd':str(root),'source':before,'runner_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),'started_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'scope':'Pure PHPUnit Framework TestCase only; no Laravel app or database fixture'};save('.source.json',meta)
start=time.monotonic()
with (out/(name+'.log')).open('wb') as f:run=subprocess.run(argv,cwd=root,stdout=f,stderr=subprocess.STDOUT)
(out/(name+'.exit')).write_text(str(run.returncode)+'\n')
after=hashes();result={'exit':run.returncode,'seconds':round(time.monotonic()-start,3),'source_unchanged':before==after,'after':after}
x=out/(name+'.xml')
if x.exists():
 t=ET.parse(x).getroot();result['junit']=t.attrib or list(t)[0].attrib
save('.result.json',result);print(json.dumps(result));print((out/(name+'.log')).read_text());sys.exit(run.returncode)
