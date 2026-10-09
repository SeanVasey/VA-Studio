# Delta review: 2ac0f3d vs 552589d (harness/mysql-native-selection)

**DECISION: APPROVE.** C1, C3, C4 and C5 are met. C2 is met for test files the scan can see, with two non-blocking follow-ups (findings 2 and 3). The worktree is unchanged.

## Evidence

- **Self-tests:**
  - rc 0 for all nine suites. Counts: phpunit-shards 52, database-receipts 59, the rest unchanged.
  - related-browser-stage: rc 1, the 4 known environmental failures.
- **Real discovery:** rc 0, 1,765 cases in 163 files, and the case identity `770ae7…` is unchanged. The generated files were deleted.
- **My mutations** (scratch copy):
  - Regex drops `getDriverName`: killed.
  - Rename the residual `CustomerConsentAdmissionTest` without editing the policy: killed.
  - New test that branches through `DB::connection()->getConfig('driver')`: **survived**.
  - New test that branches through `config('database.default')`: **survived**.

## Findings

1. **No selected file can drop out without a policy edit (Info).**
   - Pattern matches must equal `pattern_files`, in the sharder (`phpunit-shards.py:322-326`) and the verifier (`database-receipts.py:442-443`).
   - Include files and census owners must be discovered.
   - Residual files must be discovered and unselected.
   - The repository scan runs in preflight, Foundation and GitLab quality jobs (`final-verification.yml:131`). It refuses unlisted branching tests and stale entries.

2. **The scan regex has false negatives (Low).**
   - `getConfig('driver')` and `config('database.default') === …` both escape detection (the two surviving mutations above).
   - Neither occurs in today's uncovered files; the `database.default` hits only clone or pin connections.
   - Recommend adding `getConfig\(\s*['"]driver` and a `database\.default`-comparison form.
   - The `->driver(` exclusion is fine (session only).

3. **The explicit helper list hides users (Low, disclosed).**
   - 26 unselected, non-residual test files reach listed branching helpers. Twenty are `PaidGrant*` tests using `PaidGrantDependencyFixtures`.
   - Most of these branches are guard assertions, but `PaidGrantCommitFrameProbe:51,83` alters MySQL behaviour.
   - The "47 files remain SQLite-only" figure therefore undercounts the scope that branches on the driver.
   - Recommend a one-level scan: test files that name a listed helper's class or file must be selected or residual.

4. **CommerceGuardBytesTest is database-free (Info).** It mocks `DB::getDriverName` and `unprepared` (`:34-35`), and has no migrations trait. Classifying it as residual is correct and harmless.

5. **The docs satisfy C1, C3 and C4 (Info).**
   - C1: "Open blockers" makes no coverage, census or attachment claim before a hosted 8.4 pass.
   - C3: GitLab results are not evidence until the timeout reaches 240 min or the shard count reaches 8.
   - C4: the warning is in the helper docblock, the note and `ci-database-receipts.md`.
   - C5: done.
