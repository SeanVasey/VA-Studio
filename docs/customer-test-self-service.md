# Local test customer enrollment and recovery

This T23/T24 child extends the existing account-first customer library with a usable local/testing enrollment and password-recovery journey. A visitor requests a test message, opens its private captured link, chooses a password and signs in separately. Recovery keeps the same account and owner key; existing orders, original contracts, purchased files and guest ownership are unchanged.

## Activation and transport

All three settings are required:

```dotenv
VASEY_TEST_CUSTOMER_ACCOUNTS_ENABLED=true
VASEY_TEST_CUSTOMER_IDENTITY_ENABLED=true
VASEY_TEST_CUSTOMER_IDENTITY_TRANSPORT=private_capture
```

The environment must be `local` or `testing`. Production rejects these pages and commands even when the flags are supplied. SMTP, the log mailer and any other transport are refused. No mail is sent. The renderer creates a synthetic notification in the private local disk at `customer-identity-capture/<challenge UUID>.json`. A tester with access to that disposable installation reads the capture directly; no HTTP route exposes captures. Its link uses the configured application origin and puts the proof in a URL fragment. It must be opened only against that test installation.

Possession of a privately captured synthetic proof is not real mailbox verification, legal buyer verification or production enrollment acceptance. Production notice, retention, mail delivery and recovery policy remain open. Existing buyer evidence retains its `unverified_guest` policy. Main `13d7474f` includes explicit per-order guest test-purchase claims: an authenticated active account proves the original owning session and confirms the retained paid purchase. Matching email alone still transfers nothing. See the [claim/refund composition](verification/purchase-claims-refund-resolution-composition.md) and [current source reconciliation](remaining-code-assessment-20261006.md); production claims and fresh native/final acceptance remain separate.

## Customer journey

When enabled, sign-in offers **Create a test account** and **Recover a test account**. `/account/create` and `/account/recover` send a strict CSRF-protected request to `/account/identity/request`. Every syntactically valid request returns the same `{accepted: true}` acknowledgement, including unknown recovery addresses, existing enrollment addresses, staff, unverified and withdrawn accounts. The response contains no challenge ID, recipient, proof or eligibility result.

The private message opens `/account/access#<purpose>.<UUID>.<proof>`. The application removes the fragment before Inertia initializes. Proofs and uncertain completion bytes remain only in page memory; they are never written to browser storage, page props or retained history. Success and navigation clear them. Refreshing an unfinished page requires reopening the original captured link. Passwords require at least 12 characters with letters and numbers, and at most 72 UTF-8 bytes to avoid bcrypt truncation.

Completion is a strict CSRF-protected POST. It authenticates neither the customer nor staff guard. The visitor signs in separately; recovery invalidates existing customer sessions through the credential stamp. Staff authentication is not altered. Fresh customer sign-in discovers the same account-owned order history. Matching an email never transfers a guest order.

## Evidence and concurrency

Challenges expire after ten minutes. Their address, purpose, policy, initial credential/account/version binding, proof hash and timestamps are immutable. Addresses are normalized consistently, and ambiguous case variants fail closed. Database records encrypt the recipient and retain only a proof hash; a keyed pseudorandom proof can be reproduced for an exact resend without storing its plaintext in SQL. Four newly retained challenges per address per hour bound test request creation; HTTP limits separately bound request and completion attempts. Exact request retries retain the original proof and expiration. A new request key receives its own retained challenge; one successful completion invalidates other pending proofs through the newly created user or changed credential.

Each operation serializes on the normalized address before acquiring User → CustomerAccount → Challenge locks. Enrollment refuses existing users, including staff and unverified accounts. Recovery requires the exact current active account, original user, access version and credential stamp. Withdrawal, staff promotion, verification removal, email changes and password changes invalidate pending recovery. One completed command records one audit and credential write. An exact lost-success retry may confirm the retained result only while that result's account and credential are still current; changed retry bytes or a later reset cannot replay an older credential.

Capture runs after the request transaction. Capture failure keeps the public acknowledgement generic; the same request can reproduce the capture. Audit failure rolls back the complete identity change. Migration guards reject destructive replacement, identity changes, invalid state transitions and unsafe rollback of retained or foreign objects. No external mail, provider call or filesystem capture occurs under the identity locks.

## Verification

The feature adds domain/HTTP, migration, native MySQL concurrency, frontend and guarded native-browser cases. The native race suite observes independent process/connection IDs and actual waiting edges on the exact address or User PRIMARY record before allowing competing completions or committed withdrawal. Duplicate requests produce one credential write; conflicting completion payloads have one winner.

Commands from this worktree with a fresh disposable application key and the recovered PHP executable on `PATH`:

```sh
php vendor/bin/phpunit tests/Feature/CustomerIdentityTest.php tests/Feature/CustomerIdentityHttpTest.php tests/Feature/CustomerIdentityMigrationTest.php tests/Feature/CustomerAccountAccessTest.php tests/Feature/CustomerAccountMigrationTest.php tests/Feature/CustomerSessionHttpTest.php
python3 /workspace/scratch/af00b8316749/mysql-runtime/run-tests.py -- php vendor/bin/phpunit tests/Feature/CustomerIdentityConcurrencyTest.php tests/Feature/CustomerIdentityTest.php tests/Feature/CustomerIdentityHttpTest.php tests/Feature/CustomerIdentityMigrationTest.php tests/Feature/CustomerAccountConcurrencyTest.php tests/Feature/CustomerAccountMigrationTest.php tests/Feature/CustomerSessionHttpTest.php
npm test -- tests/frontend/customer-identity.test.tsx tests/frontend/customer-account.test.tsx
npm run typecheck
npm run build
node tests/browser/run.mjs tests/browser/customer-identity.spec.ts
```

The browser wrapper must enable the two new identity flags above within its existing disposable loopback installation. The browser journey uses real HTTP and CSRF for enrollment, separate recovery, new-password sign-in and rejection of the original session; its only non-HTTP read is the deliberately private synthetic capture. Native Chromium/WebKit acceptance remains separate from test discovery. Exact executed results and final integration acceptance are recorded with the tested commit; these commands alone claim no execution or production readiness.

The author verification for this candidate includes **63 SQLite tests / 515 assertions**, with no failures or skips, across identity domain/HTTP/schema and existing customer access/schema/session cases; **103 frontend tests**, including 72 new identity cases and 31 existing account cases; and successful TypeScript, production build, client secret scan, Pint and whitespace checks. Fresh guarded migrations, operator setup and installation diagnostics passed and discovered the two Chromium/WebKit self-service journeys. Native browser engines were unavailable locally, so no browser execution is claimed. Independent backend review found and verified fixes for cross-key request binding and a MySQL collation-dependent response leak; schema review separately assessed retained identity, state transitions and rollback ownership. Complete hosted acceptance remains required after composition.

The final current-source native MySQL 8.4.11 selection passed **75 tests / 1,222 assertions**, with no failures or skips, in 329.321 seconds. It includes all nine new identity race datasets and all nine existing customer-access race datasets, alongside identity domain/HTTP/migrations and account/session regressions. The earlier migration contributor's mixed-source run reported an obsolete resend-policy expectation during concurrent authoring; the frozen-source final selection supersedes that partial result. This focused selection is not the complete application database gate.
