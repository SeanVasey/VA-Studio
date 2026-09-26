# Foundation CI trigger scope

Status: source change with static verification, 2026-09-26. GitHub repository metadata confirms `main` is the default branch. This change does not establish an executed Actions pass.

An update to an open feature PR previously started two copies of Foundation CI: one from its branch push and another from the PR event. Restricting the push trigger to `main` retains complete PR checks and the post-merge run without that duplicate feature-branch run. Explicit `workflow_dispatch` permits a deliberate manual run.

| Event | Foundation CI behavior |
| --- | --- |
| Feature-branch push without a PR event | No push-triggered run; open a PR or use manual dispatch for the full checks. |
| PR opened, synchronized or reopened | Full workflow; no head/base branch or path filter was added. The same trigger applies to fork PRs. |
| Push or merge to `main` | Full workflow, including the post-merge check. |
| Tag push or push to another branch | No push-triggered run. |
| Manual dispatch | Full workflow for the selected ref, subject to GitHub's existing dispatch availability and permissions. |

The jobs, four MySQL shards, two SQLite shards, frontend/browser checks, audits, concurrency behavior and required backend aggregate are unchanged. No test command, matrix partition, failure condition or permission was relaxed.

Static validation parses the workflow as YAML, checks the event cases above and compares every non-trigger top-level field against base commit `fa209fe`. `git diff --check` also passes. Actual GitHub Actions execution remains pending; this source change neither restores consumed runner minutes nor changes the account's spending controls. Available runner capacity is still required for remote acceptance.

The change belongs to WP-01's reproducible CI work. Review and merge it as a separate focused change; do not treat it as acceptance of pending commerce work. Reverting its trigger block restores the prior duplicate feature-branch scheduling behavior.
