#!/usr/bin/env bash
# Preserve each native mutation receipt (the shared runner label is reused per mutation).
A=/home/user/VA-Studio-review-tax255b/docs/verification/tax-255-20261007/independent-review/review-evidence/addendum1
L=m-ProductionTaxCheckoutNativeInstallerTest-test_native_installer_refuses_before_ddl
seen=$(grep -c "\[native\]" $A/mutations/mutation-ledger.txt)
while true; do
  n=$(grep -c "\[native\]" $A/mutations/mutation-ledger.txt)
  if [ "$n" -gt "$seen" ]; then
    id=$(grep "\[native\]" $A/mutations/mutation-ledger.txt | tail -1 | awk '{print $2}')
    for ext in txt junit.xml exit; do cp $A/native/$L.$ext $A/mutations/$id.native.$ext 2>/dev/null; done
    echo "$(date -u +%H:%M:%S) copied $id" >> $A/mutations/receipt-copy.log
    seen=$n
  fi
  grep -q "A-N10" $A/mutations/mutation-ledger.txt && break
  sleep 0.2
done
