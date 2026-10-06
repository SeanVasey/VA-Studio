# Focused development feedback

The manual `.github/workflows/focused-feedback.yml` workflow supplies bounded
feedback when a needed environment is unavailable locally. A success covers only
the recorded selection. Under Sean's October 6 instruction, focused evidence may
support a development merge; complete final verification remains separate.

## Triggers and selections

There are no push, PR, schedule or bot-specific triggers. The former `focused.yml`
identity is disabled so stale branches cannot launch redundant unit feedback.
Select **Focused development feedback → Run workflow**, the reviewed branch/ref,
suite and engine only when necessary. Avoid duplicate requests for the same source
and selection; use local checks and batch changes while iterating. Routine PRs
receive the distinct [development preflight](ci-trigger-efficiency.md).

| Suite enum | Fixed scope | Engine |
| --- | --- | --- |
| `unit` | 19 reviewed files: synthetic migration planning, money/pricing, licensing terms, contract rendering, media/identifier guards and Stripe gateways. | `sqlite` or `mysql`; pure cases do not establish database concurrency. |
| `media` | 19 upload, resumable HTTP/session, evidence, diagnostics, scanner, budget, private-root, image and archive files. | `sqlite` or `mysql` |
| `commerce` | 30 pricing, gateway, quote, checkout, webhook, financial-observation, ownership, restore and migration files, including selected independent-process races. Heavy finalization/delivery/membership coverage remains separate. | `sqlite` or `mysql`; MySQL-only cases are reported as skipped on SQLite. |
| `financial` | Nine fixed unpaid-release, retained financial-observation/exception, payment-processing and finalization files. | `sqlite` or `mysql` |
| `customer` | Eight files: five account-access, session HTTP, ownership/commerce, migration and native-withdrawal files plus three existing owned-order history and owner-delivery HTTP/projection regression files. | `sqlite` or `mysql` |
| `operator` | 32 foundation/setup, persisted-authority/MFA, licensing, metadata/presets/bulk edits, private review, publication and rights-writer files. | `sqlite` or `mysql`; physical authenticator/device acceptance remains separate. |
| `publication` | Seven reviewed publication apply, manifest/editor and guard files, including native concurrency. | `sqlite` or `mysql` |
| `track-concurrency` | Five metadata, stems recording/preview and media-writer authority files. | `sqlite` or `mysql` |
| `seller` | 26 inquiry, preview embed, editorial/related content, disclosure and private collection/album draft authoring/version/migration/concurrency files. | `sqlite` or `mysql` |
| `frontend` | 24 reviewed frontend files including customer sign-in/library, TypeScript/Vite production build and client-bundle secret scan. | Select `sqlite`; recorded engine is `none`. |
| `browser` | 23 reviewed specs including the fresh-session customer library and exact original downloads, plus existing storefront, operator, checkout/delivery/history, player, inquiry, metadata, publication, editorial and resumable-upload journeys. Both configured engines use the isolated fixture/server wrapper. | `sqlite` only |

The exact paths live in `scripts/ci/focused-tests.py`, are printed before execution and retained in JSON evidence. Files are a bounded reviewed allowlist, not a changing-files heuristic or arbitrary user filter. Unknown/empty enums, invalid combinations, missing files, repository escapes and empty/duplicate/oversized lists fail closed. Add a new relevant test to that allowlist in a reviewed change, or run the necessary test separately and the full candidate gate; do not infer coverage for unselected features.

The fixed cap remains **32 files per selection**. Customer work has its own selection because the existing commerce/operator groups approach that cap. The SQLite receipt policy adds only four exact native method identities: one customer method with nine datasets and three product-draft methods with nine total cases. All 18 must execute on MySQL; no class-wide, wildcard or shared-case exception is added.

No dispatch value is interpolated into a shell command. Python validates environment enums and invokes fixed argument arrays with `shell=False`. PHP configurations include only selected whole files, stay beside `phpunit.xml`, and preserve its non-suite bootstrap/source/environment/extension settings. Both engines use disposable test databases. MySQL is started only when selected; no production secret is read. Checkout credentials are not persisted, permissions are `contents: read`, and there are no privileged PR events or deployment/provider changes.

## Local equivalent

From a complete Git checkout with its dependencies/tools installed:

```bash
FOCUSED_SUITE=media FOCUSED_ENGINE=sqlite python3 scripts/ci/focused-tests.py --plan
FOCUSED_SUITE=unit FOCUSED_ENGINE=sqlite python3 scripts/ci/focused-tests.py
FOCUSED_SUITE=commerce FOCUSED_ENGINE=mysql python3 scripts/ci/focused-tests.py
FOCUSED_SUITE=customer FOCUSED_ENGINE=mysql python3 scripts/ci/focused-tests.py
FOCUSED_SUITE=operator FOCUSED_ENGINE=sqlite python3 scripts/ci/focused-tests.py
FOCUSED_SUITE=seller FOCUSED_ENGINE=sqlite python3 scripts/ci/focused-tests.py
FOCUSED_SUITE=frontend FOCUSED_ENGINE=sqlite python3 scripts/ci/focused-tests.py
FOCUSED_SUITE=browser FOCUSED_ENGINE=sqlite python3 scripts/ci/focused-tests.py
python3 scripts/ci/test-focused-tests.py
```

`--plan` validates and lists a selection; it never runs tests or reports a pass. A MySQL local run requires a disposable `vaseyaudio_focused` database on loopback, root user and the fixed synthetic `ci-only-password`. PHP execution overrides database URL, environment, queue/mail/session/cache settings for these test runs. Browser mode retains the existing fresh SQLite/session/storage isolation and synthetic fixtures. Build Composer/npm dependencies first, prepare the application key for PHP, install media/PDF tools for feature selections, and install both Playwright browsers/build the client for browser mode. There is no forwarding of free-form test paths or PHPUnit/Playwright flags.

## Evidence and limits

The checked-out commit/tree, clean tracked-worktree status, selected engine/files, exact argument arrays, per-command exit status and actual JUnit case/skip/failure/error/assertion counts appear in logs, the job summary and seven-day `focused-*` artifacts. Commit staged/unstaged tracked changes first: the runner refuses dirty tracked source. The Git tree does not certify untracked files or installed dependency bytes; use a committed complete checkout with lockfile-installed dependencies, and do not interpret local focused evidence as full provenance. Frontend/browser reporters do not supply PHPUnit-style assertion counts, so those are zero rather than an invented count. Browser trace/screenshots remain failure evidence. A zero process status without a fresh nonempty passing report, or a report with only skipped cases, is rejected. Failed/timeout/setup jobs remain failures; a `planned` selection artifact is never proof that tests executed.

The runner removes stale result XML before execution and marks failure on missing commands, timeouts, malformed/empty reports or command failures. Frontend build/secret-scan failures invalidate that focused result even if tests passed. No PHPUnit warning setting, native browser isolation or page-error assertion is relaxed. Only a separately completed Foundation CI plus required independent review can satisfy final-candidate acceptance.

The original implementation was independently source-reviewed and its 20 Python safeguards passed locally. After recovering compatible PHP 8.4.26 and exact-lock dependencies, its local unit selection passed 330 tests / 1,269 assertions with zero errors, failures or skips on integration tree `537607cc0bdae289ab0367158e2b0341fa15b407`. The customer/product registration increment passes **31 selector safeguards**, including exact workflow-enum matching, the unchanged cap and exact new skip identities. These policy checks do not execute the selected product suites. Real GitHub workflow outcomes must still be recorded on the integrating tested head; neither checkpoint closes T01 or establishes full acceptance.

References: [planning strategy](../ci-development-strategy.md), [GitHub workflow syntax and input/service semantics](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax), [PHPUnit empty-suite/warning flags](https://docs.phpunit.de/en/12.5/textui.html), [Vitest reporters](https://vitest.dev/guide/reporters), [Playwright reporters](https://playwright.dev/docs/test-reporters).

Private inquiry conversations add five fixed seller PHP files plus the visitor frontend/browser journey. The seller selection remains within the unchanged 32-file ceiling. Only the eight exact MySQL inquiry/actor wait cases are allowed to skip on SQLite; MySQL must execute them.

Verified-unpaid release has a separate `financial` selection so commerce remains below the unchanged 32-file ceiling. It adds the real operator browser journey and only two exact native method identities (four MySQL race cases) to the SQLite exception policy. No existing coverage or required acceptance gate is removed.
