# Decision and status register

As of 2026-09-04. This is the initial register; entry keys are local tracking keys, not claims of historic accepted ADRs. Append dated changes and evidence instead of rewriting decision history.

## T12-RIGHTS-EVIDENCE-GUARD-01: retain verified rights evidence

October 2, 2026. The SQL retention floor is verified and merged in PR #105, supporting T12 / WP-02 and WP-04's existing evidence-retention invariant. All thirteen applicable PR jobs passed; separate main execution is blocked by GitHub payment authorization. The [current queue](../development-control.md) records the active transactional writer candidate. [The implementation record](../verification/rights-evidence-guards.md) describes seven owned triggers and their bounded parent-retention protections. No parent is completed and no row is added to the 40-task register.

### Existing rule and reproduced gap

`RightsDeclaration` already requires verified evidence to be immutable and corrections to use a new declaration. Its updating hook checks the retained model's original status. A model loaded while pending can therefore update the row after a separate verifier has persisted verified status, without changing the original verifier/time or adding a new verification audit.

An isolated source-bound probe on `81536814421b32f4235aa88b578d5e8e6c96b23b`, tree `9d74f3ebd276b32bfd86cf861d94cbaef55a6b8c`, executed nine cases / 48 assertions. It reproduced stale-model evidence changes and cross-track retargeting, raw SQL mutation and deletion of an unreferenced verified declaration. A fresh verified model refuses the update. A separately strengthened mounted-admin control passed one case / nine assertions: the pending edit actually mounted with exact data, then saving after verification left the verified row unchanged. No HTTP interleaving exploit or MySQL race was proved. The relevant source/vendor files remain byte-identical in accepted PR #104.

The same probe separately demonstrates an unenrolled direct verifier under required panel MFA and a controlled gap between the pretransaction Gate read and the write. These require a later current-authority/MFA writer boundary; SQL evidence guards alone do not resolve them.

### Implemented SQL floor and historical preparation

Forward migration `2026_10_02_000035_rights_evidence_guards.php`, dedicated behavior/migration/concurrency test sources and exact fixture adaptations are implemented in the candidate. Seven owned SQLite/MySQL triggers cover rights INSERT, AFTER INSERT, UPDATE and DELETE plus parent-track INSERT, UPDATE and DELETE. They do not rewrite rows, audits, verification identity or commercial snapshots. Their definitions and test registration do not establish hosted acceptance.

- Refuse stored verified-row changes/deletion, conflicting identity displacement and replacement/upsert collisions. Test explicit-ID and engine-specific replacement behavior; do not infer protection from deletion triggers alone.
- Guard parent-track identity deletion/replacement and actual unique-key collisions. Concurrent parent retention also relies on validated RESTRICT/NO ACTION foreign keys; sequential foreign-key-disabled cases prove guard causality, not concurrent protection with disabled constraints or arbitrary cascades.
- Keep pending edits, ordinary pending-to-verified service transitions and genuinely new declarations available. Historical rows and their original audits remain unchanged; no migration fabricates approval evidence.
- Preflight the real schema, owned guard names/definitions and temporary/shadow ownership before DDL. Refuse drift/foreign definitions, resume only an exact surviving creation prefix, and retain all seven guards during operational rollback. Ordinary-DML protection is not privileged-DDL protection.
- Preserve byte-exact retained offer revisions and original quote/order/grant/contract evidence. Newest pending declarations continue to block new readiness under existing semantics; they never rewrite an earlier verified row.

Four existing tests formerly downgraded verified declarations: `BulkTrackTagsTest`, `BulkUpdateTrackMetadataTest`, `TrackPublicationGuardTest` and `ExclusiveOfferTest`. The candidate appends a newer pending declaration instead, retaining every existing refusal, stale-review and whole-batch atomicity assertion.

### Acceptance and following dependency

Acceptance must reproduce the stale model first, then prove current stored verified evidence is retained across model/query/raw SQL, replacement/upsert and bounded parent-deletion paths on both supported engines. Migration recovery/refusal cases and a genuine independent-process rights-row wait are registered. The MySQL race has two exact datasets, stale model update and stale model delete, with matching SQLite skip identities; positive actual hosted MySQL execution is still required. SQLite does not prove the lock contract.

The SQL candidate introduces no fresh transactional actor/MFA checks, locking read or writer command; the direct-verifier authority/MFA gaps remain. After this floor is accepted, centralize supported rights writers with fresh transactional authority/MFA, compatible actor/track/rights locks and explicit reviewed identity. The subsequent manifest apply boundary also needs compatible exclusive-scope/currentness fencing and honest physical-byte proof. Existing exclusive writers and finalization have different cross-family lock orders; a static potential cycle is not an executed MySQL deadlock proof. Do not introduce a reverse lock order from the track reader without reviewing all participating writers.

Enforcing the existing immutable-evidence rule needs no new legal or scheduling decision. Production clearance/reviewer policy, recovery roles, financial rights consequences or retention exemptions remain separate owner-policy work. Track lead time, horizon, precision, grace and pending-schedule disposition after manual publication remain undecided; site-release timing rules do not transfer to tracks. This prerequisite provides no schedule, retained publication approval, apply fence or production activation.

## D-07 confirmed — single-seller personal-use store (2026-09-30)

**Explicit owner decision.** Sean clarified that the BeatStars replacement is for his personal business use: upload, publish, share, license and sell his own content to front-end listeners and customers. It is not a competing platform for multiple independent sellers. This confirms the existing first-party house-store direction.

Include Sean's administration, his catalog and commerce, customer accounts/recovery/downloads/support, and retained products/memberships offered by him. Authorized helpers or reviewers do not imply tenant businesses. Collaborator rights/credits or agreed revenue accounting concern his own content.

Exclude seller onboarding, tenant stores, seller SaaS subscriptions, marketplace discovery/ranking across sellers, platform commissions, connected-seller payout infrastructure and enterprise organization hierarchies. Do not add those systems from generic BeatStars platform research. Any collaborator-payment integration requires its own actual business need; it does not authorize a marketplace.

The [remaining execution plan](../remaining-development-plan.md) applies this boundary to every task and parity row. The architecture already uses a single-seller modular application, so existing customer/payment/rights/privacy/continuity requirements remain applicable. Product families or optional integrations may be narrowed only by an explicit scope decision that preserves actual obligations.

## Payment account observation — 2026-09-09

Sean confirmed that Vasey Multimedia is connected to Stripe for production and test use, then instructed continued development. The connected Stripe account listing exposed **Vasey Multimedia in both live and test modes**. Account availability is observed; live charge capability, tax registrations, currency/capture settings, application credentials and a deployed webhook destination have not been verified.

U-04 is therefore partially resolved: use Vasey Multimedia's **test mode** for the current payment integration, with its production mode designated for eventual launch. Do not describe missing account connection as the reason purchasing is incomplete. The remaining dependencies are application workflow/configuration and the unresolved production policy/readiness items already listed below.

The 2026-09-09 WP-07 receipt prerequisite adds test-only own-account snapshot ingestion and immutable evidence without making provisional quotes payable. Its runtime account ID and endpoint secret remain host configuration. The ChatGPT connection does not configure the Laravel runtime. Live payment activation and full WP-07 completion are not implied by this observation.

## Established direction and working baseline

| Key | Topic | Status / authority | Consequence |
| --- | --- | --- | --- |
| D-01 | Replace all aspects of BeatStars for VASEY.AUDIO | **Decision** — Sean's current request | Build storefront, seller administration, music/media, licensing, commerce, customer delivery, and a verified migration in pieces. |
| D-02 | Repository identity | **Decision** — Sean requested VASEYAUDIO | Target GitHub repository name is VASEYAUDIO. Repository existence, namespace and permissions require observed GitHub evidence. |
| D-03 | Current active theme plus newer design craft | **Decision** — Sean's current request | Current www.vasey.audio theme outranks older palette studies. See brand source mapping. |
| D-04 | Baseline Laravel modular monolith | **Recommendation in implementation** — architecture skill baseline plus authorized reversible implementation | Laravel 13 / PHP 8.4, MySQL 8.4, Inertia 3 + React/TypeScript, Filament 5, Redis workers. Do not call this a separate explicit owner stack approval. |
| D-05 | Checkout during the foundation phase | **Recommendation implemented as a development boundary** | Checkout remains disabled until payment/rights/fulfillment evidence is complete. No simulated successful sale may be presented as a real transaction. |
| D-06 | Production traffic | **Unresolved** — current task begins development | Preserve the existing live business until an exact candidate, reconciliation, and cutover procedure are reviewable. |
| D-07 | First-party house store | **Decision** — explicitly confirmed by Sean on 2026-09-30 | Sean publishes/shares/sells his own content to customers. No independent seller onboarding, tenants, marketplace commissions or seller SaaS. Contributor metadata concerns his catalog; collaborator payouts need a separate actual business/provider decision. |

## Decisions that remain open

“Owner” below means resolution responsibility, not an assertion that a reviewer or provider has been hired.

| Key | Open decision / resolution method | Owner | What it blocks | Safe work now |
| --- | --- | --- | --- | --- |
| U-01 | **Resolved 2026-10-10.** The repository is `SeanVasey/VA-Studio` (`ops/staging/README.md`, provisioning step 4; PR links in `docs/handoff/2026-10-08/MONDAY-PLAN.md`, status log). Earlier records link `VASEYDEV/VASEYAUDIO`, the repository D-02 named. | Sean | Nothing | Keep both names readable in historical records; do not rewrite old PR links. |
| U-02 | Production host, region, deployment topology, secrets, backups and incident ownership; compare deployable options with actual workload | Sean + operator | Public production deployment | Local development, CI configuration, containers and deployment checklist. |
| U-03 | R2 versus S3-compatible storage; verify privacy, retention, egress and restore needs | Sean + operator | Production asset upload/download | Storage interface, private local test adapter, quarantine and entitlement rules. |
| U-04 | Payment account owner, supported rails, currency, capture semantics, tax settings and reconciliation. **Partially resolved 2026-10-24:** merchant of record is Vasey Multimedia dba VASEY.AUDIO (see the 2026-10-24 register update) | Sean + commerce operator/accounting | Live checkout | Stripe adapter and synthetic test-mode fixtures; no new live provider account implied. |
| U-05 | Seller identity, reviewed license matrix and exact terms, rights declarations, exclusivity transfer, refund/dispute and privacy policy. **Partially resolved 2026-10-24:** seller/licensor is Sean Vasey; drafts await Sean's approval (see the 2026-10-24 register update) | Sean + qualified reviewers | Publication of legally active products and live contracts | Draft schema, marked nonbinding fixtures, approval lifecycle. |
| U-06 | Contract renderer, archival format and reproducibility standard; compare pinned offline renderers | Technical lead + legal/privacy reviewer | Fulfillment acceptance | Frozen contract input, hash contract and renderer adapter. |
| U-07 | Guest purchases versus account-first; recovery, claim identity and admin MFA design | Sean + security lead | Production customer claim/recovery flow | Account-first interfaces and owner-checked entitlement policies. |
| U-08 | Exclusive reservation TTL, late-payment policy, pending non-exclusive cutoff and post-refund availability | Sean + qualified legal/commerce review | Exclusive live checkout | Shared rights-inventory locking and paid-exception mechanism. |
| U-09 | Actual BeatStars source exports, licensed masters, historic contracts/orders, download rights, consent and memberships | Sean + migration operator | Scope acceptance and domain cutover | Manifest schema, authorized read-only audit, dry-run importer. |
| U-10 | Membership plans, credits, rollover, grandfathered rights, renewal/cancellation and provider portability | Sean + commerce operator | New memberships; any cutover that would strand an active member | Ledger and provider interface; continuity plan from source audit. |
| U-11 | Additional rails, offers, license upgrades, collaborator payouts, affiliate rewards, crypto, e-sign and distribution/publishing handoffs | Sean + appropriate reviewer/provider | Each affected feature, not baseline development | Feature contracts and verified integration research. Existing obligations elevate priority. |
| U-12 | Merch fulfillment provider, shipping, returns and service deposit/booking terms | Sean + operator | Physical goods or service payments | Product types, draft CMS and inquiry workflows. |
| U-13 | Final retained-data schedule, consent purposes, analytics/email processors and account deletion exemptions | Sean + privacy/legal reviewer | Marketing activation and production retention settings | Separate consent ledger and transactional notification interface. |

Do not seek a fresh approval for each reversible implementation step already within Sean's direction. Resolve routine implementation choices, record them, and continue. Ask only when a missing decision materially changes an external commitment or prevents the next safe step.

## Baseline rationale and effects

The packaged architecture recommends a modular monolith. The supplied research contains broader alternative stacks and integration ambitions; those are retained as requirements and options, not silently adopted dependencies. Official documentation confirms Laravel 13, the Inertia v3 React adapter and Filament 5 are documented surfaces as of this draft. Actual lockfile installation and build tests remain the implementation evidence. [Laravel releases](https://laravel.com/framework/docs/releases), [Inertia v3 setup](https://inertiajs.com/docs/v3/installation/client-side-setup), [Filament 5 installation](https://filamentphp.com/docs/5.x/introduction/installation).

| Surface | Working choice and tradeoff | Required evidence / reversal |
| --- | --- | --- |
| Domain/data | One MySQL transaction boundary for payment, rights inventory and grants; module separation in code | MySQL integration/concurrency tests; additive migrations and documented forward repair. |
| UI/admin | React storefront and Filament administration share application commands | Browser/RBAC tests; feature flags permit individual feature rollback. |
| Jobs/providers | Redis-backed workers; private object-store and payment adapters | Retry/replay tests and health checks; drain jobs before incompatible deploys. |
| Cost/operations | Fewer deployable services; operator still owns app, DB, workers, storage and backups | Measure media minutes, stored GB, download GB, workload and support burden; no fabricated budget. |
| Security/privacy | Same-origin sessions, least privilege, protected masters and separated consent | Threat review, authorization tests, redacted logs and restore drill. |
| Legal/accounting | Published terms immutable; final policy decisions unresolved | Qualified review references and approved versions before live sales. |

No need for a search cluster, event-streaming platform, custom card handling, speculative multi-region writes or marketplace payouts is established. A future material departure requires a proposed ADR with one reversible decision, options including the present baseline, domain/data/API/event/provider effects, threat and cost review, acceptance evidence, migration and rollback. It becomes accepted only with its actual authority recorded.

Decision readiness: **Provisional**. This does not block repository scaffolding or provider-independent implementation. Live commerce and cutover remain blocked by the applicable unresolved entries.


## Implementation entry — 2026-09-04: review evidence and commercial revisions

**Recommendation in implementation, within Sean's authorized continued development.** WP-04 now separates a server-frozen license review submission from independent approval evidence, and separates editable offer drafts from immutable published commercial revisions. Schema version 1 carries reviewed feature summaries and exact delivery roles. Its escaped offline HTML preview is review evidence; U-05 production legal policy and U-06 buyer/PDF contract rendering remain unresolved. No owner approval of license terms or qualification of a reviewer is inferred from the application workflow.

Historical approvals and offers remain retained. Versions lacking the new evidence require a reviewed successor; active offers lacking a commercial revision require explicit publication. The migration does not fabricate approvals, rewrite historical terms or create purchase rights. See [Licensing and offers](../licensing-and-offers.md) and [WP-04](../work-packages/WP-04-versioned-licensing-and-offers.md). Checkout, quotes, orders and grants remain outside this increment.

## Implementation entry — 2026-09-06: provisional selection reviews

**Recommendation in implementation, within Sean's authorized continued development.** Following the merged WP-04 increment, WP-06 now adds immutable server-reviewed selections of 1–10 positive USD non-exclusive track offers. Exact offer/license/file revisions, integer subtotal, issue/expiry and canonical evidence are retained. Ownership follows the browser session and authentication context; owner-scoped canonical idempotency supports retries without rewriting a prior review.

The customer action is **Review selection**. `payable` stays false; tax and total stay unknown. Its fixed 15-minute lifetime is a provisional implementation limit, not owner approval of a production price guarantee, exclusive reservation TTL or late-payment policy. It creates no buyer legal identity, assent, reservation, order or grant. U-04/U-05/U-07/U-08 remain unresolved for their live transaction scopes; development of the remaining quote/order contracts can continue. See [Provisional selection reviews](../provisional-quotes.md) and [WP-06](../work-packages/WP-06-quotes-promotions-and-exclusive-reservations.md). Checkout and live cutover remain disabled.


## D-08 implementation entry — 2026-09-12: immutable quote pricing

**Recommendation in implementation, within Sean's continuous-development authorization.** [D-08](D-08-quote-pricing-evidence.md) appends one immutable pricing record to each owned quote and preserves its original snapshot. Strict integer calculations and explicit local/testing fixed or provider-calculated tax envelopes supply reproducible evidence. Missing policy keeps tax unknown; production cannot enable these test policies. The amount comparator is only one input to later WP-07 provider verification and finalization. U-04 production tax/account policy, U-05 terms/assent and U-08 exclusive timing are not resolved by this code. The integrating PR records actual review/CI, migration and rollback evidence. Continue WP-06 promotions/shared inventory next.

## D-09 implementation entry — 2026-09-14: promotion pricing and usage holds

**Recommendation in implementation, within Sean's continuous-development authorization.** [D-09](D-09-promotion-usage.md) preserves v1 pricing and adds v2 exact single-promotion allocation, immutable test campaign policies and serialized capacity. Unstarted holds expire by their captured rule; a guarded internal attempt binding retains pending capacity until WP-07 supplies verified terminal reconciliation. No live promotion, customer identity policy, provider success or completed redemption is inferred. The integrating PR records final tests/review; shared exclusive inventory/reservations is next in WP-06.

## D-10 — Shared rights inventory foundation (2026-09-17)

[Decision and boundary](D-10-shared-inventory.md): build shared scope identity, exact revision linkage and internal test reservation lifecycle before versioning exclusive offers/quotes. Candidate CI/review remains in the integrating PR; no production TTL, ownership or payment outcome is inferred.

## D-11 — Scope-bound exclusive preparation (2026-09-23)

[Decision and boundary](D-11-exclusive-offer-preparation.md): preserve historical v1 commerce, prepare inactive v2 exclusive revisions with explicit immutable scope/link evidence, and keep public activation blocked until versioned quote/disclosure/pricing and reservation integration exist. This is authorized reversible development, not approval of U-05/U-08 legal or production policy. PR #44 is merged; the integrating preparation PR records the next candidate's actual CI/review evidence.

## D-12 — Exclusive selection integration (2026-09-23)

[Decision and boundary](D-12-exclusive-selection.md): immutable explicit test activation, governed scope coverage, quote/disclosure v2 and pricing v3 with atomic inventory. Existing non-exclusive pricing algorithms remain unchanged. U-05/U-08 production decisions remain unresolved; WP-07 supplies order/assent/provider and terminal effects next. Final candidate evidence belongs in the integrating PR.

## D-13 — Private test order preparation (2026-09-24)

[Decision and boundary](D-13-order-preparation.md): after merged PR #51, bind a complete hashed review and explicit test assent to encrypted immutable order/line evidence and one pending inventory/promotion attempt. The policy is absent by default, fresh preparation is local/testing only and requires fixed test tax. Owned retained reads/retries survive expiry and current-policy changes; terminal state support must preserve that evidence when added. This does not resolve U-04/U-05/U-07/U-08 or enable provider checkout. PR #52 merged at `5e53c2cc57938bfc1b55bfdff4d8b22c1c70fb08` with 797 MySQL tests / 6,396 assertions, 745 SQLite tests / 5,303 assertions and 52 intentional skips, 91 frontend tests, 10 browser cases, all gates and independent review. D-14 is the next bounded candidate; authoritative payment/terminal effects and WP-08 remain open.


## D-14 — Hosted test-session intent and reconciliation (2026-09-26)

[Decision and boundary](D-14-hosted-test-checkout.md): strict local/testing-only policy, encrypted durable request before I/O, 60-minute provider expiry and 15-minute retry window, fixed zero test-tax/post-discount mapping and retained pending resources. Concurrent calls share the exact provider request/key; there is no lease or automatic queue. Owner GET/return reads are non-mutating; explicit POST and bounded console recovery retain validated session observations with monotonic terminal status. Payment remains unverified and fulfillment remains unstarted. Current source/fixture implementation awaits final CI and independent review in its PR; no actual Stripe transaction or production policy is established. Authoritative PaymentIntent validation, inbox processing, historical verifier extension, finalization/grants and WP-08 delivery remain dependent work.


## D-14 acceptance and dependency reconciliation — 2026-09-26

[PR #61](https://github.com/VASEYDEV/VASEYAUDIO/pull/61) merged the September dependency graph; all original PRs #53–#60 closed with explicit dispositions recorded in the [dated ledger](../dependency-pr-integration-2026-09-26.md). React/React DOM are aligned at 19.3; Node 26 typings remain declined for the Node 24 runtime. [PR #62](https://github.com/VASEYDEV/VASEYAUDIO/pull/62) then merged hosted test checkout: accepted candidate `90c72743`, tree `7989c979`, main `10e0f218`. [CI 36217210794](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36217210794) passed 888 MySQL tests / 7,574 assertions, 831 SQLite tests / 6,346 assertions with 57 intentional MySQL-only skips, 150 frontend tests, 12 browser cases, build and audits. Two independent Codex reviews accepted the final source. This acceptance supersedes the preceding D-14 candidate status, preserving its historical record. No actual provider transaction, production policy approval or launch readiness follows from it.

## D-15 — Durable receipt work and authoritative test-payment evidence (2026-09-26)

[Decision and boundary](D-15-test-payment-processing.md): separate immutable receipts from mutable recoverable work claims, fence expired workers, retrieve current Session/PaymentIntent state under the configured test account, and preserve immutable observations and one unique payment confirmation. A confirmation produces `awaiting_finalization`, not an order-paid transition or purchase rights. Source and test implementation requires its own final CI and independent reviews in the integrating PR. Extend the pending-only historical verifier and agree WP-04/WP-08 grant/render contracts before terminal resource effects. Live enablement, refunds and cutover remain outside this increment.


## D-15 acceptance — 2026-09-26

Merged [PR #63](https://github.com/VASEYDEV/VASEYAUDIO/pull/63) accepted candidate `4107d449`, tree `f2960409947b53bfedf07f0336210ca9b8a48bf0`, on main `89d7ea1`. [CI 36220050595](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36220050595) passed 145 focused payment tests / 1,092 assertions, the full 1,033 MySQL tests / 8,651 assertions, 971 SQLite tests / 7,392 assertions with 62 intentional MySQL-only skips, 150 frontend tests, 12 browser cases, build and dependency audits. Two independent Codex reviews accepted the final source. These are synthetic provider-fixture results, not an actual Stripe transaction. This supersedes the preceding candidate status and preserves that historical entry.

## D-16 — Test-payment finalization and frozen grant interface (2026-09-26)

[Decision and boundary](D-16-test-payment-finalization.md): a separate default-off local/testing policy admits only confirmations observed strictly before the original attempt expiry. That timestamp is a local observation, not provider payment time. Atomically consume retained inventory/promotion resources, record exclusive sales and one grant per line with original encrypted buyer/seller/assent/commercial render input, and create pending exact-asset entitlements/outbox. An explicit safety failure records `paid_exception` and retains pending resources; malformed retained evidence or transient infrastructure errors do not invent that business decision. A complete effect-graph verifier preserves original order hashes and supports retained reads after terminal transitions and policy withdrawal. Customer projections distinguish payment confirmation, finalization and unstarted/blocked/pending-contract fulfillment. No PDF worker, active download, real Stripe transaction, production policy approval or cutover is included.

### D-16 acceptance — 2026-09-26

Merged [PR #64](https://github.com/VASEYDEV/VASEYAUDIO/pull/64) accepted candidate `cf7657abe897d610a2cf995b7ae4437680717280`, tree `b152cb8ddf32c2760ad5c8b52e8713521369576e`, on main `69b28a9ed108b31a9f6a4498c79c41fd357b12fb`. [CI 36244728854](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36244728854) passed 75 focused finalization tests / 814 assertions, 1,108 full MySQL tests / 9,499 assertions, 1,042 SQLite tests / 8,127 assertions with 66 intentional MySQL-only skips, 179 frontend tests, 12 browser cases, build and dependency audits. Two independent Codex reviews accepted the final source. These are synthetic provider-fixture results, not an actual Stripe transaction or production acceptance.

## D-17 — Private test-contract issuance (2026-09-26)

[Decision and boundary](D-17-test-contract-issuance.md): a separate disabled-by-default local/testing policy turns a frozen paid grant into one retained private original PDF. Pin the actual Composer package graph, font/source hashes, PHP runtime, template and deterministic metadata in an immutable profile; unsupported text or changed evidence fails rather than being substituted. Keep recoverable work claims separate from requests/results, fence stale workers, preserve completed originals and restore exact bytes after loss instead of rerendering. The subprocess provides bounded PHP/application restrictions, not complete OS isolation. Customer reads expose recorded contract progress without opening files, activating entitlements or creating download URLs. PR #64 is accepted; this dependent candidate needs its own integrated CI and independent review. U-06 is narrowed by a concrete test profile; production archival/legal/reproducibility acceptance remains unresolved, as do U-03/U-07 storage and recovery decisions.

## D-18 — Whole-order test fulfillment activation (2026-09-26)

[Decision and boundary](D-18-test-fulfillment-activation.md): formalize the approved activation proposal as an additive immutable proof for one complete paid test order. Require every original PDF and purchased asset, freshly verified outside transactions against exact historical provenance and private local storage, with a 300-second observation bound including lock waits. Reconstruct the complete graph under ordered locks before committing one encrypted proof/audit; preserve pending entitlements, outbox, first originals and licensed permissions. Typed compact media-metadata hashes retain maximum-cart capacity within existing evidence limits. Historical metadata is neither current file-health proof nor download permission. This dependent candidate requires D-17 acceptance, its own full CI and final review; no merge or production acceptance is claimed. D-19 must separately implement explicit test access, ownership, current physical checks, short-lived authorization, caps/restrictions and recovery without reusing licensed exploitation limits as website download caps.

## D-19 — Internal owner-bound test delivery foundation (2026-09-26)

[Decision and boundary](D-19-test-owner-delivery.md): add a separately enabled local/testing access policy over the exact original owner and complete retained activation. Versioned order controls, hash-only 60-second authorizations, a serialized three-per-order rolling issuance budget and one immutable redemption permit one committed stream attempt without changing licensed rights or pending entitlements/outbox. Freshly verify a selected original/revision outside transactions, retain a bounded private unlinked descriptor through the commit/consumer boundary, and never reopen the purchased path. Three fixed leased spool slots cap retained snapshots; pre-commit failures close them, while post-commit interruption consumes the attempt. This is an internal dependent candidate awaiting integrated tests/review and D-17/D-18 acceptance. The customer item reader, HTTP/privacy/CSRF/attachment boundary, UI and browser verification remain unimplemented; no active customer downloads or production acceptance is claimed.


## D-17–D-19 acceptance and main reconciliation — 2026-09-27

Private originals [#65](https://github.com/VASEYDEV/VASEYAUDIO/pull/65), activation [#67](https://github.com/VASEYDEV/VASEYAUDIO/pull/67) and internal delivery [#69](https://github.com/VASEYDEV/VASEYAUDIO/pull/69) are merged, after the resumed sequence #65 → #68 → #66 → #67 → #69. Each merge preserved its passing candidate tree and recorded independent review. Documentation [#70](https://github.com/VASEYDEV/VASEYAUDIO/pull/70) followed; main `1a6ecebd` passed [CI 36280010722](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36280010722). The [ordered evidence](../development-order.md#current-increment-and-next-handoff) retains exact candidates, runs, real MySQL races and the corrected JSON-order assertion. This supersedes current candidate/draft status in the preceding dated entries without erasing their history. It does not establish production storage/legal acceptance, guest recovery, a live provider transaction or launch readiness.

## D-20 — Owner HTTP and native test attachments (2026-09-27)

**Implementation direction within Sean's existing continuous-development authorization; not a separate owner approval or accepted runtime claim.** [D-20](D-20-test-owner-delivery-http.md) exposes the accepted D-19 services only to the original owning session through database-only item/bounded history reads, strict CSRF-protected authorization and native attachment POSTs. Secrets stay out of URLs/storage/logs; exact frozen files stream through the prepared descriptor after one committed attempt. Middleware failures receive the same private generic boundary. Technical test limits and existing immutable rights/evidence remain unchanged. This candidate still requires its own full CI and independent review of the tested commit. After acceptance, WP-09 versioned CMS is next; U-07 recovery, complete cross-order library, production storage/restore and the rest of WP-08 remain open.

## D-21 — Versioned site content and atomic publication (2026-09-28)

**Implementation direction within Sean's continuous-development authorization; candidate pending integrated verification and independent review.** [D-21](D-21-site-content-releases.md) adds a bounded plain-text home/studio/navigation/footer/SEO schema, immutable private release snapshots, staff-only preview and one transactional publication pointer with an expected monotonic revision. Publication and rollback retain hashes, actor/history and audit; rollback selects a previously activated release without changing catalog purchases, contracts, grants, entitlements or provider state. Current authorization is rechecked by the domain service, configured corrupt evidence fails closed, and approved identity/theme/assets stay fixed. The additive migration retains all release evidence during code rollback. Final D-20 acceptance remains the prerequisite in the [ordered record](../development-order.md); this entry does not imply acceptance or production readiness. Promotion UI, editorial/contact/video/scheduling and the broader WP-09 scope remain subsequent work.


## D-20–D-21 acceptance — 2026-09-28

Owner HTTP/native attachments [#71](https://github.com/VASEYDEV/VASEYAUDIO/pull/71) and versioned site content [#72](https://github.com/VASEYDEV/VASEYAUDIO/pull/72) are merged in dependency order after independent review and all required CI gates. The [ordered acceptance record](../development-order.md#accepted-downloads-and-cms--september-28-2026) retains exact accepted heads, trees, merge commits and full MySQL/SQLite/browser/build/audit results; post-merge main `ede206545ab050517bd403e1042ee8c066f5c5b3` passed [CI 36475321405](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36475321405). This supersedes candidate wording in the preceding dated entries without erasing their history. Customer downloads remain test-only; CMS acceptance does not complete WP-09, customer recovery/library or production readiness.

## D-22 — Test promotion administration (2026-09-29)

**Implemented test-only contract within Sean's continuous-development authorization.** The integrating PR and [WP-09 issue #9](https://github.com/VASEYDEV/VASEYAUDIO/issues/9) record the exact tested commit, executed CI, independent review and merge disposition. [D-22](D-22-promotion-administration.md) separates immutable campaign terms from versioned availability. Verified administrators create disabled local/testing campaigns, review exact terms, enable/disable with an expected revision and see aggregate usage. Policy changes require a fresh lifetime key/code; configured campaigns remain read-only, and collisions cannot silently replace them. Database-managed resolution fails closed before legacy fallback. New holds and held-to-pending transitions recheck availability under the same campaign lock; disable preserves pending/consumed evidence, frozen orders and idempotent retained-order recovery. Creation and changes retain actor/history/audit atomically. No production promotion, mutable purchase term, marketing consent, customer export or provider operation is introduced. Persisted contact/about/blog/video, asset references and scheduling are the next ordered WP-09 scope after acceptance.


## D-22 acceptance — 2026-09-29

Test promotion administration [PR #73](https://github.com/VASEYDEV/VASEYAUDIO/pull/73) is merged after independent review and all ten required CI gates. The [ordered acceptance record](../development-order.md#accepted-test-promotion-administration--september-29) retains head `5b2ba241e61db1bc4eaa84430071c3aac1855ace`, tree `2c62cf2245000ed6d961b2740f669b7551ab9b78`, merge `1260e00b1b7f0d80e750b00d83f094359246e935`, exact full-suite results and passing post-merge CI. This supersedes earlier pending wording without implying production promotion policy, full WP-09 acceptance or cutover.

## D-23 — Persisted editorial and contact content (2026-09-29)

**Implementation direction within Sean's continuous-development authorization; final acceptance is recorded in the integrating PR and WP-09 issue #9.** [D-23](D-23-editorial-content.md) adds required nullable about/contact/blog/video sections to schema-v2 complete site releases. Retained schema-v1 content, hashes and original baseline remain unchanged and rollback-capable. Active-only routes expose the requested page/list/entry and shared chrome; private staff previews stay pinned to one release. Strict plain text, enabled-target navigation, typed YouTube/Vimeo IDs and validated contact email exclude arbitrary URLs, HTML or scripts. Mail and external actions are disabled in preview; public links do not implement contact delivery, embedded media or consent collection. Publication still uses the singleton lock, monotonic revision and atomic history/audit. No migration or source import is introduced. Editable asset references and scheduling are next; broader contact/media/sharing/support and production readiness remain open.

## D-23 acceptance — 2026-09-29

Persisted editorial and contact content [PR #74](https://github.com/VASEYDEV/VASEYAUDIO/pull/74) is merged after independent review and all ten required CI gates. The [ordered acceptance record](../development-order.md#accepted-editorial-content--september-29) retains head `03ed4438acb3969c87913d988eaa904ca04a5b96`, tree `cf207034942779bf71d458cf8fb8947500ab0c8f`, merge `f76f0c9e38de1f90ca4116bd8f64ec6fa14286b7` and the full-suite results. Post-merge main `5b99777`, which also contains PR #75, passed [CI 36642175431](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36642175431). This supersedes the pending wording above without implying inbound contact delivery, embedded media, full WP-09 acceptance or cutover.

## D-24 — Scheduled whole-release publication (2026-09-29)

**Accepted in [PR #76](https://github.com/VASEYDEV/VASEYAUDIO/pull/76): tested head `c11fa1d`, merged as `520f66f`, [CI 36656641121](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36656641121) and post-merge [CI 36659889154](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36659889154) passed all ten jobs, with the scheduling defaults Sean approved.** [D-24](D-24-scheduled-site-publication.md) retains one pending schedule per site. Each schedule records an exact saved release, its hash, a whole-minute UTC time and the reviewed publication revision. A minute runner activates it through the D-21 lock, revision check and audit path, attributed to the scheduling administrator. Staff publication or restoration supersedes a pending schedule after a warning, and cancellation acts only on the schedule the operator reviewed. A schedule expires unpublished 60 minutes after its time. A withdrawn administrator (role, verification or required MFA), an integrity failure or a moved revision fails it closed. Database guards on both engines retain every outcome. Production needs the standard `schedule:run` cron on the undecided host (U-02). No commerce, rights or provider state changes. Editable asset references are next.

## D-25 — Editable site images (2026-09-30)

**Accepted in [PR #81](https://github.com/VASEYDEV/VASEYAUDIO/pull/81), the library (tested head `4111672`, merged as `edf6ee1`; [CI 36732001572](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36732001572) and post-merge [CI 36739781811](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36739781811) passed all ten jobs), and [PR #82](https://github.com/VASEYDEV/VASEYAUDIO/pull/82), the image slots (tested head `c173ec0`, merged as `e0e0873`; [CI 36746399582](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36746399582) passed all ten jobs, and post-merge [CI 36751214435](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36751214435) passed nine, its browser job failing one test on a race in the test that [PR #83](https://github.com/VASEYDEV/VASEYAUDIO/pull/83) fixes), with the slots and defaults Sean approved on 2026-09-30.** [D-25](D-25-editable-site-images.md) adds a private site-image library for four fixed slots: the home hero (desktop and mobile), the studio image and the share image. Uploads record a credit line and rights confirmation and are refused at intake for the wrong shape or size, transparency, CMYK, more than 8 bits per channel, rotation tags, other formats or more than 20 MiB. Each image is scanned, re-encoded without metadata into the slot's sizes (JPEG and WebP, or JPEG alone for the share image) and pinned by a manifest hash; the original is never served. Database guards on both engines retain every upload and outcome, and a lease-based claim serializes processing. Staff see a private, verified preview. Part 2 adds the slots to site releases (schema version 3, used only when a release has an image), pins each image's manifest and records the references in an insert-only index. An image is served publicly at a content-hashed URL, cached for a year, only once a release using it has been live, and a damaged file fails only that image. No commerce, rights or provider state changes.

## Register updates — 2026-10-10

Recorded by the launch-documents lane from the repository's own records; nothing here is a new owner decision beyond what the cited files already state.

- **U-01 resolved.** The GitHub repository is `SeanVasey/VA-Studio` (`ops/staging/README.md`, provisioning step 4 names it as the repository Forge installs; the status log in `docs/handoff/2026-10-08/MONDAY-PLAN.md` links its PRs). Older entries in this register and in `docs/` link `VASEYDEV/VASEYAUDIO`, the name D-02 recorded; those links are history and are not rewritten.
- **Staging host: a staging decision, not U-02.** Sean chose Laravel Forge managing a VPS for the private Stripe-test staging site on 2026-10-08 and reconfirmed it on 2026-10-09 with DigitalOcean selected (`docs/handoff/2026-10-08/MONDAY-PLAN.md`, section 2). This is recorded here as the staging-host decision only. U-02 (production host, region, topology, secrets, backups and incident ownership) remains open: the production activation packet still lists the host as an input only Sean can supply (`docs/ops/production-activation-packet.md`, sections 2 and 8), and `docs/site-content-releases.md` still describes the production host as not chosen.
- **Staging server sizing.** Two figures exist: `docs/handoff/2026-10-08/MONDAY-PLAN.md` S-2 asks for a VPS with "at least 4 GB RAM", and `ops/staging/README.md` provisioning step 1 specifies **4 vCPU / 8 GB RAM / at least 160 GB disk**, naming 4 GB as the absolute minimum because one `clamscan` can use 3 GiB alongside MySQL, PHP-FPM and ffmpeg. The ops README is the sizing specification; the plan's figure is its floor.
- **Hosted staging test mode.** PR #68 at `d8aba146` admits the Stripe-test commerce chain on `APP_ENV=staging` with staff MFA required and live mode refused (`App\Support\Environment\TestEnvironment`; `docs/private-server-readiness.md`, "First sale and cutover remain separate gates"). The packet, the preparation queue and the Monday plan's interim-environment row were corrected on 2026-10-10 to stop saying that a staging host is refused. `ExecutionContextV1` production-checkout test funds still require `local`/`testing`.

- **Production host platform: Laravel Forge (Sean, 2026-10-18).** In this session Sean approved Forge for production ("Approved - Forge will be used"). It is recorded as the platform part of U-02: a Forge-managed VPS, a second instance alongside the Forge + DigitalOcean staging host, built from the derived production kit in `ops/production/` (PR #148). U-02's remaining parts are still open: region and provider account, sizing, secrets custody, off-host backup target and incident ownership. Provisioning, DNS, deploys and live payments each still need their own authorization.

- **Member-license activation mode: `explicit_owner_confirmation_v1` (Sean, 2026-10-18).** Sean approved C4 and confirmed this mode for member-license activation. It is the mode the code already accepts and nothing else (`MembershipConsumptionTerms::from` and `MemberLicenseReviewDisplay`): a retained technical confirmation that the owner requested activation (`record_claim` `owner_requested_activation`). It is explicitly neither legal assent nor write authority. The approval does not approve any display wording, consumption terms or retention text, and it does not approve any policy, definition, profile or terms hash: `production-memberships.approved_policy_hash` and the three `member-grants.approved_*_hash` values stay `null`, and `enabled` stays `false` in `config/production-memberships.php` and `config/member-grants.php`. Wording, terms, retention, hashes, member HTTP activation and live billing each still need their own decision. Recorded with lane C4d (`docs/verification/member-domain-c4d-20261018/README.md`).

## Register update — 2026-10-24: merchant, seller and legal drafting

Sean's words on 2026-10-24: "The merchant is Vasey Multimedia dba VASEY.AUDIO and I am the seller Sean Vasey. You should be the drafter of privacy policy and you should already have determined the different contract licenses for different tiers of purchase." The same day he set governing law and venue as the State of Iowa, USA, and supplied a postal street line, which stays out of this public repository (`docs/legal/README.md`, "Merchant and seller facts").

- **U-04 partially resolved.** Merchant of record: **Vasey Multimedia dba VASEY.AUDIO**, consistent with the Vasey Multimedia Stripe account observed on 2026-09-09. Still open: currency, tax registrations and tax settings (Stripe Checkout `automatic_tax` is implemented but default-off), capture semantics and payout reconciliation. Live checkout stays blocked.
- **U-05 partially resolved.** Seller and licensor: **Sean Vasey**. Drafting of the privacy policy, the terms of sale and use and the license tiers was assigned to the development lead on 2026-10-24; the drafts are `docs/legal/PRIVACY.md`, `docs/legal/TERMS.md` and `docs/legal/LICENSE-TIERS.md`, each marked as a draft for Sean's approval. Still open: Sean's approval of the final text, caps and prices; qualified legal review; exclusive ownership semantics (the drafts default to an exclusive license with no copyright transfer); and per-track rights declarations. No license version, offer, production policy value or approved hash was created or recorded, and nothing is approved.
- **Governing law: State of Iowa, USA** (Sean, 2026-10-24). The venue county, the postal address's city and ZIP, and the minimum age remain open in the drafts' open-items lists.
- U-08 (exclusive reservation and late payment) and U-13 (retention, processors, consent notice text) are unchanged; the drafts only propose values for Sean to accept or change.

## Register update — 2026-11-07: contracts, licensing and buyer identity

- **Licensor: Sean Vasey personally** (Sean, 2026-11-07), consistent with U-05. Vasey Multimedia appears only as the brand
  owner in the contract footer.
- **License terms schema v5** (Sean, 2026-11-07; merged in #205). Each of Sean's tier limits has its own usage slot. See
  [`license-usage-v5.md`](../license-usage-v5.md).
- **[D-30](D-30-buyer-contract-identity.md) buyer contract identity** (Sean, 2026-11-07):
  - checkout collects the postal address (C-1);
  - the legal name is confirmed at the agreement step (C-2);
  - Exclusive checkout asks for an artist name (D-1);
  - characters the contract fonts cannot print are refused;
  - issued contracts stand, and corrections are support notes.

  U-13 retention values and the PRIVACY and TERMS text still need Sean's approval before any collection.

- **Business licensees allowed, and the individual statement and order-review wording approved** (Sean, 2026-11-07).
  - A business may be the licensee. Order review then records the business's legal name and the name of the person
    accepting for it, under a separate business confirmation statement. Sean approved that statement and the
    TERMS section 7 "Buying for a business" paragraph later the same day (TERMS A.10).
  - TERMS section 7 and the individual statement ("I confirm that this is my full legal name and that the license should be
    issued to me under it.") are approved.
  - D-30 records the shape.
- **PRIVACY and TERMS approved** (Sean, 2026-11-07: "I approve privacy and terms").
  - Approved as written, including every earlier proposal: the section 7 retention periods, the 120-minute session lifetime,
    the refund policy, the 30-minute exclusive reservation, 12-month re-download availability and the buyer assent text.
  - **U-13 retention periods are therefore approved** as PRIVACY section 7 states them. The configuration values derived
    from them (`customer-data-rights.retention`: financial 7 years, consent 3 years, correspondence 2 years, security 1 year)
    are this record's reading of that table and are set per host, never in Git. U-13's processors, consent notice texts and
    the reviewed deletion workflow stay open.
  - Still to fill before publication: postal address, hosting provider and region, storage, backup and email providers,
    minimum age, Iowa venue county and the DMCA agent decision. The [For legal review] wording awaits counsel.

## Register update — 2026-11-08: delegated D-30 activation decisions

Sean delegated these on 2026-11-08 ("Make the appropriate decisions you'd recommend and continue development"). Each is
the development lead's recommendation, recorded as decided; Sean can reverse any of them before activation.

- **Contract glyph repertoire (D-30 step 5, contract increment 5).** Pin fuller builds of the same three families
  (Bebas Neue, Reddit Sans, JetBrains Mono) that add Latin Extended (the Fontsource `latin-ext` subsets or the upstream
  static fonts), as a successor to the `vasey-license-pdf-v1` profile, then rebind checkout to it. The printable set stays
  the intersection of every pinned face, restricted to the profile's allowed scripts. Names such as "Łukasz" and
  "Ștefan" then print; scripts not covered by every face (Greek, Cyrillic, and Vietnamese if any face lacks it) stay
  refused at checkout rather than printing wrongly. Reason: a licensee must be able to use their real legal name, and
  refusing at checkout is safer than a contract that misprints it.
- **Reconcile with a declaration that no longer verifies (D-30 steps 6 and 8, both checkouts).** Reconcile always records
  a captured payment: the money moved, and refusing to record it would lose financial evidence. The paid-grant
  finalization re-verifies a v2 declaration and refuses to issue a contract or grant for one that fails, so the order
  stays paid with fulfillment held for staff to resolve (normally a refund). The contract binder's own check (step 7)
  remains a second line.
- **Open provider sessions at activation (D-30 step 8).** Expire them. Before the contract identity gate opens, the
  activation runbook expires every open provider session of a name-only order and reconciles each one, so no legacy order
  can be paid after the flip. This needs a provider write and therefore Sean's separate live-activation authorization.
- **v2 orders with the gate off (D-30 step 8).** Refuse at `initiate`, in both checkouts, matching `accept`, which already
  refuses a gate mismatch in both directions. With the gate off the current paid renderers print every input field, so a
  v2 order must not be paid under them.
- **Already paid name-only orders with the gate on (D-30 step 8).** `initiate` answers the order's status, without any
  provider call, when the order is already paid; it still refuses (`changed`) whenever it would open or resume a
  provider session.
- **Catalog identity proof: oversized TCP tables (identity follow-up C).** A refusal caused only by the TCP table
  exceeding its read cap is transient: that attempt refuses, and the worker is not marked unusable. A failed or short
  read and every other snapshot anomaly (provider, descriptor, wrapper or error handler) keep the permanent worker taint.
  Malformed or inconsistent table content refuses the proof as before: it is retried with fresh snapshots, at most three,
  and never taints, so torn procfs reads stay recoverable (#231).
- **Catalog identity proof: PHP version gate.** `CatalogSocketBinding` admits the PHP 8.4 series from 8.4.27 onward
  instead of exactly 8.4.27, so a routine 8.4 patch update on Forge or CI does not stop identity migrations. A different
  minor version (8.5) still refuses until it is proven.

Still Sean's, not delegable: the staging step 11 inputs (two TOTP operators, the seller tag and the backup destination),
and every live-payment, DNS, production and customer-import authorization.

## Register update — 2026-11-09: delegated retention and download-resume decisions

Sean delegated these on 2026-11-09 ("Make decisions for me based on best recommendations"). Each is the development
lead's recommendation, recorded as decided; Sean can reverse any of them. None authorizes deletion: the retention
report (#250) stays read-only until a purge is separately built, reviewed and approved.

**Retention (U-13 follow-up, open questions from #250).** PRIVACY §7 already answers most of these; the decisions map
each table to an existing §7 row and add two rows where §7 had none.

- **Privacy request records** (`customer_data_requests`, `customer_data_request_events`, `customer_data_exports`
  metadata): **7 years** after the request closes. They prove that rights requests and deletions were honored, so they
  must outlive the records they concern. Export *files* keep their existing short expiry; only the metadata is kept. New
  §7 row. Amended after the independent review of #255: `audit_events` are not in this row. They also evidence license
  approvals, exclusive activations, payments, finalizations and refunds of records kept indefinitely, so they are kept with
  those records (indefinite).
- **Consent clock:** §7's existing row decides it: kept while the email address is held, plus 3 years. The clock starts
  when the address is no longer held (account closure or erasure), not at the consent event.
- **Download and redemption records for perpetual licenses:** they follow the order (§7 orders row): retained while the
  order is retained, never on their own authorization timestamps. The report treats them as held with the order.
- **Support attachments:** §7's contact row: 2 years after the last message, or with the order when the conversation
  concerns an order or a legal claim.
- **Production identity tables:** notices, send attempts and their outcomes follow §7's verification row (12 months),
  and so do challenges that no identity origin or verification references. Amended after the independent review of #255:
  verifications, and every challenge an origin or a verification references, form the verified chain that every
  production read re-verifies from sequence 1 and that sealed order, grant, invoice and checkout bindings name, so they are
  kept with the origin (indefinite). Addresses have no timestamp and stay held.
- **Quotes and checkout reviews that never became an order:** **30 days** after the quote or checkout ends. Checkout
  intents, sessions and observations are always bound to an order snapshot, so they follow the unpaid-snapshot rule below. There is no contractual, tax or support need
  to keep abandoned purchase details longer. New §7 row.
- **The unclassified tables (111 in #250)** are classified by family:
  - order, payment, refund/dispute, fulfillment, grant, membership-billing and legacy-imported order records follow the
    orders row;
  - framework job and failure stores follow the application error log row (14 days);
  - password reset tokens follow the verification row;
  - cache and lock stores follow the rate-limit row;
  - test-commerce tables are `not_applicable` in production, where they must stay empty;
  - anything that still fits no row stays reported as unclassified.

**Large-download resume (FP-077).** Sean decided on 2026-11-09 to replace the lead's first recommendation, which was no resume at launch:

1. Delivery moves to a v2 policy that allows resume.
2. The buyer's download is resumable through a session-bound GET. Sean asked for a recommendation, and this is the lead's:
   - The existing CSRF-protected POST still creates the authorization, then answers 303 to a GET URL.
   - That URL carries only the opaque authorization id, never the secret.
   - The GET works only with the same authenticated session and owner, and only after every existing check passes.
   - Responses are `private, no-store`, `no-referrer` and `noindex`.
   - Only single-range `Range` and strong `If-Range` are honored.
   - This amends D-20, whose threat was a secret in a URL.
3. A partial or interrupted transfer does not count toward `max_downloads`.
   - A download counts once, when the distinct bytes served under one authorization cover the whole file.
   - Authorizations that started but never completed are capped per grant per rolling 24 hours (operational setting, default 10).
4. Large archives get longer resume windows once started:
   - 1 hour below 512 MiB;
   - 6 hours from 512 MiB;
   - 24 hours from 4 GiB.

   These are settings. The start window stays short, and whole-file verification still runs before every response.

The test-delivery chain is phase 1. Paid, paid-tax, free and production-free follow in their own phases, each with native
MySQL race evidence and independent review. #252 and #253 are closed as superseded.

*Addendum, 2026-11-09 (delegated; from the independent review of #258, P2-1).* Count-on-complete per authorization can be
split across authorizations (A serves `0..N-2`, B serves `-1`). Decided 2026-11-09, delegated by Sean: from Phase 2
(where `max_downloads` applies), a download counts once when the grant's union coverage across its authorizations in a
rolling 24 h window reaches 90% of the file (which includes any single authorization reaching 90%); each window counts at
most once. Phase 1 test delivery has no `max_downloads` and keeps count-on-complete. Phase 2 implements this.

**Unpaid order snapshots (retention).** An order snapshot is created before payment. One with no verified payment,
whose provider session has expired or been cancelled and reconciled, counts as "did not become an order": it falls under
the 30-day abandoned-checkout row from that point. Any paid, refunded, disputed or held order stays with the orders row.
The schedule implements this only after a separate change that proves no late webhook or reconcile can still attach
payment to such a snapshot. Until then those snapshots, and the checkout intents, sessions and observations bound to
them, stay with the orders row, and PRIVACY's 30-day row names only quotes and checkout reviews; that change widens the
row when it lands.

*Implemented and proven 2026-11-10 (BL-08), production checkout (V1) only.* The terminal state is an intent with a
`production_checkout_observations` row of kind `session_expired` and no `production_checkout_payments` row. After it,
every later provider read that reports the session paid is refused (`provider_uncertain`, one retained `uncertain`
observation, no payment row, no grant): the guard runs when a read is recorded and again on every read of the intent.
The read-only schedule counts such an order under the 30-day row from its latest expired-session observation, with its
lines, attempt, intent and session, and only while that observation is the intent's latest. A paid, held, refunded,
disputed or in-flight order is never counted.

A refused or failed read leaves only an `uncertain` observation, which cannot be told from an outage, and it may be the
one case where money moved at the provider with no local payment row. So any observation after the expiry holds the
order (independent review of PR #267, P1). An outage clears on the next successful expired read, which restarts the 30
days; a real contradiction stays with the order. Terminality rests on this store refusing contrary reads, not on the
provider: any future purge must re-read the provider session first.

Not applied, and why:
- **Stripe-test chain (`orders`).** It has no terminal unpaid state. A late paid read after a recorded expiry is refused
  and its receipt kept, but after a staff release a late confirmed payment is recorded against the order as a paid
  exception by contract, and a release can follow any expiry. It stays with the orders row. Applying the rule there
  needs a decision that a released order may refuse late money, or a purge that re-checks the provider first.
- **Expired session with a canceled payment attempt (V1).** Terminal in code, but stored under the same observation kind
  as a canceled attempt on an open session, which can still be paid. It stays with the orders row until the two are
  stored distinguishably.
- **Order that never reached the provider (V1)** and the **tax checkout (V2) chain**: outside this decision's wording
  (no provider session, or no stored expiry observation). They stay with the orders row.

PRIVACY's 30-day row is unchanged: the widened wording is proposed in `docs/customer-data-rights.md` for Sean. No purge
exists.

Still Sean's, not delegable:

- dismissing the four Dependabot alerts, which come from frozen evidence lockfiles under
  `docs/verification/member-source-currentness-20261013/` and not from the shipped `composer.lock`;
- the staging step 11 inputs;
- every live-payment, DNS, production and customer-import authorization.

## D-20 amendment — 2026-11-09: resumable test delivery (FP-077)

The decision is the "Large-download resume (FP-077)" item of the register update above; this entry records only how it amends D-20 and D-19. D-20 rejected token-bearing GET links because a secret in a URL leaks through history, logs and referrers. That rule stands: under `test-owner-delivery-v2` the token is still presented once in the CSRF-protected POST body, which answers 303 to a GET whose URL holds only the opaque authorization ID and which works only in the server-side session that created the authorization (a random session secret hash-bound into its evidence). JavaScript blob downloads stay excluded. D-19's "one committed stream attempt" holds for v1 only; a v2 authorization counts one download when served bytes cover the file. Phase 1 (test-delivery chain) and the remaining phases: [delivery-range-requests.md](../delivery-range-requests.md).

## Register update — 2026-11-10: delegated decisions and staging placeholders

Sean delegated the decisions that were waiting on him on 2026-11-10: "For things waiting for my decision - do what is
recommended by you or if requiring a wav tag audio item like in item 1 needing my input, use a staging tag and make note
of these for the final cleanup of production. We need to get these things going as well."

Read as a rule, every waiting item falls into one of three classes:

- **A, decided.** A pure decision. The development lead's recommendation is recorded as decided. Sean can reverse any of
  them before the activation it affects.
- **B, staging placeholder.** The item needs a real asset or value from Sean. Work proceeds on a clearly labelled
  STAGING or synthetic placeholder, and the item is entered in the
  [production cleanup ledger](../production-cleanup-ledger.md) (`PCL-xx`) to be replaced before production.
- **C, Sean only.** Not delegated: entering credentials or secrets on a host, enrolling authenticator devices, supplying
  BeatStars exports, dismissing GitHub alerts, counsel's legal review, and every live-payment, DNS, production-deploy,
  customer-import and BeatStars-retirement authorization.

The items are the 20 entries of the 2026-11-09 status audit's queue for Sean (`SQ-xx`), the 28 parity rows whose blocker
was Sean's decision, the open `U-xx` rows above and the open questions of `docs/legal/` and
`docs/customer-data-rights.md`, deduplicated. Nothing here is test acceptance, nothing changes a flag, and nothing
authorizes a live payment, a deploy, a DNS change, an import or a deletion. No price, license term, tax registration,
address or secret is invented or recorded.

**Placeholder convention (class B).** A staging placeholder is recognizable without context: text carries the literal
words `STAGING PLACEHOLDER`, catalog and license records carry the existing `[SYNTHETIC]` / `synthetic-rehearsal`
markers (`docs/ops/staging-runbook.md`, section 5a), and files are named `STAGING-synthetic-…`. Placeholders exist only
on `local`, `testing` and the private `staging` host, never in a production environment file, fixture or release.
Where a value is Sean's alone (above all the postal address), the placeholder is the words "set at production", never a
guess.

### Launch (v1) scope — SQ-04, U-11, U-12

Each deferral keeps launch small and is reversible: the built drafts and test journeys stay in the repository,
default-off. A deferral changes what is built before launch. It does not remove a cutover gate: BeatStars is not retired
while the Studio audit (DD-66) shows an active obligation in a deferred family.

| ID | Item | Class | Decision and reason | Parity rows moved to `postlaunch_by_design` |
| --- | --- | --- | --- | --- |
| DD-01 | What v1 sells | A | Track licensing on every tier of Sean's six templates, exclusive sales, free downloads, customer accounts, contracts and delivery. One reason: this is the BeatStars core, and it is the part already built behind flags. | none |
| DD-02 | Merchandise | A | Deferred. It needs a fulfillment provider, shipping and returns terms (U-12), none of which exists. | FP-010, FP-088 |
| DD-03 | Services | A | Public service catalog, deposits and service payments deferred (U-12). Service requests arrive through the contact form at launch. The built test journey (FP-086) stays as it is, default-off, and collects no money. | FP-008, FP-087 |
| DD-04 | Collections, albums and automatic bundles | A | Deferred. Bundle pricing rules would be invented, and a multi-track line multiplies the rights and exclusive cases. | FP-006, FP-066 |
| DD-05 | Sound kits | A | Deferred. Kit intake is built, but no storefront or entitlement delivery exists and none of the six templates is a kit license, so a kit could only be sold on invented terms. With all four families deferred, their publish and archive editors follow. | FP-007, FP-037 |
| DD-06 | PayPal | A | Stripe only at v1. One provider means one verified-payment path to prove. One-time payments leave nothing to carry over. | FP-062 |
| DD-07 | E-signature provider | A | Click-assent contracts: the recorded declaration, the frozen assent text and the original PDF are the evidence (D-30). No provider, no extra customer data processor. | FP-058 |
| DD-08 | Collaborator accounting and payouts | A | Deferred. Collaborator splits are metadata only (DD-38); no accounting, statement or payout. D-07 already excludes payout infrastructure. | FP-069 |
| DD-09 | Offers and negotiation | A | Deferred. Fixed published prices only; a buyer-specific price is handled by Sean outside the store until built. | FP-056 |
| DD-10 | Rest of U-11 (affiliate rewards, crypto, license upgrades, distribution and publishing hand-offs) | A | Stay post-launch, as their rows already say. | none (already post-launch) |

Twelve rows move: FP-006, FP-007, FP-008, FP-010, FP-037, FP-056, FP-058, FP-062, FP-066, FP-069, FP-087, FP-088. The
parity count becomes 43 `implemented_evidenced`, 32 `implemented_partial`, 1 `not_implemented`, 4 `blocked_on_sean` and
23 `postlaunch_by_design`. No row changed to an implemented status. FP-009, FP-083 and FP-085 (memberships, DD-50) and
FP-091 (marketing email, DD-44) are decided but not moved: the delegated scope list names neither family, and the
membership inventory is still a cutover gate. Sean can move them with one word. The `scope_stage` column is left as the baseline
recorded it, so the move is visibly a 2026-11-10 decision and is undone by restoring one cell.

### Money: merchant, tax, prices and policy values — SQ-02, SQ-05, SQ-06, SQ-13, U-04, U-08

| ID | Item | Class | Decision, placeholder or why it stays Sean's |
| --- | --- | --- | --- |
| DD-11 | Store currency | A | One currency: USD, stored as integer cents. The approved TERMS section 6 already says so. No presentment currencies at v1. |
| DD-12 | Capture model | A | Immediate capture in Stripe Checkout payment mode. No authorize-then-capture and no manual capture: a grant already requires durable verified payment, so a hold would add a state with no benefit. |
| DD-13 | Managed Payments or direct | A | Direct Stripe payments on Vasey Multimedia's own account. Managed Payments would make Stripe the merchant of record, against the 2026-10-24 decision. No Connect (D-07). |
| DD-14 | Tax approach | A + C | **A:** production sells through the tax-calculating chain (Tax V2 over Stripe automatic tax), not the tax-refusing chain; prices are tax-exclusive, as TERMS section 6 states; tax is collected only where a registration exists. **C:** the registrations themselves, the Stripe Tax settings and the product tax category are Sean's with his accountant; none is assumed here. PCL-08. |
| DD-15 | Payouts and reconciliation | A + C | **A:** Stripe's standard automatic payouts; once a month the Stripe payout report is reconciled against the store's sales report and the production reconcile command. **C:** the bank account and payout schedule are entered by Sean in Stripe. |
| DD-16 | Refund and dispute policy values (FP-068) | A | Confirmed as TERMS appendix A.4 reads the approved section 10: `refund_authority = authoritative_provider_operator_review`, `partial_refunds = exact_line_allocation`, `dispute_access = temporarily_block_while_reconciling`, `exclusive_reopening = qualified_operator_review`. They are entered per host, never committed. |
| DD-17 | Exclusive reservation and late payment (U-08) | A | From the approved TERMS section 9: `reservation_seconds = 1800`; `provider_lifetime_seconds = 1800`, so a provider session cannot outlive the reservation; `pending_cutoff = block_new_nonexclusive_on_pending_exclusive`; `late_action = paid_exception_operator_reconciliation`; `late_time_basis = authoritative_provider_payment_time`, because the provider's payment time is the fact a buyer and a bank can both see. After a refund an exclusive is offered again only after operator review (DD-16). U-08 is closed for development; exclusive live checkout still needs its own authorization. |
| DD-18 | Stripe TEST credentials on the staging host (S-4, FP-061) | C | Secrets entered on a host. Steps under "What only Sean can do". |
| DD-19 | Prices for every tier and offer (S-8, C-4) | B | Staging uses the existing synthetic convention: licenses named `[SYNTHETIC] TEST PRICE …` with repeated-digit amounts (1.11, 2.22, … 6.66 USD, one per tier), on synthetic tracks only. No production price exists in the repository. Sean supplies real prices. PCL-02. |
| DD-20 | Per-track tier enablement (C-4) | B | Staging: every tier is offered on every synthetic track, so each contract can be rehearsed. Production: Sean's list of tiers per track. PCL-03. |

### Licensing and contracts — SQ-05, SQ-11, SQ-14, U-05, U-06

| ID | Item | Class | Decision, placeholder or why it stays Sean's |
| --- | --- | --- | --- |
| DD-21 | Canonical tiers and license text | A | Sean's six private templates (Basic, Premium, Premium Plus, Unlimited, Custom Beat, Exclusive) are canonical for names, caps, credit, royalty and sync wording (`docs/production-contract-profile.md`, increment 1). `docs/legal/LICENSE-TIERS.md` is a superseded draft wherever it differs; only its free-license section is still used (DD-24). Custom Beat is authored with the other five but offered only where Sean assigns it. |
| DD-22 | Does an exclusive transfer ownership | A | No. An exclusive is an exclusive license with no copyright assignment, as the drafts default. It is the reversible choice: an assignment can be added later, and cannot be taken back. |
| DD-23 | Publishing details (PRO, IPI, designee) and per-track exclusive disclosures | C | Sean's own facts, given to a licensee on request and entered per track by him. Never committed. PCL-04. |
| DD-24 | Free downloads (FP-055) | A + B + C | **A:** free downloads are in v1, delivered untagged as built. **B:** no template of the six is a free license, so staging uses the LICENSE-TIERS section 5.1 draft marked `STAGING PLACEHOLDER`; the production text needs Sean's approval. PCL-05. **C:** lifting `VASEY_OPERATIVE_FREE_GRANTS_ENABLED` is a live activation. |
| DD-25 | Contract renderer and archival standard (U-06) | A | Closed as built: the pinned offline TCPDF profile `vasey-license-pdf-v2`, the original PDF kept byte-exact with its SHA-256. PDF/A is not claimed at v1. |
| DD-26 | Held tc-lib updates (#162, #164) | A | **Superseded 2026-11-10 by Sean (SQ-14):** taken now, before contract activation, so the existing profiles are re-pinned (tc-lib-pdf 8.81.1, tc-lib-pdf-font 4.6.2). Originally: not merged before launch. After activation any renderer change ships as a successor profile with its own golden; `composer audit` remains the security gate. |
| DD-27 | Licensor signature image | B + C | **B:** staging renders with a specimen image that reads `STAGING SPECIMEN — NOT A SIGNATURE`; nothing resembling a signature is drawn. **C:** Sean installs his real signature PNG on the production host. PCL-06. |

### Legal pages — SQ-07, U-05 (open questions from PR #261)

| ID | Item | Class | Decision, placeholder or why it stays Sean's |
| --- | --- | --- | --- |
| DD-28 | Should restoring a previous release skip the legal guard | A | No. Rollback stays guarded. A rollback that would republish unfinished legal text is refused; the operator rolls back to a clean release or removes the legal pages. |
| DD-29 | Square brackets in approved legal text | A | Not allowed. Brackets are the drafts' placeholder convention, so any bracket refuses publication. Approved text uses parentheses. |
| DD-30 | Deliverable wording | A | The generated, typed-term wording is canonical: "MP3", "WAV master", "Stems ZIP". The storefront's "Untagged" label is removed unless a typed term backs it. No typed term does today. |
| DD-31 | `/legal/licenses` | A | Generated from the published license versions, not typed as free text, so the comparison cannot drift from what is sold. Until that is built the page is not published. |
| DD-32 | A distinct schedule outcome for a legal refusal | A | No. The schedule keeps the existing `integrity` outcome with the reason in the audit event. A new outcome would need a trigger migration for no operator benefit. |
| DD-33 | Legal placeholders: effective date, postal address, venue county, hosting, storage, backup and email providers, server region | B | Staging-only values, each harmless and marked: the pages open with "STAGING PLACEHOLDER — not in effect"; effective date, postal address, venue county, backup provider and email provider each read "set at production"; hosting is Laravel Forge on a DigitalOcean server, storage is the server's private disk and the region is the United States (DD-55, DD-58), which are facts of the staging host. **No street address is ever written.** Sean supplies the production values. PCL-07. |
| DD-34 | Minimum age | A | "18, or the age of majority where you live", as drafted. A license is a contract, so the buyer must be able to make one. |
| DD-35 | DMCA designated agent | A | Not registered for launch. The store publishes only Sean's own content, and the TERMS section 13 complaint procedure stands. The bracketed sentence is removed. Revisit if the store ever shows customer content publicly. Counsel may overrule in DD-36. |
| DD-36 | Counsel's review of the "For legal review" sections and the no-samples warranty (C-3) | C | Only a qualified reviewer can give it. The guard refuses publication while a marker remains, so production publication waits for it. PCL-07. |
| DD-37 | Contact-form privacy notice and retention reference | A + B | **A:** the notice is one sentence that points to the published privacy policy, and the retention reference is the PRIVACY section 7 contact row. **B:** on staging the notice text carries `STAGING PLACEHOLDER` until the policy is published. PCL-07. |

### Media and catalog — SQ-01, SQ-15, SQ-16

| ID | Item | Class | Decision, placeholder or why it stays Sean's |
| --- | --- | --- | --- |
| DD-38 | Do incomplete collaborator splits block publication | A | No. The publication manifest reports them and does not block. The export shows 19 featured-artist titles with 2 recorded splits, so blocking would stop most of the catalog on metadata that feeds no price or payout. |
| DD-39 | Track scheduling and unlisted tracks (FP-032) | A | Scheduled: a track publishes at a UTC instant only if every publication guard passes at that instant, and otherwise stays a draft with the failure recorded, as site releases do. Private is the existing draft; no new state. Unlisted: reachable by its direct link and share link, absent from catalog, search, sitemaps and feeds, served `noindex`, and purchasable only under the same guards as a published track. |
| DD-40 | Seller tag (S-6) | B | A synthetic STAGING seller tag is approved: audio generated by tooling on the host, named `STAGING-synthetic-seller-tag.wav` and marked inside the file, with its SHA-256 pinned in the staging environment only. It tags only staging previews and is never installed in production. Sean supplies the approved tag WAV. PCL-01. |
| DD-41 | Official logo SVG and icon suite (S-11, FP-017) | B | No mark is recreated. The placeholder is what the repository already holds: the official PNG icons under `public/brand/`. Sean supplies the SVG source. PCL-09. |

### Customers, mail and data — SQ-08, SQ-10, SQ-12, U-07, U-10, U-13

| ID | Item | Class | Decision, placeholder or why it stays Sean's |
| --- | --- | --- | --- |
| DD-42 | Production customer identity (U-07) | A | Account-first: `buyer_identity.mode = verified_account` with `verified_account_recovery`. The approved TERMS section 3 already requires an account. No guest checkout, no social sign-in and no customer MFA at v1; recovery is by emailed code. Staff MFA stays required. |
| DD-43 | Sender domain and SMTP provider (FP-075) | A + C | **A:** transactional mail only, from a dedicated subdomain of vasey.audio so the apex mail records stay untouched until cutover; SMTP over implicit TLS on port 465, which is what the production validator admits; SPF and DKIM aligned, DMARC starting at `p=none`. Staging mail stays `log`. **C:** choosing and paying for the provider, entering its credentials and adding DNS records. PCL-10. |
| DD-44 | Marketing email, ESP and analytics processors (FP-081, FP-090, FP-091) | A | None at v1. No ESP, no suppression sync and no third-party analytics; reporting is first-party. PRIVACY then names fewer processors. The built consent ledger stays, and unknown consent stays unknown. |
| DD-45 | Marketing consent notice text | A | Stays `null`. With no marketing mail there is nothing to consent to, and a null notice keeps the preference page from collecting consent. |
| DD-46 | Retention class of order-ready notices (`transactional_notices`) | A | `correspondence`, 2 years, as scheduled. The order itself keeps the purchase evidence; two years covers the dispute window for "I never received it". |
| DD-47 | The three unclassified tables | A | `production_account_feature_bindings` and `production_listening_libraries` follow the account lifecycle and are erased by a deletion approval once production accounts can be closed (D-30 step 3b). **Owner-decided amendment, 2026-11-10 (Sean):** account closure KEEPS both the feature bindings and the listening library row, with the library's contents erased (the rows are retained by the database and the bindings key the retained consent ledger); the rows are not deleted. Closure also requires the customer's explicit acknowledgment that it may end access to remaining downloads (`closure-download-forfeiture-v1`; licenses themselves are not revoked). `production_buyer_assent_observations` is held indefinitely as review evidence. Each moves into the schedule, with its pinned test, in the change that builds step 3b; until then the report keeps listing all three. |
| DD-48 | Consent clock signal | A | The 3-year clock starts at deletion approval for an account with no retained order. While an order snapshot or contract still holds the address, consent and suppression records stay held with it. |
| DD-49 | Reviewed deletion workflow (U-13) | A | The built workflow is the workflow: customer request, staff approval under MFA, erasure of the profile and library, retention of the financial and license record. U-13 is closed except for the purge, which is DD-67. |
| DD-50 | Membership policy (U-10, FP-009, FP-083, FP-085) | A + C | **A:** no membership is sold at v1 and no recurring billing runs; `MembershipHttpMount`, both `enabled` flags and every approved hash stay as shipped. No legacy member is imported into an active entitlement automatically. BeatStars is not retired while any legacy member is active: each is honored there until it lapses, or by a reviewed manual grant. Plans, prices and allowances are not invented; none is needed while nothing is sold. **C:** the BeatStars membership inventory (DD-66). The three rows keep their status. |

### Operations, staging and cutover — SQ-01, SQ-09, SQ-14, SQ-16 to SQ-20, U-02, U-03

| ID | Item | Class | Decision, placeholder or why it stays Sean's |
| --- | --- | --- | --- |
| DD-51 | Two staging operators with TOTP (step 11) | C | Enrolling an authenticator device. Steps under "What only Sean can do". |
| DD-52 | Who the second operator is | A | On staging Sean may hold both accounts (author and reviewer); the two-account rule then separates actions, not people, as the runbook already states. For production Sean names a second reviewer or accepts the same arrangement. PCL-11. |
| DD-53 | Off-host backup destination (S-10) | A + C | **A:** an SSH-reachable store that Sean controls, encrypted at rest, at a different provider or account from the host; staging keeps 30 days there. **C:** creating it and installing its key on the host. |
| DD-54 | Reprovisioning the staging host | C | Root on the host. The installed helpers are stale after #259, so deploys refuse until `provision.sh` is rerun. |
| DD-55 | Production region, sizing and topology (U-02) | A + C | **A:** a second Forge-managed server at the same provider as staging, in a United States region (as PRIVACY section 9 proposes), never the staging machine; at least the staging specification of 4 vCPU, 8 GB RAM and 160 GB disk; one server running the web tier, workers, MySQL and private files, as the production kit builds. **C:** creating the server and every deploy. |
| DD-56 | Secrets custody (U-02) | A + C | **A:** secrets exist only in the Forge environment and in Sean's password manager; never in Git, chat, a ticket or an agent session. **C:** entering them. |
| DD-57 | Incident ownership (U-02) | A | Sean is the incident decision maker and the launch operator; support@vasey.audio is the contact. An agent prepares diagnosis and a proposed fix and takes no production action. |
| DD-58 | Private file storage (U-03) | A | The server's private disk at launch, as built and tested, with the encrypted off-host backup. R2 or an S3-compatible store is deferred until measured size or egress calls for it; the storage interface keeps that move open. |
| DD-59 | Recovery objectives and rollback thresholds (SQ-17) | A | RPO 24 hours (nightly backup plus a snapshot before every deploy; payments are also recoverable from Stripe). RTO 8 hours. During the cutover observation window, roll back on any of: a paid order with no delivery that staff cannot resolve within 30 minutes; any sign of a double exclusive sale; a failed reconciliation or integrity check; checkout unavailable for 30 minutes. |
| DD-60 | Does BeatStars keep selling during cutover (SQ-17) | A | No. BeatStars sales are frozen before the final delta, so one system holds exclusive authority at any moment (`ExclusivityAuthority`). The freeze and the switch are Sean's actions (DD-61, DD-67). |
| DD-61 | DNS control and records | C | DNS is not delegated. Sean holds the registrar and makes every change. |
| DD-62 | Device tester and deployment operator (SQ-16) | A + C | **A:** Sean is both unless he names someone. **C:** the physical-device run needs his devices. |
| DD-63 | Dependabot alerts from the evidence lockfiles | C + A | **C:** dismissing the four alerts is a click in GitHub. **A, alternative rejected:** the two archived `composer.lock` files are not renamed or moved. `archive-index.json`, `artifact-digests.json` and `catalog/component-release-artifacts.json` bind each by path and SHA-256, and `rebuild-manifests.py` asserts those bindings, so a rename would force the manifests to be rewritten and the packet would no longer be the evidence that was reviewed. No follow-up task. |
| DD-64 | CLAUDE.md adaptations and the lint preset (SQ-18) | A | The five adaptations stand as recorded (gate, auth, audit level, license and layout, deploy). Lint: Pint with a preset matched to the existing style and a TypeScript linter, changed files only at first. Sections 1 to 12 of CLAUDE.md are not edited. |
| DD-65 | The Foundation CI run at freeze (SQ-19) | A | No new approval is needed: Sean's CI cost policy of 2026-10-06 (AGENTS.md) already orders one complete run on the final candidate at an exact SHA. Further runs only for actual failures or new source. |
| DD-66 | BeatStars Studio audit and the order, customer, membership, payment and contract exports (SQ-03, U-09, FP-095, FP-097, FP-099) | C | Only Sean can sign in and export. Until then PayPal use, Content ID enrollment and active memberships stay unknown, which blocks cutover and retirement, not development. |
| DD-67 | Authorizations (SQ-20) | C | Each separate and each Sean's: the media delete path, the retention purge, live Stripe keys and the first charge, expiring open provider sessions at D-30 activation, lifting any pre-launch hold, production deploy, import into active entitlements, DNS cutover and BeatStars retirement. |

### Status of the open U-xx rows after this update

| Key | Status |
| --- | --- |
| U-02 | Decided: platform (2026-10-18), region, sizing, topology, custody rule, backup shape, incident owner (DD-53, DD-55 to DD-57). Sean's: creating the server, entering secrets, the backup credentials, every deploy. |
| U-03 | Decided for launch: private server disk (DD-58). |
| U-04 | Decided: merchant (2026-10-24), currency, capture, direct Stripe, tax approach, payout approach (DD-11 to DD-15). Sean's: tax registrations and Stripe Tax settings, bank account, live keys. |
| U-05 | Decided: seller (2026-10-24), PRIVACY and TERMS (2026-11-07), canonical tiers, exclusive ownership (DD-21, DD-22). Sean's: prices, counsel review, the production legal values, free-license text. |
| U-06 | Closed (DD-25). |
| U-07 | Decided: account-first (DD-42). Activation is a separate authorization. |
| U-08 | Decided for development (DD-16, DD-17). Exclusive live checkout is a separate authorization. |
| U-09 | Sean's (DD-66). |
| U-10 | Decided: nothing sold at v1 and the legacy rule (DD-50). Sean's: the inventory. |
| U-11 | Decided: all deferred (DD-06 to DD-10). |
| U-12 | Decided: merchandise and services deferred (DD-02, DD-03). |
| U-13 | Decided: periods (2026-11-07), classes (2026-11-09), processors, notice, clock and workflow (DD-44 to DD-49). Sean's: authorizing any purge. |

### What only Sean can do

In the order that unblocks the most work. Times are estimates for someone following the linked steps.

1. **Reprovision the staging host and create the two operators** (DD-54, DD-51), about 30 minutes. Over SSH, rerun
   `provision.sh` as `ops/staging/README.md` describes for the release that carries #259, then deploy. Then, in
   `/srv/vasey-staging/current`, run `php8.4 artisan vasey:create-admin` twice (name, email, a password of 16 or more
   characters). For each account: sign in at `https://<HOST>/admin`, open **Profile**, scan the QR code with an
   authenticator app, confirm a code, store the recovery codes offline, sign out and back in. Runbook section 2.
2. **Off-host backup destination** (DD-53), about 20 minutes. Create the storage account, then run the four commands of
   runbook section 8: generate the key on the host, add the public key at the destination, set `VASEY_BACKUP_DEST` in
   `/etc/vasey-staging/staging.conf`, and run one supervised `vasey-staging-backup nightly` that ends in "shipped".
3. **Stripe test credentials** (DD-18), about 15 minutes. In the Stripe dashboard in test mode: copy the account id,
   reveal a standard `sk_test_` key (restricted keys are refused), add the webhook endpoint for
   `https://<HOST>/webhooks/stripe` and copy its `whsec_` secret. Paste the three values into Forge → Environment and
   deploy. `docs/ops/staging-test-purchase.md` has the exact fields. Never send them by chat or commit them.
4. **BeatStars Studio audit and exports** (DD-66), one to two hours. Walk items A-001 to A-014 of
   `docs/migration/authenticated_studio_audit_checklist.csv`; export orders, customers, memberships, payment-provider
   records and buyer contracts into the private store used for the catalog export on 2026-11-07, and note each file's
   SHA-256.
5. **Production values** (the cleanup ledger), about an hour in total and not needed for staging: prices and tiers per
   track, the seller tag WAV, the signature PNG, the postal address and venue county, the logo SVG, the free-license
   text, publishing details.
6. **Counsel's review** of TERMS sections 15 to 17 and the no-samples warranty (DD-36). Elapsed time is counsel's.
7. **Tax registrations and Stripe Tax settings** with his accountant (DD-14), and the payout bank account (DD-15).
8. **Mail provider account, credentials and DNS records** (DD-43), about 30 minutes plus DNS propagation.
9. **Dismiss the four Dependabot alerts** (DD-63), about two minutes: GitHub → Security → Dependabot, filter by the
   manifest path `docs/verification/member-source-currentness-20261013/`, select the four, **Dismiss** with the reason
   that the vulnerable code is not used (archived evidence, not an installed lockfile).
10. **Physical-device run** (DD-62), about 30 minutes with an iPhone and an Android phone, once staging serves the
    synthetic catalog.
11. **Each authorization of DD-67**, one at a time, when its evidence is in front of him.

The work these decisions unblock is listed in
[`docs/handoff/2026-11-10/UNBLOCKED-WORK.md`](../handoff/2026-11-10/UNBLOCKED-WORK.md).

## Register update — 2026-11-10 (Sean): waiting-on-Sean queue decisions

Sean issued these decisions himself on 2026-11-10 ("Waiting-on-Sean queue: decisions and remaining actions", prepared
against main `25bfdc9` and the Store Progress dashboard queue SQ-01..SQ-20). They are recorded verbatim below. **Where
they differ from the delegated decisions of the 2026-11-10 update above (DD-xx), Sean's decision governs**; the
reconciliation table at the end of this section names every DD row that is superseded or refined, and the questions that
still need his word. None of these grants a live payment, a provider write, DNS, production deploy, customer import or
deletion.

### SQ-01 — Staging step 11 (staging recommendations adopted)

- **Second operator: a second staff account Sean controls**, not a second person. Created with `vasey:create-admin`
  under a distinct mailbox and its own TOTP entry. The two-person rule for license approval and free-definition review
  then holds between accounts, not between people (as `staging-runbook.md` §5a already states). Production keeps
  this as an open item: a real second reviewer is preferred before live contracts.
- **Seller tag: a staging-only synthetic tag is approved for rehearsal.** It must be a generated WAV meeting
  `docs/media-processing.md` limits (PCM, ≤15 s, ≤16 MiB, above −50 dBFS), installed under
  `private/branding/`, with its SHA-256 in Forge Environment. Rules: it is used only with
  `VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED=true` and the six `[SYNTHETIC]` tracks; no real master is processed while
  it is configured; before real tracks the documented reset (runbook "before Sean's real tracks") removes it and
  installs the real S-6 tag.
- **Off-host backup destination: Backblaze B2 through the kit's rclone remote, age-encrypted.**
  - Private bucket per host: `vasey-staging-backups` now, `vasey-production-backups` later. Never shared.
  - Application key scoped to that bucket with list/read/write and **no delete** capability; retention enforced by a
    B2 lifecycle rule, `VASEY_BACKUP_REMOTE_PRUNE=0`.
  - `VASEY_BACKUP_REQUIRE_ENCRYPTION=1`, two age recipients (primary + offline recovery key), public keys only on
    the host.
  - `VASEY_BACKUP_RETENTION=tiered`, KEEP 7 / DAILY 7 / WEEKLY 4 / MONTHLY 6 (the guide's starting values, adopted).
  - Reason: object storage with a no-delete key gives the "host cannot destroy its own off-host backups" property the
    hardening guide asks for, without running a second server. rclone/B2 is unverified in the kit; staging is where it
    gets verified.
- **Reprovisioning approved:** rerun `provision.sh` so the installed root helpers match the kit after #259, then
  `forge-deploy.sh`, then step 11.

### SQ-04 — v1 scope (U-11, U-12)

Launch is the beat store: tracks, licenses, exclusives, coupons, contracts, downloads, accounts, blog/video/content.

| Item | Decision | Rows |
| --- | --- | --- |
| Sound kits, collections/albums | **Launch** (finish FP-006/007; already partially built, no policy blocker) | FP-006, 007 |
| Services: inquiry/quote/booking | **Launch** as inquiry-only (already evidenced) | FP-086 |
| Service deposits/milestones | **Defer** to v1.1; quotes settle by invoice outside the store | FP-087 |
| Offers / negotiations | **Defer**; use inquiry + a one-off coupon instead | FP-056 |
| Automatic bundles / bulk deals | **Defer**; coupons cover launch promotions | FP-066 |
| PayPal | **Defer**, pending the BeatStars audit; reopen only if the audit shows PayPal is a material share of sales | FP-062 |
| E-signature provider | **Retire** for v1; recorded click-through assent (D-30) is the contract mechanism | FP-058 |
| Collaborator revenue accounting | **Defer**; the split *model* (BL-05) continues, accounting does not launch | FP-069 |
| Merch storefront and fulfillment | **Defer** to a later phase (or a hosted print-on-demand link-out) | FP-010, 088 |
| Memberships | **Defer**, see SQ-10 | FP-009, 083 |

### SQ-05 — Prices (partial)

- **Exclusives do not transfer ownership.** Exclusive Rights is an exclusive *license*: Sean keeps copyright in the
  beat and 50% publishing, as LICENSE-TIERS already states. No assignment clause.
- **Per-track tier enablement (C-4):** every published track offers Basic, Premium, Trackout and Unlimited by
  default; Exclusive is enabled per track (default on, Sean switches it off for tracks he wants to keep);
  Free Promotional follows SQ-11. Trackout/Unlimited/Exclusive are automatically unavailable on tracks with no stems.
- **Prices themselves stay Sean's** (AGENTS.md forbids invented prices).

### SQ-06 — U-04 remainder

- **Currency:** USD only at launch.
- **Integration:** **direct Stripe**, not Managed Payments (merchant of record stays Vasey Multimedia dba
  VASEY.AUDIO, as decided 2026-10-24).
- **Tax:** Stripe Tax (`automatic_tax`) on for production; collect only where Vasey Multimedia is registered;
  registrations are added when a jurisdiction's threshold is crossed (Stripe Tax monitoring). Home-state (Iowa)
  registration and the digital-goods treatment are confirmed with Sean's accountant before the first live charge.
- **Capture:** automatic capture at payment (digital goods delivered immediately; no authorize-then-capture).
- **Payout reconciliation:** Stripe automatic payouts on the default schedule, reconciled monthly against Stripe's
  payout reconciliation report and the store's order ledger.
- **Live webhook receipt (risk 10):** a separate live receiver path, not retrieval-only, built behind the live
  activation gate.

### SQ-07 — Legal placeholders (partial)

- Hosting provider and region: DigitalOcean via Laravel Forge, NYC3 (see SQ-09).
- Storage: Cloudflare R2 (SQ-09). Backups: Backblaze B2 (SQ-01). Email: Postmark (SQ-08).
- **Minimum age: 18** for purchases and accounts (licenses are contracts).
- **DMCA agent:** designate VASEY.AUDIO (Sean Vasey) through the U.S. Copyright Office DMCA designated-agent directory,
  contact support@vasey.audio. Registration is Sean's action.
- Postal city/ZIP and venue county are Sean's. Counsel review of the marked wording and the no-samples warranty is an
  external review, scheduled before publication.

### SQ-08 — Mail and identity

- **Sender domain:** `mail.vasey.audio` subdomain; From `orders@vasey.audio`, Reply-To `support@vasey.audio`.
- **Provider:** Postmark, transactional stream only (no marketing on the same stream).
- **DNS:** Postmark DKIM and return-path records on `mail.vasey.audio`; SPF include on the sending subdomain;
  DMARC `p=none` with reporting for the first 30 days, then `p=quarantine`. Existing `vasey.audio` mailbox records
  are preserved unchanged.
- **Identity (U-07): email-verified guest checkout, account optional.** The buyer verifies email at checkout; an
  account can be claimed later with the existing guest-claim flow (FP-078). Staff stay TOTP-only.

### SQ-09 — U-02 remainder and U-03

- **Production:** Forge-managed DigitalOcean droplet, **NYC3**, **4 vCPU / 8 GB / 160 GB** (the staging spec,
  already justified by ClamAV + MySQL + ffmpeg), single host, scale vertically when measured load warrants.
- **Secrets custody:** Forge Environment on the host; master copies (APP_KEY, DB, Stripe, Postmark, R2, B2) in Sean's
  password manager; age private keys offline. Nothing in Git.
- **Backup target:** B2 `vasey-production-backups`, same rules as SQ-01.
- **Incident owner:** Sean (primary), alerts to support@vasey.audio; the dead-man's switch for FP-028 is added before
  launch.
- **U-03 storage: Cloudflare R2** for masters, stems, previews and contracts (zero egress suits a download store;
  private bucket, entitlement-checked signed delivery).

### SQ-10 — Membership policy (U-10)

**No new memberships at launch.** Membership pages stay off (FP-009, FP-083 deferred). Legacy continuity (FP-085) is
decided from the BeatStars audit: if active members exist, they keep their BeatStars membership until their paid
period ends and are offered a launch coupon; nothing is migrated as a recurring charge.

### SQ-11 — Free grants

- **Terms text:** the Free Promotional tier in `docs/legal/LICENSE-TIERS.md` is approved as the free terms.
- **Hold:** **not lifted at launch.** Lift after the first two weeks of stable paid sales and once order mail is proven
  (free claims need verified email).

### SQ-12 — U-13 remainder

- **Email processor:** Postmark (transactional). **Marketing ESP: none at launch** (FP-081 consent capture stays,
  FP-090/091 deferred).
- **Analytics:** a cookieless processor (Plausible), so no consent banner is needed for analytics.
- **Consent notice text:** drafted by the development lead for Sean's approval with the ESP decision; not needed at
  launch because marketing mail is off.
- **Deletion workflow:** the reviewed export/deletion flow is approved for production customers once U-07 identity
  is live (D-30 step 3b).
- **Order-ready notices:** class `transactional`.
- **The three unclassified tables:** classified by the 2026-11-09 family rule; any table that still fits no row
  stays reported, not purged.

### SQ-13 — Refund/dispute values and U-08

The four values (these match the approved TERMS §§9–10):

| Key | Value |
| --- | --- |
| `refund_authority` | `authoritative_provider_operator_review` |
| `partial_refunds` | `exact_line_allocation` |
| `dispute_access` | `temporarily_block_while_reconciling` |
| `exclusive_reopening` | `qualified_operator_review` |

U-08 is settled by the approved TERMS and needs no new decision: reservation 30 minutes (§9); late payment → manual
review, full refund if the exclusive is no longer available (§9); pending non-exclusive cutoff → the reservation
blocks new non-exclusive sales on that track (§9); availability after refund → reviewed individually, never reopened
while there is any sign of use (§10). **U-08 resolved.**

### FP-068 follow-up — C1 and C4 (Sean, 2026-11-10)

Owner decisions on the review of PR #297 (BL-42, refund and dispute lifecycle on the production tax chain). They amend DD-16; they do not change its four values.

- **C1.** A dispute ends download access while the license itself is kept, with the dispute noted on the record. A lost dispute keeps the hold. Staff suspension ends access with the license record retained. Nothing revokes a license, order, contract or authorization. (Checked against the code on 2026-11-10; see `docs/refund-dispute-lifecycle.md`, "Owner decisions".) Staff can lift a dispute hold, including after a lost dispute, with the existing explicit audited `hold_released_by_staff` command (Sean, 2026-11-10; amends DD-16). Nothing restores access automatically. A refused unfinalized order has no batch, so it is resolved with the refused-order command (next bullet).
- **C4.** The exclusive acceptance items for FP-068 are not applicable on the production tax chain, because no production line is exclusive. Recorded as N/A with that reason; DD-17 and U-08 are unchanged.
- **Refused unfinalized order (Sean, 2026-11-10).** When the production tax chain's paid order is refused at finalization (`ProductionTaxFinalizeGuard` returned HOLD because committed refunds cover the gross, or a dispute is lost), staff "restore the instrumental for sale and resolve it". Built as the staff command `ResolveRefusedProductionOrder` (Filament list "Unfinalized paid production orders"): same authority as `RefundDisputeActions` (administer role, MFA under lock, audited), proceeds only when a fresh provider read is HOLD for a fully succeeded refund or a lost dispute, records one append-only resolution (`production_tax_refused_order_resolutions`: reason `full_refund` | `dispute_lost`, actor, times, sealed evidence and hash) and closes the order to finalization for good. It refuses finalized orders (those keep the hold/release path), a clear or partially refunded payment, an open dispute (the hold stays until the outcome), refunds still in flight, a failed read and an unpaid order. It creates no grant, license, contract or download authorization and rewrites nothing. "Restore for sale" needs no release: the tax chain reserves no inventory and has no exclusive line (DD-14; C4), so the track and offer are never made unavailable; a test pins that the catalog is unchanged and the offer can be ordered again. See `docs/refund-dispute-lifecycle.md`, "Resolve refused order".

### SQ-14 — Dependencies

- **tc-lib #162/#164: take them now**, before contract activation, through normal CI and review. After activation a
  renderer change needs a successor profile (risk 11), so pre-activation is the cheap window.
- **The four Dependabot alerts from frozen evidence lockfiles:** dismiss as "vulnerable code is not actually used"
  (they are not the shipped `composer.lock`). Clicking dismiss is Sean's.

### SQ-15 — Track scheduling and unlisted semantics

- **States:** draft, scheduled, published, unlisted, private.
- **Scheduled:** goes live at a stored UTC instant entered in America/Chicago; same publication checks as a manual
  publish run at that instant; if a check fails it stays scheduled and staff are alerted.
- **Unlisted:** playable and purchasable by direct link only; excluded from catalog, search, filters, sitemap, feeds
  and related tracks; `noindex`.
- **Private:** staff only; never public, never purchasable.

### SQ-16 — People (partial)

- **Physical-device tester:** Sean, on his iPhone (iOS Safari, PWA install) and Samsung Galaxy (Chrome).
- **Deployment operator:** Sean, with the development session preparing each step.
- Official logo SVG and icon source: Sean supplies.

### SQ-17 — Cutover (partial)

- **Single exclusive authority:** BeatStars keeps selling non-exclusive licenses until the DNS switch; **exclusive
  sales on BeatStars are switched off 72 hours before cutover**, so only VA Studio can sell an exclusive afterwards.
  The post-snapshot delta (FP-101) captures BeatStars non-exclusive sales in that window.
- **RTO 4 hours, RPO 24 hours** at launch (nightly backup plus pre-deploy backups; binlog shipping not built).
- **Rollback thresholds (first 48 hours):** roll back if any of: checkout error rate above 5% over 30 minutes;
  any paid order undelivered for more than 1 hour without a known cause; webhook signature failures sustained for
  15 minutes; HTTP 5xx above 2% over 15 minutes; any data-integrity alarm.
- DNS location is Sean's input.

### SQ-18 — CLAUDE.md adaptations and lint

- §3 gate, §5 auth, §6 audit, §7 license/layout, §9 deploy adaptations are **confirmed as written**.
- **Lint:** Laravel Pint with the `laravel` preset, enforced in CI on changed files only (`pint --test --diff=main`);
  the one-time whole-repo format sweep is a single dedicated PR after the freeze, not before.

### SQ-19 — Foundation CI run

**Approved.** The repository is public, so GitHub-hosted standard runners carry no Actions charge; the cost is wall
time only. Run once on the exact freeze candidate.

### SQ-04 amendment (Sean, 2026-11-10, later the same day) — no family is deferred from development

Sean: "We shouldn't be deferring development of those service pages like merch or others. Merch will use Printful."

- **Development continues now** for every family SQ-04 listed as deferred: merchandise, services (catalog pages,
  deposits/milestones), memberships, offers/negotiation and automatic bundles, alongside sound kits and collections.
  Each is built default-off behind its own server-side flag, so building it never exposes an unfinished purchase path
  (FP-001 "no unavailable product purchase links" is met by the flag, not by removing the family).
- **Merchandise fulfillment: Printful** (print-on-demand). Printful produces, ships and handles returns per its policy;
  the store sells, takes payment through the same verified Stripe path, and submits the order to Printful only after
  durable verified payment. No Printful order is placed by a test or without Sean's API credentials on the host.
- **Which families are switched on at launch** stays a per-family launch decision (Sean), taken when each family's
  evidence is complete; building a family does not authorize enabling it.
- Still Sean's for merch: the Printful account and API key (entered on the host only), product and variant choices and
  retail prices, shipping and returns wording for TERMS (counsel), and tax treatment of physical goods (accountant).

### Sean's answers, 2026-11-10 (later the same day): tiers, kit license, albums

Sean: "Your private templates are Basic / Premium / Premium Plus / Unlimited / Custom Beat / Exclusive … Sound kit
license. There should also be an albums page for my digital music such as Cancel the Sky and Wide Awake etc. This is
the license from one of the drum kits. It obviously needs to be tailored to each drum kit or sound kit or made generic
and the visual appearance more in line with other generated legal documents such as the contracts."

- **Q-3 answered: the six private templates are canonical for v1**: Basic, Premium, Premium Plus, Unlimited, Custom
  Beat, Exclusive (DD-21 stands). SQ-05's per-track defaults are read against these names: Basic, Premium, Premium Plus
  and Unlimited are on by default; Exclusive is per track (default on). Working assumption until Sean corrects it: the
  draft's "Trackout" is the private set's Premium Plus (the stems tier), and Custom Beat is not a per-track catalog
  tier. Staging keeps its synthetic shapes (DD-19, BL-40).
- **Q-2 answered: sound-kit license.** Sean supplied his current "Sample Pack License Agreement" (revision 2026-09-18,
  attached to a drum kit). It becomes **one generic sound-kit license** whose kit name, purchase date, license fee and
  licensee are filled per purchase, rendered by the store's contract pipeline in the same visual system as the
  generated license contracts (header, typography, acceptance and signature block, footer). Its text is authored as a
  private license version like the six templates and never enters Git; the repository holds only the template kind,
  its placeholders, the renderer and profile pins. Sean's signature image stays private, as for every contract.
- **Albums page.** A public albums page for Sean's own released music (for example *Cancel the Sky* and *Wide Awake*):
  release title, artwork, release date, tracklist and listening links, staff-entered, default-off until Sean enables
  it. Selling releases as digital downloads is a later decision; this page does not create a purchase path.
- **SQ-07 venue county answered:** Polk County, Iowa is Sean's county. Sean: the Fifth Judicial District is the court
  there, which matters only for a legal dispute or the possibility of one. So legal pages fill the venue-county
  placeholder with "Polk County, Iowa", and a court is named only in a governing-law or dispute clause (as the kit
  license does), never elsewhere. Postal city and ZIP stay Sean's and out of the repository.

### Reconciliation with the delegated decisions (DD-xx)

| DD row | Status | Governing decision |
| --- | --- | --- |
| DD-02 merchandise | Superseded | SQ-04 amendment: developed now, fulfillment through Printful, default-off until Sean enables it. |
| DD-04 collections, albums | Superseded | SQ-04: collections/albums **launch** (FP-006). Automatic bundles are developed now, default-off (SQ-04 amendment). |
| DD-05 sound kits | Superseded | SQ-04: sound kits **launch** (FP-007). See open question Q-2. |
| DD-03 services | Superseded | SQ-04 amendment: service pages, deposits and milestones are developed now, default-off; inquiry stays the live path until Sean enables more. |
| DD-08, DD-09, DD-50 | Refined | SQ-04 amendment: offers, collaborator accounting and memberships are developed, not deferred; each stays default-off (MembershipHttpMount stays literal `false` until Sean authorizes a mount). |
| DD-20 per-track tiers | Superseded for production | SQ-05: four tiers on by default, Exclusive per track (default on), stems-dependent tiers off without stems. Staging (DD-19/DD-20 B) unchanged. |
| DD-24 free downloads | Superseded | SQ-11: Free Promotional terms approved; the free-grant hold is **not lifted at launch**. |
| DD-26 tc-lib #162/#164 | Superseded | SQ-14: take them now, before contract activation, with profile re-pins through review. |
| DD-34 minimum age | Superseded | SQ-07: 18. |
| DD-35 DMCA agent | Superseded | SQ-07: Sean registers VASEY.AUDIO as DMCA designated agent. |
| DD-39 scheduling, unlisted | Refined | SQ-15: adds a `private` state; scheduled instants entered in America/Chicago and stored UTC; a failed check leaves the track **scheduled** with a staff alert (DD-39 said draft). |
| DD-42 customer identity | Superseded, conditional | SQ-08: email-verified guest checkout, account optional. See open question Q-1. |
| DD-43 mail | Refined | SQ-08: Postmark transactional stream on `mail.vasey.audio`; From `orders@vasey.audio`, Reply-To `support@vasey.audio`. |
| DD-44 analytics | Superseded | SQ-12: cookieless Plausible; still no marketing ESP. PRIVACY must name the processor before it runs. |
| DD-46 order-ready notices | Unchanged | SQ-12 "class `transactional`" is read as the mail class (not marketing); the retention class stays `correspondence`, 2 years. |
| DD-53 off-host backup | Superseded | SQ-01/SQ-09: Backblaze B2 via rclone, no-delete key, age-encrypted, tiered retention. |
| DD-55 production host | Refined | SQ-09: DigitalOcean NYC3 via Forge, 4 vCPU / 8 GB / 160 GB, single host. |
| DD-58 private storage | Superseded | SQ-09: Cloudflare R2 for masters, stems, previews and contracts. Not built; see open question Q-3. |
| DD-59 recovery objectives | Superseded | SQ-17: RTO 4 hours, RPO 24 hours, and the five rollback thresholds. |
| DD-60 BeatStars during cutover | Superseded | SQ-17: non-exclusive sales continue until the DNS switch; BeatStars exclusives off 72 hours before cutover; FP-101 delta covers the window. |
| DD-61 DNS | Refined | SQ-17 / Part B item 9: Sean names the DNS host; the session may prepare record changes for Sean to apply. |
| DD-64 lint | Superseded | SQ-18: Pint `laravel` preset on changed files in CI; whole-repo sweep after the freeze. |
| DD-65 Foundation CI | Confirmed | SQ-19. |

All other DD rows stand.

### Open questions for Sean raised by this update

- **Q-1 Guest checkout vs approved TERMS §3.** The approved TERMS §3 requires an account to buy (the reason DD-42 chose
  account-first). Email-verified guest checkout needs a TERMS §3 amendment, counsel's review of it, and new
  production identity code (`buyer_identity.mode`). Until Sean confirms the amendment, production identity stays
  default-off and nothing ships either way.
- **Q-2 Kit and collection terms (answered below).** None of Sean's six private license templates is a sound-kit license, and SQ-04
  defers automatic bundles. Kits need kit license terms from Sean before a kit can be sold; collections can launch as
  browse groupings of individually licensed tracks (no bundle price) unless Sean says otherwise.
- **Q-3 Tier names (answered below: the six private templates).** SQ-05 names Basic, Premium, Trackout, Unlimited, Exclusive (as in the LICENSE-TIERS draft);
  DD-21 records Sean's six private templates as Basic, Premium, Premium Plus, Unlimited, Custom Beat, Exclusive. Which
  set is canonical for v1?
- **Q-4 R2 scope.** Private storage on R2 is a new storage adapter, signed-delivery path and backup change; it is not
  built. It becomes development work before production; the server's private disk remains the tested path until it
  lands.
