#!/usr/bin/env bash
set -o pipefail
cd /workspace/VA-Studio-membership-ledger || exit 1
source /workspace/.va-studio-toolchain/activate.sh
export APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33413 DB_DATABASE=vaseyaudio_membership_reservations DB_USERNAME=root DB_PASSWORD= DB_SOCKET=/workspace/runtime/mysql-policy-run/mysql.sock DB_URL= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
python3 - <<'PY' || exit 1
from pathlib import Path
import hashlib,json,subprocess
root=Path.cwd(); out=Path('/workspace/runtime/membership-reservation-selection-evidence')
paths=sorted({p for d in ['app','tests','scripts/ci','bootstrap','config','database'] for p in (root/d).rglob('*') if p.is_file() and 'cache' not in p.relative_to(root).parts and '__pycache__' not in p.parts}|{root/'phpunit.xml',root/'composer.lock',root/'composer.json'})
(out/'discovery.sources.json').write_text(json.dumps({str(p.relative_to(root)):hashlib.sha256(p.read_bytes()).hexdigest() for p in paths},indent=2)+'\n')
(out/'discovery.base').write_text(subprocess.check_output(['git','rev-parse','HEAD'],text=True))
PY
python3 scripts/ci/phpunit-shards.py --shards=24 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection > /workspace/runtime/membership-reservation-selection-evidence/discovery.txt 2>&1
result=$?
printf '%s\n' "$result" > /workspace/runtime/membership-reservation-selection-evidence/discovery.exit
cat /workspace/runtime/membership-reservation-selection-evidence/discovery.txt
python3 - <<'PY' > /workspace/runtime/membership-reservation-selection-evidence/discovery-source-check.json
from pathlib import Path
import hashlib,json
root=Path.cwd(); out=Path('/workspace/runtime/membership-reservation-selection-evidence')
before=json.loads((out/'discovery.sources.json').read_text())
changed=[p for p,digest in before.items() if hashlib.sha256((root/p).read_bytes()).hexdigest()!=digest]
print(json.dumps({'files':len(before),'changed':changed},indent=2))
raise SystemExit(bool(changed))
PY
source_result=$?
if [ "$source_result" -ne 0 ]; then exit "$source_result"; fi
exit "$result"
