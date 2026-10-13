#!/usr/bin/env bash
set -u
cd /workspace/VA-Studio-billing-retrieval-fixtures
source /workspace/.va-studio-toolchain/activate.sh
receipt=/workspace/runtime/billing-retrieval-fixture-evidence
files=(tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php tests/Feature/ProductionMembershipBilling/BillingAwardFenceTest.php)
sha256sum "${files[@]}" > "$receipt/schema-static.before.sha256"
: > "$receipt/schema-static.syntax.log"
syntax_exit=0
for file in "${files[@]}"; do
    php -l "$file" >> "$receipt/schema-static.syntax.log" 2>&1 || syntax_exit=$?
done
printf '%s\n' "$syntax_exit" > "$receipt/schema-static.syntax.exit"
vendor/bin/pint --test "${files[@]}" > "$receipt/schema-static.pint.log" 2>&1
printf '%s\n' "$?" > "$receipt/schema-static.pint.exit"
git diff --check > "$receipt/schema-static.diff-check.log" 2>&1
printf '%s\n' "$?" > "$receipt/schema-static.diff-check.exit"
sha256sum "${files[@]}" > "$receipt/schema-static.after.sha256"
cmp "$receipt/schema-static.before.sha256" "$receipt/schema-static.after.sha256"
printf 'syntax=%s pint=%s diff=%s\n' "$syntax_exit" "$(cat "$receipt/schema-static.pint.exit")" "$(cat "$receipt/schema-static.diff-check.exit")"
