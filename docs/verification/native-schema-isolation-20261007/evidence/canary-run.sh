#!/usr/bin/env bash
# Usage: canary-run.sh <label>. Creates the canary's selected and foreign schemas, runs the frozen canary, always drops both.
set -u
label=$1
S=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad
W=/home/user/VA-Studio-trigscan-canary
SRC=$W/docs/verification/membership-257-258-composition-20261007/MembershipForeignSchemaCanaryTest.php
SEL=vaseyaudio_service_review
FOR=vaseyaudio_trigscan_canary_foreign
LOG=$S/canary-$label-lifecycle.txt
M() { mysql -h127.0.0.1 -uroot -pci-only-password -N "$@" 2>/dev/null; }
exec > >(tee $LOG) 2>&1
echo "$(date -u +%FT%TZ) $label source=$(git -C $W rev-parse HEAD) staged-fix-files=$(git -C $W diff --cached --name-only | tr '\n' ' ')"
pre=$(M -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ('$SEL','$FOR')")
[ "$pre" = 0 ] || { echo "refusing: canary schemas already exist ($pre)"; exit 3; }
cleanup() {
  rm -f $W/tests/Feature/MembershipForeignSchemaCanaryTest.php
  M -e "DROP DATABASE IF EXISTS \`$SEL\`; DROP DATABASE IF EXISTS \`$FOR\`"
  echo "$(date -u +%FT%TZ) cleanup: dropped $SEL then $FOR"
  echo "post-cleanup schemata matching: $(M -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ('$SEL','$FOR')")"
  echo "post-cleanup cross-schema FKs referencing foreign schema: $(M -e "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA='$FOR'")"
  echo "post-cleanup mysql.db grants for either schema: $(M -e "SELECT COUNT(*) FROM mysql.db WHERE Db IN ('$SEL','$FOR')")"
}
trap cleanup EXIT
M -e "CREATE DATABASE \`$SEL\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE \`$FOR\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE TABLE \`$FOR\`.users (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB"
echo "$(date -u +%FT%TZ) allocated $SEL and $FOR (root; no user or GRANT created)"
echo "server version $(M -e 'SELECT VERSION()'); other schemas present: $(M -e "SELECT GROUP_CONCAT(SCHEMA_NAME) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'vaseyaudio%'")"
cp $SRC $W/tests/Feature/MembershipForeignSchemaCanaryTest.php
echo "canary sha256 before: $(sha256sum < $W/tests/Feature/MembershipForeignSchemaCanaryTest.php | cut -d' ' -f1)"
cd $W
( . $S/native.env; export DB_DATABASE=$SEL MEMBERSHIP_FOREIGN_SCHEMA=$FOR
  timeout 2400 php $S/phpunit-own-autoload.php tests/Feature/MembershipForeignSchemaCanaryTest.php --log-junit $S/canary-$label.xml > $S/canary-$label.txt 2>&1; echo "phpunit exit=$?" )
echo "canary sha256 after: $(sha256sum < $W/tests/Feature/MembershipForeignSchemaCanaryTest.php | cut -d' ' -f1)"
sed 's/\x1b\[[0-9;]*m//g' $S/canary-$label.txt | grep -E '^(OK \(|Tests:)|Exception' | head -5
[ -f $W/tests/Feature/foreign-schema-snapshot.json ] && mv $W/tests/Feature/foreign-schema-snapshot.json $S/canary-$label-foreign-schema-snapshot.json && echo "snapshot saved"
