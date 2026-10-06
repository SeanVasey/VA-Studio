# Account-first test customer library

This bounded T23/T24 child gives an explicitly provisioned, verified nonstaff test customer one stable owner identity. A customer can sign in, use the existing test quote/order services, close the browser session, sign in again and discover that account's orders and original private deliveries. It does not close T23/T24, establish legal buyer verification, or choose U07's production enrollment, guest claim or recovery policy.

## Enabled boundary and interface

`VASEY_TEST_CUSTOMER_ACCOUNTS_ENABLED=true` enables the feature only in `local` or `testing`; its default is false and production always refuses it. Internal `CustomerAccounts::provision(User)` requires the current persisted verified nonstaff user under User → CustomerAccount locks. Repeating provisioning retains the account identity and does not reactivate withdrawn access. There is no registration, claim, password reset, email delivery or administrative account-management endpoint.

The `customer` session guard is separate from the existing `web` staff guard. Customer sign-in never establishes staff authentication or MFA. `/account/sign-in` serves the sign-in page and accepts a CSRF-protected bounded JSON POST containing only `email` and `password`. Success returns `{authenticated: true, next: '/account'}`. `/account` serves the current customer's private library. CSRF-protected `/account/sign-out` accepts exactly `{}` and returns `{authenticated: false, next: '/account/sign-in'}`; it remains available after access withdrawal. Both auth transitions rotate the session and CSRF token and direct the frontend to a fixed full-page navigation. Private Inertia history is encrypted and auth changes clear its history.

Account pages and errors are private/no-store, noindex and no-referrer. Unknown address, invalid credential, withdrawn account, unverified user and staff account use the same generic failed sign-in envelope. Requests are rate limited, bounded to 4096 body bytes, and reject extra/duplicate/nested keys, query parameters, cross-origin intent and method override. Exceptions are logged by class only at this boundary. Customer principal/owner/credential evidence is never an HTTP prop; its DTO also refuses JSON/PHP serialization and redacts debug output.

## Ownership and withdrawal

Migration `000040` retains a numeric account ID, opaque public UUID, unique user, random owner key and versioned active control. Identity fields cannot change or be deleted. Raw `REPLACE` collisions on any of the four unique identities are rejected before replacement, including SQLite with recursive delete triggers disabled. Migration preflight refuses temporary shadows and foreign guard names; rollback requires empty retained state and exact owned guard definitions before removing anything.

`CommerceRequestIdentity` derives account ownership only from the separate authenticated guard and server session marker. It verifies current account identity, access version, verified/nonstaff status and a keyed stamp of the current credential. A stale account marker fails closed; it does not fall back to guest ownership. Existing guest `QuoteOwner` behavior and all historical order owner keys stay unchanged. Matching a buyer email, signing in, or a return URL never claims an earlier guest order. Legal buyer evidence remains the existing `unverified_guest` policy.

`CustomerSessions` checks the exact credential under the user fence, acquires the account fence, and completes any password rehash before deriving the session principal. The controller retains that same principal across guard login events, then checks it again. A password reset in that interval cannot produce a session stamped for the replacement credential.

The customer-aware quote, price, review, prepare-order, checkout, issue-delivery and redeem-delivery entry points acquire User → CustomerAccount before their existing resource locks. HTTP read projections also receive a final current-access check. Current password, verification, staff-role, account version and feature withdrawal invalidate retained access. No provider call or private file preparation is moved into these new database fences. Checkout rechecks access after provider account validation and before the effecting call; a verified provider observation returned during subsequent withdrawal is still retained through the existing system evidence path, while the customer response is denied. Delivery checks before opening private bytes and again under its writer fence, closes denied prepared spools and explicitly attributes customer audits. These checks cannot revoke bytes already streamed or undo an external effect already performed.

## Usable fixture and verification

The existing guarded `tests/browser/run.mjs`/`bootstrap.php` creates only a fresh disposable loopback installation. It provisions the synthetic customer and creates one actual account-owned paid/issued/activated test order for each browser project through existing quote, pricing, order, provider-evidence verification, finalization, contract issuance and activation services. Provider and PDF renderer transports are synthetic test implementations in the CLI setup; no live provider, real payment, production legal document or receipt is claimed. The fixture hides its sample tracks from the ordinary catalog. Original contract/asset bytes remain private.

`customer-fixtures.json` contains only each project's order ID and expected filenames, roles, sizes and hashes. The existing `fixtures.json` top-level contract is unchanged. HTTP payment initiation and processing remain disabled in the browser server; retained synthetic delivery access is explicitly enabled. The separate UI lane's browser test closes an authenticated context, creates a fresh context without copying cookies/storage, signs in, discovers the same frozen order and downloads its original contract and asset with real CSRF-protected HTTP. Native Chromium/WebKit execution remains required in the final composed batch; local discovery/setup is not browser execution.

Current author verification:

- SQLite customer cases: **28 tests, 224 assertions, no failures**. Includes HTTP sign-in → real prepared order → fresh session history, exact original contract/asset bytes, explicit audit attribution, CSRF/session rotation, bounded private errors, rehash/reset, withdrawal during reads/file preparation/provider calls, immutable identity and migration shadow/collision refusal.
- MySQL 8.4.11: **37 tests, 557 assertions, no failures or skips** in the complete customer run. Its nine exact-fence datasets observe independent process/connection IDs and the exact User PRIMARY record `WAITING` edge before committed account withdrawal/password reset; every customer entry point denied without new commerce/delivery evidence or provider calls.
- Guarded browser setup, fresh migrations, installation diagnostics and Playwright discovery passed; no local browser engine acceptance claimed.
- Existing quote/pricing HTTP regression: **17 tests, 447 assertions, no failures**. Existing order preparation, owner history, hosted checkout, delivery HTTP and delivery-domain regression: **202 tests, 201 passed, 3774 assertions, one expected SQLite skip** (`HostedCheckoutTest::test_provider_create_observes_a_committed_intent_from_an_independent_mysql_connection`). These counts are separate from the customer cases above. The latter run used an exact committed-source archive in `/tmp` with an explicit `APP_BASE_PATH`, avoiding a workspace-synchronized generated Vite manifest that repeatedly caused the existing unversioned Inertia test to return its legitimate version-change 409. The clean archive remained without that generated manifest; no source/test expectation was changed.
- Production enrollment/recovery/guest claim, real hosting/provider/content/operational acceptance and full feature parity remain outside this bounded child.

Commands (from the customer checkout, with the recovered PHP executable on `PATH` and a fresh disposable app key):

```sh
php vendor/bin/phpunit tests/Feature/CustomerAccountAccessTest.php tests/Feature/CustomerAccountCommerceTest.php tests/Feature/CustomerAccountMigrationTest.php tests/Feature/CustomerSessionHttpTest.php
python3 /workspace/scratch/af00b8316749/mysql-runtime/run-tests.py -- php vendor/bin/phpunit tests/Feature/CustomerAccountAccessTest.php tests/Feature/CustomerAccountCommerceTest.php tests/Feature/CustomerAccountConcurrencyTest.php tests/Feature/CustomerAccountMigrationTest.php tests/Feature/CustomerSessionHttpTest.php
npm run build
node tests/browser/run.mjs --list
```

All changed PHP files pass Pint; `git diff --check` passes. Browser build/discovery before UI composition proves setup only; the independently authored customer UI and its native browser cases must be composed before the hosted browser gate.

The terminal legacy run used the same runtime bytes as commit `96fd8d0aa8f9031f224df251766753b97db85f80`; this evidence update changes no source. Independent source review approved that commit/tree `cbf94801a731d4d644bba1087b15b736714c31e4`, including credential/reset fences, SQLite replacement protection, migration collateral refusal, explicit delivery attribution and exact MySQL withdrawal workers. The reviewer did not duplicate the author's runtime execution.
