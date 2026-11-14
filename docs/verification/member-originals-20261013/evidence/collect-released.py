"""Archive an explicit, author-released list; never crawl a runtime directory."""
from pathlib import Path
import argparse
import gzip
import hashlib
import json

HERE = Path(__file__).resolve().parent
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--new-only', action='store_true')
args = parser.parse_args()


def sha(data):
    return hashlib.sha256(data).hexdigest()


def save(name, value):
    (HERE / name).write_text(json.dumps(value, indent=2) + '\n')


PUBLIC_ENVIRONMENT = {
    'APP_BASE_PATH', 'APP_ENV', 'APP_DEBUG', 'DB_CONNECTION', 'DB_DATABASE',
    'DB_HOST', 'DB_PORT', 'DB_SOCKET', 'CACHE_STORE', 'SESSION_DRIVER',
    'QUEUE_CONNECTION', 'TMPDIR', 'MAIL_MAILER', 'BROADCAST_CONNECTION',
    'XDEBUG_MODE', 'MEMBER_SCHEMA_BASELINE',
}


def project_environment(value, omitted, path=''):
    if isinstance(value, dict):
        result = {}
        for key, child in value.items():
            child_path = path + '/' + key
            if key in ('environment', 'env') and isinstance(child, dict):
                result[key] = {k: v for k, v in child.items() if k in PUBLIC_ENVIRONMENT}
                omitted.extend(child_path + '/' + k for k in child if k not in PUBLIC_ENVIRONMENT)
            else:
                result[key] = project_environment(child, omitted, child_path)
        return result
    if isinstance(value, list):
        return [project_environment(child, omitted, path + '/' + str(i)) for i, child in enumerate(value)]
    return value


prior = {}
if args.new_only and (HERE / 'archive-index.json').exists():
    prior = {item['path']: item for item in json.loads((HERE / 'archive-index.json').read_text())['files']}
plan = json.loads((HERE / 'release-plan.json').read_text())
index = []
seen = set()
for item in plan['artifacts']:
    name = item['path']
    target = HERE / name
    assert target.resolve().is_relative_to(HERE) and name not in seen, name
    seen.add(name)
    assert item['authorization'], name
    source = Path(item['runtime_path'])
    assert source.is_absolute() and source.suffix not in ('.sqlite', '.db', '.env', '.pdf'), source
    assert source.name not in ('.env', 'connection.json', 'credentials.json'), source
    if name in prior:
        entry = prior[name]
        data = target.read_bytes()
        assert sha(data) == entry['archived_sha256'] and len(data) == entry['archived_bytes'], name
        assert item['released_sha256'] == entry['original_sha256'], name
        index.append(entry)
        continue
    data = source.read_bytes()
    assert sha(data) == item['released_sha256'] and len(data) == item['released_bytes'], source
    public = data
    omitted = []
    if source.suffix == '.json':
        projected = project_environment(json.loads(data), omitted)
        if omitted:
            public = (json.dumps(projected, indent=2) + '\n').encode()
    archived = gzip.compress(public, mtime=0) if item.get('gzip') else public
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(archived)
    index.append({
        'path': name, 'runtime_path': str(source), 'authorization': item['authorization'],
        'original_sha256': sha(data), 'original_bytes': len(data),
        'public_sha256': sha(public), 'public_bytes': len(public),
        'archived_sha256': sha(archived), 'archived_bytes': len(archived),
        'compression': 'gzip mtime=0' if item.get('gzip') else None,
        'projection': 'environment allowlist' if omitted else None,
        'omitted_json_paths': omitted,
    })
save('archive-index.json', {
    'scope': 'Only explicit public author releases. Compression is lossless after any recorded environment projection. Original/public/archive hashes distinguish transformed receipts. No private DB, environment file, credential, PDF, or customer payload is copied.',
    'files': index,
})
print(json.dumps({'archived_artifacts': len(index), 'compressed_receipts': sum(bool(x['compression']) for x in index)}))
