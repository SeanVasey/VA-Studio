# Focused development feedback

T01 first increment adds `.github/workflows/focused.yml` alongside Foundation CI. This focused workflow does not replace full required merge checks or production/release acceptance. Its job names explicitly say **informative**. A focused success means only the recorded selection passed. The integrating B0 candidate also contains separately reviewed Foundation CI documentation-scope classification and acceptance routing; those companion changes are outside this focused runner's scope.

## Triggers and selections

Every feature-branch push, excluding `main` and tag pushes, runs the fixed **unit / SQLite** selection. A PR still triggers Foundation CI with the integrating candidate's independently defined scope policy; a push to an open PR can therefore produce both workflows. Compose a coherent candidate before opening/updating a ready PR to limit full-run frequency. The focused workflow adds no draft suppression, post-merge deduplication or schedule.

Once this workflow exists on the default branch, GitHub Actions → **Focused development feedback** → **Run workflow** can select a branch/ref, suite and engine. `workflow_dispatch` is also usable with a client that supports dispatch. This does not require the current connector to expose dispatch: feature pushes supply automatic unit feedback.

| Suite enum | Fixed scope | Engine |
| --- | --- | --- |
| `unit` | All 14 currently reviewed unit files: money/discount/pricing/promotion, licensing terms, contract text/renderer, upload/media/image evidence and Stripe gateway fixtures. | `sqlite` or `mysql`; pure cases do not establish database concurrency. |
| `media` | 11 reviewed upload/evidence/diagnostics/scanner/master/budget/private-root/site-image/archive files. | `sqlite` or `mysql` |
| `commerce` | 13 reviewed money/pricing/promotion/gateway/quote/hosted-checkout/webhook files, including selected independent-process MySQL races. It excludes heavy finalization/delivery/membership coverage. | `sqlite` or `mysql`; MySQL-only cases are reported as skipped on SQLite. |
| `frontend` | 17 reviewed frontend files, TypeScript/Vite production build and client-bundle secret scan. | Select `sqlite`; recorded engine is `none`. |
| `browser` | Storefront, operator, test checkout and owner-download specs in both configured Chromium/WebKit projects, using the existing isolated fixture/server wrapper. | `sqlite` only |

The exact paths live in `scripts/ci/focused-tests.py`, are printed before execution and retained in JSON evidence. Files are a bounded reviewed allowlist, not a changing-files heuristic or arbitrary user filter. Unknown/empty enums, invalid combinations, missing files, repository escapes and empty/duplicate/oversized lists fail closed. Add a new relevant test to that allowlist in a reviewed change, or run the necessary test separately and the full candidate gate; do not infer coverage for unselected features.

No dispatch value is interpolated into a shell command. Python validates environment enums and invokes fixed argument arrays with `shell=False`. PHP configurations include only selected whole files, stay beside `phpunit.xml`, and preserve its non-suite bootstrap/source/environment/extension settings. Both engines use disposable test databases. MySQL is started only when selected; no production secret is read. Checkout credentials are not persisted, permissions are `contents: read`, and there are no privileged PR events or deployment/provider changes.

## Local equivalent

From a complete Git checkout with its dependencies/tools installed:

```bash
FOCUSED_SUITE=media FOCUSED_ENGINE=sqlite python3 scripts/ci/focused-tests.py --plan
FOCUSED_SUITE=unit FOCUSED_ENGINE=sqlite python3 scripts/ci/focused-tests.py
FOCUSED_SUITE=commerce FOCUSED_ENGINE=mysql python3 scripts/ci/focused-tests.py
FOCUSED_SUITE=frontend FOCUSED_ENGINE=sqlite python3 scripts/ci/focused-tests.py
FOCUSED_SUITE=browser FOCUSED_ENGINE=sqlite python3 scripts/ci/focused-tests.py
python3 scripts/ci/test-focused-tests.py
```

`--plan` validates and lists a selection; it never runs tests or reports a pass. A MySQL local run requires a disposable `vaseyaudio_focused` database on loopback, root user and the fixed synthetic `ci-only-password`. PHP execution overrides database URL, environment, queue/mail/session/cache settings for these test runs. Browser mode retains the existing fresh SQLite/session/storage isolation and synthetic fixtures. Build Composer/npm dependencies first, prepare the application key for PHP, install media/PDF tools for feature selections, and install both Playwright browsers/build the client for browser mode. There is no forwarding of free-form test paths or PHPUnit/Playwright flags.

## Evidence and limits

The checked-out commit/tree, clean tracked-worktree status, selected engine/files, exact argument arrays, per-command exit status and actual JUnit case/skip/failure/error/assertion counts appear in logs, the job summary and seven-day `focused-*` artifacts. Commit staged/unstaged tracked changes first: the runner refuses dirty tracked source. The Git tree does not certify untracked files or installed dependency bytes; use a committed complete checkout with lockfile-installed dependencies, and do not interpret local focused evidence as full provenance. Frontend/browser reporters do not supply PHPUnit-style assertion counts, so those are zero rather than an invented count. Browser trace/screenshots remain failure evidence. A zero process status without a fresh nonempty passing report, or a report with only skipped cases, is rejected. Failed/timeout/setup jobs remain failures; a `planned` selection artifact is never proof that tests executed.

The runner removes stale result XML before execution and marks failure on missing commands, timeouts, malformed/empty reports or command failures. Frontend build/secret-scan failures invalidate that focused result even if tests passed. No PHPUnit warning setting, native browser isolation or page-error assertion is relaxed. Only a separately completed Foundation CI plus required independent review can satisfy final-candidate acceptance.

This implementation was independently source-reviewed and all 20 Python safeguards passed locally. After recovering compatible PHP 8.4.26 and exact-lock dependencies, the actual local unit selection passed 330 tests / 1,269 assertions with zero errors, failures or skips on integration tree `537607cc0bdae289ab0367158e2b0341fa15b407` (before the final documentation clarification). Real GitHub workflow outcomes must still be recorded on the integrating tested head. This document does not claim cloud event eligibility, runtime suite success or full T01 completion from static validation.

References: [planning strategy](../ci-development-strategy.md), [GitHub workflow syntax and input/service semantics](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax), [PHPUnit empty-suite/warning flags](https://docs.phpunit.de/en/12.5/textui.html), [Vitest reporters](https://vitest.dev/guide/reporters), [Playwright reporters](https://playwright.dev/docs/test-reporters).
