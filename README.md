# VASEY.AUDIO

Bespoke web store development replacing older, service-based e-commerce offerings. Single-seller music storefront, publishing administration, licensing, and secure delivery for Sean Vasey. Sean uploads, shares and sells his own content to customers; this is not a platform for independent sellers. This project replaces the current BeatStars site through staged, verifiable implementation.

**Status: this source implements private media, versioned licensing/offers, scoped pricing, test order preparation, hosted checkout, authoritative payment verification, atomic local/testing finalization, private original test contracts, read-only staff operations, whole-order activation proofs and customer-facing test downloads with bounded order history. Versioned site-content drafts, private preview, publication and rollback are accepted. Test promotion administration is accepted for local/testing. Persisted about/contact/blog/video content is accepted under D-23. Scheduled publication of a saved site release is accepted under D-24 (PR #76). Editable home hero, studio and share images are accepted under D-25 (PRs #81 and #82): a private image library, and image slots in site releases that serve an image publicly once a release using it has been live. Historical migration and production cutover remain incomplete.** The current BeatStars site remains authoritative for sales and existing customer obligations. The [ordered development record](docs/development-order.md) links each implementation PR, its tested source and actual CI/merge disposition. The integrating PR and [WP-09 issue #9](https://github.com/VASEYDEV/VASEYAUDIO/issues/9) record the exact tested commit, executed CI, independent review and merge disposition. [Owner HTTP/native attachments](docs/test-owner-delivery-http.md) merged in PR #71 and the [versioned site-content slice](docs/site-content-releases.md) merged in PR #72 after full CI and independent review; test promotion administration merged in [PR #73](https://github.com/VASEYDEV/VASEYAUDIO/pull/73) after all ten CI gates and independent review. Editorial content merged in [PR #74](https://github.com/VASEYDEV/VASEYAUDIO/pull/74) after the same gates. No actual Stripe transaction or production enablement is claimed.

## Start here

- [Remaining development plan](docs/remaining-development-plan.md): 40 tracked completion deliverables, dependency order, design coverage and reviewable development batches.
- [Task register](docs/remaining-development-tasks.csv) and [parity coverage](docs/remaining-parity-coverage.csv): completion criteria and ownership for every baseline capability.
- [CI development strategy](docs/ci-development-strategy.md): measured bottlenecks and the proposed focused/full verification cadence; automation remains unchanged until its implementation passes review.

Recovery checkpoint, September 30: [PR #84](https://github.com/VASEYDEV/VASEYAUDIO/pull/84) merged as `bfd5448` after all ten CI jobs passed, preserving Claude's scanner increment and adding the shared worker budget and mobile browser-test correction. The [PR #85 reconciliation](docs/verification/pr-85-reconciliation.md) accounts for every original commit and changed path: all useful work is already incorporated through PRs #78, #81 and #82. [PR #86](https://github.com/VASEYDEV/VASEYAUDIO/pull/86) then merged as `3833547`, protecting track-media revisions after an uncertain database commit. [PR #87](https://github.com/VASEYDEV/VASEYAUDIO/pull/87) merged encoder diagnostics as `8be3bd7` after all ten CI jobs and independent review. The remaining plan starts with CI throughput, followed by the six media hardening tasks, with source/policy/design work in parallel.

- [Editorial and contact content](docs/editorial-content.md): accepted about/contact/blog/video pages in one immutable release, private pinned previews and safe mail/provider links; editable site images are accepted under [D-25](docs/architecture/D-25-editable-site-images.md).
- [Site content and releases](docs/site-content-releases.md): accepted private text drafts, exact previews, audited publication and previous-release rollback, plus [scheduled publication](docs/site-content-releases.md#scheduled-publication) and [site images](docs/site-content-releases.md#site-images) in a private library and in releases; remaining WP-09 scope.
- [Promotion administration](docs/promotion-pricing.md#seller-administration--september-29-2026): disabled creation, reviewed enable/disable and aggregate usage over immutable test campaign terms; accepted in PR #73 with all required CI and independent review.
- [Operator setup and diagnostics](docs/operator-setup-and-verification.md): audited console provisioning, installation checks and isolated browser verification.
- [Agent instructions](AGENTS.md): shared rules for Astra, Codex, Claude, and other contributors.
- [Architecture](docs/architecture/): domain boundaries, decision register, contracts, and roadmap.
- [Development order](docs/development-order.md): current increment, earlier gaps, and the ordered handoff across all 14 packages.
- [Work packages](docs/work-packages/): dependency-scoped implementation assignments with acceptance criteria.
- [Migration evidence](docs/migration/): reconciled research, feature parity, field mapping, and cutover requirements.
- [Brand evidence](docs/brand/): current published theme, exact identity assets, and source provenance.
- [Verification](docs/verification/): actual local checks and remaining gates.
- [Media operations](docs/media-processing.md): worker prerequisites, approved preview tag, processing and recovery.
- [Licensing and offers](docs/licensing-and-offers.md): review submission, successor versions, published prices/files and legacy upgrade steps.
- [Provisional selection reviews](docs/provisional-quotes.md): server snapshots, session ownership, retries, expiry and the boundary before payable checkout.
- [Quote license disclosure](docs/quote-license-disclosure.md): full frozen terms tied to the owning selection review, disclosure identity and recovery.
- [Quote pricing](docs/quote-pricing.md): immutable server calculation evidence, explicit test tax policies, owner-checked pricing API and amount comparison boundaries.
- [Test order preparation](docs/order-preparation.md): merged review/assent, encrypted immutable records, owner recovery and atomic attempt binding.
- [Hosted test checkout](docs/hosted-test-checkout.md): merged local/testing policy, durable encrypted provider requests, bounded retries and manual reconciliation.
- [Test payment processing](docs/test-payment-processing.md): merged durable receipt claims and authoritative Session/PaymentIntent verification.
- [Test payment finalization](docs/test-payment-finalization.md): merged PR #64 atomic paid/exception effects, immutable grant render input, pending entitlements/outbox and customer status; no active downloads.
- [Private test-contract issuance](docs/test-contract-issuance.md): merged WP-08 increment with pinned offline rendering, immutable original PDFs, recoverable work and contract progress; entitlements remain pending.
- [Whole-order test activation](docs/test-fulfillment-activation.md): implemented immutable proof of all required original/asset checks; it does not grant download access.
- [Internal test owner delivery](docs/test-owner-delivery.md): accepted versioned controls, short-lived authorization and exact private stream snapshots.
- [Owner test-delivery HTTP/UI](docs/test-owner-delivery-http.md): accepted item/history, session/CSRF/privacy and native attachments, restricted to test mode.
- [Exclusive selection and atomic test pricing](docs/exclusive-selection.md): explicit test activation, scope coverage, versioned quotes/disclosure/pricing and reservation cutoff.
- [Exclusive offer preparation](docs/exclusive-offer-preparation.md): inactive v2 revisions with explicit scope evidence, historical compatibility and the remaining activation boundary.
- [Atomic test pricing and inventory](docs/atomic-priced-inventory.md)
- [Dependency PR integration and compatibility decisions](docs/dependency-pr-integration.md), including the [September 26 reconciliation](docs/dependency-pr-integration-2026-09-26.md)
- [Shared rights inventory](docs/shared-rights-inventory.md): internal scope/revision linkage, guarded test reservations and the boundary before exclusive sales.
- [Promotion pricing](docs/promotion-pricing.md): versioned discount allocations, shared test campaign limits and guarded usage holds.
- [Catalog administration](docs/catalog-administration.md): audited metadata saves, edit conflicts and stable published track URLs.
- [Catalog pagination](docs/catalog-pagination.md): bounded server browsing and off-page saved-selection reconciliation.
- [Public track sharing](docs/track-sharing.md): canonical URLs, server-rendered social metadata, Inertia navigation and publication privacy.
- [Track details and full license terms](docs/track-detail-and-license-disclosure.md): published offer disclosure, resilient selection, persistent playback and browser verification scope.
- [Stripe test webhook inbox](docs/stripe-webhook-inbox.md): signed event receipt, immutable encrypted evidence, duplicate handling and development configuration.

## Foundation scope

| Surface | Initial implementation | Remaining delivery |
| --- | --- | --- |
| Storefront | Paginated catalog/search/sort, dedicated track pages, frozen full license disclosure, reconciled cart, social metadata and persistent audio transport | Production catalog import, collections, physical-device and broader accessibility/performance acceptance |
| Admin | Authenticated Filament catalog/licensing management, audited metadata edits, permanent published track URLs, accepted versioned site-content publication/rollback, test promotion administration and persisted contact/about/blog/video content; scheduled site publication | Asset controls, inbound contact delivery, consent-aware embeds, detailed RBAC, recovery verification, customers and fulfillment operations |
| Media | Private WAV/artwork and bounded WAV-stems ZIP intake, queued verification, immutable master/MP3 revisions, tagged previews, measured waveforms, admin retry controls and audited stems/master associations frozen in offers | Production scanner acceptance, worker isolation, real stems alignment acceptance, resumable uploads and managed object storage |
| Licensing | Exact submitted review payloads, independent approval evidence, source/summary preview, successor diffs and effective dates | Approved production terms, a complete typed rights schema and production archival/legal validation |
| Offers | Editable drafts separated from immutable published price/license/file revisions; revision history and cart invalidation; scope-bound exclusive preparation and explicit test activation/selection | Production exclusive policy and fulfillment |
| Commerce | Immutable selection/disclosure/pricing, promotion limits/shared inventory, private order/assent, hosted test sessions and verified payments; merged #64 adds terminal resource effects, grants and pending fulfillment; merged #66 adds read-only staff visibility | Operator exception/refund resolution and production identity/legal/tax policy |
| Contracts and delivery | Merged #65 pins renderer/private originals/recovery; #67 records whole-order activation; #69 implements internal controls/authorization; #71 adds owner HTTP/privacy/native attachments and bounded order history | Complete cross-order library/recovery and production archival/storage/restore remain. Actual PR acceptance is tracked in the ordered record |
| Payment evidence | Stripe test-only snapshot webhook, immutable encrypted receipts, hosted sessions, durable work claims, authoritative confirmations and console recovery | Actual test-account interoperability and production payment/refund/dispute policy |
| Full parity | Documented work packages and evidence gates | Memberships, kits, services, merch, CRM, production promotions, remaining editorial/media flows, integrations and migration |

Public metadata observed on BeatStars is evidence, not an imported production catalog. Design fixtures are development-only and carry no saleable rights. The local cart pins the advertised offer revision and invalidates selections when that revision changes. **Review selection** asks the server to revalidate the exact selection and freeze its price, license and file evidence. The returned subtotal is provisional: tax and total are unknown, `payable` is false, and no order, reservation, assent or purchase rights are created.

The separate **Test order preparation** action requires explicit local/testing order, fixed-tax and inventory policies. It captures full terms and affirmative assent, privately retains supplied unverified buyer details and binds pending resources atomically. No policy is installed by default. Recovery is owner-checked; preparing a test order does not start payment or grant rights. Legacy `/checkout` remains 503. The merged [hosted test-checkout increment](docs/hosted-test-checkout.md) adds explicit order-specific start/reconcile routes and read-only return/status. Merged [payment processing](docs/test-payment-processing.md) separately verifies retained test payments; redirects and session observations cannot establish payment. Merged [finalization](docs/test-payment-finalization.md) consumes only fully verified retained evidence, atomically records paid effects or a retained-resource exception, and keeps entitlements pending until the later contract/delivery increment. Original [order evidence](docs/order-preparation.md) stays immutable and is verified with its terminal effect graph. Merged [contract issuance](docs/test-contract-issuance.md) preserves one original test PDF per frozen grant, while keeping delivery inactive. An issued status records the retained document manifest; ordinary customer status reads do not check filesystem durability or authorize access.

## Stack

Laravel 13 / PHP 8.4+, Inertia 3 / React / TypeScript, Filament 5. MySQL 8.4 is the production database target; SQLite is supported for local development. Redis jobs and private S3-compatible storage are staged behind later work packages. Keep the stack modular within one application and one transactional database.

No LLM is required by the storefront runtime. Astra is the requested development lead, not a dependency exposed to customers.

## Run locally

Prerequisites: a currently patched PHP 8.4 or 8.5 with `pdo_sqlite`, `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `gd`, `fileinfo`, `zip`, and `curl`; Composer 2; Node 24.15+ (within Node 24); npm. Use your platform's supported installers.

```sh
composer install
npm ci
cp .env.example .env
php artisan key:generate
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate
php artisan vasey:create-admin
npm run build
# As the user that owns storage/app/private: you here, the worker's user on a server (sudo -u <worker user> php artisan vasey:doctor)
php artisan vasey:doctor
php artisan serve
```

Open `http://localhost:8000` for the storefront and `http://localhost:8000/admin` for administration. The administrator command prompts for credentials and records console provisioning in the audit trail; no password or preconfigured user ships with the project. The doctor command identifies missing installation prerequisites and optional configuration without changing them; its only writes are a scratch directory for its scanner limits check, which it removes again, and a warning in the application log when the scanner fails that check. The initial catalog is empty by design. WAV, PNG/JPEG and WAV-only stems ZIP uploads enter private quarantine. Configure the scanner, seller tag and media worker using [Media operations](docs/media-processing.md) before requesting processing in the admin panel. Without those prerequisites, uploads cannot become ready for publication. Production mode requires TOTP enrollment. License publication requires a separate authorized reviewer, an exact submission hash, a consistency attestation and an actual review reference. See [Licensing and offers](docs/licensing-and-offers.md) and [typed usage terms](docs/typed-license-terms.md) before publishing licenses or commercial revisions. New license drafts require explicit usage, [territory and duration](docs/license-territory-duration.md) and [ownership/economic policy](docs/license-economic-policies.md) choices with matching source variables and retained policy text; v1/v2/v3 evidence is preserved.

For frontend development, run `npm run dev` alongside `php artisan serve`. For the isolated composition preview, run `npm run preview:design`; that preview uses explicitly labeled fixtures and shares the production React components. It cannot publish, charge, or grant a license.

## Verify

```sh
composer validate --strict
php artisan test
npm test
npm run build
composer audit
npm audit --audit-level=high
```

GitHub CI runs the PHP suite against MySQL 8.4 and SQLite, frontend tests/build and dependency audits (including development tools), plus an isolated operator browser job using Chromium and WebKit. The complete workflow runs for runtime pull requests, runtime pushes to `main` and manual dispatch. A conservative [documentation mode](docs/verification/ci-scope.md) validates only explicitly allowlisted prose/ledger changes; all unknown or mixed changes use full checks. Feature-branch pushes provide distinct [focused feedback](docs/verification/focused-ci.md), while the ready PR remains the full runtime acceptance gate. See [CI trigger scope and verification](docs/verification/ci-trigger-efficiency.md). See [browser verification](docs/operator-setup-and-verification.md#browser-verification) for commands, fixture isolation and evidence boundaries. A workflow file is not evidence that GitHub has run it. MySQL concurrency, real providers, mobile Safari playback, restore drills, and production deployment remain separate acceptance gates until recorded in the verification report.

## GitHub publication

Repository: [VASEYDEV/VASEYAUDIO](https://github.com/VASEYDEV/VASEYAUDIO), private. Sean created the repository during the initial implementation; the GitHub integration supplies commits and work-package issues. The first foundation was merged in [PR #15](https://github.com/VASEYDEV/VASEYAUDIO/pull/15); private media processing was merged in [PR #21](https://github.com/VASEYDEV/VASEYAUDIO/pull/21), with [verification evidence](docs/verification/media-pipeline.md). Subsequent work is delivered through bounded pull requests with implementation evidence.

Use the [PR template](.github/PULL_REQUEST_TEMPLATE.md) for each increment, including PR bodies supplied through CLI/API tools. Keep only applicable risks, record actual verification against the tested commit, and link the next work package. Use `Advances #issue` for partial delivery; close a work package only when its full acceptance criteria are met.

For subsequent operator-driven publication, the optional helper expects the official GitHub CLI authenticated as VASEYDEV:

```sh
python3 scripts/publish-github.py --issues
```

This explicitly invoked script creates the private repository if absent, pushes `main` without force, and creates missing work-package issues. It refuses a public repository or an unrelated origin. It never runs during setup, CI, or site deployment. If starting from the source ZIP without `.git`, restore its accompanying Git bundle first using the package instructions.

## Cutover rule

Do not move `www.VASEY.AUDIO` merely because the UI is ready. Reconcile the seller-owned catalog, exact contracts, paid orders, refunds, customers, consent, subscriptions, private assets, and legacy URLs. Prove payment-to-contract-to-entitlement-to-download, exclusive inventory safety, monitoring, backup restore, and rollback. Existing obligations may promote any later feature into a cutover blocker.

The merchant-of-record, tax, payment methods, storage/hosting, license terms, exclusive policy, and historical grant decisions must be settled from actual records. The supplied research is preserved through an evidence ledger with corrections; it is not treated as a blanket approval.

## Ownership

Project code and VASEY.AUDIO assets are proprietary unless a file states otherwise. Third-party dependencies and self-hosted fonts retain their upstream licenses. See the brand provenance records and lockfiles. Never commit secrets, private masters/stems, customer exports, generated contracts, or production databases.
