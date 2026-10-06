# Synthetic browser transport and the real offline worker

The public offline worker preserves real online responses, including a server's 404. Two ordinary browser files deliberately substitute public documents through Playwright `page.route`: editorial video consent and session order discovery. Those synthetic documents must remain under the fixture's transport across navigation and reload. Their dedicated test contexts now use `serviceWorkers: 'block'`; the application's worker, global browser configuration and genuine public-offline journey remain unchanged.

## Original failure and causal evidence

[Foundation run 37512741491](https://github.com/SeanVasey/VA-Studio/actions/runs/37512741491), attempt 1, tested head `8ed43b771bf37d4e81bce6cd8acac512d3330cc7`, tree `d997c15ff67b7d4762666f1dd34b0a642675d37f` and merge `1bd9be95af5a68e9e0e96fd6ecc478f3964e58b9`. Chromium reported all 64 cases: 61 passed, three failed, no skips/retries/flakes. The inquiry retry and real offline recovery passed.

Both video traces show the first synthetic public document returning 200 through the spec's actual route fulfillment. The real worker then installs. The subsequent `?preview=1` navigation has a worker-owned fetch and a page document, both returning 404, without another synthetic route fulfillment. Laravel correctly refuses the unseeded synthetic URL. Both actual failure DOMs are 404 / Not Found; the private-preview disabled-message assertion was never reached in the intended synthetic document.

The order-history trace likewise shows an initially fulfilled synthetic document, worker installation, then a real worker-owned GET during reload. Its actual cookie diff changes only the encrypted XSRF/session values and renewed fractional expiration times; names, domain, path and security flags remain equal. The failed page displays the real empty catalog. This does not establish lost session ownership. The fixture no longer owns that document response, and a real Laravel session response renews cookies.

The complete Chromium log has SHA-256 `f7d8fdd27d87fe5678372a422120c84e4ed2759565748cddb46b25cc0aad4d4c`; its raw report review has SHA-256 `949ea421eed6451f0913853b1fe382ff21d585ff597dbedfc43c740c0d939725`. Original logs, three complete traces, both 404 contexts, report identities and the successful offline screenshot remain retained against that failed source/run.

Playwright documents that service workers can bypass `page.route` interception and recommends blocking workers in contexts that own mock transport: [network/service-worker guidance](https://playwright.dev/docs/network#missing-network-events-and-service-workers). The correction follows that boundary only in these two synthetic files. It adds no production exception, preview authorization, route, query handling or server cookie change.

## Scope and verification

The only executable delta is one per-file context declaration and explanatory comments in each of the two affected specs. Removing those exact additions reproduces every original source byte, including cookie equality, private-preview denial, provider-request count, keyboard/media behavior, storage checks and screenshots. No test identity, assertion, timeout, retry, workflow, dependency, database policy or native exclusion is removed. Other native journeys and `storefront-offline.spec.ts` retain real workers.

Local `npm run typecheck` passed. Actual `playwright test --list --reporter=json`, using the existing discovery-only import manifest, found exactly the same 128 identities in 37 files, 64 per engine. That listing executes no browser body or server. Installed native engines and genuine ClamAV are unavailable locally; fresh hosted execution is required.

The failed run's SQLite shard 2 separately passed 1,882 executions with 236 reviewed skips and 20,117 assertions; all six LicensingAdmin cases passed with 97 assertions. This is positive evidence for that original source only. A changed candidate needs every full Foundation gate, all ten current-run/current-attempt database receipts, actual MySQL outcomes for every SQLite skip, both native browser inventories, startup safeguards, visual review and strict terminal aggregation before expected-head merge. No execution or visual receipt is reusable as acceptance.
