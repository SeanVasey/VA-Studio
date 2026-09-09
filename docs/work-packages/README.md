# Agentic work-package index

These issue bodies cover the complete replacement. All 14 have been published; see the [GitHub register](GITHUB.md) for their observed issue URLs. The first heading in each WP file is its suggested issue title.

Each package states dependencies, a first reviewable increment, implementation paths, acceptance criteria, verification, rollback and a copyable agent prompt. Packages with several product workflows explicitly split into dependent PRs; do not turn them into one oversized change or mark them done from a mockup.

| Package | Scope | Dependencies |
| --- | --- | --- |
| [WP-01](WP-01-foundation-repository-and-ci.md) | Foundation, repository governance and reproducible CI | None |
| [WP-02](WP-02-catalog-and-admin-authorization.md) | Persistent catalog administration and publication readiness | WP-01 |
| [WP-03](WP-03-private-media-ingestion.md) | Private media ingestion, quarantine and preview processing | WP-01 and WP-02 |
| [WP-04](WP-04-versioned-licensing-and-offers.md) | Immutable license versions, reviewed terms and sellable offers | WP-02; WP-03 supplies real deliverable resolution |
| [WP-05](WP-05-storefront-and-persistent-player.md) | Branded catalog, track detail and persistent preview player | WP-02; integrate real previews from WP-03 and structured offers from WP-04 |
| [WP-06](WP-06-quotes-promotions-and-exclusive-reservations.md) | Server quotes, promotion calculations and exclusive reservations | WP-03 and WP-04 |
| [WP-07](WP-07-payment-inbox-and-finalization.md) | Hosted checkout, durable payment inbox and idempotent finalization | WP-06; WP-04 grants schema and WP-08 render interface must agree |
| [WP-08](WP-08-contracts-entitlements-and-customer-library.md) | Deterministic contracts, entitlements and customer re-downloads | WP-03, WP-04 and WP-07 |
| [WP-09](WP-09-seller-cms-sharing-and-promotions.md) | Seller CMS, publishing, sharing and promotion administration | WP-02, WP-05 and WP-06; integrate WP-08 order/support visibility |
| [WP-10](WP-10-collections-kits-services-and-merchandise.md) | Collections, sound kits, services and merchandise workflows | WP-03, WP-08 and WP-09 |
| [WP-11](WP-11-memberships-crm-and-optional-integrations.md) | Membership continuity, customer CRM and integration boundaries | WP-06, WP-07, WP-08 and WP-12 source audit |
| [WP-12](WP-12-source-audit-migration-and-seo.md) | BeatStars source audit, restartable migration and SEO continuity | Audit starts with WP-01; imports follow target schema |
| [WP-13](WP-13-security-reliability-and-release-validation.md) | Security, reliability and exact-candidate release validation | WP-01 through WP-12 for their affected release scope |
| [WP-14](WP-14-cutover-and-postlaunch-reconciliation.md) | Rehearsed domain cutover and post-launch reconciliation | WP-12, WP-13 and applicable decisions |

## Suggested parallel lanes

- Foundation/admin first: WP-01 → WP-02.
- Rights lane: WP-04 can develop against exact-asset fixtures while WP-03 implements real media.
- Presentation lane: WP-05 consumes shared catalog/preview/offer contracts; coordinate type ownership.
- Source lane: WP-12 begins read-only inventory immediately and identifies active obligations.
- Commerce lane: WP-06 → WP-07 → WP-08; require interface review before parallel writers change shared migrations.
- Seller/expanded workflows: WP-09, then independent product increments in WP-10 and membership increments in WP-11.
- Release lane: WP-13 assembles actual evidence; WP-14 executes the rehearsed cutover only once ready.

Use a branch or isolated worktree per independent writer. One owner coordinates shared migrations, types and provider event contracts. Agent prompts remain model-agnostic; Sean's current request prefers ASTRA. Do not pin future work to a model that is unavailable or assert a model identity without runtime evidence.

## Issue publication

Once the exact VASEYAUDIO repository is available, publish each body intact with its title, then add dependency issue links and milestone/labels. A retry should detect already-published work-package IDs before creating duplicates. Record observed issue URLs rather than guessed URLs.

The [roadmap](../architecture/roadmap.md) governs phase order. The parity/source ledger governs complete coverage: any verified feature not covered by an accepted issue becomes an explicit child issue, not an implicit omission. Active memberships, pending fulfillment and historical access can elevate later work into cutover blockers.

## Current execution order

Read [ordered development status](../development-order.md) before choosing the next increment. It reconciles merged work, earlier acceptance gaps and the owner’s instruction to return to the pre-Stripe sequence.
