# Independent re-review — D1 delta 12441893..b95294e0

| | |
| --- | --- |
| Scope | `git diff 12441893..b95294e0` only, in worktree `/home/user/wt/d1` |
| HEAD | `b95294e0f15c2a80f2917c61e53d02715a7e40ea` |
| Tested source | `f9876183`; `b95294e0` changes only docs |
| Base | origin/main `e8dfe3cd` |
| Rules | Read-only: no commit, push or comment. The worktree is clean and HEAD unchanged at the end. |

## Decision: APPROVE

Every condition from the 12441893 record is met:

- **F1 code:** fixed.
- **Doc overclaims:** corrected in `docs/ops/production-free-http-runtime.md:56`, packet section 5 and the CHANGELOG.
- **Packet:** the red/green claim is restated and the duplicated parenthetical on line 26 is removed.
- **F2 and F4:** they now have tests that kill MG, MH and MD.
- **F5:** fixed.

The two new findings below are both P3 and can be deferred.

I also note a miss of mine. My original review never ran `StagingEnvironmentAdmissionTest`, so I did not catch that it had failed since `a7505c32`. Commit `f9876183` fixes it correctly.

## Findings

| ID | Sev | File:line | Finding | Evidence | Fix |
| --- | --- | --- | --- | --- | --- |
| R1 | P3 | `ProductionFreeGrantTransactions.php:103-109` | Nothing tests the `! $contended` exemption. The HTTP contention test throws a synthetic 40001 while the SQLite frame is still owned, so `abort()` returns true either way. On MySQL the exemption is what keeps a real deadlock as `contention`: the server rolled back, so the savepoint is gone and `abort()` returns false. I judge the logic correct. A contrived residual remains: a listener's raw commit followed by a later deadlock would report `contention` although the write is durable. Retry is idempotent by request key, and a repeated redeem gets 409 `already_redeemed`, so no bytes. | RF1C (drop `! $contended`): HTTP contention test OK, so the mutation survived. | Add a native deadlock case, or document the exemption as reasoned, not tested. |
| R2 | P3 (by design) | same | The change errs on the side of `outcome_unknown`. A listener's rollback-then-reopen (nothing durable) is now `outcome_unknown`, and so is an `abort()` that throws before the frame began. Both are conservative, never a false refusal of a durable write, and are documented ("a vanished frame is indistinguishable from a committed one"). | Test `test_a_raw_rollback_and_reopen_…_is_an_unknown_outcome…` | None needed. |

### Answers to the five checks

**1. F1 classification.**

- A genuine pre-commit refusal still rolls back through `abort()`, which returns true, so it stays a refusal. This covers:
  - a refusal from the work itself, from a committing listener, or from the terminal proof;
  - nesting, where `RELEASE` of the outer marker also releases the inner `trans2` savepoint;
  - a dispatcher swap.
- A frame that never began has `began()` false, so the original error is rethrown as a refusal.
- Deadlock and lock-wait timeout go through `$contended`, so they stay `contention`.
- A durable write by a listener's raw commit, with or without a reopen, is now `outcome_unknown`. It is no longer reported as a refusal: RF1 kills all three tests.
- A failure after the physical commit is still `outcome_unknown` through the earlier `proved` branch.
- Over-reporting is limited to R2. Residuals are in R1.

**2. F2 and F4 tests.**

- They are meaningful and kill the target mutations:
  - RMH: the accept graph returning `[]` fails `test_revocation_inside_the_accept_commit…`.
  - RMG: the redemption-commit graph returning `[]` fails `test_revocation_inside_the_redemption_commit…`.
  - RMD: equality reduced to `count === 1` fails `test_a_compared_column_altered…`.
- The F4 helper recreates the update trigger in a `finally` block from the server's own text (`SHOW CREATE TRIGGER` "SQL Original Statement" on MySQL, `sqlite_master.sql` on SQLite). It then runs `ProductionFreeGrantSchema::assertOwned`, which checks trigger names and action statements exactly, so a wrong restore fails the test itself.
- It cannot leak across tests: each test uses a fresh database (in-memory SQLite, or a re-migrated MySQL schema), and the restore is unconditional.
- The re-seal keeps the row self-consistent, so only the column comparison can see the change.

**3. F5.** There are now four tiers, with limits unchanged:

| Tier prefix | Routes | Limit per minute |
| --- | --- | --- |
| `-reads` | index, show | 60 |
| `-review` | review | 30 |
| `-writes` | accept, authorize (shared) | 20 |
| `-redeem` | redeem | 20 |

This matches the per-tier pattern in Tax. The new test makes 25 library GETs, then an authorize and a redeem that must both succeed. It is a real control: with the old shared prefix, the authorize would be request 26 against a limit of 20 and get 429, as the author also recorded.

**4. f9876183.**

- Classifying the mount as local-only is correct. Its gate is `in_array($environment, ['local', 'testing'], true)` and it must stay off staging, the same as `TaxHttpMount`, which sits in the same `LOCAL_ONLY_FILES` list with the same rationale.
- The test's intent is intact: only the list gained an entry, and the regex and assertions are unchanged.
- MS (removing the new entry) fails `test_source_census_routes_every_admitting_gate_through_the_helper_and_leaves_local_only_gates_explicit`, which confirms both the original failure and the fix.

**5. Docs.**

- Accurate. Ops, packet and CHANGELOG now say the observer detects a callback's raw or implicit commit but cannot prevent it, that it answers `outcome_unknown`, and that deadlocks stay `contention`.
- The deferred F3 and F6 to F9 are listed.
- The workflow comments (2481 of 9495) match the discovery result the author recorded. The four safeguards pass.
- The imported `docs/verification/production-free-mount-20261018/independent-review-12441893-DECISION.md` is byte-identical to `scratchpad/d1-review-12441893.md` (`cmp` passes; both SHA-256 `734ae9ac54b21be8b6b6e6d529730aa8e06edd35bc84998e1db66e6dd5cf599c`).

## Commands and results

All runs at HEAD `b95294e0`, PHP 8.4.26, PHPUnit 12.5.34, SQLite, `public/build` absent.

| Command | Result |
| --- | --- |
| SQLite on `tests/Feature/ProductionFreeGrants` (rerun after the container restart) | 300 tests, 2880 assertions, 9 skipped, exit 0, 19:18 |
| SQLite on `tests/Feature/StagingEnvironmentAdmissionTest.php` | OK, 15 tests, 229 assertions |
| `scripts/ci/test-phpunit-shards.py`, `test-ci-scope.py`, `test-workflow-cadence.py`, `test-focused-tests.py` (Node 24) | 52 OK, 27 OK, 14 OK, 53 OK; all exit 0 |
| `cmp` of the imported record against the scratchpad record | identical |

## Mutations

Each mutation was a temporary edit of a tracked file, restored from a byte copy and checked with `cmp`.

| ID | Mutation | Tests | Result |
| --- | --- | --- | --- |
| RMG | Redemption-commit graph returns `[]` | FirstByteTest | Killed (1: `test_revocation_inside_the_redemption_commit…`) |
| RMH | Accept graph returns `[]` | CommitObserverTest | Killed (1: `test_revocation_inside_the_accept_commit…`) |
| RMD | First-byte equality reduced to `count === 1` | FirstByteTest | Killed (1: `test_a_compared_column_altered…`) |
| RF1 | New `outcome_unknown` branch disabled (`if (false)`) | CommitObserverTest | Killed (3: raw commit, commit then reopen, rollback then reopen) |
| RF1C | `! $contended` removed from the branch | HTTP contention test | Survived (R1) |
| MS | Mount classification removed from `StagingEnvironmentAdmissionTest` | StagingEnvironmentAdmissionTest | Fails (1), as intended |

## Untested by me

- **Native MySQL:** I ran nothing natively in this delta. The author reports six native cases passing.
- **F4 on MySQL:** the trigger round-trip with the "SQL Original Statement" text containing a DEFINER clause was not exercised by me.
- **R1:** no real MySQL deadlock path was exercised.
- **Full suite:** the lead's full SQLite suite in `/home/user/wt/d1full` is separate and I did not touch it.

The worktree is clean at `b95294e0f15c2a80f2917c61e53d02715a7e40ea`.
