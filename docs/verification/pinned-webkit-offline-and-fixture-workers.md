# Pinned WebKit offline initialization and synthetic worker boundaries

This WP-01 browser correction starts from local `93729bf5fe3dd26699637963e4fee5ee0d87c1c2`. It extends the existing per-file synthetic transport boundary and backports the exact upstream WebKit initialization fix to the pinned test SDK. Application workers, production code, package versions, lockfiles, browser criteria, timeouts, retries and workflows remain unchanged.

## Source and observed failure

The predecessor [Foundation run 37512741491](https://github.com/SeanVasey/VA-Studio/actions/runs/37512741491), attempt 1, tested remote head `8ed43b771bf37d4e81bce6cd8acac512d3330cc7`, local `7ebbf011e1d41785818a02914a2d3667642deedb`, tree `d997c15ff67b7d4762666f1dd34b0a642675d37f`, merge `1bd9be95af5a68e9e0e96fd6ecc478f3964e58b9`. WebKit reported 48 passed, 15 failed and one existing skip across all 64 identities. Offline ordinal 49 failed in 1.6 seconds at the reload after `setOffline(true)`, with `WebKit encountered an internal error`; screenshot, retry and recovery criteria were not reached. Its complete job log SHA-256 is `bd91e0e93d6f51175381cfc4f34e67b3b7eb978f9a839478b09aca4b01f23c14`; independent chronology SHA-256 is `9dc0374309eaafddfefb05eff7036107e6ff63bc2436009c7920d985fa40e269`. The 71,764,203-byte raw WebKit archive exceeds the supported 32 MiB download ceiling, so no WebKit trace or screenshot is claimed. All original failed evidence remains retained.

The fresh parent [run 37516127420](https://github.com/SeanVasey/VA-Studio/actions/runs/37516127420), attempt 1, binds this local base to remote `050de0c4d5541ad5d4b69d7325b2b56e23e0290a`, tree `f3e1cf2694218c6ea51a11afd1bcde9291f5ee31` and merge `446b69e600bb77c3b8d27a6b101dbfab27d41732`. Chromium passed 62/64, with two failures and no skips. The inquiry still reached status 200 and timed out awaiting `linkedReplay.finished()` at line 161; a separate license recovery failed at its notification-response wait. The two previously corrected video cases, owned-order history, first bulk-metadata login and Chromium offline journey passed. Complete log SHA-256 is `211f83603a0391a522e9e77d7a7ec1b0fede7143428ae1b41b1a49f412096b76`; independent report SHA-256 is `629600059bd8f61e91f92822c6ff3974071759ac9da0d19b116b80ab8b64bbea`. The 67,385,674-byte raw archive exceeds the same ceiling. The worker boundary preserves the completion/status criteria and removes the unsupported interception condition; the supported log alone does not establish the inquiry stall's cause. License notification diagnosis belongs to a separate lane.

Microsoft's [PR 42894](https://github.com/microsoft/playwright/pull/42894), verified through the official [files endpoint](https://api.github.com/repos/microsoft/playwright/pulls/42894/files?per_page=100), merged October 5 at 18:15:11 UTC as `ea75b9f32ca4120efdfaf8f5b8cfd292f548f197`. The upstream SDK delta changes only the WKPage condition to `contextOptions.offline !== undefined` and its protocol payload to `offline: contextOptions.offline`. Upstream adds service-worker offline-navigation cases with real workers, explicit offline state and subsequent online recovery. The bundled 1.63.0 SDK contains the exact old two-line fragment once.

An initialization-only observation executes that actual extracted old fragment with `offline: false`: it sends no protocol command and fails the expected explicit-false assertion. `browser-worker-webkit-evidence/original-false-state-red.{mjs,log}` retains the intended exit 1. Corrected-fragment tests cover false, true and undefined. These observations and the official fix justify the precise backport; fresh hosted execution must establish its effect on this application's failed native journey. They supply no common-cause attribution for the other WebKit failures or the separate current-run administrator login.

## Exact guarded backport

`scripts/ci/apply-playwright-webkit-offline-backport.mjs` is imported only by the two native test wrappers. Both call it quietly after their existing prerequisite checks and before creating a private fixture directory, invoking PHP bootstrap or starting Playwright. Ordinary and related startup/preparation/CLI bounds are unchanged. A standalone CLI accepts no arguments and emits only a public pinned receipt.

Before any write the script verifies the project and lockfile pin, all three installed/locked SDK identities (`@playwright/test`, `playwright`, `playwright-core`), their dependency chain and all SDK paths. Shared node_modules, package, CLI and bundle symlinks are refused. The original bundle must be exactly 3,477,183 bytes with SHA-256 `549070af3acabb3efcc4f55bfe6210f9f7c2fcf633cf7eaa59bfe60719969171`. Only the exact unique two-line fragment is replaced. The result must be exactly 3,477,215 bytes with SHA-256 `4f5647c5a1fa104ff4536ec5923353d2a034d6d2f60050ed53b7633c3dadd67b`. Atomic replacement preserves the file mode and leaves any external hard-linked original untouched. Idempotence accepts only the known patched digest after the same identity/path checks. Unknown versions, bytes, malformed metadata, partial patches or arguments fail closed with one fixed refusal. A future SDK upgrade must remove or reassess this backport explicitly.

Local verification used standalone copies of the three SDK packages and Composer dependencies in the isolated worktree. Other frontend dependencies remain linked. The shared source SDK retained its original digest throughout. Script tests create independent copies of actual pinned metadata and full bundled bytes; they never patch the installed source. The new startup method invokes that suite, while removing its exact addition restores every original byte of the eleven existing startup methods.

## Six synthetic contexts

The existing two-file boundary for video consent and owned order history remains intact. Six additional files now declare only `test.use({ serviceWorkers: 'block' })` with a scope comment:

- `contact-inquiry.spec.ts` owns synthetic request responses across navigation and reload.
- `storefront.spec.ts` and `player-controls.spec.ts` own synthetic public documents and native audio fixtures.
- `order-preparation.spec.ts` owns synthetic storefront, quote, order and checkout transport.
- `editorial-content.spec.ts` uses a synthetic public storefront alongside actual staff publication.
- `inquiry-conversation.spec.ts` intercepts the deliberately lost acknowledgement alongside actual native retry transport and retained server graphs.

Playwright's [service-worker routing guidance](https://playwright.dev/docs/network#missing-network-events-and-service-workers) explains why page-owned synthetic routes need this context boundary. Removing those six exact additions restores every original source byte: assertions, request bodies, status/DTO checks, real persistence, privacy criteria and limits are preserved. `storefront-offline.spec.ts`, global configuration and the actual application worker are byte-identical to parent and retain enabled real workers.

## Executed checks and remaining gate

The backport suite passed 33 cases with no failure, cancellation or skip. It covers exact full-byte replacement, false/true/undefined state, write-free idempotence, every version identity, unknown/duplicated/partial bytes, SDK symlinks, external hard links, malformed/missing metadata, the actual CLI and ordinary-wrapper refusal before PHP/private setup. Typecheck and Node syntax passed. Real Playwright discovery using the existing import-only manifest found the exact same 128 identities in 37 files, 64 per engine; it ran no test body or server.

The available startup selection passed eleven methods in 5.736 seconds: all ten locally executable original methods plus the new backport method. It exercises actual fresh SQLite bootstrap, provisioning, bounded redacted PHP diagnosis and ordinary-runner cleanup. The remaining original paid-customer bootstrap requires genuine `/usr/bin/clamscan`, absent locally. No committed skip or substitute scanner was added; the full twelve-method startup suite remains required on the hosted runner. Native browser executables are unavailable locally. No local native execution, screenshot, visual acceptance or repair of the failed hosted run is claimed.

```sh
node --test scripts/ci/apply-playwright-webkit-offline-backport.test.mjs
node scripts/ci/apply-playwright-webkit-offline-backport.mjs
npm run typecheck
VASEY_BROWSER_DIRECTORY=/workspace/scratch/b527c7e94bd7/discovery-only VASEY_BROWSER_PASSWORD=DISCOVERY-ONLY node node_modules/@playwright/test/cli.js test --list --reporter=json
python3 scripts/ci/test-related-browser-stage.py RelatedBrowserStageSafeguards BrowserBootstrapFixtures.test_related_bootstrap_preserves_empty_commerce_without_customer_policy BrowserBootstrapFixtures.test_partial_or_malformed_stage_identity_is_refused_before_migration BrowserBootstrapFixtures.test_bootstrap_failure_diagnostic_excludes_private_values_and_unsafe_class_names BrowserBootstrapFixtures.test_ordinary_runner_keeps_safe_refusal_and_cleans_up_its_actual_directory
```

`browser-worker-webkit-evidence/source-preservation.json` records exact subtraction/protected-byte equality. `tested-source.json` binds the final commit, upstream evidence, original/final SDK digests and executed receipts. Independent exact-commit review and every fresh full Foundation gate, including both ordinary engines, genuine PWA/related journeys and all startup methods, remain required before merge.
