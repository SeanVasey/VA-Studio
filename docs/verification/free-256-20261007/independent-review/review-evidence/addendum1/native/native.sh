#!/usr/bin/env bash
A=/home/user/VA-Studio-review-free256b/docs/verification/free-256-20261007/independent-review/review-evidence
export VA_REVIEW_RACE_LOG=$A/addendum1/native-race-log.json
date -u +%FT%TZ > /tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256b-run/native-start.txt
/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256b-run/run.sh /tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256b-run/native-concurrency $A/probes/NativeConcurrencyProbeTest.php
/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256b-run/run.sh /tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256b-run/native-new-tests --filter 'ProductionFreeGrantSpoolTest|ProductionFreeGrantKeyRotationTest|ProductionFreeGrantProfileRevisionTest' tests/Feature/ProductionFreeGrants
date -u +%FT%TZ > /tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256b-run/native-end.txt
