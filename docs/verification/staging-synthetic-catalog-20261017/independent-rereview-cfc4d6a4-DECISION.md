DECISION: APPROVE at cfc4d6a40585977c0888d96573dd3c1a8df9843d

Independent read-only re-review (2026-10-18) of `09acd13d..cfc4d6a4` on `harness/staging-synthetic-catalog-20261017`. No P0, P1 or P2 is open, so prior conditions C1 and C2 are met. Four new P3s go to the lane's deferred batch.

Independent re-reviewer: a separate Claude Code subagent with no part in writing the batch. Imported verbatim by the lead.

**Provenance.**
- The code at the tested SHA `a8f162c8` is identical to head: `cfc4d6a4` touches only CHANGELOG.md and three docs.
- `c23718c4` changes only the command, the policy, the builder and the config. Nothing else in the app calls them.

## Prior conditions
- **P2-1: closed.**
  - **Production refusal.** `SyntheticRehearsalPolicy.php:29-35` refuses when `config('app.env')` or the running environment is `production`. It then refuses when the two differ, or when the config value is not a string. Both checks run before any database read.
  - **Catalog guard** (`:45-47`, `:124-133`). Two bounded EXISTS reads against the exact synthetic slugs: tracks that are published or slug-reserved, and license versions that are approved or published.
    - Every published track reserves its slug (`Track::saving`), so the published case is covered.
    - Real never-published drafts and templates without versions are admitted (test `:562-583`).
  - **Can it be fooled?** Only by real rows that take the exact reserved slugs (`synthetic-rehearsal-NN`, `…-license`). The slug regex allows them and slugs are unique. Even then, the builder reports such a track as "metadata differs" and leaves it unchanged, and a mismatched template stops the run. Not a meaningful risk.
  - **Limit.** A pre-launch production database is probably empty, so the guard adds little there. design.md:29 says so accurately.
  - **Reproduced with artisan.** No database file existed and the git tree stayed clean.
    - `APP_ENV=production --env=staging`, run as `--check`, as a write run and with `--attest`: "refused in production", exit 1.
    - `APP_ENV=local --env=staging` and `APP_ENV=staging --env=local`: "differ", exit 1.
    - `APP_ENV=staging --env=production`: refused as production.
    - Control (`APP_ENV=staging`, no `--env`): passed the environment checks. Its first database access was the catalog guard.
  - **Wording** is now accurate in command:25-27, config:4-6, design.md:26-31 and :49-72, README:26-30, runbook:181-188 and CHANGELOG:13. README:73 keeps the old test name as historical evidence.
- **P2-2: closed.**
  - **Passwords.** `confirmed()` (command:140-148) mirrors `ManageRightsScopes::authenticate` (:125-133): `secret($prompt, false)`, a fresh row read, `Hash::check` and the gate. It runs for both accounts (:89-92).
  - **No skip path.** No option or environment variable skips the prompts, and `-n` is refused (:86-88).
  - **Attestations.**
    - They are required where the panel requires MFA (:93-96).
    - They are recorded after the lock and before the builder (:97-105), one per account, through `StaffWriteAuthority::attest` (:155-164).
    - Test :552 proves they are the run's first two audit rows.
  - **`--check`** returns before any prompt (:75-85) and refuses `--attest` (:64-66).
  - **Shape.** Same writer, schema v2. The action is a namespaced role, the scope key is descriptive and the revision is null.
    - These rows cannot be replayed into a rights-scope write: the constructor is private (StaffWriteAuthority:49), and `binds()` (:159-169) requires the exact action and scope.
    - No app code reads them.
  - **Audit-only is acceptable.** Binding them would mean threading a multi-use attestation through about ten single-use domain writers (about 94 writes). That is a larger sensitive change than this staging-only seeder warrants. design.md:94 and command:150-153 state plainly that nothing consumes them.
- **P3-1: closed**, except the decision-register line, which README:258 openly leaves to the lead (runbook:158, :254-256).
- **P3-2: closed.**
  - Test :524-560 runs the whole chain under `staging` with enrolled accounts.
  - The scanner stand-in is labelled (:528, version `synthetic-host-scan-stand-in`), matching three existing rights-scope tests.
- **P3-3: closed.**
  - `handle()` (:50-59) prints a generic line and logs only the exception class.
  - It cannot make a test pass falsely: the only test that expects the generic line pins the `Log::warning` arguments exactly (:512).
  - In a real process, a QueryException carrying the SQLite path printed only the generic line.
- **P3-4: partly closed.** The `finally` helper (:106-117) and the lock-contention test (:487-494) were added. The brand-asset test is unchanged; see P3-A.
- **P3-5: closed.**
  - CHANGELOG:13 and runbook:216-217 are corrected.
  - `offersMatch` (builder:73-77, :251-263) was added. `price_minor` is cast to integer, so the strict comparison holds on MySQL.
  - The re-run test covers it (:371-378).

## New findings
- **P3-A · Brand-asset test.**
  - test:460-474 (`…never_reuse_a_brand_asset`) still checks only distinct hashes, MIME type and artwork size.
  - README:206 leaves this part of P3-4 out, while README:21 says the conditions are closed.
  - Remedy: assert that no generated sha256 matches any brand file under `assets/` or `public/`, or rename the test and record the gap.
- **P3-B · "Interactive console" claim.**
  - The claim (command:87, design.md:83, runbook:195, README:202) holds only in the sense "not `-n`": Symfony reads hidden prompts from piped stdin.
  - Probe: `printf … | artisan vasey:rights-scope register …` reached the password lookup.
  - The behaviour comes from `vasey:rights-scope`, and both passwords are still required.
  - Remedy: reword the claim, or add `stream_isatty(STDIN)` to both commands.
- **P3-C · Generic failure message.** It also says "finished steps are kept and nothing is duplicated" for `--check` and admission failures (observed in the control run). Remedy: use neutral wording.
- **P3-D · Stale test docblock.** The test-class docblock (:44-48) doesn't mention the ClamAV-labelled stand-in in the staging case.

The delta weakens no security check: it only adds refusals and audit rows.

## Commands and counts
All on SQLite with `public/build` absent; the tree was clean before and after.
- Feature file filtered to the 9 new or changed cases (both `--env` refusals, the production refusal, passwords/lock, the hidden prompt, the staging chain, both catalog guards, re-run): **9 tests, 234 assertions, OK** (64 s).
- `RightsScopeCommandAttestationTest`: **7 tests, 197 assertions, OK**.
- 7 artisan invocations plus the stdin probe; no database file was created.

## Not verified
- The other 10 feature cases (the packet claims 19 tests / 806 assertions).
- `ProductionEnvironmentTemplateTest` and `RightsScopeCommandTest` (claimed 23 / 2,071 together with the attestation suite).
- The `config:cache` variant, reasoned from the code only.
- Native MySQL, Pint, a hosted staging run, real ClamAV and a two-process lock.
