# [WP-04] Immutable license versions, reviewed terms and sellable offers

Status: **PRs #32/#33 are merged; economic policies are the current bounded increment. WP-04 remains open.** PR #22 established review evidence and commercial snapshots; PR #32 added typed usage/permission/credit terms and source variables. PR #33 added territory/duration. Current schema v4 supplies ownership policy declarations, income/royalty fields and retained policy references. Actual reviewed policy and buyer contract fulfillment remain separate dependencies.

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

## Historical increment — 2026-09-04

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

## Territory and duration increment — 2026-09-11

Schema v3 adds explicit permitted territory and perpetual/fixed calendar-month duration from grant, separate from new-offer availability. Cards and source variables share exact statements. New admin drafts use v3; a deliberate v2-to-v3 mapping preserves earlier evidence and requires both new choices. Ordinary successors keep their predecessor schema. A dedicated v3 renderer and additive migration retain all v1/v2 bytes and review evidence. No grant endpoint, geographic enforcement or legal policy is inferred.

See [operator/schema guide](../license-territory-duration.md) and [verification record](../verification/license-scope.md). The integrating PR records actual tested candidate, results and independent review. Ownership/publishing/royalty concepts and versioned policy references follow before WP-05 detail/device work.

## Ownership and economic policy increment — 2026-09-12

Schema v4 separates four ownership subjects through explicit retained policy declarations, a licensor publishing-income share and a licensee-to-licensor recording royalty with a defined receipts basis. Integer basis points have no production default; no ownership remainder or collaborator payout is inferred. Every policy reference resolves to complete text retained in the same immutable license version. The server hashes exact policy bytes, and the full bundle occurs exactly once in source substitution. Compact card statements identify the referenced policies; complete text stays in source/review/offer/quote evidence.

New admin drafts use v4. Explicit v3-to-v4 mapping starts new economic decisions blank; ordinary successors preserve their schema. A pinned v4 renderer and forward lifecycle-guard migration preserve v1–v3 validators, previews, review evidence and commercial snapshots. See [schema/operator guide](../license-economic-policies.md) and [verification](../verification/license-economics.md), with actual tested commits/results in the integrating PR.

After acceptance, the next ordered implementation is WP-05 track detail, full frozen policy disclosure and device/player work. This is a bounded policy-declaration model, not verified chain of title, an ownership allocation engine, royalty accounting or an executed contract.

## Remaining work within WP-04

- Actual seller-approved production source, reviewed license matrix, reviewer evidence and rights policy under U-05.
- Production representative policy/contract consistency evidence and full buyer-facing policy disclosure under WP-05. Economic declarations and retained references are implemented in this increment; actual ownership allocations and policy prose still require source-consistency review and approval.
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

Retain compatible v1/v2/v3/v4 validators/renderers and database guards wherever their versions are retained. Reverting to older-only code makes later licenses unavailable; do not rewrite them to fit an old schema. The migration down restores the prior transition guard for disposable/verified-compatible environments only and does not erase records. Disable a new offer/version without altering prior records. Supersede terms with a new version; preserve every referenced version.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-04 using the licensing skill invariants and repository data contracts. Keep legal source, structured terms, offer, assets and grants distinct. Make immutable published versions enforceable and use clearly nonbinding fixtures while U-05 is unresolved.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.

## License-template authoring follow-up — October 6, 2026

The independently reviewed [template-authoring child](../verification/license-template-authoring.md) replaces generic identity edits with captured, audited domain commands and truthful non-draft freeze guidance. Exact composition, native/SQLite/UI evidence and remaining hosted acceptance are recorded in the [follow-up integration record](../verification/template-authoring-and-inquiry-history-composition.md). It does not revise approved terms, introduce new license policy or complete WP-04/T12.
