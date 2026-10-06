# Native order-reference response capture

The Chromium job in run `37414702061` passed 55 cases and failed only the new order-reference journey when Playwright called `found.json()`. Its real HTTP status and `no-store` checks had already passed. Artifact `11390494477` was inspected after verifying SHA-256 `51f0f2543f11b5ea2e87c102e27343f965f04535c64973866f886302d4ddbe33`.

The trace contains only the initial sign-in document request and the expected account document request after login. Lookup made no document navigation. The owned status and delivery requests completed with HTTP 200; the page rendered the focused result and original contract/master availability. Chromium's protocol body read nevertheless failed with `Network.getResponseBody: No data found for resource`. Both response bodies are absent from the trace. This establishes a response-observation failure; the underlying browser/stream lifetime cause is not proven.

The repair is confined to the new browser spec. It applies the passing account test's transparent native-fetch capture pattern to exactly the selected order's status and delivery GETs. Each request executes once. A clone's real bytes pass through an awaited Playwright binding before the identical native Response reaches the application. Actual Playwright request/response observations remain authoritative for URL, method, status and cache headers, and the capture must report no redirect. No request is intercepted or replayed, and no response or result is substituted.

Both original JSON assertions, unknown-reference rejection, real original-file metadata, keyboard/focus, private-storage and clear/sign-out checks remain. The no-history and GET-only request assertions remain; a main-frame navigation assertion now also proves lookup stayed on the account document. The download button is focused without activation, so the existing account case retains its exact two-stream-attempt census. Product code, fixtures, scanner policy and the passing account test are unchanged.

Validation on the isolated repair branch based on `310bf63`:

- `npm run typecheck`: passed.
- The lookup, history and account frontend suites: 97 tests passed.
- Playwright discovery: the repaired journey is selected for Chromium desktop and WebKit mobile.
- `git diff --check`: passed.

Native execution remains pending: this workspace has neither browser executable nor ClamAV. These checks do not establish hosted browser acceptance, and earlier successful cases do not prove this repaired source.
