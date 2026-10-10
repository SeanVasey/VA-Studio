# Independent review: harness/foundation3-fixes 590ed36..5e15e78

**Decision: APPROVE WITH CONDITIONS.** reviewed_head: 5e15e78 (5e15e7818a8dff1569de00390ec81a2eaea2847d, worktree unmodified).

## Assessment
- **Scope:** 24 files, all allowed. No app code, contracts, vendor, lockfiles, README.md, CLAUDE.md, secrets, real data or absolute paths. The four test files are identical at bd84978e, dd29a39 and 590ed36.
- **H1 is correct** (MembershipSchemaPreparationTest.php:194-196). On MySQL, plain `DROP TABLE` of a temporary table commits; `DROP TEMPORARY TABLE` doesn't. The refusal assertions run before the drop and are unchanged. Side benefit: the drop can no longer remove the real table.
- **H2 is correct** (CustomerSuppressionMigrationTest.php:128-132). Every created table's parent exists. On both engines the refusal is the non-prefix branch (SuppressionSchema.php:66), as the old setup gave on SQLite.
- **H3 is correct** (CustomerConsentMigrationTest.php:53-58). The only dependent is `customer_suppression_intents`, with 0 rows on both engines. History and snapshot assertions are intact. The emptiness check means a future non-empty dependent can't be dropped silently.
- **H4 is correct.** The receipt path is ignored and nothing reads the old one. The committed receipt still matches receipt-map.json. The 163 selected files write no tracked paths.
- **Evidence:** jobs.tsv matches the GitHub API for all 34 jobs, and junit-summary.txt matches the artifacts. The timing files regenerate byte-identically, and the partition output matches.

## Findings
- **MEDIUM M1 (CHANGELOG.md:42).** The new bullet splits the 24-shard entry (line 41) from its sub-bullets (43-62). Those now nest under it and contradict it (lines 49-51).
- **MEDIUM M2 (README.md:233).** "This was the attachment-consumer blocker" is wrong. That blocker is SupportAttachmentsTest and ServiceSupportAttachmentsTest (mysql-native-selection README:165-179). They passed with 0 skips in accepted shards 18 and 7. It closes only with a passing exact-SHA run, and H4 is a separate defect.
- **LOW L1 (CHANGELOG.md:83).** "Each fix has … red/green evidence" is untrue at this head: H1 and H2 have red logs only (README:261-262). README "Not tested" (271) omits this. The pending delta commit is noted, not failed.
- **LOW L2 (final-verification.yml:250-251).** "40% headroom over the busiest measured shard" is wrong. The busiest shard took 80.2 min, so the headroom is about 25%. 40% is relative to the estimate.
- **LOW L3 (README.md:264; run3/support-attachments-mysql80-red.txt:16).** "Byte-identical" held for that run only. Worker order varies; my 8.0.46 run wrote first=409/second=200, the reverse of the committed receipt.
- **INFO.**
  - README:187 says "20 receipts" on a row listing 19 shards.
  - The README:173 heading says "after #85", but #85 merged after the dispatch. It is docs-only.
  - "24 of 24 expected" is unproven: shards 10, 15 and 23 never reached the clean-checkout probe (database-receipts.py:616 runs before line 131). Static inspection supports it.
  - CHANGELOG.md:64 "No hosted MySQL run…" is stale.

## Conditions
1. Move CHANGELOG.md:42 to after line 62, or before line 41.
2. Rewrite README.md:233 per M2. Keep "24 of 24" as an expectation and name the unprobed shards.
3. Before merge, add the H1 and H2 MySQL green logs and Results rows, or reword CHANGELOG.md:83 and README:271 to match.
4. Correct final-verification.yml:250-251 per L2.
5. Qualify README.md:264 per L3.

## verified_by_running
- SQLite, four touched files: 63 tests, 363 assertions, 4 skipped, exit 0.
- MySQL 8.0.46 on review_* databases (all dropped):
  - H1 case: 1 test, 2 assertions OK.
  - H2 case: 1 test, 4 assertions OK.
  - H3 steps 3 and 7: 2 tests, 28 assertions OK.
  - H4 file with ATTACHMENT_NATIVE_ISOLATED=1: 1 test, 19 assertions OK. Then `git status --porcelain` was empty, and the receipt was rewritten under storage/framework/testing.
- Raw SQL probe: plain DROP left the row after ROLLBACK; DROP TEMPORARY did not.
- Scratch probe on both engines: dependents=[customer_suppression_intents] with 0 rows. New H2 setup refused as non-prefix; the old setup gave 1824 on MySQL.
- phpunit-timings.py: identical to both committed files.
- phpunit-shards.py: identical to the evidence (122m57s and about 87m; 60m44s). Generated files deleted.
- Self-tests OK: 52, 61, 32, 14, 27, 12.
- `gh` job data plus the shard-22 and WebKit logs (67 passed, 2 skipped).
