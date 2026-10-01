# Post-merge CI reuse: deferred design decision

Status: reuse remains deferred, October 1, 2026. The bounded [T01-RECEIPTS-01 collection and shadow child](ci-database-receipts.md) is now a source candidate; final-source review and cloud verification remain outstanding. Full main CI remains required. Current batching, documentation routing, focused feedback and balanced shards provide the initial efficiency gains.

The bounded future case is an ordinary two-parent merge of a same-repository PR into main. Reuse would require the same ordered base/head pair and complete tree that passed full PR verification. The stable `backend` gate would still run, accepting an explicit reuse mode only with independently verified full evidence plus fresh build, audits and migration/route smoke checks. PRs and manual dispatch remain full. Missing or uncertain early evidence selects full; uncertainty discovered at final acceptance blocks the gate.

## Evidence discovered

Actual [PR #87 run 36795680362](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36795680362) and its ten jobs report head `e27001edde722815f65bc2f0588840078471bf2f`. The tested checkout was synthetic merge `2a87cdad2f9a5fb017cdd04198870c25164343d6`; the actual merge was `8be3bd7271595f2c21a3c5017b3b19ceb821a842`. Both Git objects have parents `[383354729e506ef93a4c458552c6087cb717fa9c, e27001edde722815f65bc2f0588840078471bf2f]` and tree `c389602f8acb43287a8d8125b482c4c0dd2b3dba`.

The run's API `pull_requests` list is empty. Commit-associated PR lookup and the PR resource do establish the merge relationship. Consequently neither API `head_sha` nor `run.pull_requests` is sufficient checkout provenance. Existing artifacts contain database/browser results, but no purpose-built full-acceptance or resolved-runtime receipt. PR #87 is not eligible for retroactive reuse under this design.

## Future acceptance contract

1. Require clean actual checkout equal to main push `after`/event SHA; exactly two parents `[B,H]`; push `before == B`; one authoritative same-repository merged PR with matching base/head/merge SHA. Direct, forced, squash/rebase, multiple-merge, advanced-base and ambiguous cases use full CI.
2. Require the trusted Foundation workflow ID/path, full mode, completed successful run and every required job/shard from one explicit current attempt. Select newest by `(created_at, run_id)` across the complete same-workflow PR/head candidate set before filtering on success, receipts or eligibility, and recheck that set at final acceptance. Initially reject reruns entirely. Reject newer failed/running/cancelled evidence even when older evidence is green. Inspect all four MySQL and both SQLite shards, quality, frontend and browser; do not substitute a display name or aggregate alone.
3. Add a new full-acceptance receipt emitted only by a fresh, trusted aggregate after validating complete job receipts and exact-once nonempty test coverage. It records actual checkout/tree/parents, PR/base/head, run/attempt, workflow/policy/lock hashes, resolved runtime/dependency identity, result counts and artifact IDs/digests. The existing full gate must then finish successfully. Reused evidence cannot produce another full receipt.
4. Download by exact artifact ID with read-only API permissions, hard-verify its SHA-256 digest, and parse bounded JSON in a bounded ZIP without links, traversal or extra entries. Never execute downloaded content or forward the GitHub token to a signed download host. Upload the aggregate receipt with overwrite disabled: name preemption must fail the collector. Artifact metadata identifies the producing run, not a job ID; validate the trusted collector/upload steps separately.
5. Recompute Git tree/workflow/policy/lock identities; require the prior-main execution policy and dependency/runtime configuration unchanged. Suggested initial freshness: no more than 24 hours from the oldest required job and one hour from merge. Reject incomplete pagination, unknown schema, missing fields, expired artifacts or API errors. Recheck authoritative run/attempt/artifact/main state at final acceptance.
6. Match resolved runtime inputs before reuse, then run a clean build, client-secret scan, fresh Composer/npm audits and explicit nonempty disposable MySQL/SQLite migration/boot and authorization smoke checks. Current floating runner/PHP/Node/Composer/MySQL/apt inputs mean tree and lock equality alone cannot establish runtime identity. The new bounded child captures initial database-job source/runtime evidence only; complete runtime identity and comparisons remain unresolved prerequisites for reuse.

## Required adverse cases

| Evidence/problem | Expected result |
| --- | --- |
| Same two parents/tree, different synthetic/actual merge SHA | Eligible only with complete new receipt and runtime proof |
| Wrong/advanced base, changed tree, unclean checkout, policy/lock/config change | Full |
| Fork, direct/forced/squash/rebase/multiple merge, unknown PR association | Full |
| Wrong workflow/event/repository, focused/docs/manual/reused receipt | Full |
| Missing/duplicate job or shard; failed, cancelled, skipped or mixed-attempt required result | Full |
| Empty/all-skipped/missing/duplicate test evidence | Full |
| Forged receipt fields, missing/expired artifact, digest mismatch, unsafe ZIP/redirect | Full without executing downloaded content |
| Newer non-green run, stale/future timestamps, runtime drift or incomplete fingerprint | Full |
| API failure, incomplete pagination, timeout, malformed output or verifier crash | Full; missing output never selects reuse |
| Fresh build/audit/smoke fails | Block acceptance |
| Evidence or main changes after selecting reuse | Full where early; block final gate where late |
| Manual run with otherwise perfect prior evidence | Full |

The first collection and full-only shadow source candidate is documented in [T01-RECEIPTS-01](ci-database-receipts.md). It validates six current-run database receipts, preserves all full gates and explicitly leaves its own outer acceptance pending. Old runs remain ineligible. It does not query prior runs or make an eligibility comparison. Use the next ordinary PR/main pair as actual cloud evidence. Enable skipping only in a separate increment after independent review and observed event/fallback behavior. Keep protection settings and stable check names unchanged; actual protection requirements remain unknown and are an activation check. No skip enabling, runtime-pinning project or protection change is included in this child.

Platform references: [workflow events](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows), [run/attempt API](https://docs.github.com/en/rest/actions/workflow-runs), [attempt jobs](https://docs.github.com/en/rest/actions/workflow-jobs), [artifacts API](https://docs.github.com/en/rest/actions/artifacts), and [artifact integrity](https://docs.github.com/en/actions/tutorials/store-and-share-data). GitHub documents digest mismatch as a warning in the download action; acceptance must enforce its own hard comparison.
