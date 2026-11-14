#!/usr/bin/env bash
set -o pipefail
cd /workspace/VA-Studio-member-source-currentness || exit 1
source /workspace/.va-studio-toolchain/activate.sh
python3 /workspace/runtime/member-source-currentness-root-evidence/setup-private-native.py > /workspace/runtime/member-source-currentness-root-evidence/native-setup.log 2>&1
setup_result=$?
printf '%s\n' "$setup_result" > /workspace/runtime/member-source-currentness-root-evidence/native-setup.exit
if [ "$setup_result" -ne 0 ]; then cat /workspace/runtime/member-source-currentness-root-evidence/native-setup.log; exit "$setup_result"; fi
python3 /workspace/runtime/member-source-currentness-root-evidence/run-native-focused.py --name native-reservation-integration --driver mysql --filter '^.*::test_real_sources_reserve_once_and_exact_retry_and_award_replay_preserve_the_retained_identity$' tests/Feature/ProductionMembershipBilling/MembershipReservationLifecycleTest.php
