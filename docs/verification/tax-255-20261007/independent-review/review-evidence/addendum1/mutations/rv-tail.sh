#!/usr/bin/env bash
A=/home/user/VA-Studio-review-tax255b/docs/verification/tax-255-20261007/independent-review/review-evidence/addendum1
L=m-ProductionTaxCheckoutNativeInstallerTest-test_native_installer_refuses_before_ddl
cd /home/user/VA-Studio-review-tax255b-aux
R=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-tax255b-mut/run.py
python3 -I $R A-U1-pi-livemode A-U2-pi-metadata A-U3-pi-on-behalf-of A-U4-line-currency A-U5-pi-not-succeeded-while-paid
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) N1-N3 repeated: their first-run text receipts were overwritten by the shared runner label (ledger lines above stand); repeats follow with receipts preserved" >> $A/mutations/mutation-ledger.txt
for id in A-N1-drifted-guard-body A-N2-interior-gap A-N3-populated-incomplete; do
  python3 -I $R $id
  for ext in txt junit.xml exit; do cp $A/native/$L.$ext $A/mutations/$id.native.$ext; done
  echo "$(date -u +%H:%M:%S) copied $id (repeat)" >> $A/mutations/receipt-copy.log
done
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) TAIL DONE" >> $A/mutations/mutation-ledger.txt
