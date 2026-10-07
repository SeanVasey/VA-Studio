#!/usr/bin/env bash
# Apply each reviewer mutation, run the selections that should catch it, revert, and prove the revert.
W=/home/user/VA-Studio-review-trigscan
E=$W/docs/verification/native-schema-isolation-20261007/independent-review/review-evidence
M=$E/mutations
R="$E/native/run.sh"
P=$E/probes/ReviewerSchemaIsolationProbeTest.php
cd $W
export OUT=$M
step() { # <mutation> <label> <phpunit args...>
  local mut=$1 label=$2; shift 2
  OUT=$M $R "$mut--$label" "$@"
}
for mut in M1_identity_schema_only M2_capability_substring_qualifier M3_inquiry_case_sensitive_qualifier M4_identity_binary_prefilter; do
  python3 $M/mutate.py $mut | tee -a $M/summary.txt
  git diff -- app database > $M/$mut.diff
  case $mut in
    M1_*) step $mut lane-isolation tests/Feature/NativeSchemaIsolationTest.php
          step $mut probes-p1-p3 $P --filter 'test_p1_|test_p3_' ;;
    M2_*) step $mut lane-isolation tests/Feature/NativeSchemaIsolationTest.php
          step $mut probes-p4 $P --filter 'test_p4_' ;;
    M3_*) step $mut lane-isolation tests/Feature/NativeSchemaIsolationTest.php
          step $mut probes-p3 $P --filter 'test_p3_' ;;
    M4_*) step $mut probes-p7 $P --filter 'test_p7_'
          step $mut lane-unicode-alias tests/Feature/ProductionIdentity/ProductionIdentityMigrationTest.php --filter test_native_dictionary_unicode_guard_alias_on_foreign_table_is_refused
          step $mut lane-cost tests/Feature/ProductionIdentity/IdentityInspectionCostTest.php ;;
  esac
  git checkout -- app database
  git diff --quiet -- app database; echo "$(date -u +%FT%TZ) $mut reverted: git diff --quiet -- app database exit=$? ; status: $(git status --short -- app database | wc -l) changed paths" | tee -a $M/summary.txt
done
echo "$(date -u +%FT%TZ) all mutations done; sha256 after revert:" >> $M/summary.txt
sha256sum app/Domain/Commerce/ProductionPolicy/CapabilityMigrationOwnership.php app/Domain/Customers/ProductionIdentity/IdentityMigrationOwnership.php database/migrations/2026_10_07_243000_inquiry_notification_intents.php >> $M/summary.txt
