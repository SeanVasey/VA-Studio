# Decision and status register

As of 2026-09-04. This is the initial register; entry keys are local tracking keys, not claims of historic accepted ADRs. Append dated changes and evidence instead of rewriting decision history.

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
| D-07 | First-party house store | **Recommendation** | Single seller, not a multi-vendor marketplace. Contributor metadata belongs in the core; collaborator payouts need a separate business/provider decision. |

## Decisions that remain open

“Owner” below means resolution responsibility, not an assertion that a reviewer or provider has been hired.

| Key | Open decision / resolution method | Owner | What it blocks | Safe work now |
| --- | --- | --- | --- | --- |
| U-01 | GitHub owner namespace, repository creation and access; inspect authenticated account and create/read back exact repo | Sean / implementation agent with available GitHub capability | Remote issue/PR publication and cloud agent handoff | Prepare commit-ready project and issue bodies. |
| U-02 | Production host, region, deployment topology, secrets, backups and incident ownership; compare deployable options with actual workload | Sean + operator | Public production deployment | Local development, CI configuration, containers and deployment checklist. |
| U-03 | R2 versus S3-compatible storage; verify privacy, retention, egress and restore needs | Sean + operator | Production asset upload/download | Storage interface, private local test adapter, quarantine and entitlement rules. |
| U-04 | Payment account owner, supported rails, currency, capture semantics, tax settings and reconciliation | Sean + commerce operator/accounting | Live checkout | Stripe adapter and synthetic test-mode fixtures; no new live provider account implied. |
| U-05 | Seller identity, reviewed license matrix and exact terms, rights declarations, exclusivity transfer, refund/dispute and privacy policy | Sean + qualified reviewers | Publication of legally active products and live contracts | Draft schema, marked nonbinding fixtures, approval lifecycle. |
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
