# [WP-09] Seller CMS, publishing, sharing and promotion administration

Status: **Planned work package**. Inspect the current implementation before starting; initial foundation code may already cover part of this scope. This issue is complete only when the acceptance evidence below exists.

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
- [ ] Promotion editor cannot bypass server currency/limits/inventory; expired promotions display correctly.
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

Candidate implementation: [site content/releases](../site-content-releases.md), [D-21](../architecture/D-21-site-content-releases.md). This adds immutable plain-text home/studio/footer/navigation/SEO snapshots, private staff previews, atomic publication and rollback with original-baseline retention. Drafts cannot change live text; purchase/provider/rights records are outside these commands. Existing theme artwork and identity remain fixed.

Local integrated CMS checks: 24 passed /229 assertions and two intentional MySQL-only skips. MySQL contention and actual browser publish/preview/rollback remain CI gates; independent domain review accepted the component, while final integrated review and required CI remain pending. Exact final source/run/merge disposition belongs in the integrating PR. This broad work package remains open.

The first slice deliberately precedes the promotion editor; existing track sharing metadata remains available. Next implement promotion administration using the accepted server currency/limits/inventory rules, then persisted contact/about/blog/video, editable asset references and scheduling. Free-download consent and business-resolution/support workflows remain separate domain-dependent work. Do not close the original acceptance checklist from this slice.
