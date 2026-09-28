# Owner HTTP and native test attachments

Status: **Implemented dependent candidate, 2026-09-27.** [D-20](architecture/D-20-test-owner-delivery-http.md) defines this owner HTTP/UI boundary over the accepted [D-19 internal services](test-owner-delivery.md). PRs #65/#67/#69 and status PR #70 are merged; main `1a6ecebd` passed [CI 36280010722](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36280010722). The candidate contains the item/history reader, private controller/request boundary, native attachment transport, order UI and regression coverage. Its own final integrated CI/review and merge disposition must be recorded before calling its HTTP/UI behavior accepted.

This scope is explicitly local/testing and requires the original owning session, complete retained activation, configured own Stripe test account, exact default-off access policy and enabled versioned order control. It does not add live payments, production download policy, email-based ownership, guest claims or an active entitlement import. Reuse the configuration/control commands and bounded storage recovery in [the internal guide](test-owner-delivery.md).

## Owner projection

`GET /orders/{order}/delivery` returns a private `delivery` envelope with the following allowlisted fields. The server derives ownership from the existing session; no submitted owner key or buyer email is accepted.

| Fields | Meaning |
| --- | --- |
| `deliverySchema: 1`, `orderId`, `testOnly: true` | Version and exact owned order identity. |
| `status: available` or `unavailable` | Current strict access policy/control availability, not proof of present file health or a new entitlement. |
| `items` | Exact purchased entries: `grantId`, `kind`, `filename`, `mimeType`, `sizeBytes`. Kinds are `contract`, `master_wav`, `download_mp3`, `stems_zip`, as actually purchased. |
| `history` | Newest bounded authorization records: `authorizationId`, `grantId`, `kind`, `issuedAt`, `expiresAt`, `status`, `attemptedAt`. |
| `historyLimit: 20`, `historyHasMore` | The response is a bounded order history, not a complete purchase library or an export. |

History status `unused` means no committed redemption is recorded; current restrictions or policy withdrawal can still prevent access. `expired` means an unconsumed authorization has passed its expiry. `attempted` means the redemption committed, including an interrupted or failed transport; `attemptedAt` records that decision, not delivery to the recipient. The server verifies presented retained records. No token/hash, owner key, buyer details, internal IDs, control version, storage path or encrypted evidence appears in this projection.

The read is database-only. It does not prepare a snapshot, inspect files, consume a budget, issue a token, change pending entitlements/outbox or make a provider request. Historical original/activation metadata cannot prove current storage durability. Authorization and redemption perform their own fresh physical checks.

## Authorization and attachment

| Request | Body and result |
| --- | --- |
| `POST /orders/{order}/delivery/authorizations` | Strict bounded JSON with exactly `grantId` and `kind`; UUID `Idempotency-Key` header and CSRF. Newly accepted issuance returns HTTP 201: `authorization` contains only `authorizationId`, `token`, `expiresAt`, `filename` and `mimeType`. |
| `POST /orders/{order}/delivery/download` | Strict bounded form body with exactly `authorizationId`, `token` and `_token`. Success is a native attachment from the redeemed prepared stream, with the verified ASCII filename/MIME and exact size. |

Neither route accepts query parameters, paths, filenames, MIME types, owner identity or arbitrary roles from the client. Body endpoints permit at most 4,096 bytes; the parser reads at most 4,097 bytes from the input stream, so an absent or false `Content-Length` cannot permit an unbounded body read. Any nonempty raw query string is rejected, including spellings PHP discards during query parsing. Reject unsupported methods (including OPTIONS), `Range`/`If-Range`, nonidentity content encodings, malformed/ambiguous bodies, extra or nested fields and duplicate JSON/form keys before invoking issuance or redemption. Both POSTs retain CSRF and same-origin session protection. Native form submission is used for the attachment; JavaScript does not download the file into a blob.

Routes reuse the existing `quotes-read` group at 60 requests/minute for GET and `quotes-create` at 10/minute for POST, with session block/lock-wait values of 120/10 seconds. These middleware limits supplement the durable D-19 budget; they do not replace it or prove concurrent enforcement.

| HTTP status / code | Meaning |
| --- | --- |
| 400/405/413/415/416/422 — `INVALID_DELIVERY_REQUEST` | Generic invalid request; 405 for unsupported method, 413 for body size, 415 for media type/content encoding, 416 for range headers, 422 for any raw query string or malformed/extra/nested/duplicate fields. |
| 404 — `DELIVERY_NOT_FOUND` | Unavailable owned order/target/authorization/token; no foreign-record distinction. |
| 419 — `SESSION_EXPIRED` | CSRF/session failure; refresh without retaining an old token. |
| 429 — `DELIVERY_RATE_LIMITED` | Middleware throttle or rolling technical issuance budget. |
| 409 — `DELIVERY_ALREADY_ISSUED` | Same request key already issued; its secret is not recoverable. |
| 409 — `DELIVERY_CONFLICT` | Request key belongs to a different target. |
| 410 — `DELIVERY_EXPIRED` | Authorization has expired. |
| 409 — `DELIVERY_ATTEMPTED` | A stream attempt already committed. |
| 503 — `DELIVERY_UNAVAILABLE` | Policy/control/evidence/target or transient availability failure, with no internal reason disclosed. |

The existing D-19 policy permits at most three new authorizations per order across all targets in the preceding 60 seconds. Each contains a 32-byte random secret stored only as its SHA-256 digest, expires exactly 60 seconds after issuance and admits one committed stream attempt. Those values are technical test abuse/concurrency limits. They are not the license's `copies_downloads` allowance, a lifetime download right or production access policy.

Identical idempotency replay reports `already_issued` without another secret or authorization; a different target under the same key conflicts. A request with an uncertain outcome must retain the same key when retried. A lost secret cannot be recovered. A deliberate new request uses a new key and remains subject to the rolling budget. Blocking or changing the control version invalidates older authorizations; re-enabling does not revive them.

## Browser behavior and privacy

The delivery panel belongs to the exact current order. It validates server envelopes and ignores stale responses after order changes/unmount. Tokens exist only in the short-lived authorization/submission scope and temporary native POST fields, which are removed after submission. Do not persist them in browser storage, URLs, reusable links, logs or telemetry.

Authorization expiry is enforced by server redemption. The client validates the canonical timestamp and envelope without comparing it to the device clock: a clock discrepancy must not discard a newly issued secret or consume the rolling budget without submitting the request.

The panel reports an attempt requested, not a completed download. Native attachment handling is controlled by the browser, and a disconnect after redemption commits consumes the attempt even when no bytes arrive. Refresh the bounded history to see the recorded attempt; request a new authorization deliberately if needed. An authorization response alone means neither redemption nor completed receipt.

Success and generic errors use `Cache-Control: private, no-store`, `Vary: Cookie`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` and `X-Robots-Tag: noindex, nofollow`. This applies to controller responses and earlier routing, CSRF, throttle, session-lock and debug-mode failures. Errors must not expose request tokens, private paths, SQL bindings, evidence or buyer data. No public storage link, cache validator, partial range or resumption protocol is introduced.

For streaming, the response owns the freshly verified unlinked descriptor and capacity lease. It never reopens the purchased/spool path. The server commits redemption before sending file bytes and closes the descriptor/lease on completion or failure. Transport errors after headers remain private and cannot append an exception trace or JSON error to the attachment or undo the committed attempt. Existing snapshot capacity/crash-residue procedures remain unchanged.

## Effects and operational reversal

| Event | Retained effect |
| --- | --- |
| Metadata/history GET | No mutation or physical verification. |
| Rejected input, owner, CSRF, policy or control | No new authorization/redemption. |
| Accepted issuance | One immutable authorization and audit; no change to order, grant, original, activation or pending fulfillment. |
| Failure before redemption commit | Prepared snapshot closes; no committed redemption. |
| Accepted redemption | One immutable attempt/audit, then bounded descriptor streaming. |
| Disconnect/failure after commit | Attempt remains consumed; receipt is unknown. |
| Disable test access/block order | Stops future eligible requests; preserves history and cannot recall already exposed bytes. |

There is no new database migration in this HTTP/UI increment. Reverting its routes/UI or disabling test access preserves D-19 records and immutable purchased evidence. Do not remove populated tables, rerender missing originals, change licensed rights, delete authorization history or reset a consumed attempt as rollback. Follow the exact-byte restore and spool inspection limits in the internal guide.

## Verification record and remaining work

**Executed prerequisite evidence:** main `1a6ecebd` passed [CI 36280010722](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36280010722). The [ordered acceptance record](development-order.md#current-increment-and-next-handoff) retains #65/#67/#69 tested sources, full MySQL/SQLite results and independent-process race evidence. These checks precede this HTTP/UI candidate and do not establish its behavior.

**Executed component evidence:** these author checks precede the final integrated candidate and do not establish its CI/browser acceptance.

| Component/source | Executed command and result |
| --- | --- |
| Projection `ca76cbb422671aa87c29a39a89558ee0627db1be` | With PHP 8.4.26 on `PATH`, `php artisan test tests/Feature/TestOwnerDeliveryProjectionTest.php`: 25 passed / 205 assertions on SQLite. |
| HTTP `82b64ede7cc55ca6e8e2f15dbe24342785eb4f67` | With PHP 8.4.26 on `PATH`, `php artisan test tests/Feature/TestOwnerDeliveryHttpTest.php`: 48 passed / 2,027 assertions on SQLite, including actual CSRF, throttle/session-lock paths, bounded raw reads and output-consumer failure after committed redemption. |
| Integrated frontend `0aecf2b` | Root ran `npm test`: 240 passed, and `npm run build`: TypeScript/Vite passed. The integrated source `dc7b9fc` retains that same UI. This verifies frontend behavior/build, not the pending native-browser or full backend gates. |

Independent review identified logging failures escaping the private error boundary, corrected in `8832d3b`, and an input-read bound that depended on `Content-Length`, raw query spellings discarded by PHP and Laravel's automatic OPTIONS response, corrected in `8a4af15`. The latter uses the bounded 4,097-byte stream read, raw-query rejection, explicit OPTIONS 405 and compressed-body rejection. The independent reviewer reproduced OPTIONS 405, a PHP-discarded raw query 422 and oversized-body 413 through the real application kernel after these fixes. UI `0aecf2b` was also reviewed for native POST/error-frame transport, transient secrets and stale-response guards; final integrated review remains pending. This is a correction/component review record, not final independent-review acceptance; the actual tested integrated commit must be reviewed after all changes.

**Unexecuted/pending at this component checkpoint:** Four new browser scenarios define eight Chromium/WebKit cases. Local execution was unavailable because the matching Playwright Chromium download was an invalid/truncated archive and no matching Chromium/WebKit cache was present. The full browser CI gate remains required. Neither test definitions nor the earlier main run prove these new cases.

**Final candidate acceptance still required:** native Chromium/WebKit attachments, full MySQL/SQLite CI and independent review of the tested commit. The passing focused tests cover owner/IDOR/session boundaries, malformed/ambiguous inputs, methods/media types/ranges/size, CSRF/session-lock/throttle/debug/log privacy, policy/control withdrawal, expiry/replay/interruption, and exact byte/header/descriptor cleanup. Once completed, the integrating PR is authoritative for the final source/tree, exact commands/results, browser execution, corrections and merge disposition. SQLite/session serialization does not substitute for the real MySQL races. This component record must not be read as a passing result for pending checks.

After HTTP/UI acceptance, proceed to WP-09 persisted versioned site content with private drafts, atomic publication and rollback. This bounded order history does not complete the cross-order customer library, U-07 guest/account recovery, refunds/disputes, production access/storage/archival/restore or historical migration. WP-08 and the broader fourteen-package/103-item parity plan remain open.

### Resumed review — 2026-09-28

Two independent source reviews found the same device-clock defect in the checkpoint candidate: a browser only ten seconds behind the server rejected a valid issuance. The correction retains strict envelope checks while letting server redemption decide expiry. Focused frontend verification passes 47 tests, including skew in both directions; TypeScript/Vite build passes. A reviewer reran the 25 projection cases / 205 assertions successfully on PHP 8.4.26 / SQLite.

[Initial PR CI 36468060846](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36468060846) passed frontend and quality checks but failed the WebKit native-attachment case (21 of 22 browser cases passed). The retained trace shows an actual form POST and intercepted attachment response, followed by a frame interruption without a download event. This matches the [reported Playwright WebKit interception behavior](https://github.com/microsoft/playwright/issues/22691). The synthetic success fixture now serves bytes from a bounded loopback HTTP server and continues the native request to it. Download-event, filename, exact saved bytes, request-body and privacy assertions remain; no runtime transport guard or browser gate was removed. The corrected candidate still requires complete CI and final source review before merge.
