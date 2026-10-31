import json, sys, xml.etree.ElementTree as ET
census = {tuple(x) for x in json.load(open('/home/user/wt/f9/scripts/ci/database-sqlite-skips.json'))['methods']}
for f in sys.argv[1:]:
    skipped=[]; ran_in_census=[]
    for tc in ET.parse(f).iter('testcase'):
        key=(tc.get('class'), tc.get('name').split(' ')[0])
        if tc.find('skipped') is not None: skipped.append(key)
        elif key in census: ran_in_census.append(key)
    bad=[k for k in skipped if k not in census]
    print(f.split('/')[-1], 'skipped', len(skipped), 'skipped-not-in-census', bad, 'census-methods-that-ran', ran_in_census)
