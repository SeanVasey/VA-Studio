#!/bin/bash
# Mutation runs for af232a8's ProductionFreeGrantFrozenBytesTest::test_no_paid_lane_or_old_free_family_file_is_copied_or_imported
# in the scratch worktree at 214256a. Each mutation adds one untracked file to app/Domain/Grants/ProductionFree, runs the
# test, records the verdict and removes the file again.
root=/home/user/rv-m16-red
P=/home/user/rv-m16/docs/verification/php-cli-resolver-20261009/independent-review/probes
cd "$root" || exit 1
dest=app/Domain/Grants/ProductionFree/ReviewMutation.php
run() {
    echo "== mutation: $1"
    ROOT=$root "$P/phpunit.sh" --filter test_no_paid_lane_or_old_free_family_file_is_copied_or_imported \
        tests/Feature/ProductionFreeGrants/ProductionFreeGrantFrozenBytesTest.php | grep -E '^(OK|Tests:|Failed asserting)' | cut -c1-160
    rm -f "$dest"
}
echo "== baseline (no mutation)"
ROOT=$root "$P/phpunit.sh" --filter test_no_paid_lane_or_old_free_family_file_is_copied_or_imported \
    tests/Feature/ProductionFreeGrants/ProductionFreeGrantFrozenBytesTest.php | grep -E '^(OK|Tests:)'
cp app/Domain/Grants/Paid/PaidGrantText.php "$dest"; run "byte copy of a Paid file"
cp app/Domain/Grants/Free/FreeGrantText.php "$dest"; run "byte copy of a Free file (namespace line App\\Domain\\Grants\\Free; has no trailing backslash)"
sed 's/^namespace App\\Domain\\Grants\\Free;/namespace App\\Domain\\Grants\\ProductionFree;/' app/Domain/Grants/Free/FreeGrantText.php > "$dest"; run "Free file copied with only its namespace rewritten"
sed 's/^namespace App\\Domain\\Grants\\Paid;/namespace App\\Domain\\Grants\\ProductionFree;/' app/Domain/Grants/Paid/PaidGrantText.php > "$dest"; run "Paid file copied with only its namespace rewritten"
git status --short -- app
