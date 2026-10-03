# GitHub migration verification checkpoint

October 2, 2026. Sean requested moving development to private `SeanVasey/VA-Studio` and reported readiness after receiving the migration commands. The destination has not yet been verified from this conversation: authenticated GitHub metadata still identifies `VASEYDEV`, exposes only that installation, and returns 404 for the requested repository. That response does not establish whether the private repository exists or whether the user's terminal copy succeeded.

## Preserved source

The [native ref inventory](../migration/github-source-inventory-20261002.json) records all 102 GitLab branches and zero tags at its observation time. It includes main `cae053efbc2019c849269605df030a36a620484e`, the original rights branch `8fdc341ea639257318a87433b65830df0ab6b3eb`, frozen writer candidate `cd0cf7d3a908c5c4fb21d571088af4bdfe6082f9`, publication `d58250a2c28a75a9b1a2e5be74f87947f0c930f2`, commerce operations `82552f05a47eedea624d99694fec7365d005aef3`, acceptance repairs `b5c0a40700777e0fdb608697dc4ddad10b3fbdb2` and configuration/onboarding `67a75de75d422032e7fab30a87dd41fa8dee156f`.

The shared local checkout is shallow at main, with 43 reachable commits and 17 local heads at inspection. It cannot substitute for complete native history. Local reviewed commits may have equal trees but different hashes from their native equivalents; they must not overwrite native refs or be presented as native commits. Retain any local review refs in a separate namespace if transferred.

The provided terminal procedure creates or accepts only an empty private destination, obtains a fresh bare GitLab clone, copies branches and tags without force, sets main as default, and pauses Actions before pushing. Its execution has not been observed here. This preserves Git data only: GitLab MR !1, comments/reviews, pipeline records and artifact retention remain separate source evidence.

## Completion checks

1. Obtain repository metadata through the authorized SeanVasey connection. Verify owner, privacy, numeric repository ID, default branch and write/admin access; verify actual Actions settings and capacity.
2. Compare all native branch names and commit SHAs against the snapshot, reconcile changes since capture, and verify complete ancestry. Check for unexpected destination refs without deleting or force-updating them.
3. Preserve the existing review/evidence mapping and historical GitLab pipeline URLs. Failed or partial GitLab runs do not become GitHub acceptance after import.
4. Apply the separately prepared CI compatibility changes. Bind the actual destination repository and Foundation workflow IDs; preserve strict source/run/attempt/artifact identity checks, full 4-MySQL/2-SQLite census, exact skip policy, both browser engines and genuine-scanner coverage.
5. Re-enable Actions after configuration review, run fresh complete acceptance, then perform expected-head integration and separate main verification. The old publication helper targets VASEYDEV/VASEYAUDIO and must not be used unchanged.

No new main merge, source deletion, production activation or completed migration is claimed. GitLab remains the retained source until the destination checks succeed. The new CI preparation branch is a subsequent development increment and is not included merely because an earlier source copy completed.
