#!/usr/bin/env bash
set -o pipefail
cd /workspace/VA-Studio-membership-award
source /workspace/.va-studio-toolchain/activate.sh
export TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_URL= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33413 DB_USERNAME=root DB_PASSWORD= DB_DATABASE=vaseyaudio_membership_award_profile DB_SOCKET=/workspace/runtime/mysql-policy-run/mysql.sock
git rev-parse HEAD > /workspace/runtime/membership-award-evidence/native-races-final.head
python3 - <<'PYHASH'
from pathlib import Path
import hashlib,json
files=sorted(p for folder in ['app','tests','database/migrations'] for p in Path(folder).rglob('*.php'))
Path('/workspace/runtime/membership-award-evidence/native-races-final.sources.json').write_text(json.dumps({str(p):hashlib.sha256(p.read_bytes()).hexdigest() for p in files},indent=2)+'\n')
PYHASH
php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionMembershipBilling/MembershipNativeAwardRaceTest.php --stop-on-error --stop-on-failure --log-junit /workspace/runtime/membership-award-evidence/native-races-final.xml 2>&1 | tee /workspace/runtime/membership-award-evidence/native-races-final.txt
result=${PIPESTATUS[0]}
printf '%s\n' "$result" > /workspace/runtime/membership-award-evidence/native-races-final.exit
python3 - <<'PYHASH'
from pathlib import Path
import hashlib,json
hashes=json.loads(Path('/workspace/runtime/membership-award-evidence/native-races-final.sources.json').read_text())
changed=[p for p,h in hashes.items() if hashlib.sha256(Path(p).read_bytes()).hexdigest()!=h]
Path('/workspace/runtime/membership-award-evidence/native-races-final.source-check.json').write_text(json.dumps({'files':len(hashes),'changed':changed},indent=2)+'\n')
assert not changed,changed
PYHASH
exit "$result"
