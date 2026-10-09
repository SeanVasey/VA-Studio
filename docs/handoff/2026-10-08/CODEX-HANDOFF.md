# Handoff from the Claude harness to Codex (2026-10-08, 23:30 UTC)

Claude ran out of usage, so Codex takes over. Everything below was checked against GitHub and the branches at that time. Read this file first, then:
- `docs/handoff/2026-10-08/MONDAY-PLAN.md`, the live Monday tracker (§1 targets, §3 work items, §4 Sean's inputs);
- `docs/handoff/2026-10-07/CLAUDE-PLAN.md`, the long plan and the merge ledger;
- `AGENTS.md`, for the CI cost policy, invariants and release boundary.

**The goal for Monday** is a private staging site in Stripe **TEST** mode, on Laravel Forge plus a VPS:
- a staging subdomain with TLS and basic auth;
- the Stripe webhook route signature-checked and exempt from basic auth;
- interim `APP_ENV=local` with `APP_DEBUG=false`, until the `staging` environment lane lands.

On it, Sean will upload and publish 3–10 real tracks, buy one with a test card, receive the test contract and files, and see a BeatStars dry-run report. Not in scope: live payments, DNS cutover, customer import, production deploy.

## 1. Open PRs (all `SeanVasey/VA-Studio`)

| PR | Branch / head | State | Next step |
| --- | --- | --- | --- |
| [#56](https://github.com/SeanVasey/VA-Studio/pull/56) Paid252 paid downloads (default-off, unmounted) | `harness/paid252-composition` @ `27a151b6` | CI green; Codex reviewed `27a151b6` with no findings; Codex rounds 1–23 all answered. One thread is deliberately left open for Sean (`PaidGrantTransfer.php:35`, slow-client slot hold, covered by C11). Independent review addenda 1–14: APPROVE WITH CONDITIONS. | Write **addendum 15** for the round 23 delta (`55976099..27a151b6`): `PaidGrantRequestInstant` trusted type plus C11 (k) in `docs/ops/paid-delivery-runtime.md`. A stopped draft probe sits in Claude's container and is lost. Then merge with `expectedHeadSha` and add a ledger row. |
| [#60](https://github.com/SeanVasey/VA-Studio/pull/60) SQLite census normalization (+33 pairs, 183 → 216) | `harness/census-normalization` @ `247ee297` | CI green; Codex no findings on the final head; threads resolved. | Merge **after #56**. #56 adds one pair (`PaidGrantSchemaRecoveryTest`). Resolve `scripts/ci/database-sqlite-skips.json` as the union (keep append order) and re-run `python3 -I scripts/ci/test-database-receipts.py`. |
| [#62](https://github.com/SeanVasey/VA-Studio/pull/62) Staging test-commerce profile, validator, pipeline runner, walkthrough | `harness/staging-test-commerce` @ `ddb1fcc1` | Draft; CI green. The independent payment review was interrupted with no decision. | Run the payment/authorization independent review (scope listed in the PR body), record the decision, mark ready, clear Codex, merge. |
| [#63](https://github.com/SeanVasey/VA-Studio/pull/63) Forge staging deploy kit + two ops doc fixes | `harness/staging-ops` @ `c7860985` | Draft; CI green. The security review was interrupted with no decision. | Security review: the nginx basic-auth exemption, the sudo helper `vasey-staging-ctl`, secrets modes, `backup.sh`, the deploy `.env` validation. Then ready, Codex, merge. |

Merged today, all recorded in the CLAUDE-PLAN ledger: #44 (`672f5826`), #54, #57, #58, #59 and #61 (`0d941d20`; tracker and doc reconciliation).

## 2. Unfinished branches (pushed as WIP; no PR yet; treat as unverified)

| Branch @ head | Work item | State at the stop | To finish |
| --- | --- | --- | --- |
| `harness/rights-scope-command` @ `732d640e` | **D1, which blocks Monday.** `PrepareOrder` needs every offer revision linked to a rights scope (`ReserveQuoteInventory.php:59`, `INVENTORY_SCOPE_UNAVAILABLE`), but `ManageRightsScope` has no caller: no Filament screen, no command. So every Studio-published offer fails at checkout. | `app/Console/Commands/ManageRightsScopes.php` and `tests/Feature/RightsScopeCommandTest.php` written; the run of Pint and the suites was interrupted. | Run the tests (red first: an unlinked revision gives 409; linked, `PrepareOrder` succeeds; authority refusal; invalid ids; idempotent repeat). Update `docs/shared-rights-inventory.md` and the CHANGELOG. Independent review (rights/authorization), then PR and merge. **Until it merges**, the interim path in `docs/ops/staging-test-purchase.md` (#62) is one `php artisan tinker` call per revision to `ManageRightsScope::register`/`link`. |
| `harness/php-cli-resolver` @ `53f8ef60` | **M-16.** Under PHP-FPM, `PHP_BINARY` is `/usr/sbin/php-fpm8.4`; spawning it exits 64, so free-grant renders fail (`render_failed`). | `app/Support/PhpCliBinary.php` resolver (`VASEY_PHP_CLI_BINARY`; validates realpath, executable, and that the child reports `cli` plus the same `PHP_VERSION`; never `PhpExecutableFinder`). `IsolatedContractRenderer` is wired. | Convert the spawn sites `FreeGrantRendererProcess` (≈61, 81) and `ProductionFreeGrantRendererProcess` (≈61, 81); check each edit against `resources/contracts/*/profile-assets.json` pins. Finish the tests and an FPM smoke test, then review and PR. **After #56 merges**, apply the same change to `PaidGrantRendererProcess` (≈60, 80). Not Monday-critical: test contracts render in the CLI `contracts` queue worker, and free grants stay off. |
| `harness/staging-env-admission` @ `2ec1c559` | **Lane B2 / M-06.** A reviewed `staging` environment that admits test commerce, requires staff TOTP MFA (`AdminPanelProvider.php:77` is production-only today) and refuses live mode. | 56 files changed across the commerce policies plus `tests/Feature/StagingEnvironmentAdmissionTest.php`; interrupted mid doc edits. | Run the whole commerce and auth suites, finish the docs, then independent review (auth/payment), PR and merge. Staging then switches from `APP_ENV=local` to `staging`. Not needed for Monday: the interim local mode is accepted by Sean (S-1). |
| `harness/embed-order-leak` @ `9b51c641` (base `d3e1c39a`) | `PublicTrackEmbedTest` fails 4 cases when run after Livewire tests in one process (it passes alone). | Root cause: Livewire's static `SupportAutoInjectedAssets` state leaks across tests and injects `<script>`/`<style>` into the script-free embed. Candidate fix: `Livewire::flushState()` in `tests/TestCase.php::setUp()`. | Prove the fix: an ordered run with a Livewire test before `PublicTrackEmbedTest` fails without it and passes with it. Rebase onto main, PR. This must land before the final Foundation run. |

## 3. Facts found today that are not yet in code

- **D2:** an abandoned unpaid order keeps its offer revision reserved, so the next buyer gets `INVENTORY_UNAVAILABLE`. Mitigation: dedicated drill offers, or review and enable unpaid release.
- **D3:** `vasey:reconcile-test-payments` re-checks abandoned sessions forever (one Stripe GET plus one observation row per sweep). The runner throttles it to every 15 min.
- **D4:** `vasey:stripe-preflight` covers production checkout only. The #62 validator's `--probe` does the test-mode account read.
- **D5:** the quote lives 15 min, so payment must finish within about 10 min of the review; later payment becomes `paid_exception` (no grant).
- **Ops:** non-root MySQL triggers need `SET PERSIST log_bin_trust_function_creators=ON` (deprecated in 8.4) with binlog on; `DB_HOST` must be `127.0.0.1` (the trigger definer); 567 triggers. Private storage must be bind-mounted: `realpath` checks reject a symlinked `storage`.
- **Harness only:** in worktrees whose `vendor/` is symlinked, contract-renderer tests fail with "Test contract issuance is unavailable". This is `open_basedir`, not a product bug.

## 4. Inputs only Sean can give (MONDAY-PLAN §4)

- S-2 Forge account, VPS and SSH;
- S-3 staging hostname and its DNS A record;
- S-4 Stripe **test** account ID, `sk_test_` key and webhook signing secret, through a secret channel only;
- S-5 a second staff account;
- S-6 the seller preview tag WAV;
- S-7 3–10 tracks, WAV ≤ 200 MiB, artwork ≤ 20 MiB, stems as a ZIP ≤ 200 MiB;
- S-8 license tiers, terms, USD prices, seller legal name (≤ 160 chars) and assent text (≤ 4,000 chars, no `'`);
- S-9 the BeatStars export;
- S-10 a backup destination, encrypted (backups include `.env`).

Open decisions for Sean, from #62:
- D2: drill offers, or unpaid release;
- whether the ~10 min pay window is acceptable;
- test pricing window to 2027-01-01 with zero tax;
- whether test customer accounts stay enabled.

Paid252 conditions before any mount: C2, C4–C8, C11 pre-mount verification including (k). Membership: CP-2, CP-3, P1B-1. C2, the operative 257 credit writer, is **not started** and blocks C4.

## 5. Order of work (fastest path to Monday)

1. **D1 command:** finish, review, merge. This is the only code blocker for a real purchase.
2. **#62 and #63:** reviews, then merge.
3. **#56:** addendum 15, then merge; then **#60** (union), then the embed fix.
4. When Sean supplies S-2 to S-4: follow `docs/ops/staging-runbook.md` (#63), then `docs/ops/staging-test-purchase.md` (#62). Run the synthetic end-to-end (M-07) with drills, then Sean's real-content purchase (M-11).
5. **M-09/M-10:** BeatStars normalizer and dry run once the export (S-9) arrives.
6. **Then:** M-16, B2, and the final Foundation CI on the exact integrated SHA (manual dispatch with `expected_sha`).

## 6. Rules carried over

- **Keep the AGENTS.md CI cost policy.** Use focused local checks and preflight. Do not restore automatic matrices. Foundation runs once on the final SHA.
- **Independent review before merge** for anything touching payment, licensing, rights, authorization, migrations or irreversible data. Merge with `expectedHeadSha`, and add a ledger row in `CLAUDE-PLAN.md` plus a status line in `MONDAY-PLAN.md`.
- **Never:**
  - commit secrets (push protection is on: split synthetic `sk_live_` literals, e.g. `'sk_'.'live_'...`);
  - enable live payment, real money, DNS changes, production deploy or customer import without Sean's explicit authorization;
  - edit files hash-pinned in `resources/contracts/*/profile-assets.json`;
  - weaken tests, guards or audits.
- **Brand:** VASEY.AUDIO only. Never conflate it with VASEY/AI.
- **Local runner** (Artisan's Collision runner is broken here): `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <args>`, with `public/build` absent.
- **Worktrees:** `scripts/dev/mkworktree.sh <path> <rev>`.

## 7. Prompt to start Codex

```text
You are taking over SeanVasey/VA-Studio (VASEY.AUDIO first-party music store; Laravel 13/PHP 8.4, Filament 5, Inertia/React/TS) from the Claude harness. First read, in order: docs/handoff/2026-10-08/CODEX-HANDOFF.md, docs/handoff/2026-10-08/MONDAY-PLAN.md, AGENTS.md, then docs/handoff/2026-10-07/CLAUDE-PLAN.md (ledger). Sean has approved all development work; anything needing his input, credentials, accounts, uploads, live payments, DNS or production deploy must be asked for, never assumed.

Goal by Monday: a private Stripe-TEST staging site on Laravel Forge where Sean publishes real tracks, buys one with a test card and receives the contract and files. Work in this order, one focused PR per item, each with tests run locally (red first for fixes), the AGENTS.md CI cost policy (focused checks + preflight only), an independent review for payment/rights/auth/migration changes, Codex findings resolved, and merge with expectedHeadSha:
1. Finish branch harness/rights-scope-command (D1: operator command linking offer revisions to rights scopes; without it every Studio offer fails PrepareOrder with INVENTORY_SCOPE_UNAVAILABLE). Run its tests, add docs/CHANGELOG, review, open PR, merge.
2. Complete the interrupted independent reviews and merge PR #62 (test-commerce profile/validator/runner) and PR #63 (Forge staging kit).
3. PR #56: write independent review addendum 15 for 55976099..27a151b6, then merge; then merge PR #60 resolving scripts/ci/database-sqlite-skips.json as the union with #56's pair; then prove and land harness/embed-order-leak.
4. Then harness/php-cli-resolver (M-16) and harness/staging-env-admission (B2), each verified and reviewed.
After every merge, add a ledger row to docs/handoff/2026-10-07/CLAUDE-PLAN.md and a status-log line to docs/handoff/2026-10-08/MONDAY-PLAN.md. Report to Sean which inputs (MONDAY-PLAN §4, S-2..S-10) are now blocking, and never put secrets in the repo or chat. Do not claim anything works without command output as evidence.
```
