DECISION: APPROVE at `28a3c8af12ae6970f47a1f4aa7352a64c8bf7a74`

Reviewer: independent Claude subagent. This was a read-only re-review of `94c70fd6..28a3c8af` (67e34c4f, c88bbe3c, 70573daf, 28a3c8af). `git status --porcelain` was empty before and after, and HEAD was confirmed. Mutations ran only in a `git archive` scratch copy with vendor symlinked; the copy has been deleted.

## Verified

1. **Scrub safety.**
   - I tested every `VASEY_*` name in the staging and derived production `forge-deploy.sh`, ctl, backup and release-step scripts, plus the `staging.conf` (`provision.sh:441-459`) and derived `production.conf` heredocs: 40 names in all. Each went through the real case arms (`forge-deploy.sh:64,66`). Only `VASEY_PHP_CLI_BINARY` matches, and it matched before this delta. `VASEY_*_SYNTHETIC_*` matches none of them.
   - The config file is sourced (:41) and checked (:42-43) before the scrub (:62-68). Its assignments are not exported, so `compgen -e` cannot unset them, and no name matches anyway. There is no ordering issue.
   - A scratch build gives a derived production `forge-deploy.sh:69` that keeps `VASEY_*_SYNTHETIC_*`.
   - Removing the arm fails `test_every_config_env_key_is_scrubbed_before_artisan_runs`. Removing the template line fails the template-coverage test.
   - At c88bbe3c I reproduced exactly the two named failures.
2. **Not holding the flag is correct.**
   - The flag is read only at `SyntheticRehearsalPolicy.php:39`, and only the console command uses that policy. Nothing in routes, bootstrap, providers, the scheduler or jobs references it.
   - The command refuses production regardless of the flag (:30-32), refuses a configured/running mismatch (:33-35), and refuses outside local/testing/staging (:36-38).
   - `PRODUCTION_LANE_PATTERN` does not match `staging-synthetic-catalog`.
   - Runbook §5a's run step sets the flag and then uses Deploy Now. That passes through `runtime_gate`, so a hold would block the documented flow.
   - The comment at `env.staging.example:211-213` is accurate.
3. **Doc P3 corrections are accurate.**
   - Lines 210/217/223/226 hold at HEAD; main's insertion is at 231-232, below them.
   - `compose-env.py:62-70` refuses any later-layer change, and composition succeeds.
   - The gate paths are correct.
   - The BOM check is at `validate-runtime.php:43-46`.
   - The `.ship.??????` cleanup with `-mmin +1440` is only inside `ship_hardened` (`backup.sh:404`).
4. **CHANGELOG merge is complete.**
   - Bullet counts: base 85, plus 6 from O1, plus 2 from main, gives 93 in the merge.
   - No line from either parent is missing, no line is duplicated, and no line comes from neither parent. HEAD is unchanged since the merge.

## Findings (P3 only, none blocking)

- **P3-A: a recorded deferral is false.**
  - Packet `README.md:437` says P3-2, P3-3 and P3-6 are "recorded in `docs/handoff/2026-10-07/codex-deferred-20261011.md`".
  - That file has no O1 entry: no `.ship`, `flock`, `ABCDEFG` or `94c70fd6`.
  - *Remedy:* add the entry before merge (docs only), or correct the sentence.
- **P3-B: the rationale for the production scrub has no test.**
  - I narrowed the arm to `VASEY_STAGING_SYNTHETIC_*`. All 280 ops tests and `build-kit --check` still pass, yet the derived production script then scrubs `VASEY_PRODUCTION_SYNTHETIC_*` and misses the real key. That is the hazard packet :444 describes.
  - *Remedy:* run the config-key coverage check against the scrub arms of the built production `forge-deploy.sh` as well.
- **P3-C: two template comments do not match the new line.**
  - The synthetic line sits under the `env.staging.example:184-185` header, which says the profile validator "requires these lines, with these values". It does not: `validate-test-commerce-profile.php:154` checks only `CAPABILITY_FLAGS`.
  - :174 ("twelve … below") now also has a flag below it that is not held. This joins deferred P3-6.
  - *Remedy:* give the synthetic block its own header above :184.
- **P3-D: nothing records the 70573daf behaviour.**
  - No CHANGELOG line covers the staging template now stating the key or the scrub covering it. The K1 entry still says "196" keys; there are 197.
  - `capability-boundary.php` also does not record the decision not to hold the flag.
  - *Remedy:* one clause in each.
- **P3-E: production README item 6 (:146) is incomplete.** It lists the flags that environment gates already refuse, but not the new environment-gated synthetic flag. *Remedy:* name it with those flags.

## Commands and counts (public/build absent)

| Check | Result | Lead's count |
| --- | --- | --- |
| `python3 -m unittest discover -s tests/ops -p 'test_*.py'` | 280 OK | 280 |
| PHPUnit (SQLite command): StagingRuntimeValidator, TestCommerceProfileValidator, ProductionEnvironmentTemplate | 149 tests, 2,693 assertions OK | 149 / 2,693 |
| `scripts/ops/test-private-server-preflight.py` | 20 OK | — |
| `build-kit.py --check`, plus a scratch build | ok, 13 files | 13 files |

- `shellcheck` 0.9.0 (`-S warning` and default severity) and `bash -n` on the staging and derived `forge-deploy.sh`: clean.

Mutations in the scratch copy:

| Mutation | Result |
| --- | --- |
| arm removed | 1 failure |
| template line removed | 1 failure |
| arm narrowed to `VASEY_STAGING_SYNTHETIC_*` | 280 OK (P3-B) |

## Not verified

- Anything on a real host: Forge environment inheritance, the sudo or `env -i` paths, the actual refresh run, or a real seeding run.
- The rest of main's C1, M1 and F2c changes beyond where they meet O1.
- Native MySQL.
- `--attest` and the MFA behaviour on staging.
