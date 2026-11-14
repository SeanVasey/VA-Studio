# Independent review — O3 production capability hold at dccc5852

- **Reviewed commit:** `dccc585251873fa21051f73f3cba033ae30ffd6e` (branch `harness/production-capability-hold-20261018`, worktree `/home/user/wt/o3`)
- **Base:** `origin/main` `6c28d3a32187ed8851445263fd8744379926ab35` (merge-base confirmed)
- **Scope reviewed:** `git diff origin/main...dccc5852`: 8 files, +147/−18 (`ops/production/validate-runtime.php`, `tests/ops/test_production_runtime_validator.py`, `ops/production/README.md`, `ops/production/env.production.example`, `docs/ops/production-activation-packet.md`, `CHANGELOG.md`, `docs/handoff/2026-10-07/CLAUDE-PLAN.md`, `docs/handoff/2026-10-17/RESUMPTION.md`)
- **Reviewer:** independent Claude subagent. It did not write the change. It made no edits, commits, pushes or GitHub comments.
- **Decision: APPROVE.** No P0 or P1. One P2 and five P3 findings, all deferrable under the freeze-and-batch rule.

## Summary

The hold is correct and fails closed in both layers.

- **File-level layer.** `validate-runtime.php:100-104` parses with the same phpdotenv that Laravel uses. It accepts only `false` or `(false)`, case-insensitive and quoted or bare, or an unset key.
- **Effective-configuration layer.** `validate-runtime.php:193-195` requires `config($key) === false`, so a renamed or removed key fails closed.

Neither layer prints a value. The four config keys are the keys the application reads:

| Config key | Read by |
| --- | --- |
| `free-grants.operative_enabled` | `FreeGrantPolicy.php:12,36` |
| `inquiries.operator_notifications_enabled` | `InquiryNotificationWork.php:53` |
| `discovery-sitemap.enabled` | `PublicDiscoveryController.php:16`, `SitemapConfiguration.php:28,33` |
| `customer-listening.v2_promotion_enabled` | `ListeningRollout.php:10-13` |

Every consumer requires `=== true`. Under `env -i`, the validator's effective view matches what `config:cache` writes. FPM and the workers read the config cache: the FPM pool sets `clear_env = yes`, and every artisan call runs under `env -i`.

Every path that installs a production `.env` runs the validator:

- `forge-deploy.sh`: the pre-build `runtime_gate` (on the served release), then `ctl prepare` → `release-step.sh build`.
- the refresh path: `runtime_gate`, then `ctl refresh` → `cmd_configure`.
- `ctl prepare` and `ctl refresh` invoked directly through sudo.

No other code path enables the four capabilities. No `FreeGrantIdentity` or `InquiryAlertTransport` binding exists in `app/`, `bootstrap/`, `config/` or `routes/`.

## Findings

| ID | Sev | File:line | Finding | Evidence | Suggested fix |
| --- | --- | --- | --- | --- | --- |
| R1 | P2 | `ops/production/validate-runtime.php:37-42`; `docs/ops/production-activation-packet.md:40-41`; `CHANGELOG.md:13` | **The rationale overstates the listening flag's exposure.** These places say none of the four is environment-gated and that the validator is "their only guard" on production. That is not true of `VASEY_LISTENING_V2_PROMOTION_ENABLED`. Its only consumer, the legacy `ListeningLibrary` behind `/account/listening-library`, needs a `customer`-guard principal. Only `CustomerSessionController::store` creates one, and it is gated by `CustomerAccessPolicy` → `TestEnvironment::admitsTestCommerce()`, which admits `local`, `testing` and `staging` only. The production listening lane, `ProductionListeningRollout`, does not read this flag at all: it reads the literal `false` in `config/production-customer-listening.php`. The hold is still harmless defence in depth, and the docs overstate risk rather than protection, so this is not a false claim of protection. It is still an inaccurate current claim under CLAUDE.md §1.4. | `CustomerAccessPolicy.php:11`; `TestEnvironment.php:20,27`; `CustomerSessionController.php:38,68`; `CommerceRequestIdentity.php:36-49`; `ProductionListeningRollout.php:14`; `config/production-customer-listening.php:4`; the only place that writes `_customer_access` is `CustomerSessionController.php:68`. | Reword these places: for V2 listening, the only reachable consumer is test-commerce-gated today. The hold keeps a future production consumer, or a change to that gate, from turning a one-way promotion on implicitly. The other three claims hold (no binding exists for the two adapters, and the sitemap controller serves on production). |
| R2 | P3 | `tests/ops/test_production_runtime_validator.py:258` | **A test comment is false, and the effective layer has no behavioural test.** The comment says the configuration check "cannot be reached by an input file alone". It can be. `PATH=false` (or `LC_ALL=false`) followed by `DISCOVERY_SITEMAP_ENABLED=${PATH}` passes the file layer, because phpdotenv's ArrayAdapter resolves the reference to `false`. The bootstrap then resolves `${PATH}` from the real process `PATH`, so the effective layer refuses the file. With the effective loop removed (mutation M2), this input passes. As it stands, M2 and M3 are killed only by a source-regex test. | Probe "interp PATH shadow" → `FAIL holds.discovery_sitemap_enabled`. Under M2 and M3 the same probe → `PASS`. | Replace the comment and add a behavioural test with the `LC_ALL`/`PATH` shadow input. Keep the regex pin, or drop it. |
| R3 | P3 | `docs/ops/production-activation-packet.md:54-57` | **The lifting procedure is incomplete.** (a) A non-first deploy validates the candidate `.env` with the served release's validator first (`forge-deploy.sh` → `runtime_gate "$previous_release"`, `ops/staging/forge-deploy.sh:165` before derivation). The deploy that ships a lift therefore still refuses the flag set to true. The lift is two steps: deploy the change with the flag still false, then turn the flag on with a `.env` refresh. (b) Removing an entry also requires editing the test's `PRE_LAUNCH_HOLDS` map, because `test_the_hold_keys_agree_with_the_staging_capability_boundary` pins all four. Both fail closed. | Derived `forge-deploy.sh` lines 157-165 and 140-148. Test lines 236-247. | Add one sentence on the ordering and one on the test map. |
| R4 | P3 | `docs/ops/production-activation-packet.md:42`; `CHANGELOG.md:13` | **"Literal `false`" is imprecise.** The check is case-insensitive and accepts quoted values (`FALSE`, `"false"`, `'false'`), as the tests assert. It refuses the bare no-`=` form even though phpdotenv treats that form as unset at runtime. That is over-strict but fails closed. | `test_the_documented_off_values_are_accepted_for_the_pre_launch_flags`; `test_a_value_only_flag_with_no_equals_sign_is_refused`. | Say "`false` or `(false)`, any case, optionally quoted". |
| R5 | P3 | `CHANGELOG.md:13` | **The entry sits under "Added".** It refuses environments that were accepted before (the entry says so), which fits "Changed" or "Security" better under Keep a Changelog. | `CHANGELOG.md` headings at lines 11, 63 and 187. | Move the entry to "Changed" or "Security". |
| R6 | P3 | `ops/production/validate-runtime.php:44-49`; `scripts/ops/capability-boundary.php:10` | **Drift is pinned on the config side only.** The four keys are a copy that a test pins against `CapabilityBoundary::stagedFlags()`, rather than requiring that file. The `capability-boundary.php` docblock still says "the two validators". Nothing pins the consumer side either: if `FreeGrantPolicy` started reading a new key, both layers would keep passing. | Code read. | Optional: require `scripts/ops/capability-boundary.php` and select the four keys from it, and/or add a source-pin test of the four consumers. Mention the production validator in the boundary docblock. |

No P0 and no P1. The output contract holds: every refusal printed only fixed identifiers. I saw no value, path or sentinel in any probe or mutation run, and the test harness asserts the same for `SENTINEL` and the temp paths.

## Docs and ledger accuracy

- **Activation packet table.** The four flag names, config keys and refusal identifiers match the code exactly. The identifiers are `holds.` plus `strtolower(name)`.
- **`ops/production/README.md`.** Line 32 and item 6 match the validator. "Refused by their own environment gates or by the preflight" is accurate: the preflight refuses the seven test-commerce switches, and `TestEnvironment` covers the capability flags.
- **CHANGELOG.** The entry is accurate apart from R1, R4 and R5. The template states all four as `=false` (template lines 211, 219, 226 and 230), and `profile()` with all four set to `=false` passes.
- **Ledger.** PR #152 merge `6c28d3a32187ed8851445263fd8744379926ab35` has parents `9225df8c…` and `a010bbf0e8b00b4de302958bb485a448ed33c928`, subject "Merge pull request #152 from SeanVasey/harness/ops-followup-20261018", and the same tree as `a010bbf0`.
  - `ed152682`, `c94770ad`, `cd35fb15` and `a28a87c5` all exist and are ancestors of `a010bbf0`.
  - From `c94770ad` to `a010bbf0` the changes are review-record imports, a merge of main (`9225df8c`) and ledger docs only.
  - "Ops suite 313 OK" agrees with today's 321: 313 plus the 8 new tests.
  - I did not verify the GitHub Actions run IDs (39104275184 and 39104264633); that needs network access.
- **Other docs.** No other current doc still says the four flags are unheld in production. Mentions in `docs/verification/production-ops-kit-20261017/README.md` (lines 262, 299, 303 and 388: "the explicit `false` is the only guard", "needs Sean's decision") are dated evidence. `docs/handoff/2026-10-07/codex-deferred-20261011.md:412` is superseded in the same ledger at line 441.

## Commands run (in /home/user/wt/o3)

| Command | Result |
| --- | --- |
| `git rev-parse HEAD` / `git merge-base origin/main dccc5852` | `dccc5852…` / `6c28d3a3…` |
| `python3 -I ops/production/build-kit.py --check` | `build-kit: ok, 13 files`, exit 0 |
| `python3 -I ops/production/build-kit.py <scratch>` | kit built; I inspected the derived `forge-deploy.sh`, `release-step.sh`, `bin/vasey-production-ctl` and the php-fpm and worker confs; the kit was discarded afterwards |
| `python3 -m unittest tests.ops.test_production_runtime_validator -v` | Ran 26, OK (8 new) |
| `python3 -m unittest discover -s tests/ops -p 'test_*.py'` | Ran 321, OK |
| `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php vendor/bin/phpunit tests/Unit/ProductionEnvironmentTemplateTest.php tests/Unit/StagingRuntimeValidatorTest.php` | `{"result":"passed","tests":86,"passed":86,"assertions":2119}` (`public/build` absent) |
| `git show -s --format='%H %P %s' 6c28d3a3…` | parents `9225df8c… a010bbf0…`; trees equal |
| Final `git status --short` / `git rev-parse HEAD` | clean / `dccc585251873fa21051f73f3cba033ae30ffd6e` |

## Adversarial dotenv probes

I ran the real validator with an empty environment (`PATH` and `LC_ALL` only) on `profile()` plus each case below. `K` is `DISCOVERY_SITEMAP_ENABLED`.

| Input | Outcome |
| --- | --- |
| `export K=true` | refused `holds.k` |
| `  K =  true  ` | refused |
| `K='true'`, `K=""`, `K=0`, `K=null`, a NUL byte in the value | refused |
| `K=true` with no trailing newline | refused |
| `K=false # c`; `K="false" # true`; `K=(false)`; `K=<TAB>false` | pass (all resolve to `false` at runtime too) |
| `X=false` then `K=${X}` | pass (resolves to `false` in both) |
| `PATH=false` or `LC_ALL=false` then `K=${PATH}` / `K=${LC_ALL}` | refused by the effective layer |
| duplicate `K=false` then `K=true` (also via `export`) | refused (`runtime.unique_keys` and the hold) |
| duplicate `K=true` then `K=false` | refused (`runtime.unique_keys`). Note: `Dotenv::parse` keeps the last value and the immutable runtime keeps the first, so the unique-keys check is what makes this case safe. With it removed (M9), the preflight's `dotenv_unique_keys` still refuses. |
| CRLF file, all false / with `K=true` | pass / refused |
| BOM at file start or before the key | `runtime.unavailable` (fails closed) |
| multi-line quoted value embedding `K=true` | refused by the preflight (`dotenv_syntax`) |
| `APP_CONFIG_CACHE=…` | refused (`runtime.no_cache_override`) |
| lowercase `discovery_sitemap_enabled=true`, or the lookalike `…ENABLEd=true` | pass. Laravel's `env()` lookup is case-sensitive, so the app never reads these names, and the effective layer confirms the config stays `false`. |

**Runtime parity.** Each artisan call runs under `env -i`: in `release-step.sh` `art()` and in `ctl configure`'s `config:cache` and `route:cache`. The FPM pool sets `clear_env = yes` and only `env[PATH]`. Before any artisan or composer step, `forge-deploy.sh` unsets the inherited `VASEY_OPERATIVE_*`, `CONTACT_*`, `DISCOVERY_*` and `VASEY_LISTENING_*` families. Supervisor workers read the config cache. Nothing in `app/` calls `env()` for these four names. Shadow interpolation needs a variable present in the runtime process environment and absent from the validator's. Both run as CLI under `env -i` with the same `$_SERVER` key set, and none of those values can be `true`.

## Mutations (each applied to `validate-runtime.php`, the test module run, then reverted)

| Mutation | Killed by |
| --- | --- |
| M1: drop the file-level loop | `test_a_value_only_flag_with_no_equals_sign_is_refused` and `test_the_hold_is_checked_before_the_preflight_child_runs`. The effective layer still refused every bad value in the probes. |
| M2: drop the effective loop | only `test_the_effective_configuration_must_be_strictly_false` (source regex); the PATH-shadow probe then passes (R2) |
| M3: `=== false` → `!== true` | regex test only; the PATH-shadow probe then passes |
| M3b: `=== false` → `== false` | regex test only. Behaviourally, null and "" would pass, so a renamed key would fail open. |
| M4: typo in a config key | 29 failures (fails closed on every profile) |
| M5: drop the V2 entry | 13 failures; the V2=true probe then passes |
| M6: accept a blank in the file layer | the value-only test (the effective layer still refuses a blank) |
| M7: no `strtolower` | 8 subtests (`FALSE`/`False` acceptance) |
| M8: `isset` instead of `array_key_exists` | the value-only test |
| M9: drop `runtime.unique_keys` | `test_duplicate_keys_are_refused`; the preflight still refuses duplicates |
| M10: leak the value into the identifier | 46 failures (the exact-identifier assertions) |
| M11: refuse only `true` in the file layer | the value-only test (the effective layer still catches the rest) |

Every mutation was killed. The coverage is proportionate: the two layers overlap by design, the file layer is pinned by the ordering and value-only tests, and the effective layer by a source regex. One behavioural test for the effective layer (R2) would remove the dependence on the regex.

## Untested conditions

- No host run: provisioning, a Forge deploy, `ctl prepare`, `ctl refresh` and `config:cache` on a real release were not exercised. I traced the paths by reading the derived kit.
- The rollback procedures (staging runbook A to D under production names) switch to an earlier release and restore `env.backup` without re-running the current validator. That is safe today only because no production release exists from before this hold.
- On a first install, `release-step.sh build` installs the candidate `.env` and runs composer's `package:discover` and `filament:upgrade` before the first validator run. Neither touches these capabilities, but that window existed before this change and was not tested.
- MySQL-backed runtime behaviour and the GitHub Actions run IDs in the ledger were not verified.
- I did not exhaustively prove that no other code path establishes a `customer`-guard principal. I checked the only place that writes `_customer_access` and the `CustomerAccessPolicy` gate.
