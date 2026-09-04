# [WP-05] Branded catalog, track detail and persistent preview player

Status: **Planned work package**. Inspect the current implementation before starting; initial foundation code may already cover part of this scope. This issue is complete only when the acceptance evidence below exists.

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

## Verification

Frontend type/build checks plus browser scenarios for navigation/playback/error state at desktop and mobile widths; inspect active-theme token usage and accessibility. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Revert to the previous storefront release; catalog and purchase evidence remain intact. Use feature flags for new player capabilities.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-05 from the existing frontend. Use docs/brand evidence and real typed catalog/preview data. Preserve the exact logo geometry and active theme, build an accessible persistent player, and never simulate actual audio or checkout availability.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.
