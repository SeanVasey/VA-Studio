# Production preparation 1: verification receipt (2026-10-07)

Lane `harness/production-preparation-1`, a worktree of `main` at
`3716324a51b0f7458ea68bdbab7b0696955923a7` (PR #42 merge). This is a development checkpoint,
**not** production acceptance. The branch is pushed as PR #46. An independent review of
`01a2590d` returned **APPROVE WITH CONDITIONS**
([`independent-review/DECISION.md`](independent-review/DECISION.md)). Its R-1, R-2 and R-6
follow-ups are below. The approval covers merging the preparation tooling only, not any
activation, real key, `--probe` against Stripe, flag change or S1–S3 step.

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
| `a2b56002` | 6: this receipt (first version) |
| `3d615162` | Review R-1 and R-2 fixes (probe class guard; funds mode bound to `APP_ENV`) |
| `e8d84306` | Review R-6: this README, the packet's plan path and R-3 note, the queue row, and `independent-review/DECISION.md` |
| `f4c55acf` | Codex P2 ×2 on PR #46: raw-PDO transaction guard in the preflight and probe (with regression test); documented MySQL backup refuses unmanifested entries and verifies the exact restored tree |
| `6abeaa94`, `99974a07` | Review addendum 2; Codex P2 ×2 on docs (`xargs -r`; clean-worktree deploy check) |
| the commit after `99974a07` | Codex P2 ×2: the probe reads capabilities from the own-account response instead of the Connect list endpoint; the backup procedure verifies the archive digest before extraction and the restored modes after |
| `4e76bf5a` | Codex P2 ×2: `proof_tree()` proves the private root's own mode; documented extraction fatal on a nonzero `tar` |
| the commit after `4e76bf5a` | Review addendum 5; symlinked-root tampering case; B-4/B-5 documentation fixes |

The first tested source was `01a2590d29ea27ebe5bb10df78c1ef5bb5f0ece6`, and the reviewer
assessed exactly that. The review follow-up was tested at
`3d6151624c063c98075eef3414114ee7afe06c5f`; after it, only documentation changed. The
reviewer's condition 3 applies: `StripeCapabilityProbe.php`, `StripeCapabilityPreflight.php`
and their test changed after `01a2590d`, so those files need re-review.

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

## Review follow-up (2026-10-07)

**R-1 (Low), fixed in `3d615162`.** The guard in `StripeCapabilityProbe::observe()` now
requires a fixture transport exactly when `APP_ENV` is `testing`, so the class itself (not
only the preflight command) never builds the real transport under tests. An optional
real-transport factory (default: the same bounded `CurlClient`) lets the new test prove the
factory is never called.

**R-2 (Low), fixed in `3d615162`.** The new check `funds_mode_environment` applies the rule
`ExecutionContextV1::make` uses: test funds only when `APP_ENV` is `local` or `testing`. It
feeds `configuration_shape_valid`, so it also gates the probe. Live funds outside production
stay allowed, because checkout allows them (`ProductionCheckoutProviderTest` runs live in
`testing`). The reviewer's stronger "live only in production" option was not adopted, so
that the preflight matches checkout exactly.

**Red, then green.** The red run used no network: the factory returned a throwing fake
transport.

| Run | Source | Tests | Assertions | Failures |
| --- | --- | ---: | ---: | ---: |
| Red: `--filter 'never_builds_the_real_transport\|funds_mode_follows'` | before the fix | 6 | 8 | 6 |
| Green: the full file | `3d615162` | 37 | 318 | 0 |

In the red run, R-1 built the real transport once, and R-2's check was missing in all five
environment cases.

**The six suites again, at `3d615162`:**

| Selection | Tests | Assertions | Failures | Errors | Skips |
| --- | ---: | ---: | ---: | ---: | ---: |
| `StripeCapabilityPreflightTest` | 37 | 318 | 0 | 0 | 0 |
| `PaymentWebhookPreparationChecksTest` | 13 | 113 | 0 | 0 | 0 |
| `BackupRestoreProofTest` | 10 | 252 | 0 | 0 | 0 |
| `ProductionEnvironmentTemplateTest` | 6 | 1629 | 0 | 0 | 0 |
| `ProductionCommerceReadinessTest` | 66 | 334 | 0 | 0 | 0 |
| `StripeWebhookTest` | 53 | 230 | 0 | 0 | 0 |
| **Total** | **185** | **2876** | **0** | **0** | **0** |

Pint `--test` passes on the changed files, and `git diff --check` is clean. A local
`php artisan vasey:stripe-preflight --json` now reports blocked 6 / absent 2 / pass 5 /
not_requested 1; the extra blocked check is `funds_mode_environment` with no funds mode set.

**Accepted findings, documented and not changed here:**
- **R-3 (Info).** The probe uses the checkout's own `PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED`.
  Once checkout is composed, enabling it for the probe also enables checkout provider I/O.
  This is now noted in the packet's S2 step 2.
- **R-4 (Low).** The documented MySQL restore `diff` would have reported dump-header noise;
  both dumps now use `--skip-comments` (sixth Codex pass). Rehearse before relying on it.
- **R-5 (Info).** The signature-only test asserts zero `verified_payments` and
  `payment_observations`, which come before any grant. It doesn't count entitlement tables
  directly.
- **R-7 (Info).** A `failed` probe reports `provider_io_performed: true` even when it failed
  on a precondition. This over-reports, which is the safe direction.

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

## Codex follow-up on PR #46 (2026-10-07)

Codex (non-gating, P0 threshold) posted two P2 findings on `e8d84306`. Both traced to real
paths and were fixed in the next commit.

**P2, `StripeCapabilityPreflight.php:190` (and `StripeCapabilityProbe::observe()`).** A
transaction begun on the raw PDO (`$connection->getPdo()->beginTransaction()`) leaves
Laravel's `transactionLevel()` at zero, so the `open_database_transaction` refusal and the
probe's own guard both admitted a probe inside an open transaction. Both now also require
`! $connection->getPdo()->inTransaction()`, the same pair `CommandTransaction` and
`ProductionPaidOrderLocatorV1` check. The regression is appended to
`test_testing_environment_refuses_the_real_transport_and_open_transactions`: a raw PDO
transaction with `transactionLevel() === 0` must make `collect()` report
`open_database_transaction` and `observe()` throw without the secret in its message, with no
fixture call.

| Run | Source | Tests | Assertions | Failures |
| --- | --- | ---: | ---: | ---: |
| Red: that test with the guard change stashed (`app/` at `e8d84306`) | `e8d84306` | 1 | 10 | 1 (`null` instead of `open_database_transaction`) |
| Green: the full file | fixed | 37 | 322 | 0 |

Pint `--test` passes on the three changed PHP files; `git diff --check` is clean.

**P2, `docs/ops/backup-restore-proof.md` step 2.** `find . -type f` omitted symlinks from
`private.sha256` while `tar` still archived and restored them, so `sha256sum --check` could
accept a restored tree holding an unverified symlink (possibly pointing outside the root).
The documented procedure now refuses any entry the manifest would not cover (symlinks,
hard links, special files) before the backup, and step 4 diffs the restored tree's
non-directory entries against the manifest's names so an extra restored entry fails. The
fragments were rehearsed on a synthetic tree: clean tree accepted; a planted symlink and a
hard link each refused; a planted symlink in the restored tree detected by the diff. The
PHP proof script already refused symlinks, hard links and special files (its tests cover a
planted symlink); only the MySQL procedure text changed. The procedure is still documented,
not executed.

Codex's second pass on `f4c55acf` added two P2 findings, both documentation:
`xargs` without `-r` hashes stdin for an empty private tree (fixed with `-r`; an empty tree
now yields an empty manifest, rehearsed), and the packet's staging deploy step accepted a
dirty reused checkout (it now refuses any tracked edit, untracked or ignored file, and
requires `HEAD^{tree}` to equal `git write-tree`; rehearsed in a throwaway repository:
clean accepted, tracked edit refused, untracked/ignored file refused).

Codex's third pass on `99974a07` added two more P2 findings. The probe called
`GET /v1/accounts/{id}/capabilities` after `GET /v1/account`; that is a Connect lookup, which
`StripeSdkCheckoutGateway` documents as not applicable to the own-account credential, and
`OwnAccountStripeGateway` already reads `capabilities.card_payments` from the `/v1/account`
response. The probe now derives the capability map from the own-account response's
`capabilities` hash (name to status, same statuses, same name pattern) and makes exactly one
request; `ENDPOINTS` is `['GET /v1/account']`. Fixtures moved into the account response, the
two provider-failure datasets that modelled the list endpoint became a hash with an unknown
status and a non-hash value, and a new data-provided test checks four malformed hashes fail
closed with one call. The documented MySQL restore now checks `private.tar.sha256` before
extraction (a replaced archive with the same member bytes but widened modes passed the
member hashes) and, after extraction, refuses any restored entry outside modes 0600/0700.
Rehearsed on a synthetic tree: digest accepted; a replaced archive refused by the digest;
with the digest bypassed, widened modes still pass the member hashes and are refused by the
mode check.

| Run | Tests | Assertions | Failures |
| --- | ---: | ---: | ---: |
| `StripeCapabilityPreflightTest` after the probe change | 41 | 336 | 0 |

Addendum 3 (re-review of `83a891bc`) carries APPROVE WITH CONDITIONS and adds two Low
findings on the documented procedure, fixed in the next docs commit: B-1, the step-3 and
step-4 checksum checks printed FAILED without stopping (now `|| exit 1`); B-2, the mode check
always failed on a normal checkout because the tracked `storage/app/private/.gitignore` is
0644 (now excluded by name). Rehearsed: a tampered archive now stops the script; a tree
holding only the 0644 `.gitignore` passes; any other 0644 file is still refused.

Codex's fourth pass (P2, docs): the mode check rejected sealed `0400` originals, which
`ContractFiles` and `PrivateMediaFiles` write deliberately. The check now accepts `0600` or
`0400` for files and `0700` for directories (rehearsed: 0400 and 0600 accepted, 0644 and
0666 refused, 0755 directory refused).

Codex's fifth pass (P2 ×2, docs): `sha256sum --check --strict` rejects the empty manifest an
empty private store now produces (the check is skipped for a zero-byte manifest; the exact
name diff still proves the empty tree), and `! -name .gitignore` exempted a nested
`.gitignore` (now `! -path ./.gitignore`, root only). Rehearsed: empty store passes; nested
0644 `.gitignore` refused; root 0644 `.gitignore` still accepted.

Codex's sixth pass (P2 ×2, docs): HTTP requests can write private files between the
database snapshot and the file manifest (pausing workers isn't enough), and the global `sed`
schema rename could rewrite stored values. The procedure now quiesces with `php artisan down`
plus stopped workers/scheduler before step 1 and `up` after step 2, and restores the dump
unchanged into an isolated MySQL server under the original schema name (no rename at all).
Both dumps use `--skip-comments`, which also closes the accepted R-4 dump-header noise. The
"Name rewriting" open limit became "Server isolation". Still documented, not executed.

Codex's seventh pass (P2 ×2): the raw-PDO guard called `getPdo()`, which opens a lazy
connection just to answer the guard and dereferences null after a disconnect — the
reviewer's N-1/N-2. Both guards now inspect `getRawPdo()` only when it is already a `PDO`
(a lazy connection holds a resolver closure, a disconnected one null), keeping
`transactionLevel()` for framework transactions; N-1 and N-2 are closed. New regression
`test_guard_never_opens_an_unopened_connection`: a resolved `mysql` connection to an
unreachable port stays unopened across a probe (red on the old guard: `PDOException
Connection refused`; green after). The documented restore's dump `diff` now exits 1 on a
mismatch instead of letting the following `CHECKSUM TABLE` mask it.

| Run | Tests | Assertions | Failures |
| --- | ---: | ---: | ---: |
| `StripeCapabilityPreflightTest` after the guard change | 42 | 342 | 0 |

Addendum 4 (re-review of `9b6b48be`) carries APPROVE WITH CONDITIONS and closes N-1, N-2,
B-1 and B-2 (reviewer re-ran the doc fragments: tampered tar stops step 3; 0400 and the root
`.gitignore` pass; a deeper `.gitignore` is refused; empty store passes); each guard is
covered on its own (reverting either makes the new test fail). New B-3 (Low, docs): the
step 4 name `diff` lacked `|| exit 1`, fixed in the next docs commit together with the
reviewer's note that a step 2 refusal leaves the application down until `php artisan up`.

Codex's eighth pass (P2 ×2, docs): the exempted root `.gitignore` is now required to be
exactly 0644 (rehearsed: 0644 passes, 0666 refused, absent passes), and the staging deploy
step became a fresh immutable checkout per authorized SHA under `<RELEASES_DIR>/<SHA>` with
persistent private storage attached afterwards from `<PERSISTENT_ROOT>` — the `--ignored`
clean-tree check had rejected every reused checkout holding `vendor/`, `public/build/` or
real private assets.

Codex's ninth pass (P2 ×4, docs): the backup procedure now fails on a nonzero `mysqldump`
and restarts (and verifies) the workers and scheduler after `php artisan up`; the packet
attaches persistent private storage as a bind mount instead of a symlink (the runtime
preflight's `canonical()` rejects symlinks and requires mode 0700) and installs the validated
`<RUNTIME_ENV>` as the release's `.env` (0600) before any Artisan, web or worker process.
Rehearsed: the inode check passes on a bind mount and fails on a symlink; `install` + `cmp`
pass on a synthetic file. Still documented, not executed.

Codex's tenth pass (P2 ×2, docs): the S3 live probe was ordered before the first flag change,
so `provider_io_disabled` would have refused it — the authorized
`PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED` change plus `config:cache` now precede the probe,
the other flags still follow one at a time; the restore extracts as the application user
(`runuser -u <APP_USER>`) and step 4 refuses any restored entry not owned by that user and
group (rehearsed: owned tree passes, a foreign-owned file is refused).

Codex's eleventh pass (P2 ×2, docs): the S1 rollback now stops and verifies the workers and
scheduler before the restore (as the backup procedure does) and switches the units to the
previously built release directory instead of checking another SHA out inside the current
one (which would have kept the newer `public/build`); it restarts and verifies the services
before `php artisan up`.

Codex's twelfth pass (P2 ×2): `proof_tree()` checked only the children's modes, so a restored
private root widened to 0777 still verified. The root's own type and mode (directory, 0700)
are now proven before the walk, with a ninth tampering case in `BackupRestoreProofTest`
(`restored private root widened` → `restored_private_tree_safe` false, result `BLOCKED`; red
on the old script, green after). The documented restore's `tar --extract` is now fatal on a
nonzero exit (a partial extraction can omit an empty trailing directory the file checks
cannot see). The script change falls under the review's condition 3.

Review addendum 5 (`independent-review/DECISION.md`): APPROVE WITH CONDITIONS carries to
`4e76bf5a`; the reviewer also showed that a restored root replaced by a symlink to an intact
0700 copy verified on the old script and is `BLOCKED` on the new one. The commit after
`4e76bf5a` adds that tenth tampering case (`restored private root replaced by a symlink`,
red on the old script, green after; the file is now 12 tests / 286 assertions) and closes the
addendum's documentation findings: B-4, the operator's 0700 `<BACKUP_DIR>` could not be read by
`<APP_USER>` during extraction, so the operator's shell now opens the archive and hands it to
the application user's `tar` on stdin and creates `<RESTORE_PRIVATE_ROOT>` for that user
(parent must be traversable by it); B-5, the ownership check assumed a group named after the
user and any `find` failure in the pipe-to-`grep` checks passed vacuously, so the inputs
name `<APP_GROUP>` separately and every `find` check captures its output and stops on a
nonzero exit; Info, `--preserve-permissions` keeps the archived 0644 root `.gitignore` under a
077 umask. Rehearsed as root with a temporary unprivileged user (removed afterwards): the
happy path passes under umask 077 with the backup directory still 0700; a root-owned restored
file is refused; an unknown group name stops the procedure (the old form passed); without
`-p` the root `.gitignore` came back 0600.

Codex's thirteenth pass (P2 ×2, docs): `php artisan up` preceded the worker and scheduler
restart, so a service that failed to start left the application live without its writers;
the restart and `is-active` check now come first and a successful `php artisan up` is the
final step (a failed start keeps maintenance mode on). The step 2 `tar --create` is fatal on
a nonzero exit, as extraction already was.

Codex's fourteenth pass (P2 ×3, docs): the S3 declared-exemption alternative to Tax255 could
not place any order, because every new order's review needs a `basis_public_id` and both
`ApproveExemptionAuthority::approve()` and `TaxExemptions::qualify()` refuse unless the
authoring flag is on and the owner is in the config-only `exemption_policy_owner_ids` list,
which ships empty; the packet now orders an exemption-authoring step (reviewed owner
allowlist commit, flag under its own authorization line, policy approval and bases) before
`PRODUCTION_CHECKOUT_HTTP_ENABLED`, and the gate table says so. The S1 `.env` install uses a
separate `<APP_GROUP>` input. The backup's `php artisan down` is fatal on failure.

Codex's fifteenth pass (P2 ×2, docs): the S1 rollback's `php artisan down` was unchecked and,
with the `file` maintenance driver, its marker is release-local, so switching the web unit to
the previous release reopened HTTP before the worker checks. The rollback now proves
maintenance mode (fatal `down`, 503 or marker check) before quiescing, enters maintenance
inside the previous release before switching units and confirms 503 after the switch, and
lifts it only there after the services are verified.

Codex's sixteenth pass (P2 ×3, docs): the restore proof's re-dump was piped into `diff`, so
only `diff`'s status was seen; the re-dump now goes to a file with its own `|| exit 1`. The S1
deploy, when run on a host already serving traffic, now skips the backup procedure's resume
step and stays in maintenance with workers stopped through the migration until the new
release is activated. The S1 rollback restores the paired private archive (verified as in the
backup's step 4) alongside the database while writers are stopped, before any release switch.

Codex's seventeenth pass (P2 ×2, docs): the S1 stage promised a resume at its end but ended
with foreground `queue:work` templates and never started the services or left maintenance;
the templates are now comments (unit/cron lines) followed by a required `systemctl start`
plus `is-active`, a fatal `php artisan up` and a reachability check. The backup procedure's
`php artisan up` is fatal too, so a host left in maintenance never continues into the restore.

Codex's eighteenth pass (P1 ×4, docs): the S1 upgrade sequence was restructured. The runtime
preflight is fatal; maintenance is entered and proven in the release actually being served
(`<RELEASES_DIR>/current`, skipped on a first install) and the services stopped and verified
inactive before the backup; the new checkout enters maintenance itself before `migrate
--force || exit 1` (and `config:cache`, `doctor` are fatal); the units are then switched by
repointing the `current` symlink atomically, reloading the web service and proving
`readlink -f current` and the worker's `/proc/<MainPID>/cwd` equal the new release while the
site still answers 503, before the final `php artisan up`. The rollback's release switch now
uses the same mechanism. Documentation only; not executed.

Codex's nineteenth pass (P2 ×2, docs): the restore's `mysql` load is fatal on failure; the S1
rollback replaces the contents of `<PERSISTENT_ROOT>/private` in place (entries moved aside
into a 0700 sibling, verified entries moved in, device:inode unchanged, manifest re-checked
through the previous release's bind mount) instead of swapping the directory object, which a
bind mount would not follow.

Codex's twentieth pass (P1 ×2, P2 ×1, docs): the verification re-dump is now created 0600
inside the operator-only `<BACKUP_DIR>` under `umask 077` and removed after the diff; the S1
upgrade requires the backup's isolated restore proof (steps 3-4) to pass before
`migrate --force`, while writers stay quiesced; the S1 rollback drops and recreates the
staging schema (the dump has no `--add-drop-database`) before loading the dump, then
re-dumps and diffs it.

Codex's twenty-first pass (P2 ×1, docs): every maintenance proof in S1 and its rollback now
captures the HTTP status and requires exactly 503 (and exactly 200 after `artisan up`), since
a failed `curl -f` from the host is also what a DNS, egress or TLS failure produces.

Codex's twenty-second pass (P1 ×3, P2 ×1, docs): the S1 quiesce also stops the PHP web
workers and proves no PHP process serves requests before the snapshot (a 503 only proves
new requests are refused); `composer install` and `npm ci && npm run build` are fatal and
the Vite manifest is required before the release can be switched to; a failed live check
after `php artisan up` re-enters maintenance at once before aborting to the rollback; the
rollback proves the worker's working directory after its restart, not while stopped.

Codex's twenty-third pass (P1 ×2, docs): the recovery `php artisan down` after a failed
live check is fatal and proven (marker file and exactly 503), and when that proof fails the
sequence stops `<WEB_SERVICE>` and reports the broken release as still public instead of
claiming maintenance; the S1 rollback's step 1 stops `<WEB_SERVICE>` and proves the PHP
workers drained (`is-active`, `pgrep`) before step 2 drops and restores anything.

Codex's twenty-fourth pass (P2 ×2): the preflight's `return_origin_https` check now applies
the production policy's own rule (`MachinePolicyV1::origin()`, made `public static`; no
logic change) instead of the looser readiness helper, so an origin `ExecutionContextV1`
refuses (localhost, an IP literal, an invalid hostname, a trailing slash) can no longer pass
the preflight; four new malformed cases, three red before the fix
(`conditions/codex-origin-rule/`); `StripeCapabilityPreflightTest` 46 / 378. The S1
`migrate --pretend` preview is fatal, so a failed preview never proceeds to `migrate --force`.

Under condition 3 the preflight change was re-reviewed (`independent-review/DECISION.md`,
addendum 6): APPROVE WITH CONDITIONS carries to `f8fed104`; the preflight now accepts
exactly the origins `ExecutionContextV1` can use (no false negative by construction; the
twelve policy-refused inputs the old helper admitted are now refused). Three Lows: O-1 the
check message overclaimed "never an IP literal" (reworded here: the shared rule refuses
only `localhost` and `127.0.0.1`); O-2 `MachinePolicyV1::origin()` has no port range check,
so `:0` passes the preflight as it already passed the policy (queued as a policy change under
its own review, not widened into this PR); D-1 the S1 block mixes privilege levels (now
names the `systemctl` and mount lines as the only privileged steps, under a unit-scoped
sudoers grant). Info: the worker `cwd` proof assumes the unit sets `WorkingDirectory` to
the `current` release.

Under the review's condition 3, the preflight and probe change was re-reviewed
(`independent-review/DECISION.md`, addendum 2): APPROVE WITH CONDITIONS carries to
`f4c55acf`. Two Low findings were accepted for merge and are now closed (seventh Codex
pass, below): N-1, `getPdo()` physically opens a configured but
unused connection, so an unreachable database crashes `collect()` instead of refusing; N-2,
after `DB::disconnect()` the guard fails with `inTransaction()` on null. Both fail closed
(no transport built, no secret in the error). Suggested fix: `getRawPdo() instanceof \PDO
&& ->inTransaction()`. Info from the backup-doc rehearsal: names containing a backslash or
newline give a false mismatch (fails safe); the `<(...)` verify step is bash-only; the
`exit 1` in step 2 closes an interactive shell.

## Still unknown or untested

- Any real Stripe account's capabilities. The probe has only run against a synthetic
  `ClientInterface` fixture, and the real `CurlClient` path is refused in `testing`.
- The MySQL backup procedure. It is documented only; it has not run, and no native MySQL was
  used in this lane.
- Host, storage, worker, scheduler, TLS, mail and DNS behaviour, all of which wait on U-02 and
  U-03.
- Hosted CI. No Foundation CI ran (CI cost policy). The independent review assessed
  `01a2590d`; the R-1 and R-2 source change in `3d615162` needs re-review under its
  condition 3.
- Every merchant, tax, legal, price and terms fact. None was invented here; placeholders look
  like `<...>`, and fixtures use `SYNTHETIC`, `.invalid` and the `XXX` currency code.

## Next dependency

1. Re-review the R-1 and R-2 delta (`a2b56002..3d615162`) — done, see the DECISION.md
   addendum — and the Codex transaction-guard delta after `e8d84306`.
2. N-1 and N-2 (transaction guard on a configured-but-unopened or disconnected
   connection) are closed; re-review the guard change under condition 3.
3. Root composition then decides whether to ship this with A4 or after A3 (Tax255).
4. Sean decides between S2a and S2b.

Plan references are to `docs/handoff/2026-10-07/CLAUDE-PLAN.md`: §3 A4 and F1, and the §4
list of inputs only Sean can supply.
