# Promotion pricing and usage holds

The accepted WP-06 foundation adds one configured test promotion to a new owned pricing review. It calculates exact discounts, reserves one campaign slot atomically and can bind that slot to a future payment attempt. The [D-09 decision](architecture/D-09-promotion-usage.md) records scope and recovery. Later accepted WP-07 finalization supplies verified consumption; release after provider reconciliation remains unfinished. The implemented WP-09 administration increment is described below. These promotion commands make no provider request.

## Selection and compatibility

`POST /quotes/{quote}/pricing/promotions` accepts exactly `{"promotionCode":"CODE"}` as JSON, at most 1 KiB, without query parameters. Codes are case-sensitive ASCII uppercase letters/digits/underscore/hyphen, 3–32 characters, beginning with a letter/digit. The server owns policy, eligibility, amount and capacity. Existing session ownership, CSRF, private/no-store responses, session locking and the shared 10/min quote-create throttle apply. Foreign/unknown quotes return the same 404 before policy or capacity is inspected. The 60/min owned `GET /quotes/{quote}/pricing` reads the resulting versioned record.

One immutable pricing per quote remains the retry boundary. The same code/quote returns the original record and consumes no extra slot. A different code or a change between promotion and no-promotion pricing requires a fresh quote with a fresh selection-review idempotency key. The original empty-object pricing POST still creates only v1 no-discount evidence; it does not silently apply configured promotions. Existing quotes/disclosures keep their original schemas, hashes and license/asset meaning.

The `PricingSnapshot` dispatcher routes retained schema 1 to `PricingSnapshotV1`; its original implementation is retained except for its class name. Promotion pricing uses schema 2 / `vasey-quote-pricing-v2`. It retains the exact v1 base offer/disclosure identities, then captures the promotion/hash, allocation trace, net tax bases and recomputed test-tax result. It expires at the earliest quote, tax-policy or promotion-policy end.

The safe response retains the v1 amount fields, declares `pricingSchema: 2`, adds `promotion: {key, version, code, hash}` and recalculates the public `pricingHash`. It always declares `testOnly: true` and `payable: false`. Private account/quote evidence, usage counters, attempt identity and internal allocation trace are omitted. A discount does not resolve unknown tax. Existing amount comparison reproduces either retained schema and verifies provider tax limits against the discounted basis.

## Explicit test configuration

`VASEY_TEST_PROMOTIONS` is an optional JSON list, accepted only in local/testing. Setup, migrations and seeders install no campaigns, codes, discounts or commercial approval. Missing/invalid configuration yields `PROMOTION_UNAVAILABLE` 503; an absent or inactive code yields the same code with 409. Production rejects configured test promotions. Configuration is limited to 64 KiB, 50 campaigns and 100 explicit eligible revision IDs per campaign.

Each campaign has exactly these fields:

| Fields | Required meaning |
| --- | --- |
| `schema_version`, `scope`, `currency` | `1`, `test`, `USD` |
| `key`, `version`, `code` | Safe lowercase key, positive integer version, code as above. Keys and codes are unique in the list. |
| `effective_from`, `effective_until` | Exact UTC second timestamps; half-open interval with increasing end. |
| `eligibility` | `{mode: "all_non_exclusive"}` or `{mode: "offer_revisions", offer_revision_ids: [...]}` with unique ascending positive integer IDs. |
| `minimum_subtotal_minor` | Nonnegative integer threshold applied to eligible lines only. |
| `discount` | `{type: "fixed", amount_minor: ...}` or `{type: "percentage", rate_bps: ..., max_discount_minor: ...}`. All bounds are explicit integers. |
| `stacking`, `allocation`, `release` | `none`, `largest_remainder_v1`, `unstarted_at_expiry`. Unsupported behaviors fail. |
| `max_uses` | Integer 1–10000, the total capacity of this test campaign across buyers and quotes. This is not a per-customer limit. |

A fixed amount must be positive and fit the eligible subtotal; excessive fixed discounts are rejected. A percentage is 1–10000 basis points: round the eligible subtotal percentage half-up, then apply the positive explicit maximum discount. No eligible lines, a failed threshold or a result rounded to zero returns `PROMOTION_NOT_ELIGIBLE` 409 before any hold survives.

The first successful use registers immutable campaign policy evidence. Both key and code are unique for the retained campaign lifetime. Changing payload, version, code or capacity under an existing identity cannot reset its budget: new selections receive `PROMOTION_CHANGED` 409, while existing pricing detects changed captured policy. A separate test campaign requires a new key and code. Configuration removal stops new/current use but does not delete history or pending holds. The WP-09 editor below creates independent campaigns under fresh identities and preserves these lifetime rules; retained policy terms are never edited.

## Calculation and shared capacity

Discounts are apportioned by eligible base amount. Each line gets its exact proportional floor, then remaining cents go by largest remainder, ties by ascending offer revision ID. The retained trace includes basis, numerator remainder, denominator, floor and extra cent. Integer long division avoids overflowing the intermediate product; each intermediate remains below three times the existing JavaScript-safe integer maximum and within PHP's 64-bit integer range. Tests include fixed expected large-integer results and reordered lines. Ineligible lines retain zero discount. Fixed test tax is rounded per net line after allocation; provider-calculated tax retains the net basis and existing authorization envelope.

Lock order: quote → selected tracks/offers through `ReadQuote` → pricing → campaign → usage rows. First campaign registration is insert-only with unique-identity readback; retryable deadlocks use the surrounding idempotent transaction. Capacity is a current locking read of at most `max_uses` matching IDs, not a stale aggregate from an earlier repeatable-read snapshot. It counts every pending use and every held use whose expiry is still in the future. Time is sampled after acquiring the campaign lock. A full budget returns `PROMOTION_LIMIT_REACHED` 409 and rolls back the candidate pricing, hold, campaign registration if new, and audit effects.

The `promotion_campaigns` snapshot/hash and `promotion_uses` identity/lifetime have restrictive FKs and database guards. Unique pricing linkage prevents duplicate holds; unique attempt linkage prevents sharing an attempt across prices. Model guards reject ordinary edits/deletion. MySQL/SQLite triggers reject campaign edits, evidence deletion and invalid usage inserts/transitions. Application audits record one `commerce.promotion.held` and one `commerce.quote.priced` effect on a successful first pricing. Replays produce neither again.

## Attempt handoff and expiry

An unstarted hold remains recorded as `held`; after its stored expiry it ceases to count under the captured `unstarted_at_expiry` rule. Expiry is derived without deleting or rewriting evidence. No cleanup worker or clock-based update is required.

`PromotionUsage::beginAttempt(quoteId, ownerKey, attemptUUID)` is an internal test-mode command, with no HTTP route. It rechecks owned/current pricing and locks its campaign/use. Before held → pending it performs another current locking capacity read, excluding its own hold and counting every other pending or currently unexpired held use. If a faster worker has reused an expired slot, a slower clock cannot turn the original hold into an extra pending attempt. Such a handoff returns `PROMOTION_LIMIT_REACHED`; reading retained pricing is not a guarantee of attempt capacity. Before expiry a successful handoff atomically binds a v4 UUID and records `pending_at`. Repeating the same attempt returns the same use; another attempt or reusing its UUID for another pricing conflicts. The transition and its single audit roll back with an enclosing transaction. A late transition fails; ownership/current publication failures cannot attach an attempt.

**WP-07 integration requirement:** commit this binding with the durable order/checkout intent before any provider request, and treat its return as a capacity hold only. A pending use always occupies capacity, even after quote/policy expiry, timeouts or application restarts. This batch has no pending → released/consumed transition. WP-07 must add verified settlement/reconciliation evidence and idempotent terminal transitions; an unverifiable or potentially successful payment must retain the slot. Neither the attempt UUID nor the amount comparator authenticates payment, buyer assent or a rights grant. A complete coupon redemption lifecycle is therefore not claimed here.

## Verification and recovery

The new tests cover money bounds/rounding/caps, eligibility, strict configuration, owner/HTTP/CSRF isolation, old-schema compatibility, immutable evidence, failed-capacity rollback, expiry during locks, clock rollback after slot reuse, provider net-tax comparisons, unique attempts and nested transaction rollback. Eight independent-process MySQL cases cover cold registration at the last slot, same quote, conflicting configured policy, expired unstarted holds, retained pending holds, matching/conflicting attempts and workers with different clocks after slot reuse. Different-quote races use distinct tracks/offers so earlier catalog locks cannot supply false serialization. For first-pricing races, a committed unrelated v1 price separates the contenders' missing-key index gaps; this avoids a test-barrier circular wait before the campaign lock while retaining repeatable-read isolation and cold campaign registration. SQLite explicitly skips those races. The PR records actual executed results; authored tests alone are not acceptance.

Deploy the additive migration before the new route/callers. Application rollback can withdraw promotion creation and attempt callers while retaining all quote, pricing, campaign, use and audit records. Down/up is tested only with empty new tables in a disposable database; do not remove meaningful campaign/hold evidence. After a future provider request, repair requires reconciliation, never manually freeing pending rows. No production restore rehearsal is implied.

Next ordered WP-06 batch: shared underlying exclusive inventory and reservation lifecycle. Preserve earlier non-exclusive grants; retain U-08's explicit TTL, late-payment, pending non-exclusive cutoff and post-refund decisions. Then WP-07 orders/assent/hosted checkout/reconciliation integrates these guards; WP-08 contracts and entitlements follows. Production promotion approval, per-customer identity/limits and operator campaign workflows remain tracked requirements.


## Terminal-effect handoff — 2026-09-26

The original foundation above remains historical. Merged PRs #52/#62/#63 now supply immutable order attempts, hosted test sessions and authoritative test-payment evidence. Accepted [finalization in PR #64](test-payment-finalization.md) adds one atomic verified `pending` → `consumed` effect for inventory and any promotion use, with matching immutable finalization proof; consumed promotion usage continues to count against lifetime capacity. Paid exceptions retain pending resources, and exclusive sales persist under a unique shared-scope constraint. Replays and historical reads must verify the complete effect graph. Its accepted source and executed results are recorded in the linked finalization guide and ordered development record. No automatic release, refund restock or production policy is introduced.


## Seller administration — September 29, 2026

Status: **Implemented local/testing WP-09 increment.** [D-22](architecture/D-22-promotion-administration.md) defines immutable authoring, revisioned availability and the shared-lock boundary. The integrating PR and [WP-09 issue #9](https://github.com/VASEYDEV/VASEYAUDIO/issues/9) record the exact tested commit, executed CI, independent review and merge disposition. The [ordered record](development-order.md) retains the dependency sequence.

### Staff workflow

1. In a local/testing installation, sign in as a verified administrator and open **Test commerce → Test promotions** at `/admin/test-promotions`. Existing panel MFA requirements still apply.
2. Choose **New test promotion**. Enter a new campaign key/code, exact start/end UTC timestamps, discount, eligible-subtotal minimum, lifetime maximum uses and eligibility. **Create disabled promotion** saves immutable terms and initial disabled history; it does not enable the code.
3. Open **Review and usage** to inspect the exact retained policy and aggregate capacity. **Enable promotion** confirms the availability revision captured when the action opened. Effective dates and normal quote/inventory validation still apply.
4. Choose **Disable promotion** to reject new eligible use. Refresh and reassess if another operator changed availability while confirmation was open. A stale or already-satisfied action is rejected without another history/audit entry.
5. For different terms, choose **Copy as new promotion**. The form copies terms and clears key/code; submit a fresh identity, then review and enable it separately. Saved campaign terms cannot be edited or deleted. There is no persisted editable promotion draft.

The form labels monetary values in cents (`100` means `$1.00`) and percentages in basis points (`1250` means `12.5%`), with an explicit maximum discount. Values are exact bounded integers; decimals, exponent notation, booleans and overflow must not become accepted amounts through coercion. Start/end fields require exact UTC seconds. Currency `USD`, test scope, schema/version `1`, non-stacking, largest-remainder allocation and unstarted-at-expiry release are server-owned. This editor does not approve production money or terms.

Eligibility is **All non-exclusive offers**, or up to 100 **Exact offer revisions** found by track title. The latter captures immutable revision IDs, including an exclusive revision only when explicitly selected; normal exclusive scope/inventory checks remain authoritative. Choosing a discount cannot make an otherwise unavailable offer saleable.

### Availability and usage

Managed campaigns show **Managed here**; registered legacy campaigns show **Configuration (read only)**. Configuration-owned campaigns have no enable/disable action and cannot be silently adopted. Authoring rejects key or code collisions with retained campaigns and valid configured legacy policies, including policies not yet used. Missing/empty legacy configuration permits authoring; malformed nonempty configuration blocks creation.

Managed code resolution happens before the legacy fallback and only in local/testing. A disabled or corrupt managed campaign cannot be re-enabled by adding the same code to configuration. Unmanaged codes keep their existing strict configuration path. Availability and effective dates are separate: enabling a future campaign leaves it scheduled, and enabling an expired campaign is rejected.

Usage shows aggregate held, expired-held, pending and consumed counts, plus used/remaining capacity. **Capacity used** counts unexpired held + pending + consumed against the immutable maximum. Expired unstarted holds remain retained but do not occupy capacity. Reads disclose no customer identities, owner keys or payment-attempt identifiers. Counts may change before the next action; checkout rechecks capacity under lock.

Availability changes share the campaign lock with new holds and held-to-pending transitions. Disable stops new eligible use once it commits; it does not remove pending/consumed rows, restore their capacity, change a captured price or rewrite an order/contract/grant. Existing retained-order recovery and payment verification remain valid. The standalone internal `beginAttempt` command continues to require current pricing and is not a post-disable historical-order recovery API.

### Evidence and recovery

Creation retains campaign, disabled revision `1`, history and audit atomically. Enable/disable advances the revision and appends history/audit; stale/ABA or no-op requests leave all records unchanged. Authorization is freshly checked at the domain boundary and again on reactive panel requests. The public storefront has no promotion-management endpoint or campaign index.

Apply the additive availability/history migration before using these commands. To withdraw a code, disable it through the domain workflow; do not edit its snapshot, lower its lifetime usage count or remove retained rows. Code rollback must preserve campaign/control/history/usage/order evidence; the migration refuses down with retained availability/history. Pre-D-22 code ignores these controls, so withdraw managed use and keep its codes out of legacy configuration during a rollback. Restoring older code alone does not disable a campaign. No production restore rehearsal, provider request, customer export, marketing send or consent change is part of this increment.

Final verification must include actual MySQL disable/hold/attempt contention, strict-policy and legacy compatibility cases, authorization/audit/stale-confirmation tests, retained-order continuity, database retention and Chromium/WebKit editor workflows. Acceptance requires all required CI and independent review on the final tested commit; the integrating PR and WP-09 issue #9 record their actual outcomes. After acceptance, WP-09 continues with persisted contact/about/blog/video, asset references and scheduling; per-customer promotion limits, bundles, free-download licensing/consent and production promotion approval remain separate work.
