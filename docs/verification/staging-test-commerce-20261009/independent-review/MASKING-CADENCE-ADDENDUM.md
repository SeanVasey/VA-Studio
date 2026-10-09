# Independent repair addendum: PR #62 file authority and reconciliation cadence

**Decision: APPROVE for the authorized focused development merge at `8f3d1cef024cabb6044f86c45f75ccb6fdc00550`.**

This addendum reviews the functional repairs after the published candidate `12d73bcb`, carrying the earlier sensitive review at `f592ec93890fdf95be8b05b3d4fb5ccb5e706ddf` only where source remains equivalent. The reviewer read `AGENTS.md`, README validation guidance, the previous [decision](DECISION.md) and [candidate addendum](CANDIDATE-ADDENDUM.md), D-14 through D-16 timing/payment contracts, the actual validator, runner, service/timer, revised walkthrough, canonical regressions and delegated payment/finalization services. The reviewer wrote only this addendum and new independent probe/receipt files; no application source, canonical test, historical driver/receipt, commit or remote ref was changed.

## Findings and resolution

1. **Safe inherited inputs could hide an unsafe profile.** Before the repair, Laravel's ordinary exported-variable precedence let a valid exported `APP_ENV`, debug flag, account, key, origin or production flag mask an invalid value in the file under review. It could also satisfy all preliminary checks and authorize the optional account GET using inherited settings. The current validator removes inherited inputs from `getenv()`, `$_ENV` and `$_SERVER` before application boot, preserving only `PATH`, then installs its absent configuration-cache override. It uses the copied profile as its sole application-input source. Independent probes exercise each adapter separately, rather than relying on the CLI's usual population of multiple adapters. All eleven invalid-file masking cases per adapter fail their intended real policy check and refuse the optional account probe. Local file interpolation wins over inherited values; unsafe or unresolved inherited interpolation cannot repair the file. Foreign PHP configuration caches remain unexecuted.

   This **supersedes** the earlier review's exported-variable precedence contract. Its original validator driver and receipts remain historical evidence; cases expecting exported debug/production/live settings to win no longer describe the current validator. A valid file now passes despite deliberately invalid inherited application values. The validator certifies that file, not the active host environment or an old cache. The revised walkthrough explicitly requires deploy, cache and workers to use the same file without shell overrides. This boundary is material to the approval.

2. **The default reconciliation interval equaled the payment eligibility window.** The original 900-second minimum could skip a session read unpaid just before payment until its 15-minute quote had expired. The actual runner default and systemd service now both use 60 seconds. The independent runner proof observes a reconciliation call with a stamp aged 61 seconds, skips a fresh stamp, preserves an explicit 900-second custom setting and honors an explicit zero. The timer remains `OnUnitInactiveSec=60s`. None of these changes alters the domain's payment or grant authority.

   The domain retains strict `confirmed_at < original attempt expiry`. `VerifyTestPayment` stamps the application's verified observation time; `FinalizeTestPayment` and `ReadFinalization` preserve the cutoff and issue no grant on a late observation. The two new real-command journey cases independently pass on SQLite: unpaid read, payment at +30 seconds and observation at +61 seconds produces a paid order and one grant; payment at +850 seconds first observed at +901 seconds produces a paid exception and no grant. These cases use the existing synthetic Stripe gateway and renderer.

No unresolved blocking finding was identified in this repair delta.

## Actual independent execution

All commands ran in `/workspace/VA-Studio-commerce` at the exact reviewed head on 2026-10-09. The new driver asserts the 40-character head before its first probe. Genuine `/workspace/.va-studio-toolchain/standalone/bin/php8.4` reports PHP **8.4.26**, SAPI **cli**. Its child processes receive only `PATH` before the driver deliberately injects the selected adapter values. No profile value or private scratch path is emitted in the retained probe receipt.

```sh
python3 docs/verification/staging-test-commerce-20261009/independent-review/validator-masking-cadence-probe.py
```

Actual exit **0**; [validator-masking-cadence-green.txt](validator-masking-cadence-green.txt) ends:

```text
RESULT: 55 independent masking/interpolation/cache/privacy/cadence checks passed; no provider request attempted
```

The 55 checks comprise one complete 35-policy baseline, 33 invalid-file masks across the three adapters, three valid-file/invalid-inherited-input cases, nine interpolation cases, four foreign-cache cases, four actual-runner cadence cases and one inspected service/timer configuration check. Every deliberately unsafe profile supplied with probe arguments prints `Probe not attempted` and fails before the account GET; valid profiles never receive probe arguments. The runner cases use an isolated scripted artisan stand-in and real wall-clock stamps, with no clock replacement or one-minute sleep.

```sh
/workspace/.va-studio-toolchain/standalone/bin/php8.4 -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --filter '(test_unpaid_read_just_before_payment_is_reconciled_one_minute_later_within_the_quote_window|test_payment_before_expiry_observed_after_expiry_remains_a_paid_exception)' tests/Feature/StagingTestCommerceProfileJourneyTest.php
```

Actual exit **0**; [observation-boundary-sqlite.txt](observation-boundary-sqlite.txt) reports **2 tests / 48 assertions**, no reported failure or skip. `git diff --check` exits **0**. The following exact source comparison prints no changed path and exits **0**, supporting the assessment that domain services, renderer pins and CI policy remain unchanged from the earlier sensitive source:

```sh
git diff --name-only f592ec93890fdf95be8b05b3d4fb5ccb5e706ddf..8f3d1cef024cabb6044f86c45f75ccb6fdc00550 -- app resources/contracts .github
```

The reviewer also inspected the implementer's retained canonical red receipt `../masking-cadence-red-corrected.txt`: **8 tests / 15 assertions / 8 failures** before the repair, with the intended masked policy check asserted. Its current focused green receipt is **8 tests / 71 assertions**. The completed current three-suite SQLite receipt `../masking-cadence-sqlite.txt` reports **73 tests / 783 assertions**, no reported failure or skip. These are inspected implementer runs, not additional independent suite executions. This decision makes no claim about a still-pending current native MySQL run.

## Limits and merge conditions

One minute is a configured minimum between eligible reconciliation sweeps, not a guaranteed observation deadline. Timer scheduling waits for the previous sweep to finish; receipt work, provider latency, bounded paging, locks, backlogs and payment near expiry can still make confirmation first visible after expiry. The revised timing guidance states this limitation and preserves the paid-exception behavior. Abandoned/expired drill orders still retain reservations and remain eligible for repeated reconciliation observations; no automatic resource release or production late-payment policy is introduced here.

The adapter probes boot the actual policy classes but contact no provider. The runner stand-in proves cadence selection, not Stripe latency or domain effects. The two synthetic journeys prove composition of the real domain commands, not real Stripe interoperability, real PDF rendering, MySQL concurrency, systemd installation or host deployment. Forge/VPS access, actual account and secure secret-channel setup, catalog/rights/media, seller/assent and private host/TLS inputs remain external acceptance requirements. Full integrated Foundation verification and Sean's first real Stripe TEST purchase remain outstanding.

This approval applies to the exact functional source reviewed above, with the repository's cheap preflight and expected-head merge control still required. An evidence-only successor needs an explicit equivalence check; another functional successor requires review of its changed behavior.
