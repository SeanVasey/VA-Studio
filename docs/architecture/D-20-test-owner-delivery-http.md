# D-20 — Owner HTTP boundary for test delivery

Status: **Implemented candidate within Sean's existing continuous-development authorization, 2026-09-27.** D-17, D-18 and D-19 are accepted in merged PRs #65, #67 and #69. Main `1a6ecebd`, including documentation PR #70, passed [CI 36280010722](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36280010722). This dependent HTTP/UI candidate requires its own integrated tests and independent review; prerequisite acceptance is not acceptance of this candidate.

Expose D-19 through the original order owner's existing same-origin session, a minimal database-only item/history projection, explicit authorization POST and native attachment POST. Reuse domain reconstruction, policy, order control, snapshot preparation and atomic redemption. Do not introduce another entitlement model or infer ownership from an email address, client owner key, payment return or knowledge of a UUID.

## Decision and alternatives

Retain the original local/testing policy and its separately enabled order controls. Read-only metadata identifies each exact purchased grant and role without checking current file bytes or allocating a stream slot. Authorization and redemption freshly verify the exact retained original or asset through the existing private adapter. Current catalog fields and source paths are not selectors.

Use an ephemeral token returned only by a newly committed authorization, then submitted in a CSRF-protected POST body. A native attachment preserves bounded server/browser handling for files up to the existing adapter limit. Token-bearing GET links risk URL/history/referrer leakage; public or signed storage URLs bypass this boundary; JavaScript blob downloads buffer the attachment and weaken truthful interruption handling. Those alternatives are excluded from this increment.

## Interface and effects

| Operation | Public contract | Durable or external effect |
| --- | --- | --- |
| Read delivery | `GET /orders/{order}/delivery`; version 1, owned order, current access status, exact item metadata and newest 20 historical authorizations | Database reads only. No file checks, token issuance, redemption, provider request or rights mutation. |
| Authorize one item | `POST /orders/{order}/delivery/authorizations`; strict JSON `grantId`/`kind` and UUID `Idempotency-Key` | Existing D-19 private verification, one authorization/audit on a new accepted request; secret returned once. |
| Request attachment | `POST /orders/{order}/delivery/download`; strict form `authorizationId`/`token`/`_token` | Existing private snapshot and atomic single redemption/audit before streaming its owned descriptor. |
| Display history | Bounded owner-only metadata with `unused`, `attempted` or `expired` status | No recovery of a secret, no reuse of a consumed attempt, no claim that bytes reached the recipient. |

The projection uses `deliverySchema: 1`, `orderId`, `testOnly: true`, `status: available|unavailable`, `items`, `history`, `historyLimit: 20` and `historyHasMore`. Each item exposes only `grantId`, `kind`, `filename`, `mimeType` and `sizeBytes`. History exposes only `authorizationId`, `grantId`, `kind`, `issuedAt`, `expiresAt`, `status` and `attemptedAt`. The allowed kinds remain `contract`, `master_wav`, `download_mp3` and `stems_zip`, limited to the exact purchased members. Database evidence and current policy/control are verified before availability is shown. `available` is not a present-file-health guarantee; `unused` is not a guarantee that an authorization remains redeemable after restriction or policy change.

No new persistence is required. Original orders, grants, contracts, activation, pending entitlements/outbox, licensed rights and inventory stay unchanged. D-19 retains its 32-byte random secret, hash-only storage, exact 60-second lifetime, at most three new authorizations per order in the preceding 60 seconds, and one committed stream attempt. These are technical test limits, never a license's exploitation allowance or approved production download policy.

## Privacy and transport boundary

Derive ownership for every request from `QuoteOwner`; missing or changed authentication/session context cannot recover the old key. Fail closed for foreign order/grant/authorization/token combinations. Reject any nonempty raw query string, unsupported methods (including OPTIONS), `Range`/`If-Range`, wrong media types or nonidentity content encodings, bodies over 4,096 bytes, duplicate/nested keys and unexpected fields before invoking mutation services. Bound the input stream read itself to 4,097 bytes instead of trusting a missing or false `Content-Length`. Keep CSRF and session protections for both POST operations. The [guide](../test-owner-delivery-http.md) defines exact public status/code mappings; internal denial reasons remain private.

All delivery responses, including routing, session-lock, CSRF, throttle and debug-mode failures, use generic private errors and no-store/cache, cookie-vary, nosniff, no-referrer and noindex headers. Do not report raw request bodies, tokens, private paths, SQL bindings or decrypted evidence. Safe status/allow/retry metadata may survive error mapping; no private target metadata may be reflected from invalid input.

Use the verified frozen ASCII filename/MIME for `Content-Disposition: attachment` and the prepared exact size for the response. Disable range/conditional caching behavior. Retain only the winning prepared descriptor; never reopen a purchased path or pass one to a path-based download response. Close the descriptor and its capacity lease on completion and every failure. Streaming failure cannot append debug/JSON details to file bytes or reverse a committed redemption.

The UI submits the secret only through a temporary native POST form. Do not place it in a URL, persistent React state, local/session storage, logs or a JavaScript blob. Clear temporary fields after submission and on cancellation. Validate server envelopes and guard stale responses across order changes. Describe submission as a requested attempt; native browser download behavior cannot establish receipt. A lost secret is unrecoverable, and retrying the same idempotency key does not reveal it again.

## Verification, rollback and handoff

The candidate must verify IDOR and session rotation, exact input/method/range handling, CSRF and middleware privacy, policy/control withdrawal, expiry and replay, bounded database-only history, exact attachment bytes/headers and descriptor cleanup. Chromium and WebKit must exercise native attachments and truthful failure/retry states. Full CI retains actual independent MySQL issuance/redemption/control races; SQLite and browser session serialization do not prove those races. The [delivery guide](../test-owner-delivery-http.md#verification-record-and-remaining-work) records executed component checks and the distinct pending integrated/browser gates. Local missing browser binaries do not waive CI. Final tested source, full CI and independent-review acceptance belong to the integrating PR once they actually pass.

Rollback disables new test access and removes/reverts the HTTP/UI surface while preserving all D-19 retained records. Blocking an order increments its control version and invalidates older authorizations; re-enabling does not revive them. A committed stream may already have exposed bytes and cannot be recalled. Do not delete history, mutate originals, roll back populated delivery tables or reset budgets to simulate reversal.

After acceptance, continue WP-09 versioned site content, draft privacy, atomic publication and rollback. U-07 guest/account recovery, the complete cross-order customer library, refunds/disputes, production access/storage/archival/restore and historical continuity remain open. This increment does not complete WP-08, the 103-item parity baseline or launch readiness.
