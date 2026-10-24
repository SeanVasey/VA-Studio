#!/usr/bin/env python3
"""Totals of junit files and every skipped case checked against the reviewed census (sqlite|mysql)."""
import sys, json, glob, xml.etree.ElementTree as ET
driver, pattern = sys.argv[1], sys.argv[2]
census = {tuple(m) for m in json.load(open(f'/home/user/wt/g1/scripts/ci/database-{driver}-skips.json'))['methods']}
tot = dict(tests=0, assertions=0, failures=0, errors=0, skipped=0); skipped = []; bad = []; files = sorted(glob.glob(pattern))
for f in files:
    root = ET.parse(f).getroot()
    for case in root.iter('testcase'):
        tot['tests'] += 1; tot['assertions'] += int(case.get('assertions', 0))
        cls = case.get('class') or case.get('classname', '').replace('.', '\\'); name = case.get('name').split(' with data set ')[0]
        if case.find('failure') is not None: tot['failures'] += 1; bad.append(('failure', cls, name))
        if case.find('error') is not None: tot['errors'] += 1; bad.append(('error', cls, name))
        if case.find('skipped') is not None: tot['skipped'] += 1; skipped.append((cls, name))
outside = [s for s in skipped if s not in census]
print(f'{len(files)} junit files', tot)
print('skipped cases', len(skipped), 'outside census', len(outside), outside[:20])
print('failed/errored', bad[:50])
