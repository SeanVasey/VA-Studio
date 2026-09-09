# Operator setup and installation verification

WP-01/WP-02 increment in [PR #29](https://github.com/VASEYDEV/VASEYAUDIO/pull/29), 2026-09-09. Run these commands from a trusted console on the installation you intend to administer. Use the [README prerequisites and fresh-install steps](../README.md#run-locally); do not regenerate an existing installation's encryption key.

## Provision an operator

```sh
php artisan vasey:create-admin
```

The command prompts for name, email and a hidden password of at least 16 characters containing letters and numbers. It requires interactive input and accepts no credential flags or environment-password shortcut. Duplicate emails and invalid input cannot change an existing account or grant it access.

Account creation and `access.operator.created` audit insertion commit together. Audit failure rolls back the account. The event records the new subject ID and `trusted_interactive_console` authority; `actor_id` is null because this is console provisioning, not an authenticated browser action. Email verification is explicitly recorded as `console_attested`, not an email-delivery claim. Passwords, email addresses and private exception details are excluded from audit context. The password is hashed through the existing User cast. Production MFA enrollment remains required by the panel and needs separate enrollment/recovery evidence.

The console is a privileged operator boundary. This command does not add a public registration path, an owner role hierarchy or an automated provisioning API.

## Inspect the installation

```sh
php artisan vasey:doctor
php artisan vasey:doctor --json
```

The report checks PHP/core extensions, encryption-key format, a read-only database query, applied migrations, verified operator presence, writable runtime directories, the Vite manifest and listed files, private local media storage and basic production HTTPS/debug/cookie settings. It reports optional media executables, scanner, seller-tag configuration, asynchronous queue selection and outbound mail configuration separately.

| Status / output | Meaning |
| --- | --- |
| `pass` | The named, limited installation check succeeded. |
| `warn` | Optional configuration is missing; follow the stated next action before using that capability. |
| `fail` | A required installation prerequisite failed. |
| Exit 0 / `foundation_ready: true` | No required installation check failed. Optional warnings may remain. |
| Exit 1 / `foundation_ready: false` | Resolve required failures and rerun the report. |

JSON uses `schema_version: 1`, `scope: installation` and a stable list of check IDs/statuses/messages. Values, file paths, account identities and raw connection exceptions are not printed. The command creates no directories, users, keys, migrations, jobs or audit records, and makes no external provider calls. It does not scan audio, validate tag bytes, send mail, prove worker liveness, test backups or certify production. A configured executable or transport does not establish successful operation.

## Browser verification

After Composer and npm dependencies are installed:

```sh
npm run build
npx playwright install --with-deps chromium webkit
npm run test:browser
```

The locked Playwright 1.63.0 suite runs desktop Chromium and WebKit with an iPhone-sized viewport. Each project checks guest/customer denial, a rejected tokenless Livewire request, actual operator sign-in, persistent draft creation, visible uniqueness errors, keyboard modal focus, two-tab stale-edit rejection/recovery, a disabled retained-URL field, and named publication blockers. It captures screenshots of validation, stale-edit and publication-blocker states. These are automated rendered browser checks; WebKit emulation is not a physical iPhone/Safari or VoiceOver acceptance claim.

Metadata create/edit modals initially focus their window, then Tab reaches the first field. A transition-end fallback recovers focus if WebKit rejected the initial attempt while the window was opening; it does not move focus away from a field already in use. The stale-edit browser flow waits for the cancellation response. Persisted creation and the winning revision are verified through fresh server reads in new tabs, with no retained component state. All workflow pages retain unexpected-JavaScript-error assertions until normal test teardown.

The Node wrapper creates a new private temporary directory, empty SQLite database, per-run encryption key and random credentials. A guarded CLI fixture builder verifies that exact empty database and temporary directory before running fresh migrations and the real interactive operator command through Symfony's command tester. It then creates synthetic draft fixtures. A retained draft URL represents synthetic history only; no media, rights, scanner evidence or purchase is invented.

The HTTP server runs in `local` environment so Laravel's CSRF protection stays active. The harness does not add test endpoints or application authentication bypasses. It binds only to `127.0.0.1:8173`, refuses to reuse an existing server, and isolates config/route/event caches and Laravel storage through per-run environment values. An existing Vite hot file or checkout maintenance file prevents starting. Normal teardown removes the temporary database, sessions, logs and credentials; abrupt process termination may leave disposable temporary files for the host to clean up.

`operator-browser` CI installs the committed lockfiles and browser engines, builds assets, provisions the isolated installation, runs diagnostics and executes the same suite. It retains only synthetic Playwright reports/screenshots/failure traces for seven days. No production database, private media or host secrets are test inputs. Inspect the exact-head PR jobs for actual results; a configured job alone is not execution evidence.

## Evidence and next dependency

Local execution of `npm ci`, `npm test`, `npm run build` and the production npm audit succeeded during this increment: 39 frontend tests, TypeScript/Vite build and zero reported production vulnerabilities. PHP/Composer were unavailable in the editing workspace; PHP and rendered browser results come from completed GitHub CI and are recorded in the PR.

The backend suite adds provisioning validation/atomicity and read-only diagnostic/redaction regressions on MySQL and SQLite. The browser suite covers the operator metadata workflows above. Independent authorization review, production MFA/recovery, real-device accessibility and full upload-to-publication browser acceptance remain tracked. Forced reloads of an active Livewire page produced WebKit unload-time promise rejections in earlier CI traces even after request settlement; abrupt-navigation behavior remains a separate QA finding, and the fresh-tab workflow does not claim to resolve it. Mobile validation evidence explicitly scrolls to the message; automatic error discovery/scrolling needs broader device acceptance. After the foundation PR is accepted, continue WP-03 with bounded stems/ZIP safety, then WP-04 typed rights and WP-05 detail/device work in the [existing order](development-order.md).

References: [Playwright web-server lifecycle](https://playwright.dev/docs/test-webserver), [Playwright CI](https://playwright.dev/docs/ci-intro), [Laravel console commands](https://laravel.com/framework/docs/artisan).
