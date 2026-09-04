# [WP-04] Immutable license versions, reviewed terms and sellable offers

Status: **Planned work package**. Inspect the current implementation before starting; initial foundation code may already cover part of this scope. This issue is complete only when the acceptance evidence below exists.

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

## Acceptance criteria

- [ ] Published content, schema and approval hashes cannot be edited/deleted through any supported write path.
- [ ] UI feature bullets and contract data derive from the same structured terms; contradictory or missing variables block publication.
- [ ] Every advertised deliverable resolves to a ready exact asset revision and role; changes create a new commercial revision.
- [ ] Current offers cannot change previously frozen quote/order data.
- [ ] No legal reviewer, approval reference, license cap or production price is fabricated.

## Verification

Lifecycle, immutability including bulk/import write paths, schema/feature consistency, effective-date selection and missing deliverable tests. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Disable a new offer/version without altering prior records. Supersede terms with a new version; preserve every referenced version.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-04 using the licensing skill invariants and repository data contracts. Keep legal source, structured terms, offer, assets and grants distinct. Make immutable published versions enforceable and use clearly nonbinding fixtures while U-05 is unresolved.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.
