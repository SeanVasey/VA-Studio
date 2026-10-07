# Production preparation 1: verification receipt (2026-10-07)

Lane `harness/production-preparation-1`, a worktree of `main` at
`3716324a51b0f7458ea68bdbab7b0696955923a7` (PR #42 merge). This is a development checkpoint,
**not** production acceptance. It is not pushed and it has no independent review yet.

This lane used no real credentials, made no network or provider calls, deployed nothing and
changed no DNS. Every key, account, event and file in it is synthetic and labelled that way.
It used no native MySQL: every PHP test ran on the SQLite default from `phpunit.xml`.

## Commits

| Commit | Deliverable |
| --- | --- |
| `7ffe5566` | 1: Stripe configuration, pin and capability preflight (`vasey:stripe-preflight`) |
| `e1ca33d9` | 2: webhook signature, replay, ordering and unknown-outcome checks |
| `0dff5565` | 3: synthetic backup/restore proof and MySQL procedure (documented, not run) |
| `eebd453f` | 4: annotated production environment template and the `.env.example` documentation block |
| `01a2590d` | 5: staged activation packet (prepared, not executed) |
| this commit | 6: this receipt |

The tested source is `01a2590d29ea27ebe5bb10df78c1ef5bb5f0ece6`. This README is the only
file added after it.

## Commands and results

The worktree's `vendor/bin` is a symlink into the main checkout, so PHPUnit ran with the
worktree's own Composer autoload:
`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <test>`.
In an ordinary checkout, `php vendor/bin/phpunit <test>` is equivalent. PHP 8.4.26 and
PHPUnit 12.5.34 were used, with no `public/build` present.

| Selection | Tests | Assertions | Failures | Errors | Skips |
| --- | ---: | ---: | ---: | ---: | ---: |
| `tests/Feature/StripeCapabilityPreflightTest.php` (new) | 31 | 304 | 0 | 0 | 0 |
| `tests/Feature/PaymentWebhookPreparationChecksTest.php` (new) | 13 | 113 | 0 | 0 | 0 |
| `tests/Unit/BackupRestoreProofTest.php` (new) | 10 | 252 | 0 | 0 | 0 |
| `tests/Unit/ProductionEnvironmentTemplateTest.php` (new) | 6 | 1629 | 0 | 0 | 0 |
| `tests/Feature/ProductionCommerceReadinessTest.php` (affected: `httpsOrigin` is now a public static) | 66 | 334 | 0 | 0 | 0 |
| `tests/Feature/StripeWebhookTest.php` (receiver regression) | 53 | 230 | 0 | 0 | 0 |
| **Total** | **179** | **2862** | **0** | **0** | **0** |

Other checks:

| Command | Result |
| --- | --- |
| `python3 scripts/ops/test-private-server-preflight.py` | 16 OK (unchanged harness) |
| `python3 scripts/dev/test-bootstrap-macos.py` (reads `.env.example`) | 13 OK |
| `php vendor/laravel/pint/builds/pint --test <9 changed PHP files>` | passed |
| `git diff --check 3716324a..HEAD` | clean |
| `php artisan vasey:stripe-preflight --json` (local `.env`, no checkout configuration) | exit 1; `configuration_shape_valid: false`, `pins_valid: true`; counts blocked 5 / absent 2 / pass 5 / not_requested 1; `provider_io_performed: false` |
| `php scripts/ops/backup-restore-proof.php --synthetic-proof --workdir <scratch 0700 dir>` | `RESTORE_VERIFIED`, 15/15 checks; 28,672 database bytes; 7 private files, 398,644 bytes |

## Mutation checks

Each source change below was made temporarily and then restored. `git status` was clean
afterwards.

- `VerifyStripeWebhook` signature tolerance changed from 300 to 0: 2 of the 3 timestamp
  cases in `PaymentWebhookPreparationChecksTest` failed.
- A new `env('VASEY_PREP_PROBE_KEY')` added in `config/payments.php`: 2 template tests failed.
- A value written into `PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY` in the template: 1 failed.
- `PRODUCTION_CHECKOUT_ENABLED=true` in the template: 1 failed.

## Runs that failed during development

These were authoring failures. They were fixed before each commit and are recorded here, not
hidden:

- Webhook suite, first run: 7 failures and 1 error. Fixture setup legitimately queues
  `ProcessMedia`, so the assertion was narrowed to `ProcessStripeReceiptJob`. The error came
  from a production environment left set during migration teardown, which is now restored in
  `finally`.
- Backup proof, first run: `BLOCKED`, because an uppercase sample filename was refused by the
  script's own path rule. The sample file was renamed.
- Proof test: an expected check count was off by one (16 instead of 15).
- Template test: the header legend matched the annotation pattern, and the secret-name pattern
  flagged `AUTH_PASSWORD_BROKER`. Both patterns were tightened.
- The first PHPUnit invocation used the symlinked main `vendor/bin` and stopped with a
  harness error (`Cannot redeclare ComposerAutoloaderInit…`). That was not a product result.

## Source hashes (SHA-256 at `01a2590d`)

```
f0d8f63ddd1b415e2427eda99a590c20152eb507d1e7e22d250a744b3e0bcb18  app/Console/Commands/StripePreflight.php
56fc99eed248456da4cfc5871846648f0b25fc903ca6d10a49487d628ec5d673  app/Domain/Commerce/Readiness/StripeCapabilityPreflight.php
ed7dcd5670f89cf7cedcc2910948c87c5e8a8388c62eff71d6bf09bbcf905abc  app/Domain/Commerce/Readiness/StripeCapabilityProbe.php
a899f76eafc497dce2207735be13be5b579e55622c8b2501f876a82ce036e414  app/Domain/Commerce/Readiness/ProductionCommerceReadiness.php
db5b6b58d2b48951b5a58ee099e823fa75fd75a01e772f9bf88751ba7628fa53  scripts/ops/backup-restore-proof.php
c6aa34b616aa10377317371594beea2adf58b2d607481143b899b6de58d0ac2b  ops/production/env.production.example
64809e8dd78662452f741a8fea249a6d04879da2ca99e141a5c0db5eedc9afbe  .env.example
a7c2b840b7ce06823e347d74b750319ffb3b600ab943b2042fe3e12c1d765fd1  tests/Feature/StripeCapabilityPreflightTest.php
6652642d3c72cba79512f012eba5a0bede2c0772260a1a439a94d0d438d7db2e  tests/Feature/PaymentWebhookPreparationChecksTest.php
1c1c8751861f14f762ac8b64422f7bdde297ba07a28207b3cff8c8cdbcca07c9  tests/Unit/BackupRestoreProofTest.php
a6ce6bfdf6dd3b324c8a76c927101c4015bf8d1a2c3bbb413593ae92a69ddf8b  tests/Unit/ProductionEnvironmentTemplateTest.php
64a9c5effe57196adeb59c0077e2311578d7915eefc5fa2f5109f50fa2ea189a  docs/ops/backup-restore-proof.md
d5982ac63de9318464eecd0f2f60901fa25dd88f12439f88dd31c0dde19209eb  docs/ops/production-activation-packet.md
```

## Findings

These are written up here and in the packet. This lane changed no runtime code for any of
them.

1. **There is no live webhook receiver.** `VerifyStripeWebhook` accepts only
   `payments.stripe.mode=test` in `local`, `testing` or `staging`, and only events with
   `livemode=false`. Production checkout has no webhook secret variable and reconciles only by
   authoritative retrieval. The preflight reports this as boundary check
   `production_webhook_receiver: blocked`. The test receiver passed every replay, ordering,
   signature and unknown-outcome check, so no fix was needed there.
2. **Hosted test mode is refused outside `local`/`testing`.** `CheckoutPolicy`,
   `PaymentProcessingPolicy` and `ExecutionContextV1` (test funds) all refuse other
   environments. A test-mode payment rehearsal on a staging host therefore needs either an
   isolated `APP_ENV=local` machine (packet S2a) or a reviewed code change (S2b). Sean
   decides which.
3. **Production checkout isn't registered.** `ProductionCheckoutServiceProvider` isn't in
   `bootstrap/providers.php` and `routes/production-checkout.php` isn't mounted, so the
   `PRODUCTION_CHECKOUT_*` flags have no HTTP effect yet. This is consistent with the PR #41
   condition that nothing registers routes or the provider until A1b closes.
4. **There is no operator command for production reconciliation.** Only
   `HostedCheckout::reconcile` exists, behind HTTP.
5. **24 family variables were missing from `.env.example`**, including all eleven
   `PRODUCTION_CHECKOUT_*` keys. They are now documented as commented, unset lines, so no
   active assignment changed. A side effect: the client-bundle secret scan
   (`scripts/ci/scan-client-bundle.py`) now also looks for
   `PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY`.
6. **90 framework and other non-family env keys remain undocumented in `.env.example`.**
   They are recorded as a shrink-only set in `ProductionEnvironmentTemplateTest`; any new
   undocumented key fails the test. They are all covered by one of the two ops templates.
7. **Several families read no environment at all.** Production identity, identity SMTP,
   production memberships, member grants and suppression are code-literal and default off.
   Billing259 has no source. Activating any of them is a reviewed code change, not a
   variable.

## Still unknown or untested

- Any real Stripe account's capabilities. The probe has only run against a synthetic
  `ClientInterface` fixture, and the real `CurlClient` path is refused in `testing`.
- The MySQL backup procedure. It is documented only; it has not run, and no native MySQL was
  used in this lane.
- Host, storage, worker, scheduler, TLS, mail and DNS behaviour, all of which wait on U-02 and
  U-03.
- Hosted CI. No Foundation CI or PR preflight ran (CI cost policy). No independent review has
  assessed this candidate. The probe and the webhook-chain conclusions touch payment code, so
  they need an independent review before anyone relies on them.
- Every merchant, tax, legal, price and terms fact. None was invented here; placeholders look
  like `<...>`, and fixtures use `SYNTHETIC`, `.invalid` and the `XXX` currency code.

## Next dependency

An independent review of `7ffe5566..01a2590d`. After that, root composition decides whether to
ship it with A4 or after A3 (Tax255), and Sean decides between S2a and S2b.
