# Staff MFA leftovers source review

DECISION: **APPROVE SOURCE WITH EXECUTION AND FINAL EVIDENCE CONDITIONS**

Reviewed `11ab36d7ed0896b9bfe811f9530b7c27508a4117` against baseline `8b2cd56c2c4434a01bd1fd699f38a6649433adbb` in `/tmp/VA-Studio-staff-mfa-leftovers-20261015`. Open P0/P1: **0/0**. Required source corrections: **none**.

Ten source files add 21 lines: existing-helper imports and **11** `StaffWriteAuthority::require($currentActor)` calls, including both ReviewLicense entrypoints. Each guard follows the existing admin gate and freshly locked User within the same transaction, before resource/file/audit effects. Removing only those additions reproduces the baseline sources byte-for-byte. The helper, APIs, business validation, actor/resource lock ordering, attestation behavior, schema/environment/provider policy and frozen contract resources are unchanged. Required panel enrollment and optional local/testing behavior therefore retain the established rule.

The unchanged new test covers **13 direct operations × 4 enrollment/rule states = 52 cases**. Required withdrawal controls retain an enrolled caller model while the persisted row has no factor, demand the exact refusal and assert unchanged domain/audit data plus transaction cleanup. Required enrolled positives verify a persisted result and actor-attributed audit; optional positives preserve existing unenrolled testing behavior. Existing Filament and bulk/preset/import callers reach the same domain guard.

The genuine corrected-storage old run reconciles to **52 tests / 156 assertions / 26 failures / 0 errors / 0 skips**, PHP/recorder/observed tool exits **1/1/1**. All 26 refusal controls fail on unchanged baseline source while all 26 positive controls pass. The initial **52/50/F20/E32/S0**, PHP/recorder **2/1**, remains retained; all 32 errors are Flysystem directory creation failures. It is not a passing baseline. Temporary-checkout/loader/storage preparation is separately recorded and makes no host claim.

Fixed whole-file and private native outcomes were pending when this source decision was recorded; no pass is inferred. Reconcile those actual completed receipts and affected behavior evidence, preserve both nonzero originals, seal the packet and obtain final evidence review before exact-head publication carry/preflight/inventory/expected-SHA merge. Real-host/session challenge, provider, activation and full release conditions remain open. This change applies existing persisted enrollment, not a new per-request challenge.

Review was read-only source/helper/caller and original hash/XML receipt inspection, without application tests, database, network, CI or shared edits. The companion records exact original commands and source/evidence hashes.
