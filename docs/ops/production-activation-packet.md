# Production activation packet: staging, payment test mode, live

Prepared October 7, 2026 by lane `harness/production-preparation-1`, from base `main`
`3716324a51b0f7458ea68bdbab7b0696955923a7`.

> **This lane ran none of it.** No command in this packet has been executed against a host,
> provider account, DNS zone, real database or real file. This lane used no credentials,
> called no providers, deployed nothing, sent no messages and changed no protections or
> budgets. Every step below needs Sean's separate, explicit authorization, naming the stage
> and the exact 40-character commit SHA it applies to. A template, a passing preflight or a
> configured flag isn't operational proof and doesn't authorize anything.

The packet implements §3 A4 (live-payment preparation) of `docs/handoff/2026-10-07/CLAUDE-PLAN.md`
(cited below as CLAUDE-PLAN) and the
preparation half of F1 (rollout and rollback). The cutover runbook
(`docs/migration/cutover_runbook.md`) still governs launch and rollback. Where the two
differ, the runbook wins, and the difference should be reported.

## 1. Gates that are code, not configuration

Configuration can't close these. Each one needs reviewed source on `main` first. Until it
lands, the stage that depends on it stays blocked whatever the environment contains.

| Gate | Blocks | Source of truth |
| --- | --- | --- |
| A1b: frame admission for intent/basis/authority writes and `initiate()` re-proof | S2b, S3 | CLAUDE-PLAN §3 A1b (release blocker) |
| A2: Paid252 consumer and whole-order delivery | S3 | `docs/handoff/2026-10-07/owners/paid-delivery-native-operations.md` |
| A3: Tax255 (`automatic_tax`, SourceV2, V2 consumer) | S3 unless every order qualifies for declared exemption | CLAUDE-PLAN §3 A3 |
| No live webhook receiver. `VerifyStripeWebhook` refuses production environments and any mode other than test; production checkout reconciles only by authoritative retrieval | S3 webhook drills | `app/Domain/Commerce/Payments/VerifyStripeWebhook.php`; `vasey:stripe-preflight` check `production_webhook_receiver` |
| Production checkout isn't registered: `ProductionCheckoutServiceProvider` isn't in `bootstrap/providers.php` and `routes/production-checkout.php` isn't mounted, so the env flags have no HTTP effect yet | S2b, S3 | root composition step |
| `ExecutionContextV1` refuses `funds_mode=test` outside `local`/`testing`, and the test checkout/processing policies require `local`/`testing` | Hosted test-mode payments on a staging host (S2) | `ExecutionContextV1::make`, `CheckoutPolicy`, `PaymentProcessingPolicy` |
| There is no operator command for production reconciliation (only `HostedCheckout::reconcile` behind HTTP) | S2b, S3 recovery drills | `app/Domain/Commerce/ProductionCheckout/HostedCheckout.php` |
| A5: refunds and disputes (T21) | S3 refunds | CLAUDE-PLAN §3 A5 |
| Production entitlements, delivery, identity, contracts and refunds are still test-only | S3 | `vasey:commerce-readiness` `implementation` checks (all `blocked`) |
| Memberships C2–C5 and Billing259 | Membership sales | CLAUDE-PLAN §3 Phase C |
| F3: one manual Foundation CI on the final integrated `expected_sha` | S3 | AGENTS.md CI cost policy |

## 2. Inputs only Sean can supply

These come from the `docs/handoff/2026-10-07/CLAUDE-PLAN.md` §4 list. Placeholders in this packet look like `<...>`; none
of them has a value here.

| Input | Unblocks | Where it lands |
| --- | --- | --- |
| Stripe account (own account, not Connect) and the mode for each stage | S2, S3 | `PRODUCTION_CHECKOUT_STRIPE_ACCOUNT_ID`, `PRODUCTION_CHECKOUT_FUNDS_MODE`, `STRIPE_ACCOUNT_ID` (test receiver); machine policy `provider_account` |
| API keys, issued by Stripe to Sean | S2, S3 | Host secret store only: `PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY` (`sk_test_`/`sk_live_`), `STRIPE_TEST_SECRET_KEY` |
| Webhook endpoint and its signing secret (test endpoint now; live only once a live receiver exists) | S2 receiver drills | `STRIPE_WEBHOOK_SECRET` (test-mode `whsec_`) |
| Merchant legal identity, currency, tax registration and tax strategy | S3 | Approved `MachinePolicyV1` choices (`seller_identity`, `currency`, `tax_calculation`); Tax255 |
| Assent text, license terms, free-grant terms text, refund and dispute policy, exclusive reservation TTL and late-payment policy (U-05, U-08) | S3 | Machine policy `assent`, `license_terms`, `refunds_and_disputes`, `reservation_and_exclusives`; Free256 |
| Approved plan prices, currency, allowances, rollover, cancellation and dunning | Membership sales | `config/production-memberships.php` `approved_policy_hash` (a code change) |
| Legal pages (privacy, terms) | S3 | `docs/legal/` (open adoption gap) |
| Production host, region, topology, backups, incident owner (U-02) and storage choice (U-03) | S1 | `ops/private-server/env.example` inputs; this packet's S1 commands |
| Storefront origin and DNS plan | S1 staging subdomain, S3 cutover | `APP_URL`, `PRODUCTION_CHECKOUT_RETURN_ORIGIN`; cutover runbook DNS section |
| Sender domain, DNS (SPF/DKIM/DMARC) and SMTP provider | Customer identity and notices | `config/production-identity-smtp.php` `settings` (a code change), `MAIL_*` |
| BeatStars exports | Migration (D2) | Content onboarding packet |
| Review lifetime (30–3600 s) | S2b, S3 | `PRODUCTION_CHECKOUT_REVIEW_LIFETIME_SECONDS` |

## 3. Stage S0: local preparation (read-only, runnable now)

**Purpose:** confirm the source, pins and templates before anyone touches a host.

```sh
git rev-parse HEAD                                   # record the exact SHA
php artisan vasey:stripe-preflight --json            # no provider I/O
php artisan vasey:commerce-readiness --json
php scripts/ops/private-server-preflight.php --template
python3 scripts/ops/test-private-server-preflight.py
mkdir -m 700 <EMPTY_DIR_OUTSIDE_REPO>
php scripts/ops/backup-restore-proof.php --synthetic-proof --workdir <EMPTY_DIR_OUTSIDE_REPO>
php vendor/bin/phpunit tests/Feature/StripeCapabilityPreflightTest.php
php vendor/bin/phpunit tests/Feature/PaymentWebhookPreparationChecksTest.php
php vendor/bin/phpunit tests/Unit/BackupRestoreProofTest.php tests/Unit/ProductionEnvironmentTemplateTest.php
php vendor/bin/phpunit tests/Feature/ProductionCommerceReadinessTest.php
```

**Expected:**
- `stripe-preflight` reports `pins_valid: true` (SDK 21.3.2, API `2026-08-26.dahlia`,
  manifest hash). The configuration checks are blocked while checkout configuration is
  absent, and the command exits 1. That is the correct state.
- `commerce-readiness` reports `production_commerce_ready: false`.
- The template check reports `TEMPLATE_VALID`.
- The proof reports `RESTORE_VERIFIED`.
- Every listed test passes.

**Evidence to retain:** the SHA, each command's exit code and redacted JSON, and the test
counts, under `docs/verification/<stage>-<date>/`.

**Rollback:** none needed. Nothing is changed.

## 4. Stage S1: staging host

**Requires:** a U-02/U-03 decision, Sean's authorization naming the host and the SHA,
and a staging hostname that doesn't touch the apex or `www` records.

**Inputs:** the host and TLS origin; MySQL 8.4 endpoint and a least-privilege runtime
account; a separate backup-only account; the private storage root; the ffmpeg, ffprobe,
prlimit and clamscan paths; the approved tag WAV and its hash; an `APP_KEY` generated on the
host; mail stays `log`.

```sh
# On the staging host, as the unprivileged application user. Every release is a fresh,
# immutable checkout in its own directory; nothing is reused, and persistent data never
# lives inside it. <RELEASES_DIR> holds one directory per authorized SHA; <PERSISTENT_ROOT>
# holds storage/app/private (masters, contracts, originals) and survives releases.
git clone --no-checkout <REPO_URL> <RELEASES_DIR>/<SHA> && cd <RELEASES_DIR>/<SHA>
git checkout --detach <SHA> && test "$(git rev-parse HEAD)" = "<SHA>"
# Authorization is bound to the commit, so the fresh checkout must be exactly its tree
# before anything is generated into it: no tracked edits, no untracked files. (Ignored
# paths are checked here too, which only works because the directory is new; vendor,
# public/build and storage/app/private do not exist yet.)
test -z "$(git status --porcelain --untracked-files=all --ignored)" || { echo 'dirty checkout'; exit 1; }
test "$(git rev-parse HEAD^{tree})" = "$(git write-tree)" || { echo 'checkout differs from <SHA>'; exit 1; }
composer install --no-dev --no-interaction --classmap-authoritative   # composer.lock is frozen
npm ci && npm run build
# Persistent private storage is attached after the source checks, never copied into the
# release. The disk root is storage_path('app/private') (config/filesystems.php, no
# environment override). It is attached as a bind mount, not a symlink: the runtime
# preflight's canonical-path check rejects a symlink at any component. The persistent
# directory is owned by the application user at mode 0700, which the preflight also checks.
# As root, once per release (and persisted in /etc/fstab so it survives a reboot):
#   mount --bind <PERSISTENT_ROOT>/private <RELEASES_DIR>/<SHA>/storage/app/private
#   echo '<PERSISTENT_ROOT>/private <RELEASES_DIR>/<SHA>/storage/app/private none bind 0 0' >> /etc/fstab
test "$(stat -c %d:%i storage/app/private)" = "$(stat -c %d:%i <PERSISTENT_ROOT>/private)" \
  && [ ! -L storage/app/private ] || { echo 'private storage not attached'; exit 1; }

# Configuration lives outside the repository, mode 0600, assembled from the two templates:
#   ops/private-server/env.example       (host baseline; fill host inputs only)
#   ops/production/env.production.example (payment families; leave every flag false, every secret blank)
php scripts/ops/private-server-preflight.php --env-file <RUNTIME_ENV> --runtime
# The preflight only parses <RUNTIME_ENV>; nothing has loaded it yet. Laravel reads .env from
# the release root, which is gitignored and outside the tree checks above, so install the
# validated file there (0600, application user) before any Artisan, web or worker process runs.
# The web and worker units run from <RELEASES_DIR>/<SHA> and therefore read the same file;
# a unit may instead carry EnvironmentFile=<RUNTIME_ENV>, but never both.
install -m 0600 -o <APP_USER> -g <APP_USER> <RUNTIME_ENV> .env && cmp -s <RUNTIME_ENV> .env || { echo '.env not installed'; exit 1; }

# Back up first (docs/ops/backup-restore-proof.md, MySQL procedure), then migrate.
php artisan migrate:status
php artisan migrate --pretend      # review the SQL
php artisan migrate --force
php artisan config:cache
php artisan vasey:doctor
php artisan vasey:commerce-readiness --json   # effective cached configuration
php artisan vasey:stripe-preflight --json     # still no provider I/O

# Supervised workers and the scheduler (host supervisor and cron, as U-02 decides):
php artisan queue:work database --queue=media --timeout=900 --tries=3 --sleep=1    # docs/media-processing.md
php artisan queue:work database --queue=contracts --tries=1 --timeout=90            # docs/test-contract-issuance.md
php artisan queue:work database --queue=payments,inquiry-alerts,default --tries=1   # per-queue flags: confirm in each feature doc
* * * * * php <APP_ROOT>/artisan schedule:run
```

**Expected:**
- The env-file check reports `FILE_CHECKS_PASSED` and `deployment_ready: false`.
- Migrations apply cleanly.
- `commerce-readiness` still reports `production_commerce_ready: false`, with only the
  host configuration checks moving to `configured`.
- `stripe-preflight` keeps its checkout configuration checks blocked (none is configured
  at S1).
- A backup and a restore into an isolated schema, using the MySQL procedure, verify by
  `diff` and `CHECKSUM TABLE`.

**Evidence to retain:**
- SHA and build hashes.
- Redacted preflight, doctor and readiness JSON.
- `migrate:status` before and after.
- Backup manifest hashes and the restore verification output.
- Worker and scheduler observations, including a missed-dispatch recovery.
- Confirmation that the private root isn't served.

**Rollback:**
1. `php artisan down`.
2. Restore the pre-migration backup into the **staging** schema only.
3. Check out the previous SHA, then `composer install` and `config:cache`.
4. Remove the staging DNS record if Sean asks.
5. Rotate any staging secret that may have been exposed.

Production DNS and BeatStars aren't touched at S1.

## 5. Stage S2: payment test mode

**Requires:** S1 accepted; Sean's authorization for test-mode provider I/O on a named
Stripe test account; a test webhook endpoint that Sean creates; no real money.

**Finding: the current source refuses S2 on a hosted staging environment.** Test checkout
(`CheckoutPolicy`), test processing (`PaymentProcessingPolicy`) and production checkout test
funds (`ExecutionContextV1`) all require `APP_ENV` `local` or `testing`. The receiver alone
also accepts `staging`, and it only stores receipts. So S2 has two paths. Sean decides
which one, and this lane has not changed any runtime code to support either:

- **S2a: isolated local rehearsal.** A dedicated machine with `APP_ENV=local`, no customer
  data and a test key. This runs today's test pipeline against Stripe test mode.
- **S2b: hosted test mode.** This needs a reviewed code change that admits a named staging
  environment, along with the gates in §1 (A1b, provider/route registration, an operator
  reconciliation command).

**Inputs (both paths):** `STRIPE_MODE=test`, `STRIPE_ACCOUNT_ID`, `STRIPE_WEBHOOK_SECRET`
(test endpoint) and `STRIPE_TEST_SECRET_KEY` from the secret store. For production checkout
test funds: `PRODUCTION_CHECKOUT_FUNDS_MODE=test`, `PRODUCTION_CHECKOUT_STRIPE_ACCOUNT_ID`,
`PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY` (`sk_test_`), `PRODUCTION_CHECKOUT_RETURN_ORIGIN`
and `PRODUCTION_CHECKOUT_REVIEW_LIFETIME_SECONDS`, plus an approved machine policy whose
`provider_account` matches them.

```sh
# 1. Shape only (no provider I/O): expect configuration_shape_valid=true, secret_key_reference=present.
#    Test funds pass funds_mode_environment only when APP_ENV is local or testing (the ExecutionContextV1 rule).
php artisan vasey:stripe-preflight --json

# 2. The first provider contact: read-only GET /v1/account and capabilities.
#    Needs PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED=true and config:cache. Both flags are mandatory.
#    That flag is the checkout's own provider I/O switch (review R-3): once checkout is composed and registered,
#    enabling it for the probe also enables checkout provider I/O on the host. Turn it off again afterwards.
php artisan vasey:stripe-preflight --json --probe --i-understand-this-calls-stripe

# 3. Test-mode receiver drills (S2a), with STRIPE_WEBHOOK_ENABLED=true and test flags on:
php artisan vasey:stripe-inbox
php artisan vasey:process-stripe-receipts
php artisan vasey:reconcile-test-payments
```

**Expected:**
- The probe reports `status: pass`, `evidence_origin: provider_observed` and
  `account_matches_configuration: true`. `activation_authorized` and
  `live_payments_authorized` stay `false`.
- The drills match `tests/Feature/PaymentWebhookPreparationChecksTest.php`:
  - An invalid signature is answered 400, with no receipt.
  - A replayed event yields one receipt and one confirmation.
  - Out-of-order and stale-expired events converge on the provider's authoritative state.
  - A timeout stays `retry`, and a pending payment stays `pending` until it's authoritatively
    paid.
  - No grant comes from the signature alone.

**Drills to run (each one a Sean-authorized test-mode action):**
- a successful card payment
- a delayed (async) payment that succeeds, and one that fails
- an expired session
- a duplicated delivery (resend from the dashboard)
- an uncertain outcome (network interrupted between create and retrieve), followed by
  reconciliation
- an endpoint secret rotation, with both secrets valid during overlap

Test-mode refunds use only the existing test exception operations. A5 doesn't exist yet.

**Evidence to retain:**
- Probe JSON.
- Inbox counts, and receipt, work, observation and verified-payment counts for each drill.
- Reconciliation output.
- The Stripe test event IDs (they aren't secret).
- Before/after hashes of retained receipts, which must not change.

**Rollback:**
1. Set every enabled flag back to `false` and run `config:cache`.
2. Sean disables the test webhook endpoint and rolls the test key in the dashboard.
3. Retain all receipts and observations; they're immutable evidence and are never deleted.

Test mode has no financial effect.

## 6. Stage S3: live

**Requires:** every gate in §1 closed on `main`; independent reviews of the payment,
licensing, authorization and migration changes; a passing F3 Foundation CI on the exact
release SHA; Sean's written live authorization; legal pages; merchant, tax and terms facts
in an approved machine policy; a reviewed live webhook receiver (or an accepted
retrieval-only design); the cutover runbook's launch packet. None of this exists yet.

```sh
# Same deployment steps as S1 on the production host at the authorized SHA, then:
php artisan vasey:stripe-preflight --json                                          # shape: funds_mode=live, sk_live_ present; probe not_requested
# The live probe is refused (provider_io_disabled) while every flag is still false, so the
# first authorized flag change comes before it. Enable one flag at a time, each followed by
# config:cache and an observation window whose length Sean sets (none is invented here):
#   PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED (now, for the probe) → PRODUCTION_CHECKOUT_RECONCILIATION_ENABLED
#   → PRODUCTION_CHECKOUT_COMMITTED_READ_RECEIPTS_ENABLED → PRODUCTION_CHECKOUT_HTTP_ENABLED
#   → PRODUCTION_CHECKOUT_ENABLED (new orders) last.
# Set PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED=true in <RUNTIME_ENV> (Sean's live authorization names it), reinstall .env as in S1, then:
php artisan config:cache
php artisan vasey:stripe-preflight --json --probe --i-understand-this-calls-stripe # needs charges_enabled and card_payments=active
php artisan vasey:commerce-readiness --json
# Remaining flags follow in the order above, each with its own config:cache and window.
```

**Expected:**
- The live probe passes.
- Readiness still lists the acceptance evidence as `unverified` until each item has been
  observed.
- The first real-money canary order is placed only under Sean's explicit authorization.
  It verifies durably, through authoritative retrieval, before any grant.

**Evidence to retain:**
- The exact SHA and the F3 run.
- Probe and readiness JSON.
- Each flag change with its UTC time and operator.
- Canary order IDs and reconciliation.
- Backup and restore proof on the production host.
- Monitoring dashboards.
- The cutover runbook's launch packet.

**Rollback** (cutover runbook §10 governs):
1. Set `PRODUCTION_CHECKOUT_ENABLED=false` first, so no new orders are accepted.
2. Keep reconciliation, receipts and legitimate downloads running for orders already paid.
3. Never restore a database over paid transactions or delete target orders.
4. Reverse DNS only as the runbook describes.
5. Rotate live keys if they may have been exposed.
6. Record the incident, data deltas and the financial and rights disposition.

## 7. Evidence and redaction rules for every stage

- Record the command, exit code, UTC time, the exact SHA and the operator.
- Never record key values, webhook secrets, `.env` contents, customer data or raw provider
  bodies. Reports from `vasey:stripe-preflight`, `vasey:commerce-readiness` and the
  preflight scripts are designed to be safe to retain.
- Append evidence; never overwrite it. Keep failed, interrupted and refused runs, and label
  them.
- Label synthetic evidence as synthetic. Never relabel a test-mode result as live.

## 8. Still unknown after this packet

- The host, region, storage and backup custody (U-02/U-03), the observed restore time, and
  off-host retention.
- Whether S2 runs as an isolated local rehearsal (S2a) or through a reviewed staging
  admission change (S2b).
- How live webhooks are received: a new receiver, or retrieval-only.
- Every merchant, tax, legal, price and term fact listed in §2.
- Real account capabilities. The probe has only run against synthetic fixtures.
