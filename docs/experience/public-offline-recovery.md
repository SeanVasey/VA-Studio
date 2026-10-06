# Public navigation connection recovery

Bounded T33/WP-05 child, October 6, 2026. Implementation begins from local `4a59cc3`, equivalent to PR #13 head `53efb369`. The reviewed child is composed into the next coherent PR #13 update; the preceding candidate's receipts do not apply to this changed source.

Owned files: `resources/js/lib/storefront-offline.ts`, its registration in `resources/js/app.tsx`, `public/storefront-worker.js`, `public/offline.html`, dedicated frontend/browser regressions and this record. Existing brand bytes, domain services, routes, private responses, player ownership and CI policy remain unchanged.

## Contract and acceptance

Top-level secure public storefront/editorial pages register the offline worker at startup and after Inertia navigation without delaying the application. Unsupported, embedded, private/admin and insecure contexts do not initiate registration. Registration failure must not break the online store.

The worker stores only the fixed offline HTML, approved theme CSS, original navbar PNG and the two exact licensed font files needed by that page. Installation fetches anonymously and rejects redirects, unexpected types or incomplete assets. Cache failures refuse installation. Updates wait for existing clients; activation removes only this feature's old cache names. No dynamic page/API, catalog, media, account, order, quote, inquiry, token, contract or attachment response is stored; no background write, replay or sync exists.

Only same-origin GET document navigation to the explicitly public routes receives the static fallback on a network exception: home, one track, about/contact roots and blog/video list or slug. The overlapping private `/contact/inquiries` path is excluded. Real HTTP errors and redirects are returned unchanged. The public fallback bypasses Inertia/API fetches, private routes, iframe/embed navigation, writes, cross-origin URLs and Range requests. Query-bearing fixed assets also receive ordinary network behavior. Missing/evicted fallback fails as a network error. The page keeps the requested address, truthfully says the store could not be reached, and offers explicit retry at that address or return home, including visible keyboard focus and responsive reflow.

## Required verification

- Execute the actual worker script in a controlled cache/network harness, including private bypass, unchanged HTTP failures, anonymous install, rejected assets, partial-cache failure, old-cache isolation and eviction.
- Exercise registration denial/failure and public-path parity; run composed frontend, typecheck/build and client scan.
- Define native Chromium/WebKit journeys for real installation, offline navigation, branded assets/fonts, exact retry URL, private/cache isolation and return online. Definitions/discovery do not prove execution.
- Independently review the committed cache/privacy/lifecycle boundary. Fresh inclusive hosted acceptance remains required before integration.

Install prompts, a web-app manifest, approved square launcher icons, offline audio/catalog/purchases, physical-device playback and broader T33 acceptance remain separate work. The current approved identity is a 420 × 100 navbar raster; it is retained exactly and is not redrawn into a launcher icon.

Implementation follows the platform's [fetch event contract](https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerGlobalScope/fetch_event), [registration contract](https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerContainer/register) and [Cache API behavior](https://developer.mozilla.org/en-US/docs/Web/API/Cache). These references support API choices, not this application's execution evidence.

## Actual results

| Check | Actual result |
| --- | --- |
| `npm test` on final application/worker bytes | 845 passed across 36 files: every preceding 768 case plus 77 new registration/actual-worker cases. The worker executes in a controlled cache/network VM, not a browser. |
| `npm run build` and `python3 scripts/ci/scan-client-bundle.py` | Typecheck/build passed; six output files scanned with no prohibited hits. Existing 500 kB chunk warning remains. |
| `npm run build:preview` and preview scan at `dist/design-preview` | Passed; four output files scanned with no prohibited hits. Preview component bytes are unchanged by the final app-entry wiring. |
| `node --check public/storefront-worker.js`; `git diff --check` | Passed. |
| Self-contained loopback recovered PHP static-server probe | All five assets returned 200, expected MIME, exact source bytes and no Set-Cookie; 87,771 total bytes. It executes no Laravel or browser worker. |
| Actual `playwright test --list --reporter=json` with discovery-only manifest | 128 identities / 37 files, 64 per engine; two new native definitions. This executes no browser journey. At child `e92742b`, all preceding spec bytes are unchanged. The composed sibling corrects inquiry retry transport and the integration adds a successful offline screenshot definition without changing any identity or criterion. |
| Original identity ledger | All six original image assets retain their approved hashes. No image is redrawn or altered. |

The initial focused run passed 70/72 and exposed both registration and worker admission of the private `/contact/inquiries` path. The narrowed public route forms now refuse that overlap; original failure output is retained. An initial split-session HTTP probe could not reach its separate server; the corrected self-contained server/probe verified the actual bytes and MIME above. Neither harness issue is hidden as application acceptance. JSDOM retains its existing document-navigation warnings without suppression.

`/root/composition_review` independently approved committed child `e92742b5c7b77f475ab1fe375b414fa6f4a3d393`, tree `1b8754b717165c80dbc44b07b825dd96f56d2af2`, with no blocking cache/privacy/lifecycle findings. The final composition preserves its application, worker, asset and frontend regression bytes. Successful native execution and visual review of the newly defined screenshots remain required. Local browser engines and genuine ClamAV are unavailable. Existing full-CI autodiscovery includes the new files; focused-selector caps/choices and every prior exclusion, timeout, retry, workflow, dependency and PHP identity are unchanged. Broader update-lifecycle, target-host and physical-device acceptance remain open. Six parent groups remain accepted and 34 open.
