# VASEY.AUDIO

Bespoke web store development replacing older, service-based e-commerce offerings. First-party music storefront, publishing administration, licensing, and secure delivery for Sean Vasey. This project replaces the current BeatStars site through staged, verifiable implementation.

**Status: development foundation with the first private media-processing increment. Live payments, purchased downloads, historical migration, and production cutover are not enabled.** The current BeatStars site remains authoritative for sales and existing customer obligations.

## Start here

- [Agent instructions](AGENTS.md): shared rules for Astra, Codex, Claude, and other contributors.
- [Architecture](docs/architecture/): domain boundaries, decision register, contracts, and roadmap.
- [Work packages](docs/work-packages/): dependency-scoped implementation assignments with acceptance criteria.
- [Migration evidence](docs/migration/): reconciled research, feature parity, field mapping, and cutover requirements.
- [Brand evidence](docs/brand/): current published theme, exact identity assets, and source provenance.
- [Verification](docs/verification/): actual local checks and remaining gates.
- [Media operations](docs/media-processing.md): worker prerequisites, approved preview tag, processing and recovery.

## Foundation scope

| Surface | Initial implementation | Remaining delivery |
| --- | --- | --- |
| Storefront | Responsive catalog, filtering, license comparison, local cart, shared track links, persistent audio transport | Production catalog import, deeper discovery, collections and product-specific routes |
| Admin | Authenticated Filament catalog and licensing management | Detailed RBAC, recovery verification, site editing, customers and fulfillment operations |
| Media | Private WAV/artwork intake, queued verification, immutable master/MP3 revisions, tagged previews, measured waveforms and admin retry controls | Production scanner acceptance, worker isolation, stems/archives, resumable uploads and managed object storage |
| Licensing | Structured license versions, approval/publication workflow and readiness checks | Reviewed executed terms, buyer-specific contracts and transactional purchase snapshots |
| Commerce | Explicitly unavailable checkout boundary | Provider checkout, verified webhook inbox, exclusive reservations, tax, refunds, grants and delivery |
| Full parity | Documented work packages and evidence gates | Memberships, kits, services, merch, CRM, promotions, editorial, integrations and migration |

Public metadata observed on BeatStars is evidence, not an imported production catalog. Design fixtures are development-only and carry no saleable rights. The local cart is provisional UI state; future server quotes must determine payable amounts.

## Stack

Laravel 13 / PHP 8.4+, Inertia 3 / React / TypeScript, Filament 5. MySQL 8.4 is the production database target; SQLite is supported for local development. Redis jobs and private S3-compatible storage are staged behind later work packages. Keep the stack modular within one application and one transactional database.

No LLM is required by the storefront runtime. Astra is the requested development lead, not a dependency exposed to customers.

## Run locally

Prerequisites: a currently patched PHP 8.4 or 8.5 with `pdo_sqlite`, `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `gd`, `fileinfo`, and `zip`; Composer 2; Node 24; npm. Use your platform's supported installers.

```sh
composer install
npm ci
cp .env.example .env
php artisan key:generate
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate
php artisan vasey:create-admin
npm run build
php artisan serve
```

Open `http://localhost:8000` for the storefront and `http://localhost:8000/admin` for administration. The administrator command prompts for credentials; no password or preconfigured user ships with the project. The initial catalog is empty by design. WAV and PNG/JPEG uploads enter private quarantine. Configure the scanner, seller tag and media worker using [Media operations](docs/media-processing.md) before requesting processing in the admin panel. Without those prerequisites, uploads cannot become ready for publication. Production mode requires TOTP enrollment; a separate administrator must approve a license version before publication.

For frontend development, run `npm run dev` alongside `php artisan serve`. For the isolated composition preview, run `npm run preview:design`; that preview uses explicitly labeled fixtures and shares the production React components. It cannot publish, charge, or grant a license.

## Verify

```sh
composer validate --strict
php artisan test
npm test
npm run build
composer audit --no-dev
npm audit --omit=dev --audit-level=high
```

GitHub CI is configured to run the PHP suite against MySQL 8.4 and SQLite, plus frontend tests, build, and production dependency audits. A workflow file is not evidence that GitHub has run it. MySQL concurrency, real providers, mobile Safari playback, restore drills, and production deployment remain separate acceptance gates until recorded in the verification report.

## GitHub publication

Repository: [VASEYDEV/VASEYAUDIO](https://github.com/VASEYDEV/VASEYAUDIO), private. Sean created the repository during the initial implementation; the GitHub integration supplies commits and work-package issues. The first foundation was merged in [PR #15](https://github.com/VASEYDEV/VASEYAUDIO/pull/15); private media processing is delivered in [PR #21](https://github.com/VASEYDEV/VASEYAUDIO/pull/21), with [verification evidence](docs/verification/media-pipeline.md). Subsequent work is delivered through bounded pull requests with implementation evidence.

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
