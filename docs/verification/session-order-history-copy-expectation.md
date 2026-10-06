# Session order-history browser copy expectation

October 6, 2026 UTC. This correction updates one stale assertion in the existing session order-history browser journey to match the reviewed customer resolution UI. Application behavior and copy are unchanged.

## Observed failure and source

PR #10 Foundation run `37481272427`, attempt 1, checked out merge `8631ab7aff12000f3f103ec4f5b15e9784804ef8`, tree `1b26d5f883add9180716bdcfeee0b5056292a27f`, with ordered parents `9bea39d04a02fc0b46090fa1972ac892c89fc2c7` and published head `cf9a9dd55adfbc43825667364908418256ea993f`. The latter maps to local `f5fa1dbf44801eb254b6d2175cf5793e416037ab`, the exact base of this isolated correction.

Chromium job `112329791740` logged **59 passed / 3 failed** in 10.8 minutes. One failure was `tests/browser/owned-order-history.spec.ts:47`: its existing 10-second assertion expected “This order needs review before fulfillment can continue.” The received region instead contained “Test payment verified. Fulfillment is blocked for this order.” The complete job log records that sentence in both the retained summary and selected checkout details, together with the previously verified payment statement and blocked contracts/downloads.

`OwnedTestOrderHistory.retainedStatus` already returns the received sentence for `paid_exception`. The [reviewed UI record](customer-resolution-ui.md) explains the intentional change: review must not imply that fulfillment will resume after a historical full-refund resolution. The browser expectation was missed when the equivalent component expectations were updated. This is a source/log-confirmed stale expectation, not a reason to restore the earlier application wording.

The retained scratch log `ci-run-37481272427-chromium.log` is 1,110,838 bytes, SHA-256 `bdb4ff2b33170ec07bd8e871dacc59e36a2fcc3fd072ecdc17416f0c6daf8feb`; `ci-run-37481272427-chromium-log-evidence.json` records its source binding and all three failures. The 43,892,744-byte browser artifact exceeds the available 32 MiB transfer limit. Its raw ZIP digest, HTML report, traces, screenshots and attached receipts were not independently inspected for this correction. No visual or complete browser acceptance claim follows from the log.

## Exact correction and preserved checks

Only the expected literal at line 47 changes, to the complete existing sentence “Test payment verified. Fulfillment is blocked for this order.” The same region locator and `toContainText` assertion remain. The test name, fixture and synthetic HTTP routes, keyboard activation, focused heading, exact GET-only request list, absence of payment/download actions, absent download panel, unchanged cookies and session storage, missing recovery locator, screenshot call and zero-page-error assertion are otherwise byte-identical. The default 60-second case budget, 10-second expectation budget, zero retries, browser selection, case identities and all CI gates are unchanged.

This journey exercises the built React frontend with synthetic HTTP responses; it does not independently prove the backend owner or historical-evidence boundary. Its original failure occurred before later safety assertions, so those assertions are retained requirements rather than a passing result from this failed execution. The other Chromium failures and the separate related-browser scanner setup failure remain unresolved by this one-line change.

## Validation and next gate

Source verification confirmed that replacing this single literal in the original test produces the entire corrected test byte-for-byte. `git diff --check` passed, and the changed-path check permits only this test and this evidence document. No behavior tests, browser discovery or native browser runs were repeated for this bounded correction. It requires independent exact-source review, integration with the separately reviewed repairs, and fresh complete hosted acceptance before merge.
