# Atomic test pricing and inventory

`ReservePricedQuote` coordinates existing immutable pricing, promotion capacity and shared rights inventory in one outer database transaction. It is an internal local/testing command, with no customer route, provider request, order, sale or rights grant. Existing provisional quotes remain non-exclusive and non-payable. This increment follows [exclusive preparation](exclusive-offer-preparation.md) in WP-06; it does not activate those revisions.

## Contract

- `hold(quoteId, ownerKey, promotionCode = null)` validates the owned current quote, creates or reuses its exact pricing and optional campaign hold, then acquires all linked rights scopes atomically. Existing pricing cannot silently switch promotion codes. Every quote line must have an explicit scope link; duplicate underlying scopes are rejected by the retained inventory rules.
- `beginAttempt(quoteId, ownerKey, attemptId)` requires an existing pricing and inventory hold. A valid UUIDv4 binds the optional promotion use and reservation to the same attempt. Same-identity retries reuse the retained effects; conflicting identities reject. Inventory failure rolls back a newly pending promotion and its audit.
- Both commands return internal `pricing`, `reservation` and nullable `promotion_use` models. These are not public response projections. A held result with a promotion requires both resources to be held; a pending result requires both to have the same attempt. Partially bound standalone legacy records cannot masquerade as a complete aggregate hold.
- The outer transaction retains quote, sorted track/offer, pricing, campaign/use, sorted scope and reservation/claim locks in that order. Nested services revalidate the same quote, whose earlier locks are already held. Do not invoke the coordinator for another selection after acquiring later locks. Retryable deadlocks roll back the entire operation before retry.
- Pricing expiry is rechecked after inventory acquisition, because a scope lock wait may cross the price deadline. Each component retains its original immutable expiry and evidence; the coordinator never extends either lifetime. A new attempt must satisfy all existing component deadlines. Pending reservations remain occupied beyond their hold TTL; retries still require current quote/pricing eligibility. Expiry never implies permission to release a potentially successful attempt.
- Inventory policy must be explicitly configured in local/testing. Existing tax policy may remain unresolved, in which case totals remain null. No production tax, reservation TTL, pending non-exclusive cutoff or late-payment rule is inferred.

## Atomic failure and recovery

A failed acquisition rolls back newly created pricing, campaign/use, reservation/claims and audits. Previously committed component evidence remains unchanged. Failure after a pending promotion transition restores its prior held state. A caller may wrap the coordinator and future order/intent creation in another transaction; a subsequent order failure must roll back both resources. Commit that future binding before provider I/O.

The lower-level services remain available for historical tests and internal work; independently invoking them does not provide this combined atomic guarantee. No immutable schema or historical reader is changed. Roll back by withdrawing the coordinator while retaining all existing records. There is no automatic release, cancellation, terminal redemption or sold-state implementation in this increment.

## Acceptance and verification

Functional tests cover with/without-promotion replay, cross-resource identity, ownership/environment/policy gates, unavailable or blocked inventory, expired/missing/conflicting reservations, exhausted campaigns, multi-scope rollback, retained pending capacity, expiry during scope acquisition and enclosing-order rollback. Independent MySQL processes cover duplicate holds, shared-scope competitors with distinct campaigns, campaign-cap competitors with distinct scopes, duplicate attempts and competing attempt IDs. Each race verifies resource and audit counts and matching lifecycle/attempt identities. SQLite skips process races explicitly.

The integrating PR records actual tested source/tree and CI results. PHP/Composer are unavailable in the implementation workspace, so backend validation runs in the existing GitHub Actions MySQL 8.4 and SQLite jobs. Independent review remains required before merging this money/concurrency change. Frontend and browser regressions run despite no UI edits.

## Next dependency

Integrate inactive exclusive revisions with versioned activation, quote/disclosure/pricing, discount eligibility and inventory-aware selection, including explicit pending non-exclusive cutoff. Then WP-07 adds buyer assent/orders, hosted test checkout and verified terminal promotion/inventory effects; WP-08 adds contracts and private delivery. See [ordered development status](development-order.md) and [WP-06](work-packages/WP-06-quotes-promotions-and-exclusive-reservations.md).
