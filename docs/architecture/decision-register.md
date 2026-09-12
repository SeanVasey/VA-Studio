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
