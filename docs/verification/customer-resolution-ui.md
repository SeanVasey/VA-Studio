# Customer retained-resolution display

This frontend child depends on the separate customer historical exception-resolution reader. It adds an explicit read panel to verified `paid_exception` order details, including the existing session recovery, checkout-return and account history/reference paths. It does not change any order, history, checkout or item schema, payment/finalization state, claim eligibility or download authority.

## Contract and customer meaning

The only new request is a same-origin, uncached GET to `/orders/{canonical-v4-order-id}/exception-resolution`, with no query or body. The exact envelope is:

```json
{"resolution":{"exceptionResolutionSchema":1,"orderId":"750000ab-0000-4000-8000-000000000001","testOnly":true,"record":{"kind":"full_refund_verified_resources_released","observedAt":"2026-10-01T12:00:00Z","releasedAt":"2026-10-01T12:00:01Z"}}}
```

`record` may instead be `null`, meaning no supported retained resolution was found during this read. The UI explicitly says that absence does not establish whether a refund was made. A populated record describes a verified full test refund and released reservations. Its observation and release dates do not establish refund arrival, current provider state or when a refund was sent. Contracts and downloads remain blocked. The previous generic wording that fulfillment could continue after review is replaced with neutral blocked-fulfillment wording in the existing summary and checkout surfaces.

The client requires exact keys and types, the same order identity, the known schema/kind, canonical real UTC-second dates and observation no later than release. The server owns current-time validation; a customer clock neither expires historical records nor establishes their authority. A dedicated streamed reader cancels above 4 KiB, rejects malformed UTF-8/JSON, redirects, non-JSON content and unexpected status, and never reads/refers to private error bodies. Access errors offer fresh sign-in; other errors remain generic.

## Interaction and privacy

The panel makes no resolution request until **View recorded test-order resolution** is activated. Refresh obtains a fresh server response and first clears the old record. Hide, page departure, unmount and order replacement cancel the current request and discard private state. A 20-second deadline clears pending UI even when a transport ignores abort. Generation, controller and timer ownership prevent an older response or `finally` handler from replacing a newer request or cancelling its deadline. No resolution data enters browser storage or a URL beyond the existing public order reference.

Existing theme classes provide the panel, full-width controls and focus treatment. No new palette, font, identity asset, imagery, motif or CSS is introduced; `docs/brand/README.md` remains authoritative. Keyboard activation, focused result/error headings and focus restoration on Hide are covered in component tests. Native responsive rendering, physical devices and screen-reader behavior remain hosted/manual acceptance work.

## Browser journey extension

The existing `test-refunded-exception-resolution.spec.ts` identity is extended after the independently reviewed `2380da8` committed-response correction (locally cherry-picked as `8b8eb7b`). Its 180-second budget and 90-second helper budget are unchanged; no browser file or case is added.

A separate guest context obtains genuine session possession through an ordinary history GET. Its encrypted cookie travels only through bounded CLI stdin. The confined existing preparation helper validates that existing guest session and derives its original owner before creating both orders through ordinary quote, pricing, order and hosted-checkout services. It never seeds a session, rewrites an owner or creates a claimed paid exception. The existing two synthetic provider records, historical clocks, suffix checks and immutable business/guard proofs remain.

The guest opens the real checkout-return page and explicitly reads null history before the operator action, populated retained history after release, and null history for the partial-refund case. A separate foreign context receives the same private 404 as an unknown order. Reads are checked for exact minimal envelopes, `no-store`, private-marker exclusion and GET-only customer order traffic. The complete existing verifier runs again after customer reads to prove unchanged provider-call counts, retained business rows and guards. Backend tests separately own SQL-write/audit absence proof. All original operator release, dropped successful response, exact retry and partial-refund assertions are retained.

## Local checks and limits

Commands ran in `VA-Studio-customer-resolution-ui` using the recovered Node/PHP runtime, with builds written outside the backend tree.

| Check | Result | Session receipt |
| --- | --- | --- |
| Focused new reader/panel plus existing checkout/history | 171 passed | `customer-resolution-ui-first-frontend.log` |
| Initial full frontend | 686 passed, 1 failed | `customer-resolution-ui-full-frontend.log` |
| Corrected full frontend before review | 687 passed across 32 files | `customer-resolution-ui-final-frontend.log` |
| Final reviewed full frontend | 688 passed across 32 files; 47 new cases | `customer-resolution-ui-reviewed-frontend.log` |
| TypeScript | Passed, including browser source | `customer-resolution-ui-reviewed-typecheck.log` |
| Vite build to `/tmp/va-customer-resolution-ui-build` | Passed; main chunk 514.38 kB | `customer-resolution-ui-reviewed-build.log` |
| PHP fixture syntax and `git diff --check` | Passed | Local command output |
| Direct Playwright discovery | Same 2 cases in 1 file, Chromium and WebKit | `customer-resolution-ui-browser-discovery.log` |

The initial full frontend failure was `order-recovery.test.tsx:29` expecting the intentionally replaced “This order needs review” copy. Only that exact fixture expectation was aligned with the neutral blocked statement; the analogous checkout fixture was also updated. All surrounding assertions remain. The failed receipt is retained. The final full run passed with the existing jsdom navigation notices; the build retains the existing 500 kB advisory threshold and warning.

Independent frontend review identified that JavaScript’s `$` regex anchor permits a trailing newline. The locator validator now also requires exactly 36 characters, with a regression proving both schema rejection and no request for that malformed input. The final full suite and build above include this correction; server authorization and schemas did not change.

Meaningful new cases cover exact/unknown fields, null history, identity/type/date rejection, malformed/oversized streaming, cancellation, no eager requests, no storage, truthful retained dates, access withdrawal, clearing, order replacement, ignored-abort deadlines, old-result isolation, account history/reference integration and exclusion from other payment states.

Discovery is not execution. Native Chromium/WebKit rendering, the modified browser preparation path, its duration and the combined backend/frontend journey were not executed locally: this recovered runtime lacks usable pinned browsers and genuine scanner runtime for ordinary browser bootstrap. Full composed backend/browser acceptance and independent exact-source review remain separate gates. This child does not claim refund settlement, production identity recovery, live commerce or launch readiness.
