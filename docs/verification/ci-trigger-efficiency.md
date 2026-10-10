# Foundation CI trigger scope

## Current policy — October 6, 2026

Sean explicitly authorized development merges without full CI and instructed
agents to stop routine full MySQL runs. This section supersedes the historical
policies below. No account budget, payment, access or protection setting changes.

| Event | Current behavior |
| --- | --- |
| Feature/main/tag push | No full workflow and no automatic focused workflow. |
| PR opened, updated, reopened or made ready | Development preflight: conservative docs routing, frontend tests/build/secret scans/audit, PHP quality/audit and tooling safeguards. No database or browser matrix. |
| Manual Focused development feedback | Selected bounded suite/engine only; prefer local checks during iteration. |
| Manual Foundation CI | Complete existing runtime inventory for final verification; requires the exact reviewed `expected_sha`. |

Full verification lives in `.github/workflows/final-verification.yml`; focused
feedback lives in `.github/workflows/focused-feedback.yml`. The old `ci.yml` and
`focused.yml` workflow identities are retired and disabled, preventing old product
branches from resurrecting their automatic triggers. Do not re-enable them. New
PR preflight lives in `.github/workflows/preflight.yml` and has a distinct result
name. It never publishes a successful `backend` full-acceptance result.

The complete twenty-four MySQL shards, two SQLite shards, both operator browsers,
related browser, audits, receipt validation and acceptance rules remain intact.
Expensive final jobs wait for frontend and backend-quality success. A formatting,
build or audit failure therefore stops the matrix before runner allocation. This
adds preflight latency to a successful final run but avoids paying for doomed
candidates. Runtime proof reuse and post-merge provenance shortcuts remain off.

GitLab final acceptance also uses manual web/API requests only. Supply `EXPECTED_SHA` equal to the exact reviewed native commit. Automatic merge-request, push, schedule and parent-pipeline sources are refused; the preflight candidate guard compares that value with both `CI_COMMIT_SHA` and actual checkout HEAD before any dependent verification jobs. Historical receipt validation remains available without restoring historical triggers.

### Working cadence and coordination

1. Finish a coherent development batch with affected local checks. If an essential
   environment is unavailable, dispatch one necessary focused selection; record
   its source and actual results. Do not repeatedly use hosted CI as a debugger.
2. Review and merge the development candidate with focused evidence under Sean's
   instruction. Report untested database/browser conditions explicitly. Existing
   protection requirements must be respected, never bypassed or changed.
3. PR #13 is merged at `13d7474`; current development continues through the
   [parallel plan](../parallel-execution-20261006.md). Before each product push or
   merge, preserve main’s CI policy and reconcile workflow changes without losing
   product-specific tests. The old workflow
   identities stay disabled even before branch integration. Inspect any stacked
   PRs before merging; do not revive superseded product candidates.
4. At final integration, verify the desired ref still points to the reviewed SHA,
   then deliberately dispatch once:

   ```sh
   gh workflow run final-verification.yml --repo SeanVasey/VA-Studio --ref main -f expected_sha=EXACT_REVIEWED_40_CHARACTER_SHA
   ```

   The scope job rejects a mismatched checkout/ref/event before suites begin.
   A manual full run always uses full mode. Record its URL, actual tested source,
   failures and receipts. Repair locally/focused first; run additional full checks
   only when needed for a changed final candidate. No final pass is claimed here.

### Investigation and immediate containment

On October 6 the active run
[37521810874](https://github.com/SeanVasey/VA-Studio/actions/runs/37521810874),
head `121dd0832a814762e3e2cee11b9a54499489dd8d`, was cancelled under Sean's updated
instruction. Its eight MySQL, two SQLite and three browser jobs were still active.
The previous run 37516127420 was already cancelled; it was not restarted.
No broad run was dispatched for this investigation. Existing product work was
preserved in its original checkout; this patch uses an isolated clone of main.

Local validation covers workflow syntax, dispatch identity rejection, strict
preflight/full acceptance outcomes, unchanged full-suite commands and matrices,
focused selection, routing and database receipts. Hosted event execution is
recorded in the integrating PR; local checks alone do not prove an Actions pass.


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
