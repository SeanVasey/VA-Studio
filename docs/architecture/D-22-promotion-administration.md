# D-22 — Test promotion administration

Status: **Accepted test-only WP-09 increment, merged in PR #73 on 2026-09-29 within Sean's continuous-development authorization.** This bounded WP-09 increment follows accepted CMS PR #72. It is not a separately approved production promotion policy. The [ordered acceptance record](../development-order.md#accepted-test-promotion-administration--september-29), PR and [WP-09 issue #9](https://github.com/VASEYDEV/VASEYAUDIO/issues/9) retain the exact tested commit, all ten passing CI jobs, independent review, merge disposition and passing post-merge CI.

## Context and choice

[D-09](D-09-promotion-usage.md) already freezes promotion calculations and serializes lifetime campaign capacity. Its configuration-only entry point requires code/runtime configuration access. Staff need an editor and usage visibility without rewriting captured terms or resetting a code's budget.

Create an immutable campaign in a disabled state, review it, then explicitly enable it through a separately versioned availability record. Creation and availability changes belong to `App\Domain\Commerce\PromotionAdministration`; Filament invokes this boundary. `PromotionPolicy` remains responsible for exact policy validation and resolution, and `PromotionUsage` owns atomic capacity decisions.

| Option | Assessment |
| --- | --- |
| Continue configuration-only campaigns | Preserves compatibility but supplies no seller editor or reviewed availability control. |
| Edit retained campaign terms or reuse a code with a new version | Rejected: would change captured meaning or attempt to reset a lifetime usage identity. |
| Immutable disabled creation plus separate availability | Chosen: staff can author and review terms while preserving pricing evidence and serialized capacity. |
| Persist editable promotion drafts or add a provider promotion service | Additional lifecycle/provider scope is unnecessary for this increment. The create/copy form is editable before submission; a saved campaign is immutable. |

Terms changes require a fresh key and code, including when copying an existing campaign. A copy has independent lifetime capacity and starts disabled. No test promotion or production commercial approval is installed by setup, migration or seeding.

## Data and application contract

| Record | Retained contract |
| --- | --- |
| `promotion_campaigns` | Existing immutable policy snapshot/hash, lifetime-unique key and code. Managed creation registers the campaign before its first use; legacy configuration still registers at first use. |
| `promotion_availabilities` | One managed campaign's current enabled state and monotonic revision, initially disabled at revision `1`. No row means legacy configuration ownership, not an enabled managed campaign. |
| `promotion_availability_revisions` | Append-only creation and availability history, retaining campaign, revision/state and actor evidence. |
| `promotion_uses` | Existing held/pending/consumed identities and immutable calculation linkage. Availability changes do not release, remove or rewrite these records. |

`create(policy, actor)` validates the existing schema and freshly authorizes the stored actor, then commits the immutable campaign, disabled availability, initial history and `commerce.promotion.created` audit together. Both persisted campaign identities and valid legacy configuration are checked for key/code collisions. Null/empty configuration permits authoring; malformed nonempty configuration fails closed. Legacy registered campaigns are read-only in administration and cannot be silently adopted or replaced.

`setAvailability(campaignId, expectedRevision, enabled, actor)` acquires the campaign row lock, reloads authorization, verifies retained evidence and rejects stale revisions and no-op transitions. A successful change advances the revision and commits state, history and `commerce.promotion.enabled`/`commerce.promotion.disabled` audit together. An A → B → A sequence therefore cannot satisfy a stale confirmation. Domain validation errors produce no partial records or audit effects.

`campaigns(actor)` supplies a protected query for bounded pagination; `detail(campaignId, actor)` supplies policy, availability and aggregate usage. It does not expose customer/owner keys, attempt IDs or individual order records. Counts are visibility at the read time, not a reserved checkout slot.

## Resolution and concurrency

Managed database codes resolve first, only in `local`/`testing`. Disabled, corrupt or invalid managed evidence cannot fall back to a matching environment entry. Unmanaged codes retain the existing strict configuration resolution. Effective dates remain a separate half-open UTC interval: enabling a future campaign leaves it scheduled; enabling an expired campaign is rejected.

New holds and held-to-pending transitions recheck managed availability while holding the same campaign row used for usage capacity. Disabling therefore serializes with these operations: work committed first remains retained; work acquiring the lock after disable cannot create a new eligible use. Commerce retains quote → catalog → pricing → campaign → uses lock order. Administration acquires campaign then its availability/history; it does not acquire catalog or pricing locks in reverse order.

Disabling changes current-use availability. It does not delete pending/consumed usage, free their capacity, reprice a frozen order or revoke a grant. Existing `PrepareOrder` idempotent retries and retained order/payment verification continue from frozen evidence. The standalone `PromotionUsage::beginAttempt` command still performs current-pricing resolution before its pending retry branch; it is not an alternate retained-order recovery path after disable.

Capacity remains lifetime-wide: current unexpired holds, every pending use and every consumed use count. Expired unstarted holds remain visible evidence but cease occupying capacity under the captured rule. This is not per-customer enforcement, a free-download license, consent collection, refund release or production policy.

## Authorization and UI

The seller surface is `/admin/test-promotions`, under **Test commerce → Test promotions**. It offers new disabled creation, review/usage, copy under a fresh identity and explicit enable/disable confirmations. It has no direct edit/delete action on saved terms. A legacy configured campaign shows its source and read-only status.

Domain commands and reads recheck the freshly loaded verified administrator; an unsaved/stale actor cannot supply permission. The Filament page rechecks role, verification, environment and the panel's required MFA provider on reactive requests. User input uses exact integer cents/basis points and UTC-second timestamps; the server owns USD/test/schema version, stacking, allocation and release behavior. Explicit immutable offer-revision eligibility can include an exclusive offer; the existing quote/inventory rules still apply. Generic non-exclusive eligibility does not include exclusives.

This uses the existing panel components and theme. No new provider, dependency, public promotion index, marketing send, customer export or secret is introduced.

## Migration, rollback and acceptance

Deploy the additive availability/history migration before application callers. Restrictive foreign keys and model/database guards retain referenced campaign, actor and revision evidence. Application rollback can withdraw authoring and new managed use while preserving all campaign, availability, history, usage, pricing, order and audit records. The migration refuses down once availability/history rows exist. A destructive schema rollback is not a campaign-disable operation; meaningful evidence must remain. Pre-D-22 code does not enforce these new controls: a code rollback must withdraw managed promotion use and must not map its codes into legacy configuration as a substitute. Restoring older code alone is not a disable operation. Empty disposable-database down/up verification is separate from production restore acceptance.

Required verification covers strict money/dates/eligibility/currency, legacy compatibility/collisions, fresh authorization, audit rollback, stale/ABA confirmations, database guards/retention, aggregate usage and frozen-order continuity. Independent-process MySQL tests must prove competing controls and disable-versus-hold/held-to-pending serialization; SQLite cannot establish those races. Actual Chromium/WebKit flows, full backend/frontend/build/audit gates and independent review must assess the final integrated candidate. Acceptance requires those gates on the final tested commit; the integrating PR and WP-09 issue #9 record their actual outcomes.

The next bounded increment is [persisted contact/about/blog/video content](D-23-editorial-content.md), then additional asset references and scheduling. Guest recovery, the complete customer library, operational payment resolution, free-download licensing/consent, other products, memberships, migration and production readiness remain open.
