# D1 Free256 returning-write progress and account-held snapshot limit

Tested application and affected-test commit: `2e071ef9b4783beb99336968a8b73cd76f7c9cf5`. This bounded development change implements two prerequisites of A6-2. It does not complete the HTTP mount or A6-2 host conditions.

Downloads captures one original monotonic start, absolute transfer deadline, minimum rate and base grace from its already captured policy. Transfer checks before reads/writes and after every returning consumer callback, including the final chunk. Only a fully accepted returning chunk earns progress. The integer cumulative budget is original base grace plus accepted bytes divided by the captured rate. With zero base, first acceptance has one fixed first-chunk startup budget from the original start; subsequent writes cannot renew it. Invalid or reversing clocks refuse and close the snapshot. An indefinitely blocked consumer callback cannot be interrupted by this component.

Spool admission uses a native digest of the original server-proved authorization account. It holds the existing global admission lock and its native slot lease while checking other held canonical slots, including slots above a newly reduced allocation count. One account cannot hold a second snapshot across origins or roles. Other accounts can use available slots under the existing global and disk-reservation bounds. Holder metadata is published before fill. Unknown held metadata refuses; close or native process exit releases the account lease. Only the slot's own regular single-link residual files may be reclaimed.

Only three unfrozen Free source files changed. The shared prepared stream and all actual frozen renderer members/manifests remain unchanged. The sole stale MAIN test reference for ProductionCustomerAccess was updated after a fresh independent Free256 compatibility review; this does not re-pin a profile-assets manifest or renderer member.

## Focused evidence

Every PHP command, selected file/filter, environment, actual process/recorder exit, original JUnit count, runner, runtime, loader and source hash is bound in `focused-evidence.zip` and its index. Actual foreground tool completions are recorded separately; they are not invented supervisor witnesses. Raw failed logs/XML remain private by exact hash because they can contain synthetic private fixture/configuration dumps; their derived case outcomes are labeled as derived. Original failures are preserved.

| Original invocation | Tests / assertions | Failures / errors / skips | PHP / recorder |
| --- | --- | --- | --- |
| First old-source progress control, stops at first defect | 1 / 1 | 1 / 0 / 0 | 1 / 1 |
| Corrected counter, complete old progress selection | 6 / 8 | 4 / 0 / 0 | 1 / 1 |
| Old-source zero-base controls | 2 / 3 | 1 / 0 / 0 | 1 / 1 |
| Old-source account-spool controls | 6 / 6 | 5 / 0 / 0 | 1 / 1 |
| First fixed component files at f1d5fb1f | 14 / 33 | 0 / 0 / 0 | 0 / 0 |
| Initial seven affected SQLite files at f1d5fb1f | 33 / 276 | 2 / 4 / 0 | 2 / 1 |
| Old shrink/clock controls, application still f1d5fb1f | 6 / 7 | 3 / 1 / 0 | 2 / 1 |
| Current three component/process files | 27 / 85 | 0 / 0 / 0 | 0 / 0 |
| Current seven affected SQLite files | 34 / 313 | 0 / 0 / 0 | 0 / 0 |
| Current selected MySQL account domain counterpart | 1 / 10 | 0 / 0 / 0 | 0 / 0 |

The selections overlap and are not summed. The initial whole failure includes three obsolete same-owner concurrent expectations, two old transfer timing expectations and one pre-existing MAIN dependency hash mismatch. Storage residual coverage still checks every held slot with distinct synthetic storage holders and a real domain redemption afterward. The domain test uses enrolled synthetic accounts and actual grants/authorizations to prove same-account role/origin refusal before another source open, no extra redemption, and different-account delivery.

Two separate PHP processes establish overlap during fill and after completed preparation, retained reservations and holder marks, different-account admission, graceful close, and explicit process exit followed by owned residual reclamation. This is native local POSIX lease evidence. It does not claim simultaneous-start contention, forced-kill recovery, a deployed filesystem, MySQL concurrency or blocked HTTP output.

Local runtime: PHP8.4.26, PHPUnit12.5.34. Focused Pint10files and shard-policy52tests pass. Corrected native discovery proves2423 selected cases/232files of9097 discovered cases/673files; no database tests were executed by discovery. Its initial absolute output prefix was rejected, retained and corrected to the documented `phpunit-ci-mysql` prefix. Timing data are estimates; no performance acceptance follows.

The first newly owned MySQL initializer exited1 before any application schema was created. Its private root-filesystem initialization ran out of space. Only that failed empty owned container/volume was removed, with original launch/state/log/removal records retained. The successful retry used its own datadir on the separate temporary filesystem, private port33522/socket/schema and recorded MySQL8.4.11. Previous datadirs and unrelated running policy server were untouched. The selected real Downloads account-limit counterpart passed1test/10assertions/F0/E0/S0 with PHP/recorder/tool0 on that server. The exact owned container stopped normally with exit0; its datadir remains retained and the unrelated policy server is still running. This selected case is not whole-MySQL or concurrency acceptance.

## Review and limits

Original design REVISE clarified the zero-base startup; v2 received bounded design approval. Source review at f1d5fb1f required D1-S1 high held-slot scanning and D1-S2 strict clock admission. Both were fixed together and proved by genuine old/current controls. The independent source decisions, dependency re-review and final source/sealed-evidence decision at `0278a5e7e3ec5362c984e414378d0ef4b389647f` are imported byte-original and name their reviewed commits. The forthcoming exact-head publication carry, cheap preflight, complete inventory and expected-SHA merge remain pending.

HTTP mount, committed-frame/first-byte/ff0 composition, real host-local source adapter bounding `open()`, renderer r2/hash-checked fonts, A1-1/A1-7, contention mapping, blocked-consumer termination/send timeout/proxy behavior, actual host sizing/flock/performance, identity/activation, real rights/terms/source exports and complete release acceptance remain open. Nothing is mounted or activated. No Foundation/GitLab dispatch, real provider call, credentials, payments, customer-active import, host verification, deployment, DNS or cutover occurred. Existing B4 and adjacent frozen metadata/membership P2s remain outside this delivery scope.

Sealed originals: 10PHP invocations, 206archive members, 1848673bytes, SHA256 `e3bd46d4ce9431ca642d7a7508264ac4ff4d572017365ed55972e26c8af488b6`. Final independent packet review is complete: **APPROVE BOUNDED DEVELOPMENT SOURCE AND FINAL SEALED EVIDENCE**, no open P0/P1 or required source/evidence correction. Exact-head publication carry, cheap preflight, complete inventory and expected-SHA merge remain pending.
