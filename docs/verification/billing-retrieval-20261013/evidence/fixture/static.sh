#!/usr/bin/env bash
set -u
cd /workspace/VA-Studio-billing-retrieval-fixtures
source /workspace/.va-studio-toolchain/activate.sh
receipt=/workspace/runtime/billing-retrieval-fixture-evidence
files=(tests/Support/BillingIntervalFixtures.php tests/Support/MembershipAwardFixtures.php tests/Support/MembershipReservationFixtures.php tests/Feature/ProductionMembershipBilling/BillingWebhookRedeliveryTest.php)
git rev-parse HEAD > "$receipt/static.base"
sha256sum "${files[@]}" > "$receipt/static.before.sha256"
git diff -- "${files[@]}" > "$receipt/static.patch"
: > "$receipt/static.syntax.log"
syntax_exit=0
for file in "${files[@]}"; do
    php -l "$file" >> "$receipt/static.syntax.log" 2>&1 || syntax_exit=$?
done
printf '%s\n' "$syntax_exit" > "$receipt/static.syntax.exit"
vendor/bin/pint --test "${files[@]}" > "$receipt/static.pint.log" 2>&1
printf '%s\n' "$?" > "$receipt/static.pint.exit"
git diff --check > "$receipt/static.diff-check.log" 2>&1
printf '%s\n' "$?" > "$receipt/static.diff-check.exit"
sha256sum "${files[@]}" > "$receipt/static.after.sha256"
cmp "$receipt/static.before.sha256" "$receipt/static.after.sha256"
printf 'syntax=%s pint=%s diff=%s\n' "$syntax_exit" "$(cat "$receipt/static.pint.exit")" "$(cat "$receipt/static.diff-check.exit")"
