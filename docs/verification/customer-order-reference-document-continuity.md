# Order-reference document continuity

Run `37416246767` on published `3fd8b166efc276a9611c7276ba4c431f377aaef6` (local `096b4e762b985cbac0b6e6df596dc8218e19a414`, tree `f0f412c5561e7280fe031fe022a719026e8dd2ce`) passed all 56 Chromium cases. WebKit passed 54 cases, retained one existing desktop-only skip and failed only the newly added navigation-event census. Both real captured status/delivery bodies and their metadata, UI, keyboard, GET-only and private-storage assertions passed before that failure. The later clear/sign-out assertions were not reached in this WebKit case.

Artifact `11391407760` was inspected after independently verifying SHA-256 `906238a6dba7d0c46c9118b28a9fe19417199966e59f9ff4d1d2215a576268ac`. Its archived spec matches the frozen source. The trace has exactly two document GETs: sign-in at `05:06:57.969Z` and the expected account document at `05:06:59.016Z`. Missing-reference, owned status and delivery GETs follow at `05:06:59.425Z`, `05:06:59.552Z` and `05:06:59.746Z`; there is no later HTTP document navigation. The final frame-event array contains the account URL, but the callback's time and document-change type were not recorded. Its exact origin is therefore unproven.

The pinned Playwright implementation reports same-document history updates through public frame-navigation events. The installed Inertia implementation also saves scroll/history state using `history.replaceState`. These explain why the broad event is insufficient evidence of a reload; the trace does not prove which history action produced this particular event.

The correction measures the intended invariant directly. After login it records actual main-frame navigation requests, waits for the visible lookup region, and retains a handle to that loaded `Document`. Before clearing or signing out, it requires zero document-navigation requests and the identical document object. An actual replacement destroys the retained execution context and fails the handle check; an attempted replacement request fails the request census. Same-document history updates do not replace the document.

All native-response capture code, original body/metadata assertions, private-state checks, clear/sign-out checks, GET-only and no-history assertions remain. The account download case and its exact original-byte/hash/attempt census remain unchanged. There are no product, fixture, timeout, retry, scanner, database, exclusion or workflow changes, and no delays, response substitutions or page-error filters.

Validation on the isolated correction based on `096b4e7`:

- `npm run typecheck`: passed, including browser TypeScript.
- `VASEY_BROWSER_DIRECTORY=/tmp/vasey-lookup-continuity-discovery node node_modules/@playwright/test/cli.js test tests/browser/customer-order-reference.spec.ts --list`: two cases discovered, one per engine.
- `git diff --check`: passed.

Native rendered execution requires the next hosted run; local browser executables and ClamAV remain unavailable. Current-run database results and earlier native successes do not accept this corrected successor. Full current-source gates, independent review and all ten database receipts remain required before merge.
