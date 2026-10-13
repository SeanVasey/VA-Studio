#!/usr/bin/env bash
set -o pipefail
cd /workspace/VA-Studio-membership-ledger || exit 1
source /workspace/.va-studio-toolchain/activate.sh
export TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Unit/MembershipLedgerAuditTest.php tests/Feature/ProductionMembershipBilling/MembershipFirstAwardTest.php --log-junit /workspace/runtime/membership-ledger-evidence/ledger-focused.xml 2>&1 | tee /workspace/runtime/membership-ledger-evidence/ledger-focused.txt
result=${PIPESTATUS[0]}
printf '%s\n' "$result" > /workspace/runtime/membership-ledger-evidence/ledger-focused.exit
python3 - <<'PY' > /workspace/runtime/membership-ledger-evidence/ledger-focused-source-check.json
from pathlib import Path
import hashlib,json
p=Path('/workspace/runtime/membership-ledger-evidence')
before=json.loads((p/'ledger-focused.sources.json').read_text())
changed=[f for f,digest in before.items() if hashlib.sha256(Path(f).read_bytes()).hexdigest()!=digest]
print(json.dumps({'files':len(before),'changed':changed},indent=2))
raise SystemExit(bool(changed))
PY
exit "$result"
