#!/usr/bin/env bash
S=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-admission4
R=$S/run-native.sh
cd /home/user/VA-Studio-review-admission4
N=docs/verification/checkout-write-admission-all-20261007/independent-review/review-evidence/addendum3/native/b8886440
C=docs/verification/checkout-write-admission-all-20261007/conditions
T=tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php
rm -f $S/native-chains.done
( $R native-g1 $N 3471 $T --filter '/(test_admitted_reproof_is_the_terminal_commit_before_provider_create|test_only_new_intent_and_pre_create_frames_hold_the_observer|test_committing_offer_withdrawal_refuses_new_intent_rolls_back)/'
  $R native-terminal-canary $N 3471 $C/codex-terminal-reproof/canary/TerminalReproofCanaryTest.php
  $R native-4d-canary $N 3471 $C/codex-reprove-admission/canary/ReproveCommitAdmissionCanaryTest.php
  $R native-g5 $N 3471 $T --filter '/(test_new_basis_admission_is_capped_at_the_selected_license_expiry|test_staff_replays_install_no_observer)/' ) &
( $R native-g2 $N 3472 $T --filter '/(test_withdrawal_in_the_pre_create_reprove_commit_refuses_before_provider_create|test_committing_fresh_withdrawal_refuses_new_intent|test_capability_closed_after_intent_commit_refuses_before_first_create|test_fresh_withdrawal_after_intent_commit_refuses_before_first_create_with_disabled)/'
  $R native-g4 $N 3472 $T --filter '/test_committing_qualifier_staff_withdrawal_refuses_new_basis/' ) &
( $R native-g3 $N 3473 $T --filter '/(test_offer_withdrawn_before_retried_create|test_committing_authoring_withdrawal_refuses_new_authority|test_committing_capability_closure_refuses_new_authority|test_committing_owner_delegation_or_offer_withdrawal_refuses_new_basis|test_staff_capsule_fresh_admission_itself|test_committing_capability_closure_refuses_new_intent_and_provider_io)/'
  $R native-g6 $N 3473 $T --filter '/test_committing_owner_staff_withdrawal_refuses_new_authority/' ) &
wait
date -u +%FT%TZ > $S/native-chains.done
