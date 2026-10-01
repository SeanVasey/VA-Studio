# Current-session test order history

This T24 preparation lets Sean's store show retained test orders belonging to the original owning session after optional tab recovery locators are cleared. It does not link guest orders to accounts, recover a lost session, settle customer identity or complete the purchase library.

In the cart, **Browse session orders** performs a read-only request. No history request is made before that action. A page lists at most twenty summaries, newest preparation time first with a stable internal-ID tie break. **Older session orders** uses the server cursor; **Refresh session orders** starts at the newest page. Completed page loads focus the results heading for keyboard users. Selecting **View test order status** opens the existing test status/checkout component. Listing alone does not start checkout, authorize a download, activate fulfillment or inspect files. Existing tab-local uncertain preparation recovery remains available.

## Server contract

`GET /orders/history` accepts only an optional canonical `before=<lowercase UUIDv4>` query. The cursor is a public order UUID resolved under the current owner before its preparation-time/ID keyset position is used. Foreign and unknown cursors have the same generic private 422 response; duplicate, encoded, nested and additional query fields are rejected. The route retains the existing read throttle, session lock, generic order error handling and private no-store/Cookie-vary headers.

The version-one envelope is `history: { orderHistorySchema: 1, testOnly: true, orders, limit: 20, nextCursor }`. A twenty-one-row query establishes whether another page exists; only the displayed twenty orders are reconstructed. Every summary goes through `ReadOrder::present()` and its complete frozen order/payment/finalization/contract graph verification, then an explicit allowlist returns:

- `id`, `createdAt`, `testOnly`, `payable`, `currency`, `totalMinor`;
- coherent `status`, `paymentStatus`, `finalizationStatus`, `contractStatus`, `fulfillmentStatus`.

No database IDs, quote/pricing/review hashes, buyer contact details, encrypted inputs, provider identifiers, contract bodies/private paths or delivery secrets are included. Corrupt displayed evidence fails the page closed; it is not repaired or silently replaced with current catalog state. Historical reads do not require current order-creation policy or provider/file I/O. A listed or selected status is not proof that files reached the buyer.

Ownership remains `QuoteOwner`'s server-derived HMAC of session secret and authentication context. Login/logout rotates that context; different sessions of one signed-in account are not linked. Email match, knowledge of an order UUID and authenticated user ID do not claim an order. The index itself does not create a customer identity or change any business ledger.

## Local verification and remaining gates

Local PHP 8.4.26/SQLite executed `vendor/bin/phpunit tests/Feature/OwnedTestOrderHistoryTest.php`: fourteen cases, 108 assertions, no failures/skips, 59.2 seconds. Cases cover verified field/privacy/nonmutation boundaries after policy withdrawal, stable time/ID ordering, twenty/twenty-one-row boundaries, cursor paging amid a new order, owner/authentication-context isolation, foreign/unknown and ambiguous input, and retained-evidence corruption. Order fixtures use real existing media/quote/pricing/assent/preparation services. This does not prove MySQL behavior or races.

`npm test -- tests/frontend/owned-order-history.test.tsx tests/frontend/order-recovery.test.tsx tests/frontend/order-preparation.test.tsx` passed three files/65 cases, including eighteen new history cases. `npm run build` passed TypeScript and the production Vite build. Playwright `--list` discovered the two new project cases. One native Chromium/WebKit scenario uses the existing synthetic HTTP transport harness to verify built UI, cleared sessionStorage in the same browser context, keyboard discovery/status selection and absence of checkout/delivery mutations. Backend authorization and real frozen evidence are established by PHP tests, not those intercepted browser responses. Native execution must be recorded by integrating CI because browser binaries are unavailable locally.

T24 remains open for approved account/guest recovery, full library search, exact historical contracts, private storage and complete large-file range/resume/retry acceptance. Production recovery, retention, terms, access policy and cutover decisions remain separate. Reverting this surface preserves all existing orders and customer rights.
