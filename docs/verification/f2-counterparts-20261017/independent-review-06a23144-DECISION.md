DECISION: APPROVE WITH CONDITIONS, for `harness/f2-counterparts-20261017` at `06a231448a1f7a47bc250cc8e2d1ad3c5b91245c` (base `efa6eea5`)

Independent reviewer: a separate Claude Code subagent with no part in writing the batch, 2026-10-18. Imported verbatim by the lead.

There is no P0 or P1 finding, so this can merge at the exact SHA under the freeze-and-batch rule. One condition: agenda item 3 is not fully reconciled until P2-1 is corrected in the packet. That correction can land now or go to the deferred batch.

What I checked on scope:
- There are six commits.
- All test changes are insertions only: 10 files, +404 lines, and no existing assertion is changed, skipped or relabelled.
- `ca63e8e9` and `06a23144` touch only the packet README.
- No app, database, config or lockfile file changes.

## Findings

**P0/P1:** none.

**P2-1. Row 18 is misclassified, and the packet's supporting statement is wrong.**
- `docs/verification/f2-counterparts-20261017/README.md:60` and `:198` say a native construction isn't possible and that no native test touches `ptx_b_u1`.
- In fact, `tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutCommitAdmissionTest.php:580` (`test_native_global_binding_capture_reads_a_peer_commit_after_the_repeatable_read_snapshot`) already proves this property on MySQL:
  - It drops `ptx_b_u1` before the owned frame (`:607`).
  - A separate, committed peer connection reuses `cs_test_SYNTHETICTAX` for an unrelated request.
  - The owned write refuses with `write_source_changed` (`:659`).
- That is exactly the "separate connection, no open transaction" construction.
- Caveat: the peer injects at the bind frame's `TransactionBeginning`, not at `TransactionCommitting`. It may therefore prove that the capture uses a current read, rather than proving the commit-time fence.
- Consequences:
  - Row 18 `binding` should be (a), with that caveat.
  - Row 18 `reviewed` (`ptx_s_u3`) and row 23 rest on "the reason given in 18", so they are probably (b).
  - The claim at `README.md:10` that nothing in the (b) set is left overstates the result.
- Remedy:
  1. Correct rows 18 and 23 and line `:198`.
  2. Then either add peer-based counterparts (drop the index before the frame, commit a peer at `TransactionCommitting`), or record them as remaining F2 work with a concrete reason, such as an observed lock wait.

**P3-1. Rows 19 and 24 (c).** The same pattern looks plausible: drop `customer_accounts_update` before the frame, then have a peer rewrite `owner_key`. Held-read row locks may make it infeasible. The packet should state why it was not tried.

**P3-2. REPLACE counterpart** (`tests/Feature/CustomerSuppressionMigrationTest.php:240`).
- It accepts any `QueryException`. Because the REPLACE row is identical, only the exception tells a refusal apart from success.
- Remedy: pin SQLSTATE 45000 and the guard message for each table.
- The packet already admits that the r6a red proved only one table.

**P3-3. Listening counterparts** (`tests/Feature/CustomerListeningMigrationTest.php:147,167`).
- They catch a bare `LogicException` without checking the message.
- `down()` refuses unconditionally (`database/migrations/2026_10_07_242000_customer_saved_tracks.php:36`), so the `down` half of each test cannot fail. This is inherited from the SQLite siblings.
- Remedy: pin the `up()` messages. The r3, r4 and r5a reds already show `up()` is load-bearing.

**P3-4. Billing red limit** (`tests/Feature/ProductionMembershipBilling/BillingSubscriptionApprovalTest.php:195-203`). This is acceptable:
- The test pins the frame-loss reason, requires a refusal, and asserts zero approvals before and after. Any write or non-refusal fails it.
- r11 proves the pinned reason is load-bearing.
- Two more layers stand behind it: `IdentityRows` (`permanent_primary_required`) and BillingSchema (`schema_prefix`).
- Optional: a double mutation would show that the zero-count assertion actually bites.

**P3-5. `restrict_fk_on_non_standard_key` dependency** (`tests/Feature/PaidTaxSchemaRecoveryTest.php:293-306`).
- The test needs this deprecated MySQL 8.4 variable and its default of ON.
- CI uses the floating `mysql:8.4` image (`.github/workflows/final-verification.yml:166`, `.gitlab-ci.yml:126`).
- It is stable within the 8.4 LTS line, and if the variable changes or disappears the test fails loudly, not silently.
- Remedy: probe the variable first and fail with a message that names the server dependency.

**P3-6. Stale text.**
- `ProductionFreeGrantSchemaTest.php:384`: the skip message still says the native counterpart is untested.
- `:245` says only the shadow differs, but `CREATE … SELECT` drops the primary key and AUTO_INCREMENT. This is harmless: the MySQL parent-floor check reads the durable `information_schema`, and r9a confirms the temporary-table check is what refuses.
- `ProductionCheckoutMigrationTest.php:203` implies MySQL lacks separate table and trigger namespaces. It has them.

**Timing and flakiness risk: low.**
- The partitioner scales each timed file by its case count (`scripts/ci/phpunit-shards.py:118-138`).
- Hosted per-case cost of the touched files is about 44–93 s, so the 16 new cases add roughly 15 minutes spread over 24 shards. Shards currently run 58–109 minutes against limits of 210 (GitHub) and 175 (GitLab).
- All new cases use a single session, with no lock waits or sleeps.
- Every touched file rebuilds the schema per test (`migrate:fresh` or `dropAllTables`). Dropped guards, renamed parents, a composite-key `users` and foreign tables therefore cannot leak into later tests.
- The largest new case is the 37-prefix replay, about 209 s locally.

## Commands and counts

**Census**
- 266 → 280 entries; the first 266 are identical.
- The first-150 hash is still `d93da4e7…c71c`, computed the way `test-focused-tests.py:139` does.
- The 14 appended entries are exactly the 14 new methods, with no duplicates. File SHA `daa82a0a…`.
- MySQL census (`8d3d4cd2…`) and selection file (`995aa895…`) are unchanged.

**Selection generator** (`phpunit-shards.py … --mysql-native-selection`)
- 2448 of 9218 cases, 233 files, 70 untimed.
- Manifest SHA `de0a01fa…` matches the packet.
- All 16 new cases appear in the shard lists. Generated files deleted; worktree clean.
- All 10 touched files were already selected at base (3 by migration pattern, 4 by include list, 3 as census owners). So the file set, and the 70 untimed files, are unchanged from base, and the old "69" and "39" comments were stale.

**Python safeguards**
- All OK: shards 52, ci-scope 27, cadence 14, focused 53, receipts 61, gitlab-receipts 32, writer-feedback 7, runtime 5, gitlab-setup 12, bootstrap-macos 13, publish 2.
- `test-related-browser-stage.py`: 12 run, 4 fail, with identical failure names on a `git archive efa6eea5` export of the base.
- Preflight does not run it (`preflight.yml:123-134`). It runs in `final-verification.yml:448`, after `npm run build`.

**SQLite whole-file runs**

| Files | Tests | Assertions | Skips | Failures |
|---|---|---|---|---|
| ListeningMigration + PaidTaxSchemaRecovery + ProductionCheckoutMigration | 44 | 2304 | 12 | 0 |
| ProductionFreeGrantSchema + CustomerSuppressionMigration | 84 | 332 | 7 | 0 |

JUnit cross-check: all 19 skips are census methods, every census method in those classes skipped, and all MySQL-census siblings ran.

**Native spot-check**
- Private MySQL 8.4.11 on port 33590, datadir `/var/lib/va-mysql84-rev` (left in place), restrict variable = 1.
- The Free, Paid and PaidTax composite-key counterparts passed: 3 tests, 16 assertions.
- "Shutdown complete" logged.

**Packet logs** (session scratchpad, `f2c/ev`)
- n1–n12 and s-a–s-d match the packet tables; the final green runs sum to 16 cases and 120 assertions.
- The run ledger shows clean sources (`e528c174`, `b573a53d`, `ca046f09`, `06b55b1e`).
- The red-mutation diff hashes match.

## Not verified
- The other 13 new native cases were not rerun natively.
- Red proofs were not re-executed; I relied on the retained logs and diffs.
- Not rerun on SQLite: the s-c files (PaidTaxDownloadJourney, BillingSubscriptionApproval), CustomerListeningFreshness, and the Free/Paid grant recovery tests.
- No hosted CI run and no hosted receipt verification.
- Feasibility of the peer counterparts in P2-1 and P3-1 was not tried.
- The base generator was not rerun, because the shared vendor autoloader conflicts with a base export. Base file-set equality is proven by the selection rules instead.
- The (a) citations for rows 6, 15, 16, 20 and 21 were checked by reading only.
