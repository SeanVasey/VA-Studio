#!/usr/bin/env bash
S=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-admission4
R=$S/run-native.sh
cd /home/user/VA-Studio-review-admission4
NM=docs/verification/checkout-write-admission-all-20261007/independent-review/review-evidence/addendum3/native/b8886440-M8
T=tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php
rm -f $S/native-m8.done
$R M8-native-reprove-cases $NM 3471 $T --filter 'test_withdrawal_in_the_pre_create_reprove_commit_refuses_before_provider_create' &
$R M8-native-terminal-cases $NM 3472 $T --filter 'test_admitted_reproof_is_the_terminal_commit_before_provider_create' &
$R M8-native-observer $NM 3473 $T --filter 'test_only_new_intent_and_pre_create_frames_hold_the_observer' &
wait
date -u +%FT%TZ > $S/native-m8.done
