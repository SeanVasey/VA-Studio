# Native inquiry retry observation

This correction starts at local `4a59cc32fa2fd682cc9ebdf4934665bfcb749b5a`, tree `c6c36ee640e64843edf17d3ecc5fbe7f5030ab23`. That tree is the source tested by PR #13 Foundation run [37507742206](https://github.com/SeanVasey/VA-Studio/actions/runs/37507742206), attempt 1: head `53efb369444b6657c7399247b0d3399767af5047`, test merge `a905d34eed072e0d320fdc51656dee532ab95f39`, with ordered main `387fdeb…` then candidate-head parents. The failure below remains evidence about that original source, not acceptance of this correction.

## Observed failure and limits

Chromium job `112420686288` completed **62 passed, 1 failed, zero skipped**. The only failed case is `tests/browser/inquiry-conversation.spec.ts:7`, **visitor reads an in-app staff reply, retries one real follow-up and retains an archived conversation**. It exceeded its existing 120,000 ms limit at original line `157:116`, awaiting `linkedReplay.finished()` after the response arrived and its HTTP 200 assertion passed. The subsequent saved-heading, receipt equality and retained-order graph assertions were not reached. The three later empty-catalog cases passed; the previous synthetic publication leak is a separate, corrected failure.

The independently checked complete supported log is `acceptance-evidence/run-37507742206/job-112420686288.log`, 1,155,933 bytes, SHA-256 `407b531f5d5fc786435fc989faaab04fede45aa6437b0eac5f469bb87f434be8`. Failure details occupy lines 10979–10992. Original raw artifact `11435515187` is 42,184,406 bytes, above the supported downloader's 32 MiB limit; its trace, failure screenshots and error context were not retrieved. No claim about their contents is made.

| Complete-log event | UTC timestamp |
| --- | --- |
| Preceding case 24 passed | 18:06:14.0448811 |
| Last server connection closed before the inquiry timeout | 18:06:47.9267567 |
| Inquiry case 25 reported its two-minute timeout | 18:08:15.7313296 |

Server activity stopped approximately 87.8 seconds before the timeout report. Together with the exact await location, this supports a stalled completion observation rather than an inference that the journey simply needed a larger budget. Dynamic PHP connection log lines do not identify request URLs or the instant this await began, so they cannot establish its exact elapsed duration or the browser's final network event.

In the original spec, both retries made their real server request through `route.fetch()`, decoded that API response, and delivered it to the browser through `route.fulfill({ response })`. The order retry's route handler asserted server HTTP 200, no-store and the exact saved/receipt DTO before fulfillment. The browser-side response then arrived with HTTP 200; its completion promise did not settle before the test limit. This identifies the intercepted retry completion boundary, not the underlying cause. Consuming an API response before forwarding it does not by itself establish incorrect Playwright usage.

The inspected locked Playwright **1.63.0** client implementation in `playwright-core/lib/coreBundle.js` has `Response.finished()` await a per-response promise under the target-close scope. `_onRequestFinished` resolves it to null; `_onRequestFailed` records failure text and emits the failure event without resolving that response promise. A browser-side request failure after response headers is therefore compatible with this wait, even if the API request already completed. The unavailable trace prevents confirming that event or attributing it to fulfillment, application stream cancellation, server transport or a browser mechanism. The local bundle SHA-256 is `549070af3acabb3efcc4f55bfe6210f9f7c2fcf633cf7eaa59bfe60719969171`.

## Correction and retained criteria

Only the first committing POST in each retry exercise uses `route.fetch()` followed by `route.abort('failed')`. The real service must return 201 before that deliberate loss of acknowledgement. The route records both browser request bodies, but the second POST uses `route.continue()` with no URL, method, header or body override. The actual browser receives the server's idempotent HTTP 200 response through ordinary transport, and the test reads its message ID or receipt into the existing comparison arrays.

Both `finished() === null` assertions remain. The exact response DTOs, status assertions, repeated message ID/receipt, byte-identical request bodies and request keys, one-message/one-inquiry/one-context graph, unchanged commerce originals, encrypted input, no retained raw notice token, UI/focus, foreign-session/CSRF denial, archived history, storage privacy and screenshots remain. Explicit no-store assertions now also cover the native retry responses and initial follow-up API response. The original case title and line-7 identity, 120-second case limit, default expectation limit, zero retries and workflows are unchanged. No application or fixture-helper behavior changes.

This removes an unnecessary fulfillment step from the successful retry while strengthening observation of the actual browser/server transport. It is a narrow corrective candidate; it does not prove the original fulfillment step caused the stall.

## Executed checks

Using Node **24.19.0** and the recovered locked frontend dependencies:

- `npm run typecheck`: passed, including `tests/browser` under the existing TypeScript configuration.
- `npx vitest run tests/frontend/contact-inquiry.test.tsx tests/frontend/inquiry-conversation.test.tsx tests/frontend/order-inquiry.test.tsx --reporter=json --outputFile=/workspace/scratch/b527c7e94bd7/browser-diagnosis-37507742206/frontend-inquiry-checks.json`: **88/88 passed**, zero failures/skips; 37 contact, 12 conversation and 39 order-inquiry cases.
- `npm run build`: passed TypeScript and the production build, 619 modules. The existing large-chunk advisory remains; its threshold was not changed.
- `VASEY_BROWSER_DIRECTORY=/workspace/scratch/b527c7e94bd7/browser-diagnosis-37507742206/discovery node node_modules/@playwright/test/cli.js test --list --reporter=json`: successful ordinary discovery of **126 cases in 36 files**, 63 per engine, no discovery errors. The unchanged inquiry journey appears once per engine at line 7 with expected status passed. The JSON receipt is `browser-diagnosis-37507742206/ordinary-discovery-final.json`.
- `git diff --check`: passed.

The first direct discovery attempt failed because `operator.spec.ts` reads a fixture manifest during import. Final discovery provided an empty temporary `fixtures.json` solely for that import; no test bodies, bootstrap, guarded fixture helper or native browser executed. That placeholder is not scanner or fixture-readiness evidence. The initial discovery error remains in `browser-diagnosis-37507742206/ordinary-discovery.json`.

The local runtime lacks the required native browser executables and genuine ClamAV installation/signatures. No local native browser, MySQL, screenshot or corrected HTTP journey pass is claimed. Fresh composed hosted acceptance must exercise both real retries, the retained graph and all ordinary browser cases with the existing gates before this correction can be accepted.
