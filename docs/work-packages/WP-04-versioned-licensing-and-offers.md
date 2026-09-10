# [WP-04] Immutable license versions, reviewed terms and sellable offers

Status: **First review-evidence and offer-revision increment implemented in [PR #22](https://github.com/VASEYDEV/VASEYAUDIO/pull/22); broader work package remains open.** This increment binds human review to exact content and separates editable offers from published commercial snapshots. It does not implement a complete machine-readable legal schema or buyer contract fulfillment.

- Suggested issue title: `[WP-04] Immutable license versions, reviewed terms and sellable offers`
- Phase: 1
- Dependencies: WP-02; WP-03 supplies real deliverable resolution. Final legal text is U-05.
- Suggested branch: `work/wp-04-versioned-licensing-and-offers`
- Implementation paths: app/Domain/Rights, app/Domain/Catalog readiness, app/Filament licensing resources, database/migrations and license tests.

## Problem

The buyer-facing license card, exact purchased rights and deliverable files must agree and remain durable after future edits.

## First reviewable increment

Template/version lifecycle, normalized terms schema and product-license offer with exact deliverable roles.

## Scope

- Separate authored source, structured terms, feature bullets, approval evidence, renderer reference and effective dates.
- Draft/review/approved/published lifecycle; successor version/diff replaces in-place editing of published content.
- Offer revision with integer price/currency, exact asset manifest, rights declaration and shared exclusive-scope reference.
- Admin editor/comparator preview and readiness; synthetic nonbinding fixtures until actual reviewed terms are supplied.

## Current increment — 2026-09-04

- Server-authored review payload with source, schema-versioned summaries/roles, template identity, effective UTC window and deterministic escaped HTML preview hash. Review submission freezes that exact content.
- Separate authorized reviewer approval bound to the submission SHA-256, actual review reference and explicit source/summary consistency attestation. Immutable review evidence and SQL guards protect submitted/reviewed content; human approval does not establish reviewer legal qualification.
- Monotonic successor drafts and comparison of source, structured fields and dates. Historical approvals are retained without invented new evidence; a reviewed successor is required for new availability.
- Editable offer drafts separated from immutable commercial revisions. Explicit publication captures price/currency, exact license/review evidence, rights identity, preview lineage and exact asset hashes/roles/MIME/size. Editing a draft keeps the current published revision intact.
- History, deactivation and storefront/cart revision identity. Legacy active offers without a revision require explicit operator publication. Checkout remains unavailable; no quote, order, contract, grant or provider integration is included.

See [Licensing and offers](../licensing-and-offers.md) for the operator workflow and upgrade sequence. Schema version 1 validates feature-summary strings and required delivery roles; it does not interpret legal prose, resolve contract variables or mechanically prove that summaries agree with the source.

## Typed usage terms increment — 2026-09-10

Schema v2 requires explicit prohibited/limited/unlimited choices for seven usage categories, four permitted/prohibited choices, a producer-credit decision and exact deliverable roles. It accepts no independently authored feature list. The domain generates summaries and the same allowlisted source-variable values; missing, unknown or malformed variables and contradictory/invalid limits block content validation and review submission. The v2 review preview shows retained source, substituted text, generated cards and a variable comparison table. Human review still assesses surrounding prose and actual policy.

New admin drafts use these typed fields. **Define usage rights** deliberately maps a v1 version into a linked typed successor without inferred caps; **New revision** retains its predecessor's schema. Existing v1 validators, literal preview bytes, review hashes and frozen offers/quotes remain valid. A forward migration replaces only the lifecycle state guard, allowing the exact v1/v1 and v2/v2 schema/renderer pairs while preserving all content/evidence protections. It installs the successor guard before removing the old guard and changes no stored rows.

See [typed licensing verification](../verification/typed-licensing.md) and [operator/schema guide](../typed-license-terms.md). The integrating PR records actual CI outcomes; this is a bounded usage-policy model, not a complete license or executed buyer contract.

## Remaining work within WP-04

- Actual seller-approved production source, reviewed license matrix, reviewer evidence and rights policy under U-05.
- Remaining typed fields for territory, license duration, ownership/publishing/royalties and versioned policy references; representative policy/contract consistency fixtures. The current usage/permission/credit variables are bounded and still require human source-consistency review.
- Buyer-specific deterministic contract rendering, PDF/archive format and reproducibility acceptance under U-06/WP-08.
- Quote/order snapshot integration under WP-06/WP-07, shared exclusive inventory and reservation policy; current publication is limited to positive USD non-exclusive offers.
- Historical source-contract reconciliation and continuity evidence. Existing records are retained; no replacement approval or executed contract is inferred from a migration.

## Acceptance criteria

- [x] Published content, schema and approval hashes cannot be edited/deleted through supported application/model and ordinary bulk SQL paths.
- [ ] UI feature bullets and contract data derive from the same structured terms; contradictory or missing variables block publication.
- [x] Every advertised deliverable resolves to a ready exact asset revision and role; changes create a new commercial revision.
- [ ] Current offers cannot change previously frozen quote/order data.
- [x] No legal reviewer, approval reference, license cap or production price is fabricated; test-only evidence remains explicitly synthetic and nonbinding.

## Verification

See the [verification record](../verification/licensing-and-offers.md) for exact commits, environment, commands and scope. Local integration passed 99 PHP tests / 559 assertions and 18 frontend tests, plus the production build. Independent verification passed 45 focused PHP tests / 282 assertions and found no unresolved blocking finding in the examined paths. The PR records GitHub MySQL/SQLite CI results separately. Full legal-schema/variable consistency, buyer-contract fixtures, quote/order isolation and production policy evidence remain pending.

## Rollback and boundaries

Retain the v2-aware application validator/renderer and database guard wherever v2 versions are retained. Reverting to v1-only code makes v2 licenses unavailable; do not rewrite them to fit v1. The migration down restores the prior transition guard for disposable/verified-compatible environments only and does not erase records. Disable a new offer/version without altering prior records. Supersede terms with a new version; preserve every referenced version.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-04 using the licensing skill invariants and repository data contracts. Keep legal source, structured terms, offer, assets and grants distinct. Make immutable published versions enforceable and use clearly nonbinding fixtures while U-05 is unresolved.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.
