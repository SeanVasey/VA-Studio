# Delta review: 5b42498 vs 1f89dec (PR #82 head)

**DECISION: APPROVE.** Nothing in /home/user/wt-mysel was changed; `git status` is clean.

1. **Same partition, strict count (Info).**
   - GitLab now calls `phpunit-shards.py` with `SHARD_COUNT=8`, the same timings file and `--mysql-native-selection`. That is GitHub's input, so the sharder produces GitHub's 8-shard partition deterministically.
   - `COUNTS` is `{"mysql": 8, "sqlite": 2}` (`gitlab-database-receipts.py:24`). `DATABASE_JOBS` and `UPSTREAM` derive from it (`:32-35`), so the collector requires exactly ten database jobs.
   - The new test refuses old 4-shard archives, whether one or four are mixed in (fails with "unexpected ZIP entry").
   - My scratch mutations were all killed: `COUNTS` mysql 4 (5 tests fail), `timeout: 240m` (1 fails), and a 4-entry `SHARD` matrix (1 fails).
2. **No stale 4-shard assumptions (Info).**
   - The `CI_JOB_ID`/`CI_RUNNER_ID` literals `13`/`513` are now derived from `len(UPSTREAM)`.
   - The "six receipts" message is now computed.
   - The remaining 4-shard mentions are marked historical: `ci-database-receipts.md:7` and the evidence note at lines 385, 428, 431, 444 and 459.
   - `ci-throughput.md:22` refers to GitHub's earlier move from four to eight shards, so it is not stale.
3. **GitLab SQLite is unchanged (Info).** It still has 2 shards, 60 minutes and no flag or env vars.
4. **Self-tests pass.** All nine gate suites return rc 0; gitlab-database-receipts has 31 tests. related-browser-stage returns rc 1 with the 4 known environmental failures.
5. **Residual risk, disclosed (Low).** The 175-minute limit allows about 24% headroom over a GitHub-derived estimate. GitLab durations stay unmeasured, and the note's "Open blockers" section says so.
