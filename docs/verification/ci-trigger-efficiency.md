# Foundation CI trigger scope

## T01/T02 candidate — September 30, 2026

The new [documentation routing](ci-scope.md) and [focused feedback](focused-ci.md) guides describe the current candidate. Runtime PR/main/manual events retain complete Foundation CI, while an explicit allowlist can select documentation validation. Feature pushes run a separate informative unit workflow. The `backend` aggregate requires every selected-mode result, including frontend/browser in full mode. The dated PR #68 record below describes the earlier unconditional policy; it remains provenance, not the new docs exception. Actual integration and event execution results belong to the integrating PR. No runtime post-merge deduplication, draft suppression or scheduled run is introduced.

## Accepted PR #68 policy (historical)

Status: merged through [PR #68](https://github.com/VASEYDEV/VASEYAUDIO/pull/68), 2026-09-26. GitHub repository metadata confirms `main` is the default branch. See the [ordered acceptance record](../development-order.md#current-increment-and-next-handoff) for the integrating PR's actual CI and merge status; static verification alone does not establish an executed Actions pass.

An update to an open feature PR previously started two copies of Foundation CI: one from its branch push and another from the PR event. Restricting the push trigger to `main` retains complete PR checks and the post-merge run without that duplicate feature-branch run. Explicit `workflow_dispatch` permits a deliberate manual run.

| Event | Foundation CI behavior |
| --- | --- |
| Feature-branch push without a PR event | No push-triggered run; open a PR or use manual dispatch for the full checks. |
| PR opened, synchronized or reopened | Full workflow; no head/base branch or path filter was added. The same trigger applies to fork PRs. |
| Push or merge to `main` | Full workflow, including the post-merge check. |
| Tag push or push to another branch | No push-triggered run. |
| Manual dispatch | Full workflow for the selected ref, subject to GitHub's existing dispatch availability and permissions. |

The jobs, four MySQL shards, two SQLite shards, frontend/browser checks, audits, concurrency behavior and required backend aggregate are unchanged. No test command, matrix partition, failure condition or permission was relaxed.

Static validation parses the workflow as YAML, checks the event cases above and compares every non-trigger top-level field against base commit `fa209fe`. `git diff --check` also passes. The initial Actions attempt was rejected before any test steps; resumed execution and final acceptance are recorded in the linked status checkpoint. This trigger change does not change the account's spending controls or replace runner capacity.

The change belongs to WP-01's reproducible CI work. Its acceptance is separate from the domain evidence required for each commerce increment. Reverting its trigger block restores the prior duplicate feature-branch scheduling behavior.
