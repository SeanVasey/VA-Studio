# Independent review: Lane B2, staging environment admission

- **Reviewer:** independent reviewer for authentication and payment/commerce admission (Claude Code subagent). I did not write this code.
- **Date:** 2026-10-09
- **Subject:** branch `harness/staging-env-admission` at `56efbb7a517abc55f17ea9a8b37b29e4970a2210`. I diffed it against `main` `e5e500cadae68958293089ccc2b4a22bcdb9df66` (70 files, +1307/−108).
- **Worktrees:** review `/home/user/rv-b2` (detached at `56efbb7`, tracked tree clean) and red baseline `/home/user/rv-b2-red` (detached at `e5e500ca` with B2's four test files copied in). I changed no product code. Everything under `independent-review/` is untracked.
- **Runtime:** PHP 8.4.26, PHPUnit 12.5.34, SQLite in memory, `public/build` absent. PHPUnit ran through the direct `_composer_autoload_path` invocation (`evidence/pu.sh`).

## Verdict

**APPROVE WITH CONDITIONS** for a development merge of `56efbb7`.

- No finding blocks the code merge.
- The conditions below apply before anyone relies on staging staff MFA on a host. Condition C1 concerns the move from the interim `local` profile to `staging`.

**What this approval does not authorize:**

- production deployment or a production `APP_ENV`
- live payments, live Stripe mode or keys, or real money
- paid-grant (Paid252) operative activation
- production or verified membership, member-grant or membership-billing activation
- production customer identity
- DNS or cutover

Each of these still needs Sean's separate authorization under AGENTS.md "Release boundary". This approval covers only the reviewed commit `56efbb7`. Any later commit needs its own review of the delta.

## Conditions

- **C1 (Medium, ops; required before staging MFA is relied on).** The Filament MFA-required page middleware is compiled into the route cache when the cache is built.
  - My probe (`evidence/probe-route-cache-mfa.txt`) built `route:cache` under `APP_ENV=local` and served it under `APP_ENV=staging`. In that state, `/admin/tracks` lost `EnsureMultiFactorAuthenticationIsEnabled`, while `isMultiFactorAuthenticationRequired()` still returned `true`.
  - `ops/staging/forge-deploy.sh` handles a deploy of the already-served SHA after a Forge `.env` change with `ctl refresh`, and `refresh` → `cmd_configure` runs only `config:cache`.
  - So the documented switch ("change `APP_ENV` in Forge, re-run `provision.sh --app-env staging`") can leave a route cache built under `local`. Page-level MFA enforcement would then be absent in staging, even though `vasey:doctor` and the domain-level `AdminMultiFactor` checks report that MFA is required.
  - **Required:** move from `local` to `staging` through a full release activation (`release-step.sh activate` runs `route:cache` under the new `.env`). Alternatively, make `cmd_configure` also run `route:cache` (or `route:clear`).
  - **Then verify:** run `php artisan route:list --path=admin/tracks --json` on the host and confirm the middleware is present. B2's runbook should state this. The defect is in the ops kit and is pre-existing in kind (production never switches `APP_ENV`), but B2 introduces the transition that triggers it.
- **C2 (Low, follow-up; not merge-blocking).** Two staff-write services that staging now admits do not call `AdminMultiFactor::satisfiedBy` at the domain level. My probe (`evidence/probe-run-1.txt`) recorded both completing for an *unenrolled* admin in staging:
  - `PromotionAdministration` (`create`, `setAvailability`)
  - `ManageRightsScope` (`vasey:rights-scope` writes)

  This matches the production model for most catalog writes. `domain-mfa-census.txt` shows that `PublishOffer`, `PublishLicense`, `SaveTrackMetadata` and others also rely on the panel's route middleware. So it is consistent with production, and both services are refused outright in production.

  The paths that reach them are:
  - the panel, where the page middleware requires enrollment (subject to C1). Livewire snapshots are checksummed, so a page that an unenrolled user never loaded cannot be driven.
  - an interactive host console with a password.

  Recommend adding the domain check, as `TestPaymentExceptionOperations`, `FreeGrantStaff` and `CatalogDraftImporter` already do, in a separate reviewed change.

## Findings by review item

### 1. Admission helper and gate census

- `TestEnvironment::admitsTestCommerce()` uses `in_array(app()->environment(), ['local','testing','staging'], true)`.
- My matrix probe (`probe-run-1.txt`, `test_probe_helper_exact_match_semantics`) recorded the following. `Staging`, `STAGING`, `staging-eu`, `stage`, `" staging"`, `"staging "`, `"staging\n"`, `""` and `preview` are each refused for test commerce and for `refusesProductionOnly`. `staging` admits test commerce, requires MFA and refuses production-only paths. `production` requires MFA and refuses test commerce.
- `testing` is admitted by the helper, as it is on `main`. On a host, the ops validators refuse it: `validate-test-commerce-profile.php` accepts only `staging|local`, and `--profile=staging` requires exactly `staging`.
- **My own grep** (`evidence/reviewer-grep-app.txt`, 100 lines) searched `app/`, `config/`, `routes/`, `bootstrap/` and `database/seeders` for:
  - `environment(`, `isProduction`, `isLocal`, `config('app.env'`, `env('APP_ENV'`, `runningUnitTests`
  - quoted environment names and reversed `'testing','local'` lists

  Every hit falls into one of the README's classes (a)–(d):
  - **(a)** All 29 files route through the helper, and none keeps a local/testing literal.
  - **(b)** Local/testing only.
  - **Testing-only scanners and transports.** These are unaffected by staging.
  - **(c)** `environment('production')` gates, which refuse staging, plus the changed production-only branches.
  - **(d)** Environment values captured as evidence.
- Nothing else admits staging. The only file that names `'staging'` is the helper (enforced by the census test).
- **Census strength.**
  - The census regex covers `environment('local', 'testing')`, `environment(['local','testing'])` and `in_array($x, ['local','testing'], true)`. Requiring every match in `app/` to be classified is exhaustive for that idiom. It is how the Paid252 gap was found.
  - It does not cover environment-free operative lanes. I checked those by hand:
    - **Free grants operative:** needs a bound `FreeGrantIdentity`, and nothing binds one.
    - **Paid, production memberships, member grants, membership billing, production free grants and production identity:** each `enabled` value is hard-coded `false` in `config/`, with no `env()`.
    - **Production account features, preferences, listening and suppression:** derive from `IdentityPolicy`, which staging refuses.
  - **Pre-existing, unchanged:** any non-`production`, non-`staging` name, such as `preview` or `Staging`, gets no required MFA. Fail-closed MFA (required unless `local`/`testing`) would be stricter. This is informational, not a B2 regression.
- **Pre-existing:** `php artisan … --env=staging` on any host switches the CLI environment, exactly as `--env=local` already could. Staging is not weaker than `local` here.

### 2. Staff MFA

- `AdminPanelProvider` makes MFA required when `TestEnvironment::requiresStaffMfa()` returns true. `AdminMultiFactor::satisfiedBy` evaluates the panel closure at call time.
- Probe results (`probe-run-1.txt`):
  - `satisfiedBy(unenrolled)` returns false in staging and in production, and true in local and testing.
  - `satisfiedBy(enrolled)` returns true everywhere.
- **Real staff domain action,** `TestPaymentExceptionOperations::locked`:
  - staging, unenrolled admin → `AuthorizationException`
  - staging, enrolled admin → passes authority and reaches the record lookup (`ModelNotFoundException` on a random ID)
  - production → unchanged
- **CLI (`vasey:rights-scope`):** requires an interactive console and the staff password; it does not require MFA. This matches the other staff CLI and domain paths that lack a domain MFA check (see C2). It is acceptable for development because shell access to the host is a stronger credential. It does not match production, because production refuses the command entirely.
- **Page redirect:** B2's `StagingOperatorMfaTest` boots under `staging` and proves the redirect to MFA setup. It also proves `local` is optional and the per-environment matrix. My red reproduction showed 2 failures on `main`.

### 3. Live-mode and production-only refusal

**Refused in staging** (B2 tests, which I reproduced, plus my preflight probe):

- **Stripe live mode or absent mode:** all 9 Stripe gates.
- **Keys:** `sk_live_`, `rk_test_`, `rk_live_` and `pk_test_` at the SDK gateway; no transport request is sent.
- **Webhooks:** a signed `livemode:true` event is rejected with `STRIPE_WEBHOOK_INVALID`.
- **`ExecutionContextV1`:** live funds and test funds.
- **`StripeCapabilityPreflight`:** `funds_mode_environment` is `blocked`.
- **Production identity:** `IdentityPolicy` (production and rehearsal) and the `SmtpIdentitySettings` production branch.
- **Membership provenance:** `Production\MembershipPolicy` and `MemberGrantPolicy` refuse verified-production provenance with reason `provenance`.
- **Paid252:** `PaidGrantPolicy::enabled()` and `provePure()` (403).

**No regression:**

- **Production:** live funds, production identity, production membership and member grant, the operative paid lane and `provePure`, all asserted `admitted` in production.
- **Local/testing:** rehearsal paths are unchanged.
- **Production doctor:** output differs only by the added `profile` key, and no consumer parses keys strictly (`Doctor.php` reads `foundation_ready`).

My red run of B2's admission test on `main` product code (`red-main-admission.txt`) shows that `main` admitted production identity, the operative paid lane and verified-production member provenance under `staging`. B2 closes real exposures, not hypothetical ones.

### 4. Free256 frozen pin (`ActivationPolicy.php`)

All evidence is in `evidence/free256-pin-review.txt`.

- **The change:** the byte change is the `use TestEnvironment;` import plus the one `account()` environment condition.
- **`outsideTransactions()`:** byte-identical to `main`.
- **Family 256 reach:** family 256 (`app/Domain/Grants/ProductionFree/*`) does not reference `ActivationPolicy` directly. It reaches it only through `PreparedDeliveryStream` and `DeliveryAssetFiles`, which call only `outsideTransactions()`. Their pinned hashes are unchanged and equal to `main`.
- **Imports:** PHP `use` imports do not autoload, so the new import adds no load-time dependency on the 256 paths.
- **The new pin:** `38d51e78…6c95`, which equals the file's actual sha256 at `56efbb7`.
- **`AdminMultiFactor.php` pin:** unchanged and passing.
- **Ruling: the re-pin is approved against family 256.**

### 5. Ops and profile

- **Python tests:** all 13 `tests/ops/test_*.py` files run individually give 74 tests, all OK. The preflight self-test gives 20 tests, OK (`ops-python-tests.txt`).
- **My independent preflight probe** (`probe-preflight.txt`, 19 cases, 0 unexpected):
  - A valid staging file passes.
  - Each of these is BLOCKED by the named check:
    - `APP_ENV` set to `Staging`, `local` or `testing`
    - `STRIPE_MODE=live`
    - an `sk_live_` test key
    - `rk_live_` in an unrelated variable
    - an `rk_test_` key
    - funds mode `live` or `test`
    - a non-boolean switch
    - `APP_DEBUG=true`
  - A staging file under the default profile is blocked.
  - The production default and an explicit production profile are unchanged.
  - Duplicate or unknown `--profile` flags and a repeated `--runtime` are rejected as usage errors.
- **Other ops changes:**
  - `validate-runtime.php` accepts `local|staging`.
  - `provision.sh` defaults to `staging` and validates `^(local|staging)$`.
  - `InstallationReport` adds staging rows for hosted settings and test mode only. They are covered by `InstallationReportTest`, which passed in my selection.
- **Minor inconsistency, harmless:** doctor's `staging_test_mode_only` tolerates `PRODUCTION_CHECKOUT_FUNDS_MODE=test`, while the preflight requires it blank. `ExecutionContextV1` refuses it either way.

### 6. D1 and `PrepareOrder` under staging (ClamAV stand-in)

The stand-in hides nothing material. My `probe-d1-run-1.txt` showed:

- **Without the stand-in,** staging refuses the test-only scan evidence. Both `CreateQuote` and the rights-scope link fail with `SELECTION_CHANGED` (409). Staging does not silently accept the synthetic scanner.
- **With the stand-in,** the whole chain runs under `staging`: `CreateQuote`, `PriceQuote`, the unlinked `PrepareOrder` (refused with `INVENTORY_SCOPE_UNAVAILABLE` 409), register, link, and `PrepareOrder` (prepared). This includes quote creation and pricing. B2's own variant runs those two under `testing`, which is a small coverage gap.

The stand-in replaces only the scanner's output at fixture time. Staging admission depends on the engine name, exactly as production does. Real ClamAV execution is outside this lane.

### 7. Red and green reproduction, and affected suites

See the runs table. The recorded red reproduces exactly on `main` (same failures and counts). B2's tests are green at `56efbb7`.

## Other observations (informational)

- B2's README does not record the inherited `main` failure in `ProductionFreeGrantFrozenBytesTest` (see Runs).
- The README's results table still has `FINAL_PLACEHOLDER` for the final affected selection, and `evidence/final-*.txt` does not exist. The author should fill it in or point it at this review's runs before merge.
- Historical evidence hash records under `docs/verification/*/` (for example `native-fixture-independent-20261007/reviewed-source-hashes.json`) list files that B2 changes. No script, CI job or test enforces them, and they bind to their own recorded commits, so they are historical and not contradicted.
- `StagingTestCommerceProfileJourneyTest` runs its chain under `testing`, with `APP_ENV=staging` only in the profile file. Staging-specific admission is proven by `StagingEnvironmentAdmissionTest`, my D1 probe and the profile validator.

## Runs

All of these runs are SQLite in memory, `public/build` absent, PHPUnit 12.5.34. Raw output, the command, the source SHA and `rc` for each run are in `evidence/`.

| # | Run | Source | Result | rc | Evidence |
| --- | --- | --- | --- | --- | --- |
| 1 | Red: `StagingOperatorMfaTest` | `main` `e5e500ca` + B2 tests | 3 tests, 2 failures (same as recorded) | 1 | `red-main-mfa.txt` |
| 2 | Red: D1 `--filter staging_admits` | same | 1 failure, `ORDER_POLICY_UNAVAILABLE` (same as recorded) | 1 | `red-main-d1.txt` |
| 3 | Red: profile `--filter staging_profile_passes` | same | 1 failure (same as recorded) | 1 | `red-main-profile.txt` |
| 4 | Red: `StagingEnvironmentAdmissionTest` | same | 15 tests, 1 error (helper absent), 6 failures; `main` admits production identity, the operative paid lane and verified member provenance in staging | 2 | `red-main-admission.txt` |
| 5 | Green: `StagingEnvironmentAdmissionTest` + `StagingOperatorMfaTest` | `56efbb7` | OK, 18 tests, 229 assertions | 0 | `green-b2-own-tests.txt` |
| 6 | Reviewer probes: helper, MFA and staff writes | `56efbb7` | OK, 4 tests, 35 assertions; outcomes recorded (C2) | 0 | `probe-run-1.txt`, `probes/ReviewerStagingProbeTest.php` |
| 7 | Reviewer probe: D1 under staging | `56efbb7` | OK, 2 tests, 5 assertions | 0 | `probe-d1-run-1.txt`, `probes/ReviewerD1StagingProbeTest.php` |
| 8 | Reviewer probe: preflight, 19 near-miss files | `56efbb7` | 0 unexpected | 0 | `probe-preflight.txt`, `probes/reviewer_preflight_probe.py` |
| 9 | Reviewer probe: route cache vs MFA middleware | `56efbb7` | middleware absent when the cache is built under `local` (C1); cache cleared afterwards | 0 | `probe-route-cache-mfa.txt` |
| 10 | Ops: `tests/ops/test_*.py` (13 files, each run separately) + preflight self-test | `56efbb7` | 74 + 20 tests OK | 0 | `ops-python-tests.txt` |
| 11 | Affected selection (115 files that reference any changed `app/` class, plus the staging, MFA, checkout, order-prep, webhook and frozen-bytes files), 4 shards | `56efbb7` | 1,848 tests, 16,598 assertions; 33 skips (native MySQL, isolated DB or POSIX only); **1 failure** (see below) | 1/0/0/0 | `affected-shard-{1..4}.txt`, `affected-selection.txt`, `shard-configs/` |
| 12 | `ProductionFreeGrantFrozenBytesTest` on `main` | `e5e500ca` | 3 tests, 1 failure, the same `app/Domain/Grants/Paid` directory assertion | 1 | `main-baseline-frozen-bytes.txt` |
| 13 | `ProductionFreeGrantFrozenBytesTest` at B2 | `56efbb7` | 3 tests, 1 failure (same); the re-pinned bytes test passes | 1 | `green-frozen-bytes.txt` |
| — | Aborted attempt 1 of run 11 | `56efbb7` | **Not counted.** My route-cache probe briefly wrote `bootstrap/cache/routes-v7.php` in the same worktree while it ran. | — | `failed-harness-attempts/` |

**The one failure in run 11 was already on `main`.**

- It is `ProductionFreeGrantFrozenBytesTest::test_no_paid_lane_or_old_free_family_file_is_copied_or_imported`, which asserts that `app/Domain/Grants/Paid` does not exist.
- That directory arrived on `main` with #56 (Paid252). Run 12 shows the identical failure on `e5e500ca`, so B2 neither causes nor fixes it.
- B2's README results row ("only failure the Free256 `ActivationPolicy` pin") predates its #56 integration and does not mention this. The README should record it as an inherited `main` failure that belongs to the Free256/Paid252 owners. This is a documentation gap, not a B2 defect.


## Not tested

- MySQL: race and native cases skip on SQLite by design. CI, or a private native `mysqld`, is the evidence for those.
- Browser specs.
- A real staging host, including its actual route cache (see C1).
- Real Stripe, and real ClamAV.
