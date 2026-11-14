#!/usr/bin/env bash
# Run only after root explicitly releases the native schema.
set -o pipefail
cd /workspace/VA-Studio-membership-award || exit 1
source /workspace/.va-studio-toolchain/activate.sh
export TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_URL= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33413 DB_USERNAME=root DB_PASSWORD= DB_DATABASE=vaseyaudio_membership_award DB_SOCKET=/workspace/runtime/mysql-policy-run/mysql.sock
python3 - <<'PY' || exit 1
from pathlib import Path
import hashlib,json,subprocess
root=Path.cwd(); out=Path('/workspace/runtime/membership-award-evidence')
base='bf498055a5917172f930c877ffcff5f220dc4d4d'
for relative in ['app/Domain/Memberships/Production/MembershipSchema.php','app/Domain/Memberships/Production/MembershipSchemaCatalog.php','app/Domain/Memberships/Billing/BillingSchema.php']:
    assert (root/relative).read_bytes()==subprocess.check_output(['git','show',f'{base}:{relative}']), f'Changed metadata source: {relative}'
files=sorted({p for folder in ['app','bootstrap','config','database','tests','resources/contracts'] for p in (root/folder).rglob('*') if p.is_file() and 'cache' not in p.relative_to(root).parts}|{root/p for p in ['composer.json','composer.lock','phpunit.xml']})
(out/'native-schema-final.sources.json').write_text(json.dumps({str(p.relative_to(root)):hashlib.sha256(p.read_bytes()).hexdigest() for p in files},indent=2)+'\n')
(out/'native-schema-final.base').write_text(subprocess.check_output(['git','rev-parse','HEAD'],text=True))
(out/'native-schema-final.patch').write_bytes(subprocess.check_output(['git','diff','HEAD','--','app','bootstrap','config','database','tests','resources/contracts','composer.json','composer.lock','phpunit.xml']))
PY
php -r '$p=new PDO("mysql:unix_socket=".getenv("DB_SOCKET").";dbname=".getenv("DB_DATABASE"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); echo json_encode(["mysql_version"=>$p->query("SELECT VERSION()")->fetchColumn(),"database"=>$p->query("SELECT DATABASE()")->fetchColumn(),"php_version"=>PHP_VERSION],JSON_PRETTY_PRINT),PHP_EOL;' > /workspace/runtime/membership-award-evidence/native-schema-final.metadata.json || exit 1
php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionMembershipBilling/BillingAwardFenceDefinitionTest.php tests/Feature/ProductionMembership/MembershipSchemaPreparationTest.php tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php tests/Feature/ProductionMembershipBilling/BillingAwardFenceTest.php tests/Feature/ProductionMembershipBilling/BillingIntervalOwnershipTest.php --filter '::(test_old_trigger_snapshot_cannot_admit_a_removed_current_hint_guard|test_old_catalog_snapshot_cannot_admit_a_fence_moved_to_myisam_before_its_open|test_native_foreign_check_reserves_actual_global_symbol_before_any_owned_ddl|test_native_foreign_table_local_unique_does_not_pollute_owned_fk_dictionary|test_native_changed_enum_check_is_not_normalized_into_owned_provenance|test_raw_reader_refuses_shadow_floor_and_preserves_owned_rows|test_reserved_guard_name_collision_refuses_and_retains_foreign_marker|test_contiguous_empty_tail_restarts_but_a_data_bearing_unguarded_prefix_is_not_adopted|test_each_missing_guard_and_empty_owned_seed_recover_without_changing_evidence|test_exact_legacy_upgrade_and_each_interrupted_guard_state_preserve_every_row)$' --stop-on-error --stop-on-failure --stop-on-risky --log-junit /workspace/runtime/membership-award-evidence/native-schema-final.xml 2>&1 | tee /workspace/runtime/membership-award-evidence/native-schema-final.txt
result=${PIPESTATUS[0]}
printf '%s\n' "$result" > /workspace/runtime/membership-award-evidence/native-schema-final.exit
python3 - <<'PY' > /workspace/runtime/membership-award-evidence/native-schema-final-source-check.txt
from pathlib import Path
import hashlib,json
root=Path.cwd(); out=Path('/workspace/runtime/membership-award-evidence')
before=json.loads((out/'native-schema-final.sources.json').read_text())
changed=[name for name,digest in before.items() if not (root/name).is_file() or hashlib.sha256((root/name).read_bytes()).hexdigest()!=digest]
print(json.dumps({'files':len(before),'changed':changed},indent=2))
raise SystemExit(bool(changed))
PY
source_result=$?
if [ "$source_result" -ne 0 ]; then
    exit "$source_result"
fi
exit "$result"
