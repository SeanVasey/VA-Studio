DECISION: APPROVE WITH CONDITIONS at 09acd13d7f41f2b517c00f1c7f6980db3823c2f7

Independent read-only review (2026-10-18) of `harness/staging-synthetic-catalog-20261017` against origin/main `cca1d18e`. I found no P0 or P1 issues, so under the freeze-and-batch rule this SHA may merge. Two conditions apply:
- **C1:** close P2-1 before the first hosted-staging run, and before any production host carries this command.
- **C2:** close P2-2 before the first hosted-staging run.

Independent reviewer: a separate Claude Code subagent with no part in writing the batch. Imported verbatim by the lead.

## Provenance (verified)
- **The merge is clean.** The head tree `f4dd3744` equals `git merge-tree --write-tree 0c8b3eba cca1d18e`. Main's side brought six doc/CSV files only: CHANGELOG, README and docs/*.
- **The tested source is the head code.** `git diff 5f7fe502 09acd13d -- app config tests ops routes database .env.example composer.* package*` is empty. `0c8b3eba` touches only CHANGELOG and three docs.
- **Code delta against main:** 10 files, +1,085/−0. No existing service, guard, policy, test, census or workflow was edited. No binaries or brand assets were committed.

## Findings

**P2-1 · The production refusal is not unconditional**
- `SyntheticRehearsalPolicy.php:22` relies on `app()->environment()`. Laravel's `--env=` switch overrides that value, even when config is cached (`LoadConfiguration.php:61`).
- **Reproduced:** with `APP_ENV=production`, the flag on and Stripe in test mode, `artisan vasey:staging-synthetic-catalog --env=staging` passed the production check. It stopped only at the missing-operator refusal, while `config('app.env')` still read `production`.
- The Stripe check gives no protection on production before launch: the production template sets `STRIPE_MODE=test` and leaves funds mode blank.
- **Barriers that remain:**
  - the cached flag, which the template sets to false;
  - two MFA-enrolled `Synthetic` admin accounts in that database;
  - the configured seller tag.
- So this needs a deliberate operator or a double misconfiguration; it is not an outside attack.
- A non-production `APP_ENV` pointed at a production database has no data-level guard either. That gap exists across the codebase.
- **Overstated claims:** "refused unconditionally" and "cannot run in production" (command:18, config:5, design.md:23 and :42, packet README:22, runbook:177, CHANGELOG:13).
- **Remedy:**
  - Also refuse when `config('app.env') === 'production'`, or when it differs from `app()->environment()`.
  - Add a test that sets `config(['app.env'=>'production'])` under `detectEnvironment('staging')`.
  - Recommended: refuse while any published track or license template lacks the `synthetic-rehearsal` marker.
  - Correct the wording.

**P2-2 · Acting as staff from the console is weaker than the repo's existing pattern**
- The command acts as two staff accounts identified only by email, with no password and no attestation (`SyntheticRehearsalPolicy.php:43-70`).
- It also records the reviewer's license approval, including `summary_consistency_confirmed => true`, on the reviewer's behalf (`SyntheticRehearsalBuilder.php:129-130`).
- The domain services' own checks are honoured: the gate plus a locked `AdminMultiFactor` read (e.g. `RightsDeclarationBoundary.php:36-37`). On staging, though, they verify only that an authenticator is enrolled, not a code.
- The only existing console command that writes as staff, `ManageRightsScopes.php:59-72,126-133`, requires an interactive password plus a recorded `StaffWriteAuthority` attestation where MFA is required.
- **Inaccurate claim:** "Like every staff-attributed console write" (design.md:180, runbook:253). Runbook:216 contradicts it.
- The two-person rule holds per account only: one shell drives both accounts.
- **Remedy:**
  - Confirm each account's password interactively (hidden input), as `ManageRightsScopes::authenticate` does.
  - Append a pre-write audit or attestation entry naming the command and both accounts.
  - Otherwise, Sean explicitly accepts the enrollment-only model in the decision register.
  - Fix the wording either way.

**P3-1 · How the S-6 dependency is presented**
- Never generating a tag is the correct design choice.
- But the approved purpose, rehearsing before real tracks arrive, stays blocked until S-6 arrives or Sean decides otherwise.
- Runbook §5a states this only in admission item 5 and under Limits (:250).
- **Remedy:**
  - Add a "Blocked until S-6 or Sean's decision" line at the top of §5a and to the decision register.
  - Reset step 4 (:241) should read "reinstall the approved S-6 tag" and require removing any synthetic tag before real masters.

**P3-2 · The write chain under staging rules has not run.** Full runs used `testing` or `local`, where panel MFA is optional. Under `staging`, only `--check` ran (test:148-157). Add one full run under `staging` with enrolled accounts.

**P3-3 · Some failures are uncaught.** A `RuntimeException` (Media:34, :47, :67), a `QueryException` and similar errors escape the catch list (command:77-95). They print stack traces containing paths, contrary to Refusal:5. Add a final `Throwable` catch with a generic "result unconfirmed; re-run to resume" message.

**P3-4 · Test gaps**
- The lock-contention refusal (command:54-58) is untested.
- Environment resets are not in `finally` (test:94-97, :114-117, :148-158).
- `…never_reuse_a_brand_asset` (:371) checks only that sources are distinct and correctly sized, not that no brand asset is used.

**P3-5 · Wording and reporting**
- "Every row carries the marker" (CHANGELOG:13) overstates it. Offers, revisions, derivatives and processing runs inherit the marker through their foreign keys; design.md:138-140 states this correctly.
- Runbook:206 says "set the flag back to false". It needs "then Deploy Now (configuration refresh)", because the cached config otherwise keeps `true`.
- On a re-run, a published track is reported `already present` without re-checking its offer prices (Builder:67-76).

## Assessed, no findings
- **Admission order:** every check completes before any write. The lock is taken after admission, and staging's cache store is `database`. `--check` writes nothing.
- **Flag:** strict `=== true`, default false.
- **Production template:** the entry is `=false` and matches the existing fixture-flag precedent. It is required by `ProductionEnvironmentTemplateTest:98-99`, which accepts the production or private-server template.
- **Writes:** only through domain services, in admin order, with no raw inserts. The test's 94-event audit census adds up.
- **Labels and terms:**
  - Labels: `[SYNTHETIC]` titles, plus the artist, description, tags, provenance, license name/source/first feature, approval reference and the artwork text.
  - Prices are integer 111 and 222 with `USD`. The license text grants no rights.
- **Discovery leakage on staging is immaterial.** Robots is `Disallow: /`, sitemaps are empty outside production, and pages carry `noindex` (PublicDiscoveryController:16-49, StorefrontMetadata:52).
- **Idempotence and resume are sound.** Staff withdrawal is honoured: `published_slug` is immutable and published tracks cannot be deleted (Track:22-40). There is no purge.
- **Media:**
  - Files are generated in a private `processing/<uuid>` directory (mode 0700) and removed in `finally`.
  - `UploadedFile(test:true)` matches ResumableMediaUploads:126.
  - Nothing is read from the repository or the network.

## Spot checks (head, SQLite; public/build absent before and after; git tree clean)
- `ProductionEnvironmentTemplateTest`: 6 tests, 1,651 assertions, OK. Adding the feature file's claimed 12 tests / 589 assertions gives the packet's 18 / 2,240.
- Feature file, six refusal cases plus the successful run: 7 tests, 409 assertions, OK (44 s).
- Feature file, re-run / withdrawal / edited-track case: 1 test, 28 assertions, OK (27 s).
- `OfferWriterAuthorityTest` + `LicensingAdminTest`: 15 tests, 131 assertions, matching the packet's 9/34 and 6/97.
- The `--env` bypass was reproduced with artisan as described in P2-1; no database was touched.
- The packet's arithmetic is consistent (§4 totals 218 / 2,289; §3 audit count 18 → 20). The packet contains no raw logs, so §2 and §3 are narrative only.

## Not verified
- The native MySQL run (§2) and the genuine-scanner manual run (§3).
- Four feature cases: resume/drift, failed-media retry, storefront/quote, generated sources.
- The other ten §4 regression suites, Pint, and the shard-selection proof.
- A hosted staging run, a passing real ClamAV scan, the worker claim race and the lock under two processes.
