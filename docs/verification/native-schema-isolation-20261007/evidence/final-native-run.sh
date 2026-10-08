#!/usr/bin/env bash
# Affected native selection at the branch head c7f9987b.
# Daemon actually used: the SHARED MySQL 8.4.11 daemon on 127.0.0.1:3306, schema
# vaseyaudio_trigscan (native.env exported APP_ENV=testing DB_CONNECTION=mysql
# DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=vaseyaudio_trigscan DB_USERNAME=root).
# No private daemon was used for this run. native.env itself is scratchpad-only and is
# not part of the evidence.
S=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad
. $S/native.env
cd /home/user/VA-Studio-trigscan
out=$S/final-native-summary.txt
echo "tree $(git rev-parse HEAD) dirty=$(git status --short | wc -l) start=$(date -u +%FT%TZ)" > $out
run() { n=$1; shift; s=$(date +%s); timeout 5000 php $S/phpunit-own-autoload.php "$@" --log-junit $S/final-native-$n.xml > $S/final-native-$n.txt 2>&1; echo "$n exit=$? $(( $(date +%s) - s ))s $(sed 's/\x1b\[[0-9;]*m//g' $S/final-native-$n.txt | grep -E '^(OK \(|Tests:)' | tail -1)" >> $out; }
run NativeSchemaIsolationTest tests/Feature/NativeSchemaIsolationTest.php
run IdentityInspectionCostTest tests/Feature/ProductionIdentity/IdentityInspectionCostTest.php
run ProductionIdentityMigrationTest tests/Feature/ProductionIdentity/ProductionIdentityMigrationTest.php
run ProductionIdentityDependencyAdmissionTest tests/Feature/ProductionIdentity/ProductionIdentityDependencyAdmissionTest.php
run ProductionIdentityRuntimeTest tests/Feature/ProductionIdentity/ProductionIdentityRuntimeTest.php
run ProductionIdentityCommittedFrameTest tests/Feature/ProductionIdentityAdapters/ProductionIdentityCommittedFrameTest.php
run IdentityHistoricalCommittedReceiptTest tests/Feature/ProductionIdentityAdapters/IdentityHistoricalCommittedReceiptTest.php
run ProductionBuyerAssentObservationMigrationTest tests/Feature/ProductionBuyerAssentObservationMigrationTest.php
run ProductionFeatureNativeAdmissionTest tests/Feature/ProductionFeatures/ProductionFeatureNativeAdmissionTest.php
run InquiryNotificationMigrationTest-selected tests/Feature/InquiryNotificationMigrationTest.php --filter 'test_external_dependencies_are_refused_before_adoption_or_empty_rollback|test_foreign_schema_or_guard_residue_is_refused_before_any_ddl|test_every_committed_installation_prefix_can_resume_and_repeated_up_preserves_all_guards|test_nonempty_notification_table_with_a_missing_mysql_foreign_key_is_never_retroactively_adopted'
echo "done $(date -u +%FT%TZ)" >> $out
