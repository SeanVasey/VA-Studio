"""Read only the independent ROOT public admission; never read credential bytes."""
import hashlib
import json
import os
from pathlib import Path
import re
import stat


def profile(case):
    path = Path('/admission/job.json')
    before = path.lstat()
    if not (os.geteuid() == os.getegid() == 1000 and stat.S_ISREG(before.st_mode)
            and before.st_uid == 0 and before.st_gid == 1000 and stat.S_IMODE(before.st_mode) == 0o444
            and before.st_nlink == 1 and before.st_size <= 16384):
        raise ValueError('Independent admission unavailable')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        opened = os.fstat(stream.fileno())
        raw = stream.read(16385)
        after = os.fstat(stream.fileno())
    fields = ('st_dev', 'st_ino', 'st_uid', 'st_gid', 'st_mode', 'st_nlink', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
    if len(raw) > 16384 or any(getattr(before, key) != getattr(opened, key)
            or getattr(opened, key) != getattr(after, key) for key in fields):
        raise ValueError('Admission moved')
    pin = os.environ.get('VA_ACCOUNT_LEDGER_ADMISSION_PIN', '')
    if not re.fullmatch('[0-9a-f]{64}', pin) or hashlib.sha256(raw).hexdigest() != pin:
        raise ValueError('Admission pin mismatch')
    def closed(pairs):
        value = {}
        for key, item in pairs:
            if key in value:
                raise ValueError('Duplicate admission field')
            value[key] = item
        return value
    value = json.loads(raw, object_pairs_hook=closed)
    expected = {'schema', 'kind', 'native_admitted', 'candidate_sha', 'case', 'source_tree_oid',
                'binding', 'service', 'runtime', 'qualification', 'dependency', 'secret_metadata'}
    if not (type(value) is dict and set(value) == expected and value['schema'] == 1
            and value['kind'] == 'required-supervised-ledger-job' and value['native_admitted'] is True
            and value['case'] == case and value['candidate_sha'] == os.environ.get('VA_ACCOUNT_LEDGER_SOURCE_SHA')
            and value['qualification'] == {'controller_profile': True, 'runtime_profile': True,
                'source_verified': True, 'same_account_observer': True, 'locked_dependencies': True}):
        raise ValueError('Unqualified admission')
    result = value['runtime']
    if not (type(result) is dict and set(result) == {'php_version', 'php_binary_sha256', 'python_version', 'python_binary_sha256'}
            and result['php_version'] == '8.4.27' and result['python_version'] == '3.13.5'
            and all(type(result[key]) is str and re.fullmatch('[0-9a-f]{64}', result[key])
                    for key in ('php_binary_sha256', 'python_binary_sha256'))):
        raise ValueError('Qualified runtime profile unavailable')
    return result
