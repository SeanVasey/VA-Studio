# [WP-10] Collections, sound kits, services and merchandise workflows

Status: **Planned work package**. Inspect the current implementation before starting; initial foundation code may already cover part of this scope. This issue is complete only when the acceptance evidence below exists.

- Suggested issue title: `[WP-10] Collections, sound kits, services and merchandise workflows`
- Phase: 3; elevate any active source obligation before cutover
- Dependencies: WP-03, WP-08 and WP-09. Services/merch require U-12; audit findings from WP-12 determine urgency.
- Suggested branch: `work/wp-10-collections-kits-services-and-merchandise`
- Implementation paths: app/Domain/Catalog collections/kits, app/Domain/Services, app/Domain/Merch, provider adapters, seller/customer pages and product workflow tests.

## Problem

A complete store replacement must preserve product types beyond individual beats and their distinct fulfillment promises.

## First reviewable increment

First PR: collections/albums and a licensed sound-kit archive using existing digital delivery. Then separate dependent PRs for service inquiry/milestones and merchandise provider lifecycle.

## Scope

- Collections/albums with ordered tracks and album/bundle licensing semantics; kits/presets with manifests, demos and exact archive entitlement.
- Services: inquiry/brief with private uploads, operator estimate, approved quote/deposit, milestone/revision and delivery state.
- Merchandise: variants, stock/source mapping, shipping/tax quote, paid fulfillment request, tracking and return/refund handoff.
- Distinguish operational integration from rebuilding a shipping or booking provider; unavailable workflows remain explicitly unavailable.

## Acceptance criteria

- [ ] Album/kit purchases resolve exact included assets and license semantics; previews cannot expose archives.
- [ ] Service buyer/operator can trace scope, deposit and deliverable milestones with private briefs and audit.
- [ ] Merch test order reconciles one provider fulfillment through status/tracking and exception/return paths without duplicate submission.
- [ ] Each product type has real admin creation, storefront, payment/fulfillment/support and cancellation behavior under approved policies.
- [ ] Pending legacy service/merch obligations from source audit have a tested continuity plan before retirement.

## Verification

One end-to-end synthetic acceptance per product type plus archive safety, duplicate provider submit, service attachment authorization, shipping/tax mismatch and return exception tests. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Disable new sales for the affected product type; retain fulfillment/status access for existing obligations. Reconcile provider submissions before retries.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-10 in its named small increments, starting with kits/collections. Reuse licensed digital delivery where valid; keep services and merchandise state machines distinct. Split follow-up PRs with explicit acceptance and do not claim a navigation card constitutes product parity.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.

## Private WAV kit intake composition — October 6, 2026

Reviewed kit source `7e2d83d` joins the customer/collection/inquiry/unpaid-order candidate. Staff can create private WAV kit drafts, retain source provenance, upload exact ZIP revisions, inspect immutable technical manifests and retry processing. [Kit evidence](../sound-kit-intake.md) records 41 shared-engine cases / 298 assertions, 50 native MySQL cases / 660 assertions and unchanged 58-case / 589-assertion stems regressions. Independent review re-executed the shared-engine and stems selections. Full combined CI and native browser acceptance remain separate.

The whole-file admin form is bounded below 9 MiB by the current development HTTP server; its 200 MiB application ceiling is not proof of upload admission. Typed resumable kit transport is being developed separately. This technical intake does not issue kit licenses, create prices, expose public archives, grant purchases or implement preset/MIDI formats. Those required product workflows remain open.
