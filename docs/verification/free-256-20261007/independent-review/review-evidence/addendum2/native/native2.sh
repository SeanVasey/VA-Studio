#!/usr/bin/env bash
date -u +%FT%TZ > /tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256b-run/native2-start.txt
/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256b-run/run.sh /tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256b-run/native2-attempts-library-schema --filter 'ProductionFreeGrantAttemptLimitTest|ProductionFreeGrantLibraryWindowTest|test_migration_installs_nine_tables' tests/Feature/ProductionFreeGrants
date -u +%FT%TZ > /tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256b-run/native2-end.txt
