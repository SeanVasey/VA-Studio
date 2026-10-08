#!/usr/bin/env bash
R=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256-run/run.sh
( $R 3641 /tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256-run/native-a1 --filter 'prefix_resumes_to_the_exact_schema#0-22' tests/Feature/ProductionFreeGrants ) &
( $R 3642 /tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256-run/native-b1 --filter 'prefix_resumes_to_the_exact_schema#23-36' tests/Feature/ProductionFreeGrants; $R 3642 /tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256-run/native-b2 --filter 'ProductionFreeGrantRenderingTest' tests/Feature/ProductionFreeGrants ) &
( $R 3643 /tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256-run/native-c1 --filter 'ProductionFreeGrantSchemaTest' --exclude-filter 'prefix_resumes' tests/Feature/ProductionFreeGrants; $R 3643 /tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256-run/native-c2 --filter 'ProductionFreeGrantApprovalTest' tests/Feature/ProductionFreeGrants ) &
( $R 3644 /tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256-run/native-d1 --filter 'ProductionFreeGrantAssentTest|ProductionFreeGrantLibraryTest|ProductionFreeGrantJourneyTest|ProductionFreeGrantDeliveryTest|ProductionFreeGrantFrozenBytesTest|ProductionFreeGrantRequestIdentityTest' tests/Feature/ProductionFreeGrants ) &
wait
echo all-done
