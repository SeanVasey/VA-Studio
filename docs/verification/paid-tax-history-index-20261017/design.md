# Paid Tax history index: design

Lane T1, branch `harness/tax-history-index-20261017`, based on `origin/main` at `efa6eea5`. This document describes the change made in commit `3711a7b6542b989f996bd70e01e59b00fedd688f`. It answers the condition in the [Tax HTTP design](../paid252-tax-http-20261015/design.md): "A future Tax screen/index must use its own reviewed source/current-owner closure". The [README](README.md) holds the evidence.

Everything stays default-off. `paid-tax-grants.http_mounted`, `rehearsal_enabled` and `operative_enabled` keep their shipped `false` values. No provider transport, live credential, deployment or activation input is added.

## What was added

1. **Domain.** `PaidTaxReads::index(principal, actor, ?read)` returns a bounded list of the buyer's retained Tax batches. Each entry carries locators and display-safe fields only.
2. **Read mode.** `PaidTaxProjectionRead::captureIndex(receipt, deadline)` adds an explicit owner-only mode for that list.
3. **HTTP.** `GET /paid-tax-grants/index` returns JSON. It sits inside the existing default-false `TaxHttpMount` registration and behind the existing `TaxRootPrivacy`/`PaidTaxPrivacy` JSON rules.
4. **Page.** A page shell at `GET /paid-tax-licenses` carries no private Inertia props. Its client journey reads the JSON endpoints and redeems downloads through a hidden native form.

The list response has this exact shape:

```json
{"schemaVersion": 2, "originLimit": 20, "hasMore": false,
 "origins": [{"id": "<batch uuid>", "orderId": "<order uuid>", "createdAt": "2026-10-17T17:39:53Z", "lineCount": 1, "provenance": "synthetic_rehearsal"}]}
```

The list never contains provider account, session or payment identifiers, request or reviewed-session identifiers, hashes, payload ciphertext, declarations, terms, amounts, file paths or tokens. A listed id grants nothing. Opening a batch still goes through `show`, `document`, `downloads`, `authorize` and `redeem`, and each of those re-runs the full producer `SourceV2` closure exactly as before.

## Index mode: why owner-only

`PaidTaxProjectionRead::capture` requires a producer `ProductionTaxPaidOrderCommittedReadReceiptV2`. That receipt is minted by one held `ProductionTaxPaidOrderSourceV2` for one order, inside the frame that locked that order. A history list spans up to 20 orders, so it has no single producer source. Three options were considered.

- **Run the producer closure once per row**, as `show` does. That would open up to 20 producer frames per page load, each locking an order's producer rows and holding its own commit observer. One projection read can capture only one producer receipt, so the bytes of the list could never be closed by them all. This option was rejected as both unworkable and disproportionate for a locator list.
- **Make the producer argument of `capture` nullable**, as the V1 `PaidGrantProjectionRead` does. This option was rejected because the type system would then stop requiring a producer receipt on the producer-backed paths (`show`, `finalize`, `status`, `authorize`, `document`, `redeem`).
- **Add a separate, explicit owner-only mode** (chosen). `captureIndex` sets a private `index` flag. `proveBeforeBytes` then requires the flag and a missing producer to agree: owner-only mode must have no producer receipt, and the producer mode must have one. In owner-only mode it runs the original consumer receipt's closed proof, its raw closed proof and the original deadline. In the producer mode it runs exactly the previous sequence. `capture` is unchanged. Either mode can be captured only once, and a read captured in one mode cannot be captured in the other (tested).

The index runs on `PaidTaxRuntime`, `PaidTaxTransaction`, `PaidTaxRows` and `PaidTaxReadReceipt` only. It never constructs `ProductionTaxPaidOrderSourceV2`, `ProductionTaxPaidOrderLocatorV2`, `TaxPaidOriginalCommitDispatcher` or `PaidTaxConsumerCommitAdmission`. It never reaches the frozen shared V1/generic `IdentityOriginalCommitWitness` metadata paths, which only those producer classes use. A test proves that the connection's event dispatcher is unchanged while an index response is pending, and that `show` installs `TaxPaidOriginalCommitDispatcher`.

## Current-owner closure

The index follows the same order as `PaidTaxCommands::run`, minus the producer steps:

1. Capture the runtime and the raw actor, prove that no transaction is open, capture the policy, then run the standalone `ProductionCustomerAccess::current()` check.
2. Open one original frame through `PaidTaxTransaction::run`. Its begin hook serializes the buyer (`PaidTaxActor::lockOriginal`, `FOR UPDATE` on MySQL).
3. Inside the frame, construct `PaidTaxRows`, capture the policy again, then lock the current owner with `ProductionCustomerAccess::lock` and read the durable binding.
4. Call `PaidTaxRows::admitSource()` once. No younger producer source follows, but this keeps the original cleanup savepoint and the `finish()` contract identical to the producer-backed commands.
5. Read and verify the rows (next section). Capture a `PaidTaxReadReceipt` over the owner rows (user, account, identity origin, verification history and challenges) plus the list window.
6. Run the final fence. Every resolver callback (`app(PaidTaxPolicy)`, `app(ProductionCustomerAccess)`, `callbackPhase()`) runs first. Then come the receipt's live locked proof, `ProductionCustomerAccess::proveCurrent` and `PaidTaxPolicy::provePure`, then `finish()` and commit.
7. After commit, capture the read in owner-only mode, then run the receipt's closed proof, its raw closed proof and the deadline. The response owner repeats the same proofs immediately before the first byte.

Refusals map exactly as in the producer-backed commands: an `IdentityException` becomes 403, and a `CheckoutException` keeps its status (409, 422, 503 and so on). Every failure path releases only the original rows and frame through `PaidTaxCleanup::abort(null, rows, frame)`.

Each row's owner check is the same one `show` uses. `PaidTaxGrants::sameOwner` compares the row's sealed `original_buyer` with the current durable binding on origin id, provenance, account id, account public id, user id and identity policy version and hash. It refuses with 404.

## Row verification and whole-list refusal

Every fetched row is verified, including the sentinel row that is never shown. `PaidTaxRecords::decode` checks the ciphertext, the SHA-256 seal and that the payload is canonical JSON. If the seal is wrong it refuses with 503.

The decoded payload must then have exactly the twelve batch keys, and it must agree with the row's columns:

- origin id, order id and order hash
- producer (`production_tax_checkout_v2`)
- the column-derived source identity, which covers request, reviewed session and provider account, session, payment and funds mode (`PaidTaxGrants::batchIdentity`, made public for this reuse; its body is unchanged)
- account id equal to the principal's account; the sealed buyer's user id equal to the row's `actor_id`
- provenance equal to the sealed buyer's and the current policy's
- a list of 1 to 10 line commitments, matching `line_count`
- the ISO creation time equal to the `created_at` column

Any of these mismatches refuses with 409. `PaidTaxPolicy::retained` then requires the sealed delivery policy to be well formed and to keep the current provenance.

Any failure on any row refuses the whole list, and no partial list is ever returned. Tested tampering covers:

- a transplanted ciphertext (409)
- a re-sealed payload whose source identity disagrees with a column (409)
- a payload seal mismatch (503)
- a different identity origin inside the seal (404)
- a tampered row sitting in the sentinel position (409)

A changed delivery policy version with the same provenance keeps retained batches listable, the same rule `show` applies. A captured list is still refused before bytes if the policy changes after capture.

## Sentinel and window

The index selects `account_id = ? ORDER BY id DESC LIMIT 21`, with `FOR UPDATE` on MySQL as V1's index and the Tax status endpoint already do. It shows the first 20 rows. `hasMore` is true only when the 21st row exists.

The receipt snapshot is `account_id = ? AND id >= <oldest fetched id>`, read with limit 22 and compared with the fetched rows in ascending order. The fetched rows are the top N by id, so every row at or above the oldest one is in the set. The extra row of limit lets an insert become visible. Any insert, removal or change in the shown window or its sentinel therefore fails the live, closed and raw-closed proofs. With no rows, the snapshot is `id >= 0`, so a first batch committed between list and bytes also refuses.

This fails closed. A same-buyer finalize that commits after the list was read but before its bytes leave makes that response refuse (403), and the next read shows the new batch. The Tax status endpoint uses the same `id >= minimum` window precedent. Under MySQL, a concurrent finalize for the same buyer first waits on the same current-owner locks, so this race is narrow.

The list select is a prepared statement on the frame's primary connection, as in the status endpoint. It is not wrapped in the private `PaidTaxRows::held()` lock-wait cap. The receipt's re-reads go through `PaidTaxRows::rows()` and do get the 5-second cap. Lock waits on the list select are bounded only by the server setting and then by the 60-second deadline that is checked straight afterwards.

## Commit semantics

The index frame writes nothing, so it installs no commit admission. If a commit callback injects a write into the same physical transaction, that write belongs to the callback and commits with it. The index never lists it, because the post-commit raw owner proof refuses (403).

The tests target each point inside the original frame only, via the `PaidTaxTransaction::run` stack frame. That excludes the separate pre-frame `ProductionCustomerAccess::current()` transaction. A withdrawal at the frame's first policy resolution or at the final fence is refused and rolled back with the frame. A withdrawal injected at commit is refused by the terminal proof. Identity retention then forbids reactivation, so later reads stay refused.

## HTTP

`GET /paid-tax-grants/index` is registered inside the existing `HandleInertiaRequests`-free group of `routes/paid-tax-grants.php`. That file is required only when `TaxHttpMount::admitted('paid-tax-grants.http_mounted')` is literally `true` in `local` or `testing`. The route uses the sibling throttle (`60,1,paid-tax-grants`) and session block (`20, 5`).

The path is under the `paid-tax-grants/*` prefix, so the existing root seam applies before identity or SQL:

- a runtime refusal while unmounted (404, even with a stale route table)
- query strings and GET bodies refused (422)
- wrong methods refused (405)
- the private header set and forced JSON

The controller uses the existing `projection()` path: policy capture, session principal, the customer-guard `User`, a `PaidTaxResponseOwner` read and `PaidTaxStreamResponse::projection`. That response re-proves immediately before echo and emits only the fixed error body on a late refusal.

## Page privacy decision

The page is a shell at `/paid-tax-licenses`. That path is deliberately outside the JSON-forced `paid-tax-grants` prefix. Rendering an Inertia page under the prefix would have needed a reviewed exception to `PaidTaxPrivacy`, `TaxRootPrivacy::matches` and the root `shouldRenderJsonWhen`. None was added.

- `PaidTaxController::page` first repeats `TaxHttpMount::admitted('paid-tax-grants.http_mounted')`, because the root seam does not cover this path. That refuses a stale cached route with a private 404 before any policy, identity or SQL work (tested).
- It then refuses any method other than GET or HEAD, any query string and any body (422), so a token cannot be carried in the page URL. Next it requires the Tax policy and the current customer (404 or 403). Finally it renders `Inertia::render('PaidTaxLicenses')` with no props.
- The Inertia page object contains only `props.errors = []` (tested), and the HTML contains no batch, order, declaration or hash.
- `PaidTaxPrivacy::protect` sets `private, no-store`, `noindex`, `no-referrer` and `nosniff`. `HandleInertiaRequests` already overwrote `Vary` after the controller, and it already special-cases `orders/*/checkout/return`. It now also keeps `Cookie, X-Inertia` for `paid-tax-licenses`.
- When unmounted, the page route does not exist and ordinary 404 handling applies, because the path is outside the private prefix.
- Exceptions raised by middleware before the controller on this path, such as a 429, use the ordinary handler. The shell has no private data and refuses query strings, so the risk is limited to a generic error page.
- `resources/js/app.tsx` resolves `PaidTaxLicenses` through a dynamic import, so the shell and journey ship as a separate lazy chunk. Without a registration the shell would throw "Unknown page" in a browser.

## Client journey

`resources/js/components/PaidTaxJourney.tsx` is new and self-contained. It reuses the V1 `PaidGrantJourney` patterns without changing V1. The flow is:

1. An explicit "Open saved Tax licenses" button reads `/paid-tax-grants/index`. Nothing loads on render.
2. "Open saved order" reads `show`.
3. "Prepare" sends one `document` request per click.
4. "Refresh preparation and download status" reads `downloads`.
5. "Authorize" sends `requestKey`, `originHash`, `kind` and `nonce`.
6. "Download" submits a hidden `application/x-www-form-urlencoded` form with `token` and `_token` into a hidden `no-referrer` frame, clears its inputs and removes the form.

Every answer is checked against an exact shape before display:

- **List:** at most 20 unique entries; `hasMore` only with exactly 20; ISO times; 1 to 10 lines.
- **Projection:** exact keys; nested Tax amounts with the inclusive/exclusive arithmetic and `preTax` agreement; no files before fulfilment; exact deliverables after.
- **Status:** bound to the open order and its lines.
- **Authorization:** bound to the requested line and kind, with the server's fixed filename and media type.

Tokens appear only in component memory and in the transient form field. They never reach a URL, history entry, storage write or rendered text (tested). A submitted authorization is not offered again. A refused one is offered again only when a later status read shows that exact authorization unused.

Refusals behave as follows:

- HTTP 403, 404 or 419, or a sealed late 403/404/419 body after 200 headers, clears everything, including issued tokens and pending replays, and links to a fresh sign-in.
- Any other refusal, malformed shape or network loss shows only a "could not be confirmed" alert.
- An uncertain authorize keeps its exact body for a byte-identical retry, enabled after a saved status read. It can also be discarded; a committed authorization then expires unused and spends no download.
- Hiding the tab clears what is shown and abandons the request in flight. Leaving the page clears everything.

Accessibility is meant to meet WCAG 2.2 AA, though only basics are tested; see the README's untested conditions:

- semantic `main`, h1, h2 and h3, a skip link, lists and buttons with accessible names
- a polite `role="status"` progress region, and a focused `role="alert"` for messages
- `aria-busy` while a request is in flight, disabled controls while busy
- focus moved to the opened order's heading

There is no motion. Styling reuses `customer-account.css` and `paid-grants.css` and adds no tokens or brand assets.

The journey deliberately leaves out three V1 conveniences:

- automatic multi-request preparation continuation (one document request per click)
- the order-reference finalize form (batches appear in the list after finalize)
- the proof-gated "set aside" for uncertain authorizations; a plain discard is used instead

## Changed shared files

| File | Change |
| --- | --- |
| `PaidTaxProjectionRead` | New private flag, new `captureIndex`; `proveBeforeBytes` branches on that flag only; `capture` and `abandon` are unchanged. |
| `PaidTaxGrants::batchIdentity` | Visibility changed from private to public; body unchanged. |
| `HandleInertiaRequests` | One extra path in the existing `Vary` branch, plus Pint's import-order fix. That file failed `ordered_imports` at base `efa6eea5`; the README records it. |
| `resources/js/app.tsx` | One lazy resolver line. |

No schema, migration, census, workflow, V1, producer or identity file changed.

## Adjacent findings (not fixed)

- `resources/js/app.tsx` does not register the V1 `PaidGrants` page or `ProductionCustomerFeatures`, so those shells would throw "Unknown page" if mounted in a browser.
- The census and timing files (owned by lane F2c) were not edited. The README reports the native selection delta.
