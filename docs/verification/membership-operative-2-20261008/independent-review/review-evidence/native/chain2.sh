#!/usr/bin/env bash
# Runs after chain.sh finishes: reviewer native probes, then the lock-removal mutation under the same lock-race probe.
until grep -q "CHAIN DONE" /home/user/VA-Studio-review-member2/docs/verification/membership-operative-2-20261008/independent-review/review-evidence/native/ledger.txt; do sleep 20; done
/home/user/VA-Studio-review-member2/docs/verification/membership-operative-2-20261008/independent-review/review-evidence/native/run-native.sh probes-native docs/verification/membership-operative-2-20261008/independent-review/review-evidence/probes/Lane2ReviewProbeTest.php --filter test_native_
/home/user/VA-Studio-review-member2/docs/verification/membership-operative-2-20261008/independent-review/review-evidence/native/run-native-mut.sh R6-no-mysql-lock mutation-R6-no-mysql-lock-native docs/verification/membership-operative-2-20261008/independent-review/review-evidence/probes/Lane2ReviewProbeTest.php --filter test_native_concurrent_appends
echo "$(date -u +%FT%TZ) CHAIN2 DONE" >> /home/user/VA-Studio-review-member2/docs/verification/membership-operative-2-20261008/independent-review/review-evidence/native/ledger.txt
