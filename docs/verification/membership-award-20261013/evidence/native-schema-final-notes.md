# Final native schema selection

Executed `bash /workspace/runtime/membership-award-evidence/native-schema-final.sh` from `/workspace/VA-Studio-membership-award` only after root released the main native schema following its positive award canary. The retained script contains the exact environment, command, ten-case filter, fail-fast flags, version check, source inventory, and after-run hash check. Read-only discovery in `native-schema-final.selection.txt` confirmed exactly ten cases before execution.

Actual environment: private local **MySQL 8.4.11**, **PHP 8.4.26**, exclusively owned disposable schema `vaseyaudio_membership_award`, socket `/workspace/runtime/mysql-policy-run/mysql.sock`, port **33413**. No live/provider accounts or host verification. Source HEAD **904f08d55fe832aacb37b76cbd04bd0d8a02d314**; the script checked all three metadata product files byte-for-byte against committed **bf498055a5917172f930c877ffcff5f220dc4d4d** before connecting. The broad **2,061-file** before/after source inventory matches exactly. No product/test edits occurred during execution.

Result: **10 tests, 40 assertions, zero failures/errors/skips**, PHPUnit/process exit **0**, elapsed **784.443 seconds** (13:04.443). All requested cases completed:

- BillingAwardFenceDefinitionTest: old trigger snapshot after current hint guard removal; old catalog snapshot after fence moved to MyISAM before its open.
- MembershipSchemaPreparationTest: foreign CHECK reserves the actual global symbol; local UNIQUE does not pollute the FK dictionary; changed enum CHECK stays refused; raw reader refuses a temporary table shadow.
- BillingSchemaPreparationTest: empty tail recovery versus retained unguarded prefix; reserved guard name collision retains its foreign marker.
- BillingAwardFenceTest: each missing guard and empty owned seed recover while preserving evidence.
- BillingIntervalOwnershipTest: exact legacy upgrade and every interrupted guard state preserve every row.

This selection does not repeat approval, the full native suite, or three native award races. Root owns those separate race results. During this selection root ran its native award races on separately owned `vaseyaudio_membership_award_profile` on the same instance; `native-schema-final-workload.md` records that concurrent local verification workload. Elapsed time is not an unloaded benchmark.

Files: `native-schema-final.sh`, `.selection.txt`, `.metadata.json`, `.base`, `.patch`, `.sources.json`, `.txt`, `.xml`, `.exit`, `-source-check.txt`, and `-workload.md`. Main native schema is released for root after this completion. No commits were made by this subagent.
