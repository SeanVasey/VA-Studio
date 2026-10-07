# Independent review: harness/production-preparation-1

- **Reviewed source:** `01a2590d29ea27ebe5bb10df78c1ef5bb5f0ece6` (tree `b7b6240f1b3750926a38916602b1630e41daee11`)
- **Branch head:** `a2b56002198247dfb4f86d06193c36da25a39212`. It differs from `01a2590d` only by adding `docs/verification/production-preparation-1-20261007/README.md`, checked with `git diff --quiet 01a2590d a2b56002 -- . ':!docs/verification/production-preparation-1-20261007'`.
- **Base:** `main` `3716324a51b0f7458ea68bdbab7b0696955923a7` (confirmed as an ancestor). Commits reviewed: `7ffe5566`, `e1ca33d9`, `0dff5565`, `eebd453f`, `01a2590d`, plus the receipt `a2b56002`.
- **Reviewer:** an independent Claude Code review subagent, 2026-10-07. Worktree `/home/user/VA-Studio-review-prep`, made by `scripts/dev/mkworktree.sh` at `a2b56002`.
- **Environment:** PHP 8.4.26, PHPUnit 12.5.34, SQLite only. No `public/build`, no network, no real key. The local `.env` holds no Stripe key value: every `STRIPE_*` secret is empty and `PRODUCTION_CHECKOUT_*` is unset.

## Decision: APPROVE WITH CONDITIONS, for exact `01a2590d` (and the docs-only `a2b56002`)

The six deliverables do what they claim, and I reproduced each claim adversarially. I found no secret leak, no way to reach the probe transport without passing the gates, and no mocked-away receiver. The pin check is not vacuous, and the backup proof is real. All findings are Low or Info, so none blocks a development merge.

**Conditions:**

1. **Scope of this approval.** It covers merging the preparation tooling, tests and documents only. It does **not** approve or authorize any of the following:
   - live activation or setting a real key;
   - running `--probe` against Stripe;
   - enabling any `PRODUCTION_CHECKOUT_*` or `STRIPE_*` flag;
   - deployment, DNS, migration of a real database, or any S1/S2/S3 step in the activation packet.

   Each of those still needs Sean's separate, explicit authorization naming the stage and SHA.
2. **Fix R-1 and R-2 before the first real probe.** Before anyone first runs `vasey:stripe-preflight --probe --i-understand-this-calls-stripe` against a real Stripe account (packet S2 step 2), fix R-1 and R-2 below, or record an explicit acceptance of them. Neither is needed for merge.
3. **Re-review on source change.** Re-review if any of these files change after `01a2590d`: the preflight, the probe, the backup script or the env-template test.

## Non-scope

- **MySQL behaviour.** No native MySQL was run (SQLite only). The MySQL backup/restore procedure is documented and was not executed, by the lane or by me.
- **Live provider behaviour.** The probe has run only against synthetic fixtures.
- **Foundation CI.** No full suite or Foundation CI was run. Only the affected files were run, per the AGENTS.md CI cost policy.
- **Release gates.** A1b, A2, A3, A5, the live webhook receiver, production checkout composition and the reconciliation command are named gaps, not reviewed work.

## Findings

| ID | Severity | Finding | Evidence | Recommendation |
| --- | --- | --- | --- | --- |
| R-1 | Low | The probe's own guard doesn't refuse the real transport in `testing`. `StripeCapabilityProbe::observe()` requires `fixtureTransport === null \|\| app()->environment('testing')`, so with a null fixture it admits `testing`. The refusal "real transport in testing" lives only in `StripeCapabilityPreflight::collect()`. Today `collect()` is the only caller, so it isn't reachable. | `StripeCapabilityProbe.php:42`, `StripeCapabilityPreflight.php:91`, `StripeCapabilityProbe.php:50` (`new CurlClient`) | Add `&& ($this->fixtureTransport !== null \|\| ! app()->environment('testing'))` to the guard at line 42 (defence in depth). |
| R-2 | Low | Funds mode isn't bound to `APP_ENV`. The preflight reports `configuration_shape_valid: true`, and allows the probe, for `funds_mode=test` in production or `funds_mode=live` in `local`. `ExecutionContextV1::make` refuses test funds outside `local`/`testing`, so the preflight can pass a configuration that checkout would refuse. The probe is read-only GETs, so there's no money effect. | `StripeCapabilityPreflight.php:50,86-93` vs `ExecutionContextV1.php:59` | Add a `funds_mode_environment` check: test only in `local`/`testing`, live only in `production`. At minimum, report it. |
| R-3 | Info | The probe flag is the production I/O flag. The probe needs `PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED=true`, and packet S2 adds `config:cache`, which enables provider I/O for every process on the host. There's no effect today because the provider and routes aren't registered (A-3 below). After composition (S2b/S3), the probe step also turns on checkout provider I/O. | `production-activation-packet.md` §5 step 2; `config/production_checkout.php:14` | State this in the packet, or give the probe its own one-shot flag. |
| R-4 | Low | The MySQL verify `diff` (documented only) will likely report header noise. Step 4 diffs a re-dump of the restore schema against the original dump. `sed` rewrites only backticked names, so the non-backticked header (`-- Host: …  Database: <name>`, plus server version and host) will probably differ and give a false failure. | `docs/ops/backup-restore-proof.md` (step 4) | Use `--skip-comments` on both dumps, or filter comment lines. Rehearse before relying on it. |
| R-5 | Info | The signature-only test doesn't assert entitlements directly. `test_valid_signature_records_evidence_only…` asserts `verified_payments` = 0 and `payment_observations` = 0 after a valid signature, which precedes any grant. It doesn't assert entitlement or grant tables. | `PaymentWebhookPreparationChecksTest.php` | Optionally add an explicit entitlement count of 0. |
| R-6 | Info | Documentation nits. The lane README says "not pushed … no independent review yet", which is stale now. The packet cites "CLAUDE-PLAN §3/§4" without a path (it's `docs/handoff/2026-10-07/CLAUDE-PLAN.md`). The lane didn't update `docs/live-payment-and-production-preparation-queue.md` or the plan's state ledger (AGENTS.md work-protocol step 5). | files named | Update these at the next checkpoint. |
| R-7 | Info | `provider_io_performed: true` is reported for any `failed` probe, including a precondition failure before transport. This is conservative over-reporting, which is acceptable. | `StripeCapabilityPreflight.php` return block | None. |

## Assessment by item

### 1. Secret handling: PASS

- **Code read.** `StripePreflight.php` emits only `$report`. The report holds static messages, statuses, booleans, `funds_mode` (only when it is exactly `test` or `live`), flags and a redacted observation. The secret key and webhook secret are used only in `is_string`, `preg_match` and `===` tests (`StripeCapabilityPreflight.php:59-65`). The key is passed only to `observe()`, where it is `#[SensitiveParameter]` (`StripeCapabilityProbe.php:36`).
- **Exception paths.** Every exception in `observe()` is replaced by a new `RuntimeException` with a static message and no `previous` (`:76`), and `collect()` discards it (`provider_request_failed`). There is no `Log::`, `report()`, `dump`, `print_r` or `error_log` in the new code. The Stripe SDK's own logger calls (`StripeObject.php:187,192`, `ApiRequestor.php:83`) never include the key. `zend.exception_ignore_args = On` locally.
- **Adversarial test.** A throwaway test (`review-evidence/ReviewerProbeGatingAdversarialTest.php.txt`, 23 tests, 143 assertions, all passing) placed a marker in the key and the webhook secret, and put secret-shaped values into `funds_mode`, `account_id` and `return_origin` (as URL userinfo). It used malformed keys: newline, punctuation, array, restricted `rk_`, and foreign mode. It ran table, `-v` and `-vvv` output, plus the provider-failure path, checking `getMessage`, `getTraceAsString`, `print_r(getTrace())`, `(string) $e` and `getPrevious() === null`. The marker never appeared.
- **Baseline `--json` on the local `.env`.** Exit 1, `configuration_shape_valid: false`, `pins_valid: true`, counts blocked 5 / absent 2 / pass 5 / not_requested 1, `provider_io_performed: false`. The secrets show as presence only (`absent`/`present`/`blocked`). Saved in `review-evidence/preflight-baseline.json`.

### 2. Probe gating: PASS (with R-1 and R-2)

These conditions are checked, in order, before `observe()` is called. A `CurlClient` is created only at `StripeCapabilityProbe.php:50`, after all of them hold:

1. `--probe` passed. Otherwise the status is `not_requested`.
2. `--i-understand-this-calls-stripe` passed (`Preflight.php:87`).
3. `config('production_checkout.provider_io_enabled') === true`, strictly (`:72`, `:88`).
4. The configuration shape is valid: mode `test|live`, `acct_` id, bounded HTTPS origin, integer lifetime 30–3600, and a present key that is mode-shaped (`:89`).
5. The key is present and shaped `sk_<funds_mode>_[A-Za-z0-9]{1,240}` (`:90`).
6. Not `testing`, or a fixture is injected (`:91`).
7. No open DB transaction on any connection (`:92`).

`observe()` then re-checks the following (`Probe.php:39-45`):

- mode and key shape;
- account shape;
- a fixture only in `testing`;
- `Stripe::$accountId === null`, `verifySslCerts === true`, `logger === null`;
- transaction level 0 on every connection.

The global `ApiRequestor` client is restored in `finally` (`:72`).

The adversarial test confirmed refusal with zero fixture calls and an unchanged `ApiRequestor::httpClient()` for each of these:

- `provider_io_enabled` set to `'true'`, `'1'`, `1`, `'yes'`, `[true]` or `stdClass` → `provider_io_disabled`.
- Flag set but no key (null, `''`, array) → `secret_key_absent_or_malformed`.
- `sk_live_` in test mode, `sk_test_` in live mode, `rk_test_`, a key with a newline, a key with `!` → `configuration_shape_invalid`.
- `testing` without a fake → `fixture_transport_required_in_testing`.
- Inside `DB::transaction` through Artisan → `open_database_transaction`.
- `--probe` without confirmation → `confirmation_flag_missing`.
- Confirmation without `--probe` → `not_requested`.

### 3. Pin equality: PASS

There are five sources: (1) `composer.lock`, (2) installed Composer metadata (`InstalledVersions` reading the worktree's `vendor/composer/installed.php`), (3) SDK constants `Stripe::VERSION` and `ApiVersion::CURRENT`, (4) checkout constants `ExecutionContextV1`, `CheckoutPolicy` and `StripeSdkCheckoutGateway::API_VERSION`, and (5) the pinned manifest (`locked_sdk_version`, `implemented_pinned_api_version`, `spec_api_version`, `gzip_sha256`) plus the OpenAPI snapshot hash.

I ran `php artisan vasey:stripe-preflight --json` with a valid synthetic shape in the environment, so the baseline exits 0. I mutated each source one at a time and reverted each with `git checkout` or a backup copy. The SDK constants were changed through `-d auto_prepend_file` loading a modified class copy, so the shared vendor was not touched.

| Mutation | Exit | `pins_valid` | Blocked check |
| --- | --- | --- | --- |
| baseline | 0 | true | none |
| composer.lock → v21.3.3 | 1 | false | `sdk_lock_installed_runtime_equal`, `sdk_matches_pinned_manifest` |
| installed.php → v21.3.3 | 1 | false | `sdk_lock_installed_runtime_equal` |
| `Stripe::VERSION` → 21.3.3 | 1 | false | `sdk_lock_installed_runtime_equal` |
| `ApiVersion::CURRENT` → clover | 1 | false | `api_version_pins_equal` |
| `ExecutionContextV1` / `CheckoutPolicy` / `StripeSdkCheckoutGateway` API_VERSION (each) | 1 | false | `api_version_pins_equal` |
| manifest `implemented_pinned_api_version` / `spec_api_version` | 1 | false | `api_version_pins_equal` |
| manifest `locked_sdk_version` | 1 | false | `sdk_matches_pinned_manifest` |
| manifest `gzip_sha256` / spec +1 byte | 1 | false | `provider_source_manifest_hash` |
| manifest missing | 1 | false | three checks |
| after revert | 0 | true | none |

After the matrix, `git status` showed only `review-evidence/` and the throwaway test, and `installed.php` compared byte-identical to its backup.

### 4. Webhook suite: PASS

- **Real receiver.** The tests POST to the real `/webhooks/stripe` route (`routes/webhooks.php:7`) → `StripeWebhookController` → `ReceiveStripeWebhook` → `VerifyStripeWebhook`, which uses the real SDK `WebhookSignature::verifyHeader` with a tolerance of 300. Only the *provider* gateway (`StripePaymentGateway`) is synthetic. The receiver, dedupe and processing are real.
- **Mutations I ran myself (each reverted):**
  - **W1:** disable `verifyHeader`. 5 of 7 invalid-signature cases fail (`wrong_secret`, `body_tampered`, `stale`, `future`, `no_v1`). `missing_header` and `two_timestamps` are refused earlier, by the receiver's own header parser, so they test that layer.
  - **W2:** map unknown provider errors to `failed` instead of `retry`. `test_unknown_provider_outcome_stays_retryable…` fails.
  - **W3:** remove the same-id fingerprint conflict check in `ReceiveStripeWebhook`. The replay test fails (expected 409, got 200). My first attempt at W3 was ineffective because of operator precedence, so it was redone and recorded.
- **Signature alone.** No test asserts a grant from a signature alone. The first test asserts that a valid signature yields 0 `verified_payments` and 0 `payment_observations`, with no provider calls. Verified state appears only after `ProcessStripeReceipt` does an authoritative `retrieve` and `payment_intent`.
- **Ordering and unknown outcomes.** The out-of-order test checks that a stale "expired" snapshot doesn't override authoritative paid state (no `expired` observation, one `VerifiedPayment`). The unknown-outcome test checks the sequence `retry` → `pending` → `awaiting_finalization`, with the pending observation preserved immutably.

### 5. Backup/restore: PASS (R-4 for the documented MySQL path)

I ran `php scripts/ops/backup-restore-proof.php --synthetic-proof --workdir <scratch 0700 dir>`. It exited 0 with `RESTORE_VERIFIED`, 15 of 15 checks passing, 28,672 database bytes, 7 files and 398,644 bytes, and `mysql_procedure: documented_not_executed`.

| `--verify` scenario | Result | Blocked checks |
| --- | --- | --- |
| clean restore | `RESTORE_VERIFIED` | none |
| one byte flipped in a restored private file (size and mode kept) | exit 1, `BLOCKED` | `restored_private_files_match_manifest`, `restored_private_bytes_equal_backup` |
| last byte of the restored DB flipped | exit 1, `BLOCKED` | `restored_database_bytes_equal_backup`, `restored_database_logical_matches_manifest` |
| unlisted extra file | `BLOCKED` | `restored_private_files_match_manifest` |
| after reverting | `RESTORE_VERIFIED` | none |

Non-empty, in-repo and mode-0755 workdirs were each refused with `BLOCKED`.

The script contains no `exec`, `shell_exec`, `proc_open`, `system` or `popen`, and no `mysql`. The MySQL procedure exists only in `docs/ops/backup-restore-proof.md`, labelled "documented, not executed".

### 6. Env template: PASS

`ProductionEnvironmentTemplateTest` passes (6 tests, 1,629 assertions). Each mutation below was reverted afterwards:

| Mutation | Tests that failed |
| --- | --- |
| Undocumented family key added to `config/payments.php` | "missing from both ops templates" and the family coverage test |
| Undocumented non-family key added to `config/app.php` | "missing from both templates" and the shrink-only `.env.example` test |
| Template key with no config reader | "Template documents a key no config reads" |
| `PRODUCTION_CHECKOUT_FUNDS_MODE=whsec_abc123` with a matching annotation | "carries a credential, identifier, address or URL" |

A scan of the template, the `.env.example` block, the packet and the backup document for `sk_`/`rk_`/`pk_`/`whsec_`/`acct_` values, emails and URLs found nothing real. The only hits were the framework `SQS_PREFIX` placeholder, `localhost`, and `hello@example.com` in pre-existing `.env.example` lines. Every active value is `false`, apart from `STRIPE_MODE=test`. The diff-wide grep for credential-shaped strings, excluding SYNTHETIC/Fixture-labelled ones, was empty.

### 7. Activation packet: PASS (R-3, R-6)

Every command it lists exists:

- `vasey:stripe-preflight`, `vasey:commerce-readiness`, `vasey:doctor`, `vasey:stripe-inbox`, `vasey:process-stripe-receipts` and `vasey:reconcile-test-payments` (from `php artisan list --raw`);
- `scripts/ops/private-server-preflight.php` with `--template` and `--env-file … --runtime`;
- `scripts/ops/test-private-server-preflight.py`;
- the `media` and `contracts` queue commands, which match `docs/media-processing.md:82` and `docs/test-contract-issuance.md:62`.

Every path it cites exists. Sean-only inputs are tabulated in §2, each stage's "Requires" names Sean's authorization and the SHA, and placeholders use `<…>` with no values. It claims no execution: the lead says "This lane ran none of it", and §8 says "The probe has only run against synthetic fixtures".

### 8. `httpsOrigin` visibility change: PASS

The diff changes only `private function` → `public static function` and `$this->` → `self::`. The body is unchanged and never used `$this`. The only callers are `ProductionCommerceReadiness.php:65` and `StripeCapabilityPreflight.php:51`. `tests/Feature/ProductionCommerceReadinessTest.php` passed: 66 tests, 334 assertions.

### 9. The lane's four architecture findings

| Lane finding | Verdict | Evidence |
| --- | --- | --- |
| No live webhook receiver | TRUE | `VerifyStripeWebhook.php:18-24` returns 503 unless `webhook_enabled`, env ∈ {local, testing, **staging**} and mode `test`. `:66` and `:82` refuse `livemode !== false`. `ReceiveStripeWebhook.php` stores `'livemode' => false` only. |
| Test-mode payments require a local/testing `APP_ENV` | TRUE | `CheckoutPolicy.php:14,35`; `PaymentProcessingPolicy.php:12`; `FinalizationPolicy.php:19`; `ExecutionContextV1.php:59`. The receiver alone also admits `staging`, which the packet states correctly. |
| Production checkout provider and routes are unregistered | TRUE | `bootstrap/providers.php` lists only App, AdminPanel and SupportAttachment providers. `ProductionCheckoutServiceProvider.php:10` exists but isn't registered. `bootstrap/app.php:32-40` and `routes/web.php` never load `routes/production-checkout.php`. `php artisan route:list --json` shows 168 routes and none for production checkout. |
| No operator reconciliation command for production | TRUE | The only reconcile commands are `vasey:reconcile-test-checkout` (it uses `App\Domain\Commerce\Checkout\HostedCheckout`, the test one) and `vasey:reconcile-test-payments`. Nothing in `app/Console` or `routes/console.php` references `App\Domain\Commerce\ProductionCheckout\`. `ProductionCheckout\HostedCheckout::reconcile` (`HostedCheckout.php:49`) is reached only through `ProductionCheckoutController`. |

## Commands and results

`$P` is the worktree PHPUnit wrapper: `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; …require "vendor/phpunit/phpunit/phpunit";' -- <test>`. The worktree's `vendor/bin` symlinks to the main checkout, and `php vendor/bin/phpunit` fails with "Cannot redeclare class ComposerAutoloader…". Reflection confirmed that `App\` resolves to `/home/user/VA-Studio-review-prep/app/...`.

| Command | Result |
| --- | --- |
| `git fetch origin harness/production-preparation-1` | head `a2b56002` |
| `scripts/dev/mkworktree.sh /home/user/VA-Studio-review-prep a2b56002` | worktree at `a2b56002…` |
| `$P tests/Feature/StripeCapabilityPreflightTest.php` | passed, 31 tests / 304 assertions |
| `$P tests/Feature/PaymentWebhookPreparationChecksTest.php` | passed, 13 / 113 |
| `$P tests/Unit/BackupRestoreProofTest.php` | passed, 10 / 252 |
| `$P tests/Unit/ProductionEnvironmentTemplateTest.php` | passed, 6 / 1,629 |
| `$P tests/Feature/ProductionCommerceReadinessTest.php` | passed, 66 / 334 |
| `$P tests/Feature/StripeWebhookTest.php` | passed, 53 / 230 |
| `$P tests/Feature/ReviewerProbeGatingAdversarialTest.php` (throwaway, since deleted) | passed, 23 / 143 |
| `php artisan vasey:stripe-preflight --json` (local `.env`) | exit 1; shape false, pins true, `provider_io_performed: false` |
| Pin mutation matrix (§3) | every mutation exits 1 with `pins_valid: false`; baseline and revert exit 0 |
| Webhook mutations W1, W2, W3 (§4) | 5, 1 and 1 failures respectively; all reverted |
| Env-template mutations (§6) | each failed as expected; reverted |
| `php scripts/ops/backup-restore-proof.php --synthetic-proof …` and `--verify` mutations (§5) | as tabulated |
| `python3 scripts/ops/test-private-server-preflight.py` | Ran 16, OK |
| `python3 scripts/dev/test-bootstrap-macos.py` | Ran 13, OK |
| `php scripts/ops/private-server-preflight.php --template` | `TEMPLATE_VALID` |
| `php vendor/laravel/pint/builds/pint --test <changed PHP files>` | passed |
| `git diff --check 3716324a..a2b56002` | clean |
| `git diff --name-only 3716324a..a2b56002` filtered for `.github`, settings and workflow | none (no workflow or protection changes) |
| `git status --short` (final) | only `?? review-evidence/` |

## SHA-256 of files relied on (worktree at `a2b56002`; identical at `01a2590d` except the receipt README)

```
f0d8f63ddd1b415e2427eda99a590c20152eb507d1e7e22d250a744b3e0bcb18  app/Console/Commands/StripePreflight.php
56fc99eed248456da4cfc5871846648f0b25fc903ca6d10a49487d628ec5d673  app/Domain/Commerce/Readiness/StripeCapabilityPreflight.php
ed7dcd5670f89cf7cedcc2910948c87c5e8a8388c62eff71d6bf09bbcf905abc  app/Domain/Commerce/Readiness/StripeCapabilityProbe.php
a899f76eafc497dce2207735be13be5b579e55622c8b2501f876a82ce036e414  app/Domain/Commerce/Readiness/ProductionCommerceReadiness.php
a7c2b840b7ce06823e347d74b750319ffb3b600ab943b2042fe3e12c1d765fd1  tests/Feature/StripeCapabilityPreflightTest.php
6652642d3c72cba79512f012eba5a0bede2c0772260a1a439a94d0d438d7db2e  tests/Feature/PaymentWebhookPreparationChecksTest.php
db5b6b58d2b48951b5a58ee099e823fa75fd75a01e772f9bf88751ba7628fa53  scripts/ops/backup-restore-proof.php
1c1c8751861f14f762ac8b64422f7bdde297ba07a28207b3cff8c8cdbcca07c9  tests/Unit/BackupRestoreProofTest.php
64a9c5effe57196adeb59c0077e2311578d7915eefc5fa2f5109f50fa2ea189a  docs/ops/backup-restore-proof.md
c6aa34b616aa10377317371594beea2adf58b2d607481143b899b6de58d0ac2b  ops/production/env.production.example
64809e8dd78662452f741a8fea249a6d04879da2ca99e141a5c0db5eedc9afbe  .env.example
a6ce6bfdf6dd3b324c8a76c927101c4015bf8d1a2c3bbb413593ae92a69ddf8b  tests/Unit/ProductionEnvironmentTemplateTest.php
d5982ac63de9318464eecd0f2f60901fa25dd88f12439f88dd31c0dde19209eb  docs/ops/production-activation-packet.md
4f7d2a06df789dc16bfe987987296d468a9b626b3f2817907377b1898dea0c85  docs/verification/production-preparation-1-20261007/README.md
460c9c6a5c2e0c78414b15bc0447209c75f4ec04c25a9cd9d397fdeabfe0c7bf  config/production_checkout.php
7b08341640033b05df0d4318653e68c348ad1a62ee59c97dbc01a36af722785b  app/Domain/Commerce/ProductionCheckout/OwnAccountStripeGateway.php
5ba9a292027db02c1b5688df7d36d8405d8bb4854c4c2732dd6ceda9bc42a220  app/Domain/Commerce/Payments/VerifyStripeWebhook.php
01a91c7dc7276295e4155cb6a1c42e2792fc1e699b3d3d417b62a37434f1e0cc  app/Domain/Commerce/Payments/ReceiveStripeWebhook.php
a6b71124d3b4c59cf26f42bdd9fd9da2925060c62bbad388d8a68a981c1c3412  app/Domain/Commerce/Payments/ProcessStripeReceipt.php
c42e91304735d2183fa0125cf1b841dd276c247713adea58df399ddfeddecd39  app/Domain/Commerce/Checkout/CheckoutPolicy.php
7f27d8c58b57d8a03164b1d2e1957256c64fe99caa8190984a95de98950a2f40  app/Domain/Commerce/Payments/PaymentProcessingPolicy.php
ac908ae218e7988ef5cef50eaeed808a4838c57422e99a1694fff6ecd4c4436c  app/Domain/Commerce/ProductionCheckout/ExecutionContextV1.php
4a7e3af70523aedec308ba5220817f0ba1580d26d1873bb13ad49e4007f41834  bootstrap/providers.php
866204f62ff4ca102c703a52a683350a810bd6d2592457707ac551bd94f5d5b7  bootstrap/app.php
e228541e68867a3a7d3edc5e390e7474d2f0a9ad923a6f7e27b95870835bfd83  app/Providers/ProductionCheckoutServiceProvider.php
415b6c42a8381f5d62519d1f0b108538e6ed86ccf19d85e267dc3e2f1c2ac496  routes/production-checkout.php
8318cb2dd022e5614af4d551bb86231707a18485311478dd4b51a57ee108eeb9  composer.lock
3099e6c6c5475359d94018422f42ad31f02cc0d9ba3caf2f1aed678586ab58d1  docs/verification/operative-checkout-new-20261007/provider-source/dahlia-receipt.json
b1397cbd2a6c58d8f646101dbe5d87895f3402ce2859f8bfca3bb2182fdb0c13  docs/verification/operative-checkout-new-20261007/provider-source/stripe-openapi-dahlia-30d3391c.json.gz
8be8c185c928d84387924425a8ae69c7b63e50bd52e3b9a7e5708c962c56471b  review-evidence/ReviewerProbeGatingAdversarialTest.php.txt
bf7d98a62674a4b29c61d3af437338f0f856f25df2fbdc7cb4ef280a1a3ab645  review-evidence/adversarial-probe-gating-result.json
00ef6c69e63555725b679673c1d6c23ec73cb33a692f6e7a6a0bc14f9a11b852  review-evidence/backup-proof-mutated-db.json
e9c8878cc59a7cb7990d0ba626e9bd7af84f5a159f408fcc110190c60304991a  review-evidence/backup-proof-mutated-private.json
77786da61891205245f5de2de5df4923ed374edef360d3abf6c0928886870b94  review-evidence/backup-proof-synthetic.json
2496618938b3bcdf12dc6ba2aed9850a5c8a278b25e3295e6fc24a311832b2a4  review-evidence/preflight-baseline.json
```

`StripePreflight.php` and `StripeCapabilityPreflight.php`, re-hashed from `git cat-file -p 01a2590d:<path>`, match the hashes above.

---

## Addendum: narrow re-review of `3d615162` (docs head `e8d84306`), 2026-10-07

**Result: APPROVE WITH CONDITIONS carries to `3d6151624c063c98075eef3414114ee7afe06c5f`, and to the docs-only head `e8d84306642a40177df100a67caed3c66b31a521`.** R-1 and R-2 are resolved. Conditions 1 (scope: no activation) and 3 (re-review if these files change again) still apply.

### Scope of the change

- `git diff --stat a2b56002..3d615162` shows exactly three files: `StripeCapabilityProbe.php` (+11/−3), `StripeCapabilityPreflight.php` (+5/−2) and `tests/Feature/StripeCapabilityPreflightTest.php` (+47).
- `git diff 01a2590d..3d615162` also includes the receipt README, but only because `a2b56002` (docs) lies between the two.
- `git diff --stat 3d615162..e8d84306` is documentation only: the queue row, the packet's plan path and R-3 note, the lane README, and `independent-review/DECISION.md`. That copy is byte-identical to the original decision (SHA-256 `78606749…9216` for both).

### R-1: resolved

- The guard is now `($this->fixtureTransport === null) !== app()->environment('testing')` (`StripeCapabilityProbe.php`). It is an exact XOR: a fixture only in `testing`, and in `testing` only a fixture.
- The optional `?Closure $realTransport` builder (default: the same `CurlClient` with a 3 s connect and 10 s total timeout) is invoked only after every precondition. Its result must be a `ClientInterface`.
- The container autowires `null` for the builder, because a non-instantiable `Closure` with a default falls back to that default. The lane tests resolve the probe through `app()`. Nothing in `app/`, `bootstrap/` or `config/` binds `Closure::class`.

**Reviewer throwaway test** `review-evidence/ReviewerProbeBuilderAddendumTest.php.txt` (12 tests, 75 assertions, all passing, since deleted). With a counting builder bound, the builder count stayed 0 and `ApiRequestor::httpClient()` was unchanged in every case:

- through Artisan: no confirmation; `provider_io_enabled` set to `'true'` or `1`; no key; `sk_live_` in test mode; `rk_test_`; a bad origin; a fully valid configuration in `testing` without a fixture;
- inside `DB::transaction`;
- direct `observe()` in `testing`, for both test and live modes. The exception had no previous exception and no secret marker.

Under a simulated `production` environment, the builder is likewise not invoked for:

- test funds (refused by R-2 as `configuration_shape_invalid`);
- a non-null `Stripe::$logger` (precondition failure);
- a fixture outside `testing`.

With every gate satisfied, it is invoked exactly once, `evidence_origin` is `provider_observed`, and the previous HTTP client is restored. The original 23-case adversarial test also re-passed at `3d615162` (23 tests, 143 assertions).

**Mutation check.** Restoring the old guard makes `test_direct_observe_in_testing_never_builds` and the lane's `test_probe_class_itself_never_builds_the_real_transport_in_testing` fail. Reverted.

### R-2: resolved, and it matches checkout exactly

- **Same expression.** The preflight uses `$modeValid && ($mode !== 'test' || app()->environment(['local', 'testing']))`. That is the same predicate as `ExecutionContextV1.php:59` (`$mode !== 'test' || app()->environment(['local', 'testing'])`), plus the mode-shape conjunct.
- **Gates the probe.** It feeds `configuration_shape_valid`, so it also blocks the probe.
- **Environment matrix.** My test covered local, testing, staging, production and development, each with test and live funds: `funds_mode_environment` and `configuration_shape_valid` equal the checkout rule in all 10 cases.
- **Mutation check.** Removing the environment conjunct fails two of my tests and two lane data sets (test funds on production and on staging). Reverted.
- **Live outside production.** I agree with not adopting "live only in production". The preflight now mirrors checkout, which admits live funds in `testing` (`ProductionCheckoutProviderTest`). The remaining operational risk is a live key used for a read-only probe from a non-production host. That is a procedural matter for the activation packet, not a code defect.

### Commands (worktree at `e8d84306`; `$P` as above)

| Command | Result |
| --- | --- |
| `git fetch origin harness/production-preparation-1`; `git checkout --detach e8d84306` | HEAD `e8d84306` |
| `$P tests/Feature/StripeCapabilityPreflightTest.php` | passed, 37 tests / 318 assertions |
| `$P tests/Feature/ReviewerProbeGatingAdversarialTest.php` (original throwaway) | passed, 23 / 143 |
| `$P tests/Feature/ReviewerProbeBuilderAddendumTest.php` (new throwaway) | passed, 12 / 75 |
| R-1 guard reverted (temporary) | 1 reviewer test and 1 lane test fail; restored |
| R-2 conjunct removed (temporary) | 2 reviewer tests and 2 lane data sets fail; restored |
| `php artisan vasey:stripe-preflight --json` (local `.env`) | blocked 6 / absent 2 / pass 5 / not_requested 1, `pins_valid: true`, `provider_io_performed: false`; `funds_mode_environment` blocked (no mode set) |
| `git status --short` (final) | only `?? review-evidence/` |

### What remains before any real probe (`--probe --i-understand-this-calls-stripe` against Stripe)

1. Sean's explicit written authorization, naming the stage (S2a or S3), the Stripe account, the key mode and the exact 40-character SHA.
2. For S2a (test mode): a dedicated `APP_ENV=local` machine with no customer data. Since R-2, test funds are refused anywhere else.
3. For live mode: the S3 prerequisites in the packet, or a separately authorized read-only live probe. Prefer running it from the production host, given the residual risk above.
4. Keys stay in the host secret store. Set `PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED=true` only for the probe window and turn it off afterwards (R-3). Until checkout is composed it has no other effect.
5. Keep the probe JSON as evidence. Only `evidence_origin: provider_observed` counts as observation; fixture results never do.
6. Still accepted and open: R-4. Fix the dump-header noise in the MySQL `diff` before executing that procedure, which is separate from the probe. R-5 and R-7 are Info.

### SHA-256 (at `e8d84306`)

```
54eb7833eee32119d20dbc0ca823e770f52d57a69de191b9c5deb76a3add8e83  app/Domain/Commerce/Readiness/StripeCapabilityProbe.php
5df786d2749e76f6d55257e142bdaa443025239aa853f9a4dcafd2fd40090549  app/Domain/Commerce/Readiness/StripeCapabilityPreflight.php
50f4beb2344b8e876d8398fee2867786357465409a3f5681d592208fec5a9f9d  tests/Feature/StripeCapabilityPreflightTest.php
01a979c1417563f0ca15c235faca6622c143631a11bd251ee972925b1f34c47b  docs/ops/production-activation-packet.md
0d5628326c3b4022d3e6d987c10d4ea0a9a986f29a5823d2d14c1d247116fba6  docs/verification/production-preparation-1-20261007/README.md
e2f17573fed420d9db009126dddb6afce40a3f50bc3f8cd0ce02bc9539ca51e7  docs/live-payment-and-production-preparation-queue.md
78606749d1e6941a6d844a695d53f6f1c2b704a1c4905c064be008701bed9216  docs/verification/production-preparation-1-20261007/independent-review/DECISION.md (original decision, pre-addendum)
d12ccee5c25cf9c407428b0f441e856872218d4a4b15b7d71a3e040636e66610  review-evidence/ReviewerProbeBuilderAddendumTest.php.txt
```

---

## Addendum 2: condition-3 re-review of `f4c55acf` (2026-10-07)

**Result: APPROVE WITH CONDITIONS carries to `f4c55acf375f552aacd301d693bef6d7da83dfc0`.** Conditions 1 and 3 still apply. The two new Low findings below don't block merge. They should be fixed, or explicitly accepted, before the first real probe.

### Delta `e8d84306..f4c55acf`

- **Code.** `StripeCapabilityPreflight::openTransaction()` (`:190`) and `StripeCapabilityProbe::observe()` (`:52`) now also refuse when `$connection->getPdo()->inTransaction()`. Each change is one line.
- **Test.** 18 lines are appended to `test_testing_environment_refuses_the_real_transport_and_open_transactions`.
- **Docs.** Steps 2 and 4 of `docs/ops/backup-restore-proof.md` change, and the lane README and DECISION copy are updated.
- **Nothing else.** No other code or test changed (`git diff --stat e8d84306..f4c55acf -- app tests`).

### Red before, green after

| Source | Result |
| --- | --- |
| Both app files at `e8d84306` | Fails at "null is identical to 'open_database_transaction'". 36 of 37 tests pass. |
| Only the preflight reverted | Fails at line 273, the `collect()` reason. |
| Only the probe reverted | Fails at line 283: `observe()` ran and made fixture calls. |
| `f4c55acf` | Passes: 37 tests, 322 assertions. |

Each guard is therefore independently covered.

My earlier throwaway tests re-pass at `f4c55acf`: the gating test (23 tests, 143 assertions) and the builder test (12 tests, 75 assertions).

### Does `getPdo()` over `DB::getConnections()` open connections or leak?

- **Connection objects: no.** `DatabaseManager::getConnections()` returns only already-resolved connections. A throwaway test confirmed the set of connection names is unchanged after `collect(true, true)`.
- **N-1 (Low): physical connections, yes.** `Connection::getPdo()` (`vendor/laravel/framework/src/Illuminate/Database/Connection.php:1293-1301`) invokes the lazy PDO resolver. A connection that was resolved but never queried is therefore connected by the probe gate. Throwaway evidence:
  - a lazy SQLite connection went from a closure to a `PDO`;
  - an unreachable resolved connection made `collect()` throw `SQLiteDatabaseDoesNotExistException` uncaught. That throw is outside `observe()`'s `try`, so the command crashes instead of reporting `refused`.

  It is fail-closed: there were no fixture calls and no transport. The exception text can name the DB path or host, but never a Stripe secret.
- **N-2 (Low): crash on a disconnected connection.** After `DB::disconnect()`, the connection stays in the manager with a `null` PDO, so `getPdo()->inTransaction()` raises `Error: Call to a member function inTransaction() on null`.
  - In `collect()` the error is uncaught, so the command crashes.
  - In `observe()` it is caught and reported as `failed`.

  This is fail-closed too.
- **Recommendation for N-1 and N-2.** Use `($pdo = $connection->getRawPdo()) instanceof \PDO && $pdo->inTransaction()` (`getRawPdo()` exists at `Connection.php:1309`). A connection that was never opened, or was disconnected, can't hold a transaction, and that check needs no reconnect.
- **Exposure is narrow.** `openTransaction()` is the last arm of the `match` (`StripeCapabilityPreflight.php:95`). It runs only after the confirmation flag, the provider I/O flag, the shape checks, the key check and the testing/fixture checks have all passed. Runs without `--probe` are unchanged.

### Shell fragments in the backup document

I rehearsed them in bash on a synthetic tree. The script is `review-evidence/backup-doc-rehearsal.sh.txt`, with the fragments copied verbatim and placeholders substituted.

| Case | Result |
| --- | --- |
| Clean tree | Step 2 passes; step 4's `sha256sum --check` and the name `diff` are clean |
| Step 2 with a symlink, dangling symlink, symlinked directory, hard link or FIFO | Each refused, exit 1, before any manifest or tar is written |
| Step 4 with an extra restored file | Name `diff` fails |
| Step 4 with a planted restored symlink | Name `diff` fails |
| Step 4 with a missing restored file | Checksum check and name `diff` both fail |
| Step 4 with a flipped restored byte | Checksum check fails |

The `!`-negated pipeline and the `find` precedence are correct, and so is `cut -c67-`: 64 hex characters, a space, then a mode character, so the name starts at column 67.

**Info:**

- Names containing a backslash or newline are escaped by `sha256sum` (a leading `\`), so the name `diff` reports a false mismatch. This fails closed.
- The step 4 process substitution (`<(...)`) is bash-only, and the code fence says `sh`: `dash` gives `Syntax error: "(" unexpected`. State that bash is required.
- The `exit 1` in step 2 closes an interactive shell.

R-4 (dump-header noise in the MySQL `diff`) remains accepted and open.

### SHA-256 (at `f4c55acf`)

```
d7fff6c43b1f080c0779f08c00f3f5ac8174128f25157c0d39b6256214ce4055  app/Domain/Commerce/Readiness/StripeCapabilityPreflight.php
2b0165b3da5903655bbf29310c6bb4e824e0a7f5cf44498b46b31b2aca3551fd  app/Domain/Commerce/Readiness/StripeCapabilityProbe.php
6e3e775e63de3ec100b67d6c56cfa45d09cb25f628932ee377ef5d1059a0cd41  tests/Feature/StripeCapabilityPreflightTest.php
ea700b3c225b64fb8ad0b94ef9122fb72e6de98eade793a52141dba214753c5b  docs/ops/backup-restore-proof.md
3591bb9c0311e1d4eaceb6096f860b4aa605de438b3126e06f7fb817fcbf9754  independent-review/DECISION.md as committed at f4c55acf (before this addendum)
37a3c9d2b1aedf1e022c257de9d3dce920633c3747f8d55c2c50abcccfd154ff  review-evidence/ReviewerPdoProbeAddendumTest.php.txt (VA-Studio-review-prep)
9718c7538207dad88d7308b043747dfcad5fe484255732c434cb1b3aaa9f4b3a  review-evidence/backup-doc-rehearsal.sh.txt (VA-Studio-review-prep)
```
