# Development CI strategy

Status: T01/T02 implementation candidate, September 30, 2026 (America/Chicago). The integrating change adds informative focused feedback, conservative documentation routing, an explicit full-mode aggregate and refreshed timing weights. Full cloud acceptance is pending; branch protection is unchanged. Draft suppression and runtime post-merge deduplication remain proposed follow-ups.

## Current increment

The [focused feedback workflow](verification/focused-ci.md), [documentation routing](verification/ci-scope.md), and [timing refresh](verification/ci-throughput.md) are implemented in this candidate. Runtime PRs, runtime main pushes and full dispatch still execute the complete suite. The backend aggregate now also requires frontend/browser success. No runtime post-merge evidence reuse, schedule or draft suppression is introduced. Later sections distinguish the longer-term target from this bounded implementation.

## Observed evidence

Main: `8be3bd7271595f2c21a3c5017b3b19ceb821a842`. [Current workflow](../.github/workflows/ci.yml) runs ten jobs on PR events, main pushes and full manual dispatch. [PR #68's accepted trigger change](verification/ci-trigger-efficiency.md) already removed duplicate feature-branch push runs. A draft PR currently runs the same suite as a ready PR.

[PR #87 run 36795680362](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36795680362) passed all ten jobs in 62m06s. Its actual test-step durations were:

| Suite | Duration |
| --- | ---: |
| MySQL shard 1 | 60m00s |
| MySQL shard 2 | 26m51s |
| MySQL shard 3 | 31m35s |
| MySQL shard 4 | 23m37s |
| SQLite shard 1 | 8m26s |
| SQLite shard 2 | 10m53s |
| Operator browser | 8m32s |

MySQL containers took 33–42 seconds, media/PDF packages 20–44 seconds and Composer 8–10 seconds. Setup caching can help, but does not explain or solve the hour-long shard.

The three largest files on shard 1 were `TestOrderFinalizationTest` (25m29s), `TestOwnerDeliveryProjectionTest` (11m52s) and `SiteScheduleRunnerTest` (5m09s). This is observed execution time, not proof of a database-setting or runner-hardware defect.

Timing weights currently use PR #82 evidence: 121 files and 1,744 cases. The present suite has 123 files and 1,870 cases; newly untimed files receive fallback weights rather than being omitted. Current estimated weights are around 24m26s per MySQL shard. Retrospective whole-file balancing from the latest JUnit suggests approximately 35m30s per shard under identical timings. Runner variability and changed file placement make that an experiment, not a promised result.

The existing `backend` aggregate uses `always()` and requires successful quality, MySQL and SQLite jobs. Preserve this fail-closed behavior. Frontend/browser are separate jobs. Classic branch-protection inspection returned 403 (integration lacks access); the readable rulesets list was empty. Actual enforced check names therefore remain unverified. Do not infer that protection is absent or change repository settings based on that result.

## Immediate process improvement

Before T01 changes workflows, compose related work on a branch without a PR, run available focused checks, review the candidate, then open one ready PR. Branch pushes are durable without starting Foundation CI under current triggers. Continue existing full PR/main gates until the revised policy is actually accepted.

Do not make tiny status-only commits after successful CI just to insert that run's result into the same source. Put exact run results in the PR body and update durable status in the next substantive batch. A changed runtime candidate always needs fresh acceptance.

During preparation, a compatible PHP 8.4.26 runtime and exact-lock dependency caches were recovered from an earlier same-project workspace. A reconstructed checkout matches all 764 baseline Git blobs and actual PHPUnit discovery matches 123 files /1,870 cases. Focused local SQLite/PHP checks are now available; a Composer executable, local MySQL and qpdf remain unavailable at this checkpoint. Focused cloud execution supplies the database/tool environments that local checks lack. Record each actual test environment separately.

## Target verification tiers

| Situation | Required evidence | Full MySQL frequency |
| --- | --- | --- |
| Work in progress on a feature branch | Relevant unit/domain cases, format/static/build checks and affected browser cases; targeted MySQL for database behavior | Focused cases as needed, not every full repository suite |
| Final ready runtime/asset/schema/config/dependency/test/workflow candidate | Complete MySQL + SQLite partitions, frontend/build/secret scan/audits, browser and independent review where required | Once per coherent final batch; rerun when the candidate changes |
| Documentation-only candidate, after classifier acceptance | All changed paths proven documentation-only; documentation/link/consistency checks; explicit mode evidence | Not required by the new approved docs mode; skipped suites never described as executed |
| Merge to main, after provenance replacement is accepted | Verify tested PR/base/tree/policy provenance and run clean-build, migration/boot and critical-route smoke checks | Reuse valid pre-merge evidence only for the exact integrated source; mismatch/missing evidence triggers full |
| Safety backstop / release | Full run on a new unverified integrated tree or changed runtime inputs; release always has current complete proof | At most one change-aware backstop per active day plus explicit release/manual runs; do not rerun an unchanged fully verified tree automatically |
| Any unclear path, classification error or incomplete proof | Full checks or blocked acceptance | Full by default |

These are target behaviors, not existing capabilities. A real scheduled workflow is not created by this plan. Choose its schedule and deduplication contract in T01; scheduled runs never substitute for pre-merge proof.

A three-deliverable example: three separate PR/main pairs can cause six full runs before corrective pushes. One coherent batch with full pre-merge checks and a proven post-merge smoke path would need one full run plus focused checks. This illustrates reduced frequency, not measured savings or permission to omit new-source verification.

## Implement T01 in safe stages

1. **Focused cloud feedback.** Add an informative manual workflow with bounded, allowlisted suite selections for a pinned ref. Use distinct check names from full merge acceptance. Support affected MySQL tests when database semantics matter and a frontend/browser mode. Record actual SHA, engine, selected tests and outcomes. Reject empty/unknown selections; do not interpolate user inputs into shell commands. Keep existing full dispatch.
2. **Document-only classifier and draft handling.** The classifier runs on an authoritative complete diff, including renamed/deleted files. A narrow allowlist covers ordinary prose files only; application-loaded assets, executable snippets/config, workflow/scripts, lockfiles, migrations, test helpers, brand manifests and ambiguous files default to full. Failure, truncated diff, unexpected status or missing base/head defaults to full. The workflow itself must always report acceptance rather than vanish through a top-level path filter.
3. **Always-running acceptance jobs.** Preserve observed required names until actual protection requirements are verified. Each success must encode the selected mode and validate every required result for that mode, including frontend/browser. A docs mode success is explicit documentation acceptance, not a fake PHP pass. Runtime mode rejects skipped, cancelled, absent, failed or unknown dependencies. A draft's informative checks must never satisfy final runtime acceptance.
4. **Ready-candidate events.** If draft suppression is introduced, handle `opened`, `synchronize`, `reopened` and `ready_for_review`; verify draft-to-ready actually starts full checks. A new head invalidates previous proof. Manual focused dispatch is feedback, not the required PR gate.
5. **Post-merge deduplication, separately verified.** Only replace unconditional main full runs after trusted GitHub evidence proves the successful accepted PR tested the current base/head combination and resulting tree under the current CI/runtime/lockfile inputs. Record checked-out commit and tree, not just workflow `head_sha`. Missing/stale/ambiguous evidence, direct pushes, changed base, merge conflicts or differing trees require full checks. Invalidate for relevant environment/toolchain changes. If trusted provenance cannot be implemented/read, retain main full checks while benefiting from batch frequency and timing improvements.
6. **Bounded backstop.** If introduced, use a read-only no-secret safety workflow with explicit change/evidence detection. It supplements, never replaces, full final-candidate verification. Avoid a blind nightly duplicate when the same tree/runtime is already verified. Never execute untrusted PR code using privileged `pull_request_target` credentials.

The CI-policy implementation itself must pass the current full gates and independent review. Land it before later feature batches rely on its behavior. A docs file cannot waive an existing required check.

## Required policy regression cases

Test classifier behavior for prose-only, mixed runtime/docs, rename/delete, new/unknown path, unavailable/truncated diff, changes to classifier/workflow, and base changes. Exercise the aggregate truth table for success, failure, cancellation, skip, missing and invalid outputs in every mode.

Verify actual events for draft/open/ready/update, full and focused dispatch, docs-only PR and main push. Inspect resulting checks in GitHub, including required names and event eligibility. Protect against stale-head status reuse. If a merge queue is adopted later, add and verify `merge_group`; this plan does not require introducing one.

Keep the exact-once expanded test inventory and 35 current partition safeguards. New tests can change that safeguard count only through actual implementation evidence, not a fixed expected success claim.

## T02 performance work

1. Collect current complete, successful JUnit artifacts from both engines with run/tree provenance; update weights through the existing timing tool, preferably using multiple comparable runs to limit runner noise.
2. Prove every expanded test identity still appears exactly once and keep whole-file isolation unless a separately reviewed partition change demonstrates safety.
3. Run the next candidate and compare observed slowest shard, total runner-minutes and per-file costs. Use the next ordinary complete run as a second sample; do not launch redundant full runs merely to obtain a nicer average.
4. Profile the three measured heavy files. Remove redundant fixture/bootstrap work only when isolation, MySQL transaction/race behavior, immutable-history checks and failure cases remain proven.
5. Consider Composer/tool caching or additional shards only after measurements show a benefit. More shards may shorten latency while increasing cost; do not silently change paid runners or billing.
6. Keep warning/audit failure settings, realistic constraints and real independent-process concurrency tests. Do not speed tests by turning off durability/locking semantics they are meant to validate.

## Evidence and outcome reporting

For each integrated batch record: task IDs; tested head, checked-out commit/base/tree; workflow version; event and run URL; executed/pass/skip/error counts by engine; independent review; actual merged tree; focused versus full mode; runner-minutes and wall time where available. A source change after acceptance invalidates that candidate's completion claim.

T01 is complete only when the intended event/aggregate behavior is observed, required checks remain enforceable, the full existing runtime inventory passes, and the next contributor can run a documented focused command. T02 is complete when the measured optimization is validated without coverage loss; report the observed result even if it misses the target.

## References

- [Current Foundation CI](https://github.com/VASEYDEV/VASEYAUDIO/blob/8be3bd7271595f2c21a3c5017b3b19ceb821a842/.github/workflows/ci.yml)
- [Accepted trigger policy](verification/ci-trigger-efficiency.md)
- [Partitioner](../scripts/ci/phpunit-shards.py), [timing tool](../scripts/ci/phpunit-timings.py), [partition safeguards](../scripts/ci/test-phpunit-shards.py)
- [GitHub required status checks](https://docs.github.com/en/pull-requests/how-tos/merge-and-close-pull-requests/troubleshooting-required-status-checks): skipped workflows can leave checks pending; conditional jobs and dependent aggregates need explicit handling; manual dispatch checks are not a substitute for an eligible PR required-check event.
- [GitHub workflow events](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows): PR workflows normally check out the synthetic merge ref; record actual source and handle ready-for-review explicitly when needed.
