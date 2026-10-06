# Private local alpha

Use this disposable installation to explore the existing storefront and practice real administration with synthetic data. It runs only on your computer at `127.0.0.1:8174`. It is not a staging deployment or a production installation.

## Start the real application

Install the locked Composer and npm dependencies with PHP 8.4+ and its required extensions, including SQLite, and Node 24.15+. The [Mac bootstrap](development-macos.md) can prepare those development tools. No MySQL server, scanner, FFmpeg, payment account, email service or media files are needed for this sandbox.

```sh
composer install --no-interaction --prefer-dist --no-scripts
npm ci --ignore-scripts
npm run build
npm run alpha:local
```

The launcher prints the storefront and admin URLs, the synthetic operator email and a new random password after the real admin sign-in page responds. Keep that terminal open. Sign in at `/admin` using those temporary credentials. Every launch creates a different encryption key, database, session cookie and private storage directory; the checkout's `.env`, database and cached configuration are not used or overwritten. Admin assets are published into that disposable directory; built storefront assets are read from the checkout. Do not run `composer setup` against an existing installation: that legacy script regenerates its encryption key.

All fixture records are visibly named **SYNTHETIC ALPHA**. In Tracks, edit either draft, save it, reload and inspect the persisted changes. Create another draft if useful. The publication action reports real readiness blockers: these drafts have no audio, artwork, rights evidence or approved offers. Site-content drafts and their private previews use the existing administration services, and publishing a site release affects only this disposable local installation. Do not enter real customer information, credentials, legal terms or private media.

The real storefront starts with its honest empty catalog. This launcher does not fabricate saleable tracks, licenses, completed processing or purchases. Payments, webhooks, contract issuance, fulfillment activation, downloads, contact inquiry capture and outbound mail are disabled. Background jobs are stored only in the temporary database; no worker or scheduler is started. Uploads therefore cannot become processed media in this sandbox.

Press **Ctrl+C** to stop the server and delete that run's database, files, credentials and edits. Start again for a clean session. Save desired copy separately before stopping; there is no export or resume command. Normal shutdown, failed setup and early server exit clean up the owned temporary directory. A machine crash or forced kill can leave a private `vasey-alpha-*` directory in the operating system's temporary folder; do not reuse it as an installation.

The launcher refuses an occupied port, an active Vite hot file or checkout maintenance mode. Stop that development server first. It never reuses or stops an unrelated listener, and offers no host/database/credential override. Its PHP HTTP process permits 9 MiB upload requests so an 8 MiB resumable chunk plus multipart overhead can be admitted; application file and chunk limits remain enforced. Do not expose this local development server through a tunnel or reverse proxy.

## Preview the composed storefront

For a separate visual fixture view, run:

```sh
npm run preview:design
```

Open `http://127.0.0.1:5174/?fixtures`. This existing preview uses the production React components with explicitly labeled **[DEMO]** fixtures. It demonstrates catalog composition, selection and responsive layout; it has no real media playback, backend admin, payment or publication. Its fixtures are not synchronized with the disposable admin database. Use the real alpha URLs above for persisted admin behavior.

## Verification boundary

```sh
node --test scripts/dev/private-alpha.test.mjs
# CI and an installed PHP runtime must execute the native bootstrap/HTTP cases:
PRIVATE_ALPHA_REQUIRE_PHP=1 node --test scripts/dev/private-alpha.test.mjs
```

The safety suite checks isolated environment values and caches, private permissions, unique secrets, path canonicalization, refusal to reuse an occupied listener, child-process cleanup and credential disclosure only after per-run readiness. The native cases run real SQLite migrations and audited operator/draft services, inspect stored evidence, refuse reseeding and unsafe configuration, boot real Laravel HTTP, and reject direct PHP, storage, foreign-host and escaping-symlink requests. They also load actual admin assets, verify guest and CSRF protection, sign in through the real Livewire endpoint and save a draft, then confirm its new revision and audit record. This is HTTP protocol verification, not a rendered-browser test. The required flag turns unavailable PHP/SQLite/Composer prerequisites into a failure. Without it, native cases are explicitly skipped when that runtime is unavailable; those skips are not runtime evidence.

These checks do not establish production deployment, real media processing, MySQL concurrency, actual provider interoperability, customer migration or launch readiness. Exact executed source and browser sign-in/edit evidence belong in the integrating PR. The next setup dependency is a selected private deployment and separately verified real media/worker configuration; this sandbox changes neither existing sales nor the cutover gates.
