# [WP-09] Seller CMS, publishing, sharing and promotion administration

Status: **Partially implemented; remains open.** The first CMS increment is accepted in PR #72, test promotion administration in PR #73, persisted editorial/contact content in PR #74, scheduled whole-release publication in PR #76, fail-closed public pages in PR #77, the Site Releases row menu in PR #78 and editable site images (D-25) in PRs #81 and #82: the private library, then image slots in site releases with their public serving. Acceptance records are maintained in issue #9 and the integrating PRs. This issue is complete only when all acceptance evidence below exists.

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

## Editable site images, part 1 — September 30, 2026

[D-25](../architecture/D-25-editable-site-images.md) and the [staff guide](../site-content-releases.md#site-images) add a private library for the four fixed images Sean chose: the home hero (a desktop and a mobile crop), the studio image and the share image. Administrators upload a JPEG or PNG with a source or credit line and a rights confirmation. Intake checks the slot's shape and minimum size and refuses transparency, CMYK, more than 8 bits per channel, rotation tags, other formats and files over 20 MiB. The `media` worker scans each upload, re-encodes it without metadata into the slot's sizes and pins them by a manifest hash; database triggers keep every upload and outcome permanent. A scanner, tool or storage outage leaves the image waiting for an audited retry, while a detection or a refused file fails for good. Nothing in this part is public; part 2, below, adds the slots to site releases and serves live images at content-hashed URLs.

The Site Releases row menu from PR #78 is accepted; the [ordered record](../development-order.md#accepted-site-releases-row-menu--september-30) retains its evidence. Blog and video thumbnails, withdrawing an image and a CDN remain out of scope.

## Editable site images, part 2 — September 30, 2026

[D-25](../architecture/D-25-editable-site-images.md) part 2 lets a site release use the library's ready images. The four slots are the hero (desktop and mobile together), the studio image and the share image, each with a description.

- **Saving.** Each image's manifest is pinned in the release (schema version 3, used only when a release has an image). The references are recorded in an insert-only index whose database triggers accept only ready images of the right slot, before the release is ever scheduled or published.
- **Reading.** Reads check every reference against the database and fail the release closed on any mismatch. Publishing, restoring and the scheduler check the stored files first. Restoring away from a broken release always works.
- **Public serving.** An image is served publicly at an unguessable content-hashed URL only once a release using it has been live, with year-long immutable caching. Anything else gets the same empty no-store 404, and a damaged file fails only that image.
- **Storefront.** WebP and JPEG sources are sized to how each image is painted, and sharing metadata uses the share image with its dimensions.
- **Staff tools.** The editor warns about a bright hero under the heading, and `vasey:doctor` checks the active release's image files.

MySQL race tests cover pinning against processing completion and publishing against the scheduler. Both parts are accepted; the [ordered record](../development-order.md#accepted-image-slots-in-site-releases--september-30) retains their evidence. Withdrawing a live image, blog and video thumbnails, and a CDN remain out of scope.

## Inquiry and embed children — October 1, 2026

The [single-seller experience candidate](../verification/single-seller-experience-increment.md) adds private saved inquiries with an audited operator inbox, explicit-consent video frames and a script-free public tagged-preview embed. Public inquiry intake stays disabled until the owner-approved notice, retention reference and eligible operator are configured. The current contact email fallback remains available. These children do not establish real email delivery, buyer/order support, private support attachments, related-track associations, free-download licensing or full WP-09 acceptance. Final MySQL/native/browser and independent review evidence belongs to the integrating candidate.

## Manual related tracks child — October 1, 2026

[T16-RELATED-TRACKS-01](../verification/editorial-related-tracks.md) implements ordered first-party track links on selected blog/video detail pages. Schema 4 retains only native track IDs, with the existing immutable release and image evidence; current public readiness and inventory decide which links appear. Private previews omit track destinations on the server. Withdrawal preserves retained CMS identity and restoration, while fresh public requests omit unavailable tracks. Existing schemas 1–3 remain exact.

The isolated candidate has focused schema/domain/editor/HTTP/migration and frontend evidence. Actual MySQL contention and a genuine ordinary eligible-track native fixture remain acceptance gates, so this child, broader T16 and WP-09 are not complete. The child guide records exact checks, source boundaries and the genuine ClamAV fixture prerequisite; operational rollback never deletes retained schema-4 evidence.

## Retained inquiry alert intents — October 1, 2026 candidate

The [T15b engineering contract](../verification/inquiry-notification-intents.md) atomically retains one minimal original-operator alert intent with each saved inquiry. Optional locator-only queue wakeups and a bounded scanner recover missed dispatch; fenced claims, definite-failure retry limits and terminal unknown outcomes prevent automatic replay of ambiguous handoffs. Original encrypted input, privacy/retention evidence and truthful saved receipts are unchanged.

Both intake and notification activation remain disabled by default. No mail/provider binding is supplied; only synthetic adapters exercise this child. Submitted means handoff acceptance, not recipient delivery. Final MySQL/SQLite/source acceptance, processor/policy decisions and unknown-state operator resolution remain open. This child does not complete T15 or the original WP-09 checklist.
