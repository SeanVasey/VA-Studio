#!/usr/bin/env bash
set -o pipefail
cd /workspace/VA-Studio-billing-retrieval || exit 1
source /workspace/.va-studio-toolchain/activate.sh
receipt_dir=/workspace/runtime/billing-retrieval-root-evidence
if [ -e "$receipt_dir/native-setup.exit" ] || [ -e "$receipt_dir/native-canaries-final.source.json" ] || [ -e "$receipt_dir/native-races-final.source.json" ]; then
  printf '%s\n' 'Prior native receipts exist; inspect before another invocation.'
  exit 1
fi
python3 "$receipt_dir/setup-private-native.py" > "$receipt_dir/native-setup.log" 2>&1
setup_result=$?
printf '%s\n' "$setup_result" > "$receipt_dir/native-setup.exit"
cat "$receipt_dir/native-setup.log"
if [ "$setup_result" -ne 0 ]; then exit "$setup_result"; fi
python3 "$receipt_dir/run-native-focused.py" --name native-canaries-final --driver mysql \
  --filter 'test_owned_award_commits_one_period_and_one_event_and_replays_the_original_result|test_real_sources_reserve_once_and_exact_retry_and_award_replay_preserve_the_retained_identity|test_current_database_interval_producers_retain_immutable_versioned_rows_in_every_billing_table' \
  tests/Feature/ProductionMembershipBilling/MembershipFirstAwardTest.php \
  tests/Feature/ProductionMembershipBilling/MembershipReservationLifecycleTest.php \
  tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php
canary_result=$?
if [ "$canary_result" -ne 0 ]; then exit "$canary_result"; fi
python3 "$receipt_dir/run-native-focused.py" --name native-races-final --driver mysql \
  --filter 'test_two_older_snapshots_cannot_claim_the_same_valid_database_interval_across_ledgers|test_hint_insert_waits_for_award_fence_then_refuses_committed_receipt_without_undoing_award|test_hint_committed_after_repeatable_read_snapshot_is_seen_by_the_owned_awards_current_reads|test_a_stale_settled_retrieval_from_a_second_process_is_refused_after_a_newer_reversal|test_a_stale_retrieval_whose_worker_clock_runs_ahead_is_still_refused_after_a_newer_reversal|test_a_retrieval_stalled_before_its_reads_appends_the_reversal_and_the_overlapping_older_settled_read_cannot_follow_it|test_a_retrieval_stalled_before_its_reads_is_refused_for_retry_after_an_overlapping_settled_append_and_the_retry_records_the_reversal|test_native_committed_clock_callback_owner_withdrawal_refuses_receipt_without_undoing_reservation' \
  tests/Feature/ProductionMembershipBilling/BillingNativeDatabaseIntervalOwnershipRaceTest.php \
  tests/Feature/ProductionMembershipBilling/MembershipNativeAwardRaceTest.php \
  tests/Feature/ProductionMembershipBilling/BillingNativeStaleRetrievalRaceTest.php \
  tests/Feature/ProductionMembershipBilling/BillingNativeIntervalRetrievalRaceTest.php \
  tests/Feature/ProductionMembershipBilling/MembershipReservationClockClosureTest.php
