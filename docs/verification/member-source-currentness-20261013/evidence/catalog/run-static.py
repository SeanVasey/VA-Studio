from pathlib import Path
import hashlib,json,subprocess,time
root=Path('/workspace/VA-Studio-member-catalog-currentness');out=Path('/workspace/runtime/membership-catalog-currentness-evidence')
files=['app/Domain/Memberships/Production/MembershipCatalogDefinition.php','app/Domain/Memberships/Production/MembershipCatalogEvidence.php','tests/Unit/MembershipCatalogDefinitionTest.php','tests/Feature/ProductionMembershipBilling/MembershipNativeCatalogCurrentnessTest.php']
hashes=lambda:{p:hashlib.sha256((root/p).read_bytes()).hexdigest() for p in files}
before=hashes();runs=[]
for name,argv in [('syntax-'+str(i+1),['php','-l',p]) for i,p in enumerate(files)]+[('pint-final-v2',['vendor/bin/pint','--test',*files]),('diff-check',['git','diff','--check'])]:
 start=time.monotonic()
 with (out/(name+'.log')).open('wb') as f:r=subprocess.run(argv,cwd=root,stdout=f,stderr=subprocess.STDOUT)
 (out/(name+'.exit')).write_text(str(r.returncode)+'\n')
 runs.append({'name':name,'argv':argv,'cwd':str(root),'exit':r.returncode,'seconds':time.monotonic()-start})
 print(name,r.returncode,(out/(name+'.log')).read_text().strip())
after=hashes();result={'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'runner_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),'before':before,'after':after,'runs':runs,'unchanged':before==after}
(out/'static-final.json').write_text(json.dumps(result,indent=2,sort_keys=True)+'\n')
assert before==after
assert all(r['exit']==0 for r in runs)
