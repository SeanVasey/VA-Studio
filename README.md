# VASEY.AUDIO

Bespoke web store development replacing older, service-based e-commerce offerings. First-party music storefront, publishing administration, licensing, and secure delivery for Sean Vasey. This project replaces the current BeatStars site through staged, verifiable implementation.

**Status: development foundation with private media processing, exact license review evidence, immutable offer revisions, provisional selection reviews and a Stripe test webhook inbox. Live payments, purchased downloads, historical migration, and production cutover are not enabled.** The current BeatStars site remains authoritative for sales and existing customer obligations.

## Start here

- [Operator setup and diagnostics](docs/operator-setup-and-verification.md): audited console provisioning, read-only installation checks and isolated browser verification.
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
- [Exclusive offer preparation](docs/exclusive-offer-preparation.md): inactive v2 revisions with explicit scope evidence, historical compatibility and the remaining activation boundary.
- [Atomic test pricing and inventory](docs/atomic-priced-inventory.md)
- [Dependency PR integration and compatibility decisions](docs/dependency-pr-integration.md)
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
| Admin | Authenticated Filament catalog/licensing management, audited metadata edits and permanent published track URLs | Detailed RBAC, recovery verification, site editing, customers and fulfillment operations |
| Media | Private WAV/artwork and bounded WAV-stems ZIP intake, queued verification, immutable master/MP3 revisions, tagged previews, measured waveforms, admin retry controls and audited stems/master associations frozen in offers | Production scanner acceptance, worker isolation, real stems alignment acceptance, resumable uploads and managed object storage |
| Licensing | Exact submitted review payloads, independent approval evidence, source/summary preview, successor diffs and effective dates | Approved production terms, a complete typed rights schema, buyer contracts and archival PDF rendering |
| Offers | Editable drafts separated from immutable published price/license/file revisions; revision history and cart invalidation; internal inactive exclusive revisions with frozen scope/link evidence | Exclusive activation and quote/pricing integration, purchase snapshots and fulfillment |
| Commerce | Immutable owned selection/license disclosure and pricing/test-tax evidence; v2 promotion allocation/campaign limits and internal shared-inventory test reservations | Exclusive offer/quote integration, actual production policies, buyer identity/assent/orders, provider checkout, terminal coupon redemption/release, refunds, grants and delivery |
| Payment evidence | Stripe test-only snapshot webhook with SDK signature verification, immutable encrypted receipts, scoped duplicate handling and a metadata-only operator command | Hosted Checkout sessions, durable processing/reconciliation, authoritative payment validation and atomic order/grant/fulfillment effects |
| Full parity | Documented work packages and evidence gates | Memberships, kits, services, merch, CRM, promotions, editorial, integrations and migration |

Public metadata observed on BeatStars is evidence, not an imported production catalog. Design fixtures are development-only and carry no saleable rights. The local cart pins the advertised offer revision and invalidates selections when that revision changes. **Review selection** asks the server to revalidate the exact selection and freeze its price, license and file evidence. The returned subtotal is provisional: tax and total are unknown, `payable` is false, and no order, reservation, assent or purchase rights are created.

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
php artisan vasey:doctor
php artisan serve
```

Open `http://localhost:8000` for the storefront and `http://localhost:8000/admin` for administration. The administrator command prompts for credentials and records console provisioning in the audit trail; no password or preconfigured user ships with the project. The read-only doctor command identifies missing installation prerequisites and optional configuration without changing them. The initial catalog is empty by design. WAV, PNG/JPEG and WAV-only stems ZIP uploads enter private quarantine. Configure the scanner, seller tag and media worker using [Media operations](docs/media-processing.md) before requesting processing in the admin panel. Without those prerequisites, uploads cannot become ready for publication. Production mode requires TOTP enrollment. License publication requires a separate authorized reviewer, an exact submission hash, a consistency attestation and an actual review reference. See [Licensing and offers](docs/licensing-and-offers.md) and [typed usage terms](docs/typed-license-terms.md) before publishing licenses or commercial revisions. New license drafts require explicit usage, [territory and duration](docs/license-territory-duration.md) and [ownership/economic policy](docs/license-economic-policies.md) choices with matching source variables and retained policy text; v1/v2/v3 evidence is preserved.

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

GitHub CI runs the PHP suite against MySQL 8.4 and SQLite, frontend tests/build and dependency audits (including development tools), plus an isolated operator browser job using Chromium and WebKit. See [browser verification](docs/operator-setup-and-verification.md#browser-verification) for commands, fixture isolation and evidence boundaries. A workflow file is not evidence that GitHub has run it. MySQL concurrency, real providers, mobile Safari playback, restore drills, and production deployment remain separate acceptance gates until recorded in the verification report.

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
