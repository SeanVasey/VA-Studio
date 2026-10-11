# Independent review: 2fd7ccb (parent f57e725), harness/foundation2-fixes

**DECISION: APPROVE WITH CONDITIONS.** The code is correct. The conditions are about documentation and evidence. None of them blocks the hosted Foundation run.

## Findings

1. **Diagnosis (a) is confirmed. Severity: info.**
   - The agent's `repro.cjs` on WebKit 2359:
     - with workers allowed: no request was routed, the server received `/media/x`, `play` was rejected with `NotSupportedError` and `currentTime` stayed 0.
     - with workers blocked: the route was hit and `currentTime` reached 2.47.
   - Traces `tr/pi` and `tr/tc` show `storefront-worker.js` loaded and `/media/synthetic-browser-preview` failing with status -1/0, not fulfilled by the route.
   - Neither spec asserts anything that depends on the worker:
     - The manifest and icons are fetched with `page.request`, which the worker does not handle (public-install.spec.ts:72-85).
     - The install guidance is checked only through the DOM.
     - Neither spec checks a worker controller or the cache.
   - Playwright's `block` stub resolves `undefined`, and `registerStorefrontOfflineRecovery` tolerates that (storefront-offline.ts:6-15), so no page error results.
   - Chromium keeps `allow`.
   - The only coverage lost is WebKit running these two journeys with the real worker, which `page.route` cannot test anyway.
   - A function-valued `serviceWorkers` option is valid: `Fixtures`/`TestFixtureValue` in playwright/types/test.d.ts:6863-6868, and tsc passes.

2. **The forwarder in fix (b) works. Severity: info.**
   - I ran the committed `unpluggableOrigin` verbatim (types stripped) against WebKit 2359 five times. The upstream used a 60-second keep-alive, and 6 sockets were open at unplug.
   - All 5 runs passed every step:
     - the offline page was served from the worker cache;
     - `fetch('/api/catalog')` gave a network failure;
     - Retry stayed on the offline page;
     - re-plugging on the same port recovered the original URL;
     - a double unplug was harmless.
   - With `setOffline` instead, a reload still fails with "internal error", even with the backported line present.
   - Destroying the sockets and closing the listener produced a real failure each time; WebKit never reused a connection.
   - A truncation stress test (256 KB to 8 MB responses, slow reader, upstream closing after the response) lost no bytes in 15 of 15 transfers.
   - `afterEach` and `unplug` are idempotent.
   - PHP's cli-server sends `Connection: close`, so no keep-alive state survives on the app side.

3. **The re-plug can lose its port. Severity: low.**
   - `plug()` relistens on the same ephemeral port (storefront-offline.spec.ts:22). Another socket could take that port in the short window, which gives `EADDRINUSE`.
   - TIME_WAIT on the closed sockets usually prevents this, and if it happens the test fails visibly rather than passing falsely. An optional fix is to retry `listen` a few times.

4. **The different origin is safe. Severity: info.**
   - Port 8173 comes from the merged `project.use` (playwright/lib/common/index.js:580).
   - `server.php:6-12` checks only environment variables, not the Host header. The app has no `forceRootUrl` or TrustHosts. `app.url` only feeds meta and canonical tags (StorefrontMetadata.php:76) that the browser does not load.
   - Cookies are host-only and do not depend on the port, and the spec makes no POST, so CSRF does not apply.
   - The worker scope is the forwarder origin.
   - Cache keys are absolute URLs that include the port, but `cacheKeys()` compares only the pathname (line 51). `toHaveURL` uses suffix regexes, and the `href` check compares the raw attribute.
   - `offline.html` does not use `navigator.onLine`.
   - Every assertion proves the same behaviour on WebKit. A real origin outage is, if anything, a stronger test.

5. **The timeout figures are slightly off. Severity: low (the wording needs correcting).**
   - From the job 113946653391 log:
     - the last progress line is 3294/3725, which is **88%**, at 18:39:45, about 59 minutes after the tests started at 17:40:37;
     - there are no F or E markers;
     - the shard's estimate line says 37m07s.
   - That projects to about 67 minutes for the tests, or about 68 minutes for the job. The commit message and workflow comment say "about 92%" and "about 65 minutes"; they should be corrected.
   - 100 minutes is still justified, with about 45% headroom.
   - The shards are unbalanced (48 minutes against about 67), so the SQLite timings should be regenerated as a follow-up.
   - No self-test pins 60. Results:

     | Self-test | Tests | Result |
     |---|---|---|
     | cadence | 14 | OK |
     | database-receipts | 59 | OK |
     | gitlab-database-receipts | 31 | OK |
     | ci-scope | 27 | OK |

6. **Typecheck passes. Severity: info.** `npx tsc --noEmit -p .` on Node 24.21 exits 0 at 2fd7ccb, and tsconfig includes `tests/browser`.

7. **Documentation is missing. Severity: low (a condition).**
   - The earlier CI-limit commits (3259c21, 5b42498) updated CHANGELOG.md; this commit does not.
   - `docs/verification/pinned-webkit-offline-and-fixture-workers.md` still presents `setOffline` plus the backport as the WebKit offline path.
   - The WebKit offline journey no longer depends on the backport. Record that and the forwarder approach in the doc or handoff ledger.

8. **Untested condition. Severity: residual.** I could not run the full app harness here, so no full app WebKit run was done. The hosted Foundation run on the exact SHA remains the acceptance evidence.

## Conditions

1. Correct the 92% / 65-minute wording.
2. Add a CHANGELOG entry and the verification-doc note.
3. Accept Foundation evidence only from a run on the exact reviewed SHA.
