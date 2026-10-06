# [WP-05] Branded catalog, track detail and persistent preview player

Status: **Catalog, public-sharing metadata, pagination/reconciliation and dedicated track detail/license disclosure are accepted through PR #40.** The [single-seller experience candidate](../verification/single-seller-experience-increment.md) adds reviewed queue/loop/speed/media-session and public-embed children. Native browser, physical-device playback, broader accessibility and production performance acceptance remain open.

- Suggested issue title: `[WP-05] Branded catalog, track detail and persistent preview player`
- Phase: 1
- Dependencies: WP-02; integrate real previews from WP-03 and structured offers from WP-04.
- Suggested branch: `work/wp-05-storefront-and-persistent-player`
- Implementation paths: resources/js, resources/css, public brand assets, Inertia controllers/props and frontend/browser tests.

## Problem

The new store must make Sean's music easy to discover and audition while using the active VASEY.AUDIO visual identity.

## First reviewable increment

Catalog-to-track-detail navigation with one persistent player, real derivative playback and accessible license comparison.

## Scope

- Current active theme and brand-source mapping; responsive layout, authentic geometry-locked logos and approved assets.
- Search/filter/sort, track details, preview queue, waveform seek, player states, volume and mobile media behavior.
- Shared typed page props for real catalog data; unavailable/empty/error states and disabled commerce until enabled server-side.
- Canonical URLs and meaningful share metadata; keyboard/focus, reduced motion and artwork/text alternatives.

## Acceptance criteria

- [ ] Only published tracks appear; query state survives navigation and pagination without accidental full-catalog fetches.
- [ ] Audio continues appropriately across Inertia navigation; one active source prevents overlapping playback.
- [ ] Waveform/controls correspond to the actual preview; absent media never produces fake playback.
- [ ] License comparison reflects structured version data; purchasing UI respects server readiness and checkout availability.
- [ ] Desktop/mobile browser review shows no overflow, illegible contrast, cut-off logo, unreachable player or keyboard trap.

## Current sharing increment — 2026-09-09

Home and eligible track URLs include server-rendered title, description, canonical URL, Open Graph and image-card metadata, with verified public artwork and image alternatives. Inertia replaces the same keyed tags across track navigation and return home. Metadata is generated after existing publication-readiness checks; private evidence is excluded. Non-production pages request no indexing. See [Public track sharing](../track-sharing.md) for the contract, source references, tests and operational limits.

This adds no payable quote, order, payment, grant, standalone track-detail layout or pagination. At that increment, full-catalog queries and mobile playback/visual acceptance remained follow-ups; pagination is addressed below. The implementing PR records actual CI results against its head; test definitions alone are not acceptance evidence.

## Pagination and cart reconciliation — 2026-09-09

Bounded server search/filter/sort and encrypted previous/next cursor positions replace full-catalog fetching. Off-page saved selections resolve through a separate bounded public endpoint. Current revision checks, retry and stale-response protection preserve cart identities without trusting stored prices. Direct track URLs resolve independently of the current page and the shared audio owner survives navigation. See [catalog contract and verification scope](../catalog-pagination.md).

The original next step was recovered from issue #5. The subsequent handoff now explicitly closes earlier foundation/admin gaps before further Phase 1 detail/player work or Phase 2 commerce; see [ordered development status](../development-order.md). CI evidence belongs to the actual PR head; device and live catalog acceptance remain unverified.

## Detail, disclosure and native browser coverage — 2026-09-12

Dedicated track artwork/metadata, exact published offer cards and full frozen license text now use the current catalog/offer revision. Same-origin lazy disclosure handles changed offers, failure, retry and late responses; all internal review and private-media evidence remains excluded. Native audio source identity includes the preview URL so a replacement for the same track loads correctly. See [contract and scoped tests](../track-detail-and-license-disclosure.md).

The candidate adds desktop Chromium/mobile WebKit scenarios for real Inertia navigation, native synthetic-WAV decoding/seeking, one audio owner, full policy scrolling, responsive overflow and dialog keyboard recovery. Browser transport fixtures are test-owned; they do not certify production publication or physical-device behavior. PHP readiness/projection tests use the real synthetic media/review/publication services. The PR records executed outcomes at the actual candidate. After this bounded increment is merged under Sean's continuous-development authorization, resume WP-06 while retaining the broader gates above.

## Verification approach

### October 6 user-testing increment

The [testing guide](../user-testing.md) exposes the existing real storefront through isolated empty/sample/detail views and a protected static deployment. It adds no production route, domain rule, media or license terms. The four-file preview increment passed focused tests and independent source review; the composed frontend passed 368 tests, both builds and client scans. Desktop deployed-browser checks cover navigation, search/genre filtering, license selection, cart and keyboard dialog dismissal. Mobile/physical-device playback and broader experience acceptance remain open. Final integration acceptance belongs to the exact integrating PR, not the deployment's availability.

Frontend type/build checks plus browser scenarios for navigation/playback/error state at desktop and mobile widths; inspect active-theme token usage and accessibility. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Revert to the previous storefront release; catalog and purchase evidence remain intact. Use feature flags for new player capabilities.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-05 from the existing frontend. Use docs/brand evidence and real typed catalog/preview data. Preserve the exact logo geometry and active theme, build an accessible persistent player, and never simulate actual audio or checkout availability.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.
