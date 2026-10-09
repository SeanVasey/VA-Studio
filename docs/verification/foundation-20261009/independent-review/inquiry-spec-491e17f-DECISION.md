# Review: 491e17f (parent e2b3906), tests/browser/inquiry-conversation.spec.ts

**DECISION: APPROVE WITH CONDITIONS** (both conditions are documentation fixes; the spec change is sound)

## Findings

1. **No coverage lost (info).** `ContactInquiryForm.tsx:130-132` sets `saved` only when the response is not redirected, has JSON content type (order path), status is 200/201, and the body has exactly 2 keys, `state === 'saved'`, and a v4-UUID `receipt`. The page then renders that receipt (`:178`).
   (a) The spec still asserts status 200 on the observed response (spec:164); 200 rather than 201 is what shows this was a replay. Exact shape and JSON type are now checked through the page's validation. The server's exact replay body is also covered by `tests/Feature/OrderInquiryHttpTest.php:58` (`assertExactJson`).
   (b) The receipt equality check is kept (spec:173).
   (c) The `conversation-order-verify` proof is unchanged (spec:175-176, 197).
   The dropped `finished()` check is implied: `privateInquiryJson` reads to `done` and then calls `JSON.parse` (`order-inquiry.ts:33-44`), so a truncated or failed body leaves the page in `uncertain`. One property is now weaker: the browser spec no longer checks the body independently of client validation. The PHP test above covers that.

2. **The pass cannot come from stale state (info).** The first POST is aborted (spec:155), so `fetch` rejects and `uncertain()` runs (`ContactInquiryForm.tsx:160-161`). The status stays `error`, which spec:158 asserts. Clicking Retry sets `sending` (`:117`). Only the validated retry response can set `saved` (`:132`). No other code path renders "Inquiry saved".

3. **The locator is unique and the read is race-free (info).** The region (`OrderInquiry.tsx:9-22`) holds one form. In the saved state that form renders exactly one `.contact-inquiry-receipt code` (`ContactInquiryForm.tsx:178`). The order ID `<code>` (`OrderInquiry.tsx:18`) is outside that class, and `InquiryConversation` (`:184`) only mounts after a click. The heading and the receipt render in the same commit, and nothing changes the state afterwards, so `textContent()` after `toBeVisible()` is safe.

4. **The evidence totals do not add up (minor, condition 1).** Adding the runs listed in `chromium-stream-response-repro.txt` gives 16 hung of 85 stream-reader runs: A 1/1, B 2/4, C 1/20, D 12/60. The file, commit message, CHANGELOG and README all say 13 of 82. The 0/22 `response.json()` control does add up. The committed `.mjs` only runs the Run D variants: `response-json` and the unrouted controls are absent from the loop. It also imports an absolute path (`/home/user/VA-Studio/node_modules/...`). The mechanism is plausible: reader consumption plus CDP "No data found for resource", with a page-side success every time. I did not reproduce it here because Chromium 1243 is not installed.

5. **README section C overclaims (minor, condition 2).** The heading reads "fixed here (test-only)", but the spec has not run in CI on this commit. The body does say "the spec itself still needs CI". The phrase "a second failure, so not a flake" also contradicts the "intermittent" characterization.

6. **No other spec has the same hazard (info).** `privateInquiryJson` is only used for the order POST, `for-order` GET and `order-context` GET. In tests/browser those are only read through `page.request` (spec:135-137, 177-180) or `route.fetch()` (spec:153). The conversation replay at spec:62-67 is consumed with `response.json()` (`InquiryConversation.tsx:153`). Other stream-reader libraries (inquiry-history, owned-order-history, purchase-claim and others) are already handled by specs that capture page-side copies (customer-order-items/account/order-reference/library-browsing).

7. **Typecheck passes (info).** `npx tsc --noEmit -p .` under Node 24.21.0 exited 0, and tsconfig includes tests/browser. The working tree is clean at 491e17f.

8. **Adjacent issues, not introduced here (low).** The README table still contains `FIXED_PLACEHOLDER` (an unchanged context line).

## Conditions
1. Reconcile the stream-reader totals in the evidence file, commit and PR text, CHANGELOG and README: correct them to 16/85, or list exactly which runs make up 13/82. Also say that the committed script reproduces Run D only.
2. Retitle README section C as "patched (test-only), awaiting CI" until a CI run on the exact SHA passes Chromium. Drop or reword "so not a flake".
