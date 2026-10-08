#!/usr/bin/env bash
# Usage: f253-run.sh <label> <worktree>; the 253 checkpoint selection (9 cases + final postcommit case) on private 3410.
label=$1; W=$2
S=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad
cd $W
export APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3410 DB_DATABASE=vaseyaudio_trigscan_f253 DB_USERNAME=root DB_PASSWORD= DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
F='test_actual_public_catalog_projection_and_all_ordinary_writes_keep_pinned_v1_readable|test_postcommit_public_withdrawal_cannot_release_old_public_link_and_reload_is_fresh|test_commit_event_identity_floor_withdrawal_withholds_projection_and_preserves_durable_write|test_plain_scalar_reference_cannot_mutate_the_captured_purpose_snapshot_in_place|test_beginning_listener_cannot_make_the_feature_adopt_or_commit_a_foreign_transaction|test_committing_listener_purpose_withdrawal_prevents_a_durable_grant|test_regular_begin_commit_and_aftercommit_callback_order_is_preserved|test_saved_callback_commit_reopen_cannot_make_cleanup_rollback_a_foreign_transaction|test_actual_over_text_capacity_envelope_is_refused_before_update_with_row_and_revision_unchanged'
{
echo "$label tree=$(git rev-parse HEAD) staged=$(git diff --cached --name-only | wc -l) start=$(date -u +%FT%TZ) load=$(cut -d' ' -f1-3 /proc/loadavg)"
start=$(date +%s)
timeout 7000 php $S/phpunit-own-autoload.php --filter "$F" tests/Feature/ProductionFeatures --log-junit $S/f253-$label.xml > $S/f253-$label.txt 2>&1
echo "exit=$? wall=$(( $(date +%s) - start ))s end=$(date -u +%FT%TZ) load=$(cut -d' ' -f1-3 /proc/loadavg)"
sed 's/\x1b\[[0-9;]*m//g' $S/f253-$label.txt | grep -E '^(OK \(|Tests:)|^[0-9]+\) |Exception:' | head -30
} > $S/f253-$label-summary.txt 2>&1
