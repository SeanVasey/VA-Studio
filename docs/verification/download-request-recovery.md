# Stalled download-request recovery

This bounded T24 child makes existing test-download recovery controls available when a metadata or authorization response stalls. It preserves D-19/D-20, all PHP/server bytes, response schemas, ownership, the 60-second authorization lifetime, one-attempt consumption, issuance limits and disabled ranges. It does not implement resumable downloads or production delivery.

## Reproduction and behavior

Base `31c9540c70321889ab0cb58a376482c3774d2ad9` set the delivery panel busy before awaiting fetch and JSON-body completion, then released it only in `finally`. Four focused regressions held either fetch or the response body indefinitely for a status read or authorization request. All four failed on the original application source because the refresh button remained disabled after 20 seconds. `download-recovery-original-red.log` retains those four failures; the other 47 cases were filtered from that run, not accepted as passing or exempted from execution.

The panel now owns a 20-second deadline spanning both fetch and bounded JSON reading, matching the existing customer-library request pattern. Timeout invalidates the controller/generation and releases controls without depending on promise settlement. A status timeout clears unavailable metadata and offers refresh. An authorization timeout uses the existing unconfirmed-result copy, retains the exact selector and idempotency key, and permits an explicit same-request retry. Refreshing metadata does not discard that identity. A deliberate new request remains the only replacement action while the panel is active; a retry does not recover an already-issued secret or reset a consumed attempt.

Late results and old `finally` callbacks cannot update a successor's UI, release its controls or submit an authorization token. Pagehide clears private metadata, notices, pending request identity, timers and attachment frames. Returning to the mounted page requires an explicit fresh read. Unmount and order replacement retain their existing cleanup behavior. No token enters persistent state, URLs, storage or logs.

The optional `AbortSignal` argument on `deliveryJson` permits interruption of body reading. It requests cancellation and releases the reader lock without awaiting an uncooperative source's cancellation promise. Existing callers without a signal retain the previous reader behavior. The 128 KiB byte bound, fatal UTF-8 decoder and schema checks remain. The native `submitAttachment` function is byte-identical to the base: the deadline applies only to JSON metadata/issuance, never to file bytes, attachment duration or the browser's download completion.

## Actual local evidence

Runtime: Node 24.19.0 with installed lockfile dependencies. No PHP behavior tests were run for this frontend-only child.

- Original application: four intended regression failures for stalled fetch/body × status/issuance. No product change was present during this reproduction.
- First corrected focused run: 51/51 passed. Expanded adversarial run: 60/60 passed. Final focused run: 61/61 passed in 2.59 seconds, comprising all original 47 cases plus 14 additions.
- Final full frontend suite: 768/768 cases across 34 files passed in 13.64 seconds. The log retains two jsdom navigation-not-implemented notices; neither produced a test failure.
- TypeScript passed. Production build passed; its 529.28 kB main chunk still emits the existing 500 kB threshold warning. No chunk threshold, test timeout or test policy was changed.
- Playwright discovery lists the same four named journeys in each engine: eight expanded cases in one file. Discovery is not browser execution.

The new cases cover the precise 19,999 → 20,000 ms deadline boundary, ignored fetch aborts, late body results, old cleanup while a new request owns the controls, same-key retry after a stalled read-only refresh, explicit replacement, pagehide privacy and fresh recovery, StrictMode compatibility, unmount/order replacement, cancellation that never settles, reader-lock release, byte limits and invalid UTF-8. Existing native POST, expiry, uncertain retry, secrecy and schema assertions remain.

The existing native uncertainty journey retains its immediate network failure, exact retry, explicit replacement and private expiry assertions. An additive phase then holds a fourth authorization response until the real deadline aborts it, retries the same key, releases the late held route in `finally`, and proves no additional native attachment or secret exposure. The original 60-second case budget, 10-second assertion budget, four browser identities and all other journeys remain unchanged. Actual Chromium/WebKit abort timing and the native journey have not been executed locally; hosted acceptance remains required.

Commands and retained logs (all under `/workspace/scratch/0c039e9e0645`):

```sh
npm test -- tests/frontend/test-owner-delivery.test.tsx -t 'stalled delivery request recovery'
npm test -- tests/frontend/test-owner-delivery.test.tsx
npm test
npm run typecheck
npm run build
VASEY_BROWSER_DIRECTORY=/workspace/scratch/0c039e9e0645/download-recovery-discovery-only npx playwright test tests/browser/test-owner-delivery.spec.ts --list
```

Receipts: `download-recovery-original-red.log`, `download-recovery-focused-first.log`, `download-recovery-focused-expanded.log`, `download-recovery-focused-final.log`, `download-recovery-full-frontend.log`, `download-recovery-typecheck.log`, `download-recovery-build.log`, and `download-recovery-browser-list.log`. The separate tested-source manifest binds the final four executable/test files. Root owns integration, shared registrations and inclusive acceptance; independent exact-source review precedes composition.
