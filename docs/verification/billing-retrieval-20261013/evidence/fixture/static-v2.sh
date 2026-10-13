#!/usr/bin/env bash
set -u
cd /workspace/VA-Studio-billing-retrieval-fixtures
source /workspace/.va-studio-toolchain/activate.sh
receipt=/workspace/runtime/billing-retrieval-fixture-evidence
files=(tests/Support/BillingIntervalFixtures.php tests/Support/MembershipAwardFixtures.php tests/Support/MembershipReservationFixtures.php tests/Feature/ProductionMembershipBilling/BillingWebhookRedeliveryTest.php tests/Support/membership-interval-race-worker.php)
git rev-parse HEAD > "$receipt/static-v2.base"
sha256sum "${files[@]}" > "$receipt/static-v2.before.sha256"
git diff -- "${files[@]}" > "$receipt/static-v2.patch"
: > "$receipt/static-v2.syntax.log"
syntax_exit=0
for file in "${files[@]}"; do
    php -l "$file" >> "$receipt/static-v2.syntax.log" 2>&1 || syntax_exit=$?
done
printf '%s\n' "$syntax_exit" > "$receipt/static-v2.syntax.exit"
vendor/bin/pint --test "${files[@]}" > "$receipt/static-v2.pint.log" 2>&1
printf '%s\n' "$?" > "$receipt/static-v2.pint.exit"
git diff --check > "$receipt/static-v2.diff-check.log" 2>&1
printf '%s\n' "$?" > "$receipt/static-v2.diff-check.exit"
sha256sum "${files[@]}" > "$receipt/static-v2.after.sha256"
cmp "$receipt/static-v2.before.sha256" "$receipt/static-v2.after.sha256"
printf 'syntax=%s pint=%s diff=%s\n' "$syntax_exit" "$(cat "$receipt/static-v2.pint.exit")" "$(cat "$receipt/static-v2.diff-check.exit")"
