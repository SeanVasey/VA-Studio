"""Summarizes PHPUnit JUnit files and checks every skipped case against the reviewed SQLite skip census."""
import json, sys, xml.etree.ElementTree as ET
census = {tuple(m) for m in json.load(open('scripts/ci/database-sqlite-skips.json'))['methods']}
out = {}
for path in sys.argv[1:]:
    root = ET.parse(path).getroot()
    files, tests, asserts, fails, errs, skips, outside, bad = set(), 0, 0, 0, 0, 0, [], []
    for tc in root.iter('testcase'):
        tests += 1; asserts += int(tc.get('assertions') or 0); files.add(tc.get('file'))
        method = tc.get('name').split(' ')[0]
        if tc.find('failure') is not None: fails += 1; bad.append('FAIL ' + tc.get('class') + '::' + tc.get('name'))
        if tc.find('error') is not None: errs += 1; bad.append('ERROR ' + tc.get('class') + '::' + tc.get('name'))
        if tc.find('skipped') is not None:
            skips += 1
            if (tc.get('class'), method) not in census: outside.append(tc.get('class') + '::' + method)
    name = path.rsplit('/', 1)[-1]
    out[name] = dict(files=len(files), tests=tests, assertions=asserts, failures=fails, errors=errs, skipped=skips,
                     skipped_outside_census=sorted(set(outside)), problems=bad)
tot = {k: sum(v[k] for v in out.values()) for k in ('files', 'tests', 'assertions', 'failures', 'errors', 'skipped')}
out['total'] = tot
print(json.dumps(out, indent=1))
