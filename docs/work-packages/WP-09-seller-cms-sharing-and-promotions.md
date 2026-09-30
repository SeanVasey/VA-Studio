# [WP-09] Seller CMS, publishing, sharing and promotion administration

Status: **Partially implemented; remains open.** The first CMS increment is accepted in PR #72, test promotion administration in PR #73 and persisted editorial/contact content in PR #74, and scheduled whole-release publication in PR #76. Fail-closed public pages are the current increment, followed by the Site Releases row menu and D-25 editable site images; acceptance records are maintained in issue #9 and the integrating PRs. This issue is complete only when all acceptance evidence below exists.

- Suggested issue title: `[WP-09] Seller CMS, publishing, sharing and promotion administration`
- Phase: 3
- Dependencies: WP-02, WP-05 and WP-06; integrate WP-08 order/support visibility.
- Suggested branch: `work/wp-09-seller-cms-sharing-and-promotions`
- Implementation paths: app/Domain/SiteBuilder, app/Filament CMS/promotions, resources/js pages, site release migrations and CMS tests.

## Problem

Sean must update the site, publish and share releases, and run promotions without code edits or accidental changes to sold terms.

## First reviewable increment

Versioned home/navigation/content release with preview and rollback, plus shareable track metadata and one promotion editor.

## Scope

- Editable hero/sections/navigation/footer, contact/about, blog and video content; asset references and scheduling.
- Versioned site-release preview, atomic publish pointer and previous-release rollback.
- Canonical/social metadata, share links and draft/private sharing controls; share UI does not send outreach without authorization.
- Promotion UI for existing server rules, usage visibility, bundles/free-download presentation and lawful consent separation.
- Operator views for catalog readiness and order/support handoff; no arbitrary paid/grant toggles.

## Acceptance criteria

- [ ] Draft changes do not affect live content; publication validates links/assets and records actor/version.
- [ ] Rollback restores content without changing catalog purchases, grants or provider state.
- [ ] Blog/video/contact and social metadata are real persisted flows, not static placeholder links.
- [x] Test-mode promotion editor preserves server currency/limits/inventory and displays expired promotions correctly. Production promotion readiness remains open.
- [ ] Public/free-download flows show the actual license and separate optional marketing consent.

## Verification

CMS preview/publish/revert browser tests, direct-route draft privacy, link/metadata checks, promotion validation and consent records. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Atomically restore prior site release; disable new promotion use while keeping historic quote calculations intact.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-09 as a usable seller publishing workflow. Keep content releases distinct from transaction history, connect promotions to server rules and create real share metadata. Work in reviewable increments and do not send marketing messages as part of testing.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.


## First CMS slice — September 28, 2026

Accepted in [PR #72](https://github.com/VASEYDEV/VASEYAUDIO/pull/72): [site content/releases](../site-content-releases.md), [D-21](../architecture/D-21-site-content-releases.md). This adds immutable plain-text home/studio/footer/navigation/SEO snapshots, private staff previews, atomic publication and rollback with original-baseline retention. Drafts cannot change live text; purchase/provider/rights records are outside these commands. Existing theme artwork and identity remain fixed.

The [ordered acceptance record](../development-order.md#accepted-downloads-and-cms--september-28-2026) retains exact source/tree/merge and all 10 passing CI jobs, including full MySQL/SQLite suites, real MySQL publication/rollback races, Chromium/WebKit flows, build/audits and independent source review. The prerequisite owner downloads in PR #71 are also accepted. This broad work package remains open.

## Test promotion administration — September 29, 2026

Accepted local/testing scope in [PR #73](https://github.com/VASEYDEV/VASEYAUDIO/pull/73): [D-22](../architecture/D-22-promotion-administration.md) and the [promotion administration contract](../promotion-pricing.md#seller-administration--september-29-2026). Staff create immutable disabled test campaigns, review terms, enable/disable them using a monotonic expected revision and inspect bounded aggregate usage. Copying requires a fresh lifetime key/code; there is no mutable published policy or persisted editable promotion draft. Legacy configured campaigns remain read-only in administration.

The domain reuses server-owned USD/currency, money, eligibility, dates, non-stacking, allocation and lifetime-capacity rules. Availability shares the campaign lock with new usage holds and held-to-pending transitions. Disabling does not erase pending/consumed usage or rewrite frozen orders. Authorization, audit atomicity, stale/ABA confirmation, strict inputs, database retention, independent MySQL contention and actual browser workflow are required gates. All ten required CI jobs and independent review accepted the final tested commit. The [ordered acceptance record](../development-order.md#accepted-test-promotion-administration--september-29) retains the exact tested source, merge, MySQL/SQLite/browser results and post-merge verification.

The next increment is persisted contact/about/blog/video content, then editable asset references and scheduling. Existing public track sharing metadata remains available. Free-download licensing/consent, broader sharing controls and business-resolution/support workflows remain separate domain-dependent work. Do not close the original acceptance checklist from these partial slices.

## Persisted editorial/contact content — September 29, 2026

[D-23](../architecture/D-23-editorial-content.md) and the [operator guide](../editorial-content.md) extend immutable complete site releases to optional about/contact pages and blog/video listings with detail routes. Newly authored schema-v2 releases preserve all retained v1 evidence; private previews remain pinned to one saved release and public routes project only selected content. Navigation validates enabled destinations. Publication/rollback continue through the existing monotonic pointer, authorization and audit transaction. No new migration, imported source content or commerce mutation is introduced.

This is a bounded implementation contract; the integrating PR and issue #9 record actual acceptance against the final tested source. Required coverage includes v1/v2 compatibility, link/provider/email validation, draft privacy, active-only route/metadata projection, fresh staff/MFA checks, preview pinning, publication/rollback and full MySQL/SQLite/browser/build/audit gates with independent review.

Contact supplies published copy and an encoded email link, not an inbound inquiry service or verified delivery. Video entries supply typed provider watch links, not consent-aware embeds or related-track associations. Editable assets, scheduling, source slug/date migration, broader sharing/support and free-download licensing/consent remain open. The original broad acceptance checklist is not complete from these partial flows.

## Scheduled publication — September 29, 2026

[D-24](../architecture/D-24-scheduled-site-publication.md) and the [operator guide](../site-content-releases.md#scheduled-publication) add one pending schedule for a complete saved release at a whole UTC minute. A minute runner publishes it through the existing lock, expected revision and audit transaction, attributed to the scheduling administrator. Staff publication or restoration replaces a pending schedule after a warning, and cancellation is explicit. Missed, unauthorized, stale or corrupt schedules fail closed and keep their reasons. Production needs a scheduler cron entry on the undecided host.

Editorial content from PR #74 is accepted; the [ordered record](../development-order.md#accepted-editorial-content--september-29) retains its evidence. Editable asset references are next. Track-release scheduling, local-time display and chained schedules remain separate work. The original broad acceptance checklist is not complete from these partial flows.
