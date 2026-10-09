# Delta review of B2: `56efbb7a` to `5513e3ae`

- **Reviewer:** the same independent reviewer for authentication and payment/commerce admission who approved B2 at `56efbb7a517abc55f17ea9a8b37b29e4970a2210` (APPROVE WITH CONDITIONS).
- **Date:** 2026-10-09
- **Subject:** `harness/staging-env-admission`, from `56efbb7a517abc55f17ea9a8b37b29e4970a2210` to the pushed head `5513e3aee07746734b708f860117252595ec2452`.
- **First-parent delta:**
  - `3b36a0a`: fix for review condition C1
  - `293cd4d`: merge of `main` `b9cb7b67`, which is #67 (M-16)
  - `0b88d78`: documentation and ledger
  - `5ad7546`: repair of an ops test fixture
  - `5513e3a`: README results
- **Method:**
  - I modified no tracked files and did not commit or push.
  - Runs used a clean `git archive 5513e3a` export at `scratchpad/b2-delta-src` (outside this directory), with a physical vendor copy and `composer dump-autoload`.
  - `public/build` was never created.
  - The export provenance check found 1,982 files byte-equal to the commit with 0 mismatches (`evidence/export-provenance.txt`).
  - PHP 8.4.26, PHPUnit 12.5.34, SQLite in memory, Python 3.13.16.

## Verdict

**APPROVE** `5513e3ae` for a development merge.

- C1 is closed.
- The merge kept B2's staging semantics and M-16's renderer changes.
- The ops fixture repair is minimal and correct, and it does not weaken the validator.
- The delta changes nothing in `app/`, `config/`, `routes/`, `bootstrap/`, `database/` or `resources/` beyond #67's own diff, byte for byte.
- B2's staging refusals and MFA requirement still hold after the merge.
- C2 is unchanged and remains a documented, non-blocking follow-up.

**What this approval does not authorize:**

- production deployment
- live payments or keys
- Paid252 operative activation
- production memberships, member grants or membership billing
- production customer identity
- DNS or cutover

These still need Sean's separate authorization.

## Findings

### 1. C1 is closed (`3b36a0a`)

**The change.** `cmd_configure` in `ops/staging/bin/vasey-staging-ctl` now runs `route:cache` as the app, immediately after `config:cache`, under the same `env -i` and `as_app` discipline. Any failure dies before `sealed_release` and before the `configured` line.

**My adversarial probe** (`evidence/probes/reviewer_c1_configure_probe.py`, 5 tests, OK; `evidence/probe-c1-configure-run-1.txt`) reuses the branch's real fixture: the real `cmd_configure` source, the real sealer and the real `validate-runtime.php`. It found:

- **Order:** `config:cache` runs, then `route:cache`.
- **`route:cache` failure:** exit code 1 with `route cache failed; remain quiesced and restore the snapshot`. Nothing goes to stdout, so there is no `configured` line and no seal-verify step. The maintenance file remains, so admission stays closed. `refresh` never reaches `resume`, because `die` exits under `-e`.
- **`config:cache` failure:** `route:cache` is never attempted.
- **A mutant with the `route:cache` step removed** exits 0 having run only `config:cache`, which the branch's new assertion rejects. So the test would catch a revert.
- **Sealing still holds:** a successful configure runs exactly `stage-env` then `verify`.
  - `route:cache` writes `bootstrap/cache/routes-v7.php`, which is inside the sealer's declared runtime exception: `RUNTIME = {"bootstrap/cache", ...}` in `seal-release.py`, the same place `config:cache` already writes.

**Against the real application** (`evidence/probe-route-cache-real-5513e3a.txt`, on the export):

- A route cache built under `local` and served under `staging` gives MFA middleware **absent**.
- The C1 sequence (`config:cache` then `route:cache` under `staging`) gives it **present**.
- The runbook's host check (`route:list --path=admin/tracks --json -v | grep -c EnsureMultiFactorAuthenticationIsEnabled`) printed `1`.

**Residual (informational, not blocking).** The *manual* recovery procedures in `docs/ops/staging-runbook.md` still run only `config:cache` after restoring an environment: §B rollback step 5 and §D, a failed same-SHA refresh.

- **§B is consistent.** A rollback restores the previous release together with the environment snapshot taken before the deploy, and that release's route cache was built under the same environment.
- **§D fails closed.** If `route:cache` succeeded under `staging` and a later step failed, restoring the `local` environment leaves `EnsureMultiFactorAuthenticationIsEnabled` in the cache. That middleware requires enrollment unconditionally (vendor source checked), so the result is stricter, not weaker.
- **Recommendation:** add `route:cache` to those two manual steps for symmetry in a later documentation change.

### 2. The merge resolution (`293cd4d`)

All evidence is in `evidence/merge-resolution.txt`.

- My `git merge-tree --write-tree 3b36a0a b9cb7b67` reproduces the single conflict, in `ops/staging/env.staging.example`. The recorded merge differs from that result **only** by deleting the 6 conflict-marker lines.
- **Against B2's side,** the resolution adds exactly two things from M-16:
  - `VASEY_PHP_CLI_BINARY=/usr/bin/php8.4`, with its comment;
  - M-16's renderer note, which replaces the old "fail under PHP-FPM ... Keep them off".
- **Against main's side,** it keeps all of B2's staging content:
  - the `APP_ENV=staging` profile header;
  - `APP_ENV=staging`;
  - the "local, testing or staging" policy comments.
- The auto-merged `CHANGELOG.md`, `docs/ops/staging-runbook.md` and `ProductionFreeGrantFrozenBytesTest.php` equal the `merge-tree` result. The frozen-bytes test keeps B2's `ActivationPolicy` re-pin (`38d51e78…`), which I approved against family 256. It also takes #67's replacement for the directory assertion that failed after #56.

### 3. The ops fixture repair (`5ad7546`)

Evidence: `evidence/ops-fixture-5ad7546.diff`, `evidence/ops-fixture-red-before-5ad7546.txt` and the probe above.

**The change.** It touches only `tests/ops/test_staging_protected_configuration.py`. The fixture's base environment gains `VASEY_PHP_CLI_BINARY=<realpath of the running CLI PHP>`. `validate-runtime.php` itself is unchanged by the delta, apart from #67's own `runtime.php_cli_binary` probe.

**Red, then green.**

- **Red:** I ran the pre-repair test file (from `0b88d78`) against the `5513e3a` validator. `test_valid_configuration_installs_fresh_read_only_environment_after_real_admission` fails (1 failure, rc 1).
- **Green:** with the repair, the file passes (3 tests OK).

**The repair does not weaken the validator.** I called the validator directly:

- The fixture's base environment passes.
- A candidate with no `VASEY_PHP_CLI_BINARY`, with `/bin/true`, or with a relative `php` fails exactly `runtime.php_cli_binary`.
- The fixture's unsafe cases are still refused for their own reasons, never for the binary:
  - `PRODUCTION_CHECKOUT_ENABLED` → `runtime.production_fresh_checkout_enabled`
  - key rotation → `runtime.key_custody`
  - `APP_DEBUG=true` → `runtime.unique_keys`
- The `APP_DEBUG` case is refused as a duplicate key because the fixture appends a second `APP_DEBUG` line. That is pre-existing test design on both sides and is not touched by the delta.

### 4. No `app/` change beyond the merge

Evidence: `evidence/merge-app-delta.txt`.

- `git diff 56efbb7 5513e3a -- app config routes bootstrap database resources` is byte-identical to `git diff e5e500ca b9cb7b67` over the same paths (#67):
  - `IsolatedContractRenderer`
  - `AppServiceProvider` (bindings for the PHP CLI binary and the renderer factory)
  - `PhpCliBinary`, `PhpCliBinaryUnavailable`, `PhpCliProcess`
  - `config/app.php` (`php_cli_binary`)
- None of the 40 `app/` files that B2 changed is touched: all have the same sha256 at `56efbb7` and `5513e3a`.
- #67 touches no environment gate, MFA wiring or Stripe path.

### 5. B2's guarantees still hold after the M-16 merge

All runs were on the `5513e3a` export (see the runs table).

- B2's staging tests are green, including the Paid252 staging refusal and the exhaustive census.
- `StagingTestCommerceProfileJourneyTest`, `TestCommerceProfileValidatorTest`, `StagingRuntimeValidatorTest` and `ProductionFreeGrantFrozenBytesTest` are green. The frozen-bytes test is now 3/3; it was 1 failure on `main` and at `56efbb7`.
- My original probes give the same outcomes as at `56efbb7`:
  - **Helper matrix:** exact match; the near-miss names are refused.
  - **`AdminMultiFactor`:** refuses an unenrolled admin in staging and in production.
  - **`TestPaymentExceptionOperations`:** refuses an unenrolled admin in staging.
  - **D1:** the full chain runs under staging, and staging refuses test-only scan evidence.
- **C2 is unchanged, as expected:** `PromotionAdministration::create` and `ManageRightsScope::register` still complete for an unenrolled admin below the panel in staging. It remains a documented follow-up (B2 README "Independent review").
- **Ops:** all 13 `tests/ops/test_*.py` files run separately give 74 tests OK, and the preflight self-test gives 20 OK. My 19-case preflight probe found 0 unexpected results.

### 6. Branch records

- The 39 files of my original review that `3b36a0a` committed under `independent-review/` are byte-identical to my originals in `/home/user/rv-b2`.
- The README's new results rows are consistent with the evidence files they cite. Its final 124-file selection at `0b88d78` reports 1,965 tests, 0 failures and 33 skips. I read those files but **did not re-run that selection**. My runs here are the targeted set below.

## Runs

All runs are on the export of `5513e3a` unless noted. Raw output with the command and rc is in `evidence/`.

| # | Run | Result | rc | Evidence |
| --- | --- | --- | --- | --- |
| 1 | `StagingEnvironmentAdmissionTest` | OK, 15 tests, 213 assertions | 0 | `run-StagingEnvironmentAdmissionTest.txt` |
| 2 | `StagingOperatorMfaTest` | OK, 3 tests, 16 assertions | 0 | `run-StagingOperatorMfaTest.txt` |
| 3 | `RightsScopeCommandTest` | OK, 10 tests, 223 assertions | 0 | `run-RightsScopeCommandTest.txt` |
| 4 | `StagingTestCommerceProfileJourneyTest` | OK, 9 tests, 249 assertions | 0 | `run-StagingTestCommerceProfileJourneyTest.txt` |
| 5 | `TestCommerceProfileValidatorTest` | OK, 47 tests, 454 assertions | 0 | `run-TestCommerceProfileValidatorTest.txt` |
| 6 | `StagingRuntimeValidatorTest` | OK, 17 tests, 79 assertions | 0 | `run-StagingRuntimeValidatorTest.txt` |
| 7 | `ProductionFreeGrantFrozenBytesTest` | OK, 3 tests, 103 assertions | 0 | `run-ProductionFreeGrantFrozenBytesTest.txt` |
| 8 | Reviewer probes: helper, MFA and staff writes | OK, 4 tests, 35 assertions; same outcomes as at `56efbb7` | 0 | `probe-staging-run.txt` |
| 9 | Reviewer probe: D1 under staging | OK, 2 tests, 5 assertions | 0 | `probe-d1-run.txt` |
| 10 | `tests/ops/test_*.py` (13 files, run separately) + preflight self-test | 74 + 20 tests OK | 0 | `ops-python-tests.txt` |
| 11 | Reviewer preflight probe, 19 cases | 0 unexpected | 0 | `probe-preflight.txt` |
| 12 | Reviewer C1 configure probe | OK, 5 tests | 0 | `probe-c1-configure-run-1.txt` |
| 13 | Red: the pre-`5ad7546` ops fixture against the `5513e3a` validator | 1 failure (the valid-configuration test) | 1 | `ops-fixture-red-before-5ad7546.txt` |
| 14 | Real route cache: `local`-built vs the C1 sequence under `staging` | absent, then present; host check prints 1 | 0 | `probe-route-cache-real-5513e3a.txt` |

## Not tested

- MySQL: native and race cases skip on SQLite.
- Browser specs.
- A real staging host, `ctl refresh` end to end with root ownership, and PHP-FPM.
- Real Stripe and ClamAV.
- The full 124-file affected selection at `5513e3a`; I relied on the branch's recorded run at `0b88d78`, as stated in finding 6.
