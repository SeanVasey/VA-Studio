#!/usr/bin/env bash
# Runs each mutation in the separate detached mutation worktree (same SHA), then reverts and proves a clean tree.
M=/home/user/VA-Studio-review-free256-mut
E=/home/user/VA-Studio-review-free256/docs/verification/free-256-20261007/independent-review/review-evidence
W=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256-upgrade
cd "$M" || exit 9
R() { php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never "$@"; }
run() { # id, phpunit args...
  id=$1; shift
  out=$E/mutations/$id.txt
  { echo "== $id at $(git rev-parse HEAD)"; python3 $E/mutations/mutate.py "$id"; echo '$ git diff --stat'; git diff --stat; git diff; } > "$out" 2>&1
  R "$@" >> "$out" 2>&1; rc=$?
  echo "rc=$rc" >> "$out"
  git checkout -- app database resources
  git diff --quiet -- app database resources; d=$?
  echo "after revert: git diff --quiet -- app database resources rc=$d" >> "$out"
  echo "$id rc=$rc revert_clean_rc=$d"
}
run S1 --filter test_reviewer_must_differ_from_author tests/Feature/ProductionFreeGrants/ProductionFreeGrantApprovalTest.php
run S2 --filter test_review_guard_refuses_self_review tests/Feature/ProductionFreeGrants/ProductionFreeGrantSchemaTest.php
run S3 --filter test_definition_cap_is_enforced tests/Feature/ProductionFreeGrants/ProductionFreeGrantAssentTest.php
run S4 --filter test_revocation_stops_new_and_outstanding tests/Feature/ProductionFreeGrants/ProductionFreeGrantDeliveryTest.php
run S5 --filter test_a_preexisting_file_at_the_claim_path tests/Feature/ProductionFreeGrants/ProductionFreeGrantRenderingTest.php
run S6 --filter test_drifted_private_bytes tests/Feature/ProductionFreeGrants/ProductionFreeGrantDeliveryTest.php
for U in U1 U2 U3; do
  cp $W/db-prepared.sqlite $W/db.sqlite
  DB_CONNECTION=sqlite DB_DATABASE=$W/db.sqlite VA_REVIEW_STATE=$W/state.json VA_REVIEW_LABEL=$U run $U --filter test_observe $E/probes/UpgradeProbeTest.php
done
cp $W/db-prepared.sqlite $W/db.sqlite
DB_CONNECTION=sqlite DB_DATABASE=$W/db.sqlite VA_REVIEW_STATE=$W/state.json VA_REVIEW_LABEL=control-after-reverts R --filter test_observe $E/probes/UpgradeProbeTest.php > $E/mutations/control-after-reverts.txt 2>&1; echo "rc=$?" >> $E/mutations/control-after-reverts.txt
git status --short; echo "final status listed above"
