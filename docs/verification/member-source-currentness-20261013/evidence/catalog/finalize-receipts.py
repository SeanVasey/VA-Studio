from pathlib import Path
import hashlib,json,subprocess,xml.etree.ElementTree as ET
r=Path('/workspace/VA-Studio-member-catalog-currentness');e=Path('/workspace/runtime/membership-catalog-currentness-evidence')
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def save(name,data):(e/name).write_text(json.dumps(data,indent=2,sort_keys=True)+'\n')
head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=r,text=True).strip()
assert head=='982f4ea69ed8d2191345cd8c752377da19780a89'
assert not subprocess.check_output(['git','status','--short'],cwd=r,text=True)
runs=['pure-initial','pure-quote-boundary','pure-engine-boundary-red','pure-engine-boundary-green','pure-final','native-old-source-red','native-old-source-red-v2','native-fixed-five','sqlite-eligibility-whole']
summary={}
for name in runs:
    xml=e/(name+'.xml'); assert xml.is_file()
    root=ET.parse(xml).getroot();leaves=[]
    for t in root.iter('testcase'):
        leaves.append({'class':t.get('class'), 'name':t.get('name'), 'assertions':int(t.get('assertions','0')), 'failures':len(t.findall('failure')), 'errors':len(t.findall('error')), 'skips':len(t.findall('skipped'))})
    totals={k:sum(t[k] for t in leaves) for k in ['assertions','failures','errors','skips']};totals['tests']=len(leaves)
    totals['exit']=int((e/(name+'.exit')).read_text());totals['cases']=leaves
    summary[name]=totals
assert {k:v for k,v in summary['native-old-source-red-v2'].items() if k!='cases'}=={'tests':1,'assertions':12,'failures':0,'errors':1,'skips':0,'exit':2}
for name in ['pure-final','native-fixed-five','sqlite-eligibility-whole']:
    assert summary[name]['exit']==0 and all(summary[name][k]==0 for k in ['errors','failures','skips'])
    source=json.loads((e/(name+'.source.json')).read_text())
    hashes=source.get('source',source.get('sources'))
    assert isinstance(hashes,dict),(name,source.keys())
    changed=[p for p,d in hashes.items() if sha(r/p)!=d]
    assert not changed,(name,changed)
    summary[name]['current_source_hashes_verified']=len(hashes)
old=json.loads((e/'native-old-source-red-v2.source.json').read_text());fixed=json.loads((e/'native-fixed-five.source.json').read_text())
assert old['source']==fixed['source']
assert old['overlay']['sha256']==sha(Path(old['overlay']['file']))
assert fixed['overlay'] is None
assert old['runner_sha256']==fixed['runner_sha256']==sha(e/'run-native.py')
owned=['app/Domain/Memberships/Production/MembershipCatalogEvidence.php','app/Domain/Memberships/Production/MembershipCatalogDefinition.php','tests/Unit/MembershipCatalogDefinitionTest.php','tests/Feature/ProductionMembershipBilling/MembershipNativeCatalogCurrentnessTest.php']
source_map={'head':head,'owned':{p:sha(r/p) for p in owned},'native_red_and_green_all_captured_sources_equal':True,'native_red_overlay':old['overlay'],'runs':summary,'limitations':['Native counter positive is the original snapshot post-commit receipt window, not an unrelated insert while its original locking transaction remains open.','Native engine transition not executed; engine distinctions are pure corpus only.','Other eligibility revision, rights and callback variants executed on SQLite only in this batch.','No host, provider, production, HTTP activation or runtime grant readiness claim.']}
save('final-verification.json',source_map)
files=sorted(p for p in e.rglob('*') if p.is_file() and p.name!='artifact-digests.json')
assert all(p.suffix in ['.py','.json','.md','.log','.exit','.php','.xml','.lock'] for p in files)
save('artifact-digests.json',{'count':len(files),'files':{str(p.relative_to(e)):sha(p) for p in files}})
print(json.dumps({'head':head,'artifacts':len(files),'runs':{n:{k:v for k,v in row.items() if k!='cases'} for n,row in summary.items()}},indent=2))
