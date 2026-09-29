# CLAUDE.md

The shared contributor rules in `AGENTS.md` apply in full. This file adds operating notes for Claude Code sessions. Where they differ, `AGENTS.md` and Sean's current instructions win.

@AGENTS.md

## Orientation

- This repository is the VASEY.AUDIO music store (Sean Vasey Productions), replacing BeatStars. VASEY/AI is a separate AI-tools brand: never mix its name, copy, assets, tokens or domains into this project.
- Before choosing work, read `docs/development-order.md` (ordered status and next slice), the selected `docs/work-packages/WP-xx-*.md`, its GitHub issue (#1–#14) and the matching `docs/architecture/D-xx-*.md`. Prose status can lag GitHub; confirm against merged PRs and the latest CI run on `main`.
- Commerce, contracts, delivery and promotions run only behind default-off local/testing policies (the `*Policy` classes under `app/Domain`). Adding a live path, changing provider or DNS settings, or importing customer data needs Sean's explicit authorization for that step.

## Code map

- `app/Domain/{Catalog,Commerce,Contracts,Delivery,Media,Rights,SiteBuilder}`: business rules and state transitions. New rules go here.
- `app/Filament`: staff administration. `app/Http` with `resources/js` (Inertia, React, TypeScript): storefront. Both call domain services and never reimplement pricing, licensing, publication or entitlement logic.
- `app/Jobs` and `app/Console/Commands`: media processing, Stripe receipt processing, payment finalization, contract issuance and the `vasey:*` operator commands.
- `database/migrations`: additive only. Preserve immutable evidence tables and their triggers.
- `docs/`: decision contracts (`architecture/D-xx`), work packages, verification evidence, and the migration and brand ledgers.

## Cloud environment setup

The default container does not match the project runtime. Before running checks:

```sh
. /opt/nvm/nvm.sh && nvm install 24 && nvm use 24   # package.json requires Node >=24.15 <25
apt-get update && apt-get install -y --no-install-recommends ffmpeg qpdf poppler-utils php8.4-bcmath   # CI parity
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist
npm ci
cp .env.example .env && php artisan key:generate
```

The network policy blocks GitHub's archive download hosts, so Composer falls back to git clones and the first install takes several minutes. There is no local MySQL server: MySQL-only tests skip under SQLite, and the four MySQL shards in GitHub CI are the concurrency evidence. A skip is never a pass. The preinstalled Playwright browsers do not match the pinned `@playwright/test` version, so browser specs run in CI.

## Checks

- Focused: `php artisan test --filter=<TestClass>`, `npm test`, `npm run build` (includes the TypeScript check).
- Full SQLite suite in parallel: `python3 scripts/ci/phpunit-shards.py --shards=4 --prefix=phpunit-ci-local` (the prefix must match `phpunit-ci-*`), then run `php vendor/bin/phpunit --configuration=phpunit-ci-local-<n>.xml` for each shard. The generated `phpunit-ci-local-*` files are scratch and not gitignored: delete them before regenerating and never commit them.
- Before opening a PR: `composer validate --strict`, `composer audit` and `npm audit --audit-level=high`. The PR must then pass all ten jobs in `.github/workflows/ci.yml`, including the MySQL shards and the Chromium/WebKit browser job (`npm run test:browser`).

## Workflow

- One bounded increment per PR, on the branch the session assigns (Codex used `work/wpNN-<slug>`). Fill in `.github/PULL_REQUEST_TEMPLATE.md`. Use "Advances #N" for partial work-package delivery; use "Closes #N" only when every acceptance criterion has evidence.
- Record the tested head SHA, exact commands and pass/skip/fail counts in the PR. Update `docs/development-order.md` and the work-package file at meaningful checkpoints.
- Concurrency claims need independent-process MySQL tests; follow the existing `*ConcurrencyTest.php` files and the race workers in `tests/Support`.
- Payment, licensing, authorization, migration and irreversible data changes need an independent review of the exact tested commit before merge.
