# Independent SQLite census repair and reviewer correction

**DECISION: APPROVE THE PRECISE METADATA REPAIR; FINAL GATES PENDING.** Reviewed `2f3ef182635fbeb1c3b8d0c7c3b277f491c6e9c3` against preflight-blocked `2ff3299111005e2c4bf6a50478e7214edc9cf435`. The only changed path is scripts/ci/database-sqlite-skips.json. No product source repair or app/native rerun is needed solely for this order correction.

I independently verify the exact 257 historical pairs and order from actual PR122 merge `0fc2391cfd624cfa97fb5d02cc8be94492d8df36`, followed by exactly the two reviewed Tax native methods. All 259 pairs remain unique and set-equal to the previously reviewed policy. The original first-150 SHA256 is restored to `d93da4e7a13a8c6aff8f97f363eb6d93dde884bad53c9ae50eeb0ed32514c71c`. The golden assertion and its entire test file are unchanged. No pair is removed, assertion changed or gate weakened.

**Correction to my earlier assessment:** requiring whole-list sorting for SQLite was wrong. The parser explicitly calls read_census with require_sorted=False for SQLite and True for MySQL. Historical SQLite append order is separately protected by the focused-test golden prefix. My earlier generic sorting requirement missed that contract; the limited 52/14/27 checks did not cover it. Whole-list sorting therefore caused a real P2 metadata/preflight blocker, caught before merge. Earlier original review artifacts stay immutable; this note supersedes their incorrect SQLite-sorting requirement while preserving the valid MySQL/path sorting requirements.

The previous preflight remains failed, not green because its audit now passed. Run all eight Python self-check commands actually required by preflight, including focused tests, runtime, receipt and writer-feedback checks, then fresh clean discovery and source/loader/metadata binding. Membership is unchanged, but raw policy/manifest hashes differ and must be captured. Final exact-head evidence approval, successor carry if needed, new same-head cheap preflight and expected-SHA merge remain. No outcome of those pending checks is asserted here.

Only read-only source/Git/JSON/SHA inspection was performed. Application/dependency/tests/loaders/caps/frozen profiles are unaffected by the one-file commit. Earlier failed originals, successful components/continuation, old native runtime scope and all default-off/unmounted/host/provider/activation/release/frozen adjacent P2 limits remain.

Report SHA256: `71307a3e57d8a09b9fdae7513c636e0c74dcb7c0af64faad3b51e86d2521f2ad`.
