#!/usr/bin/env bash
# Sequential native chain, priority order.
R=/home/user/VA-Studio-review-member2/docs/verification/membership-operative-2-20261008/independent-review/review-evidence/native/run-native.sh
$R BillingNativeStaleRetrievalRaceTest tests/Feature/ProductionMembershipBilling/BillingNativeStaleRetrievalRaceTest.php
$R BillingNativeDedupRaceTest tests/Feature/ProductionMembershipBilling/BillingNativeDedupRaceTest.php
$R BillingOverlappingRetrievalTest tests/Feature/ProductionMembershipBilling/BillingOverlappingRetrievalTest.php
$R BillingRetrievalJobTest tests/Feature/ProductionMembershipBilling/BillingRetrievalJobTest.php
$R MembershipRowsClone tests/Feature/ProductionMembership/MembershipRowsFunctionClosureTest.php --filter clone
$R BillingSweepHintsCommandTest tests/Feature/ProductionMembershipBilling/BillingSweepHintsCommandTest.php
$R BillingObservationLedgerTest tests/Feature/ProductionMembershipBilling/BillingObservationLedgerTest.php
$R BillingWebhookRedeliveryTest tests/Feature/ProductionMembershipBilling/BillingWebhookRedeliveryTest.php
$R BillingWebhookIntakeTest tests/Feature/ProductionMembershipBilling/BillingWebhookIntakeTest.php
$R StripeSdkBillingGatewayTest tests/Feature/ProductionMembershipBilling/StripeSdkBillingGatewayTest.php
echo "$(date -u +%FT%TZ) CHAIN DONE" >> /home/user/VA-Studio-review-member2/docs/verification/membership-operative-2-20261008/independent-review/review-evidence/native/ledger.txt
