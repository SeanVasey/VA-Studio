# Independent review: G1 N-1 revised native test (9a99e6b9, ef82ff36)

- Branch `claude/g1-token-20261104`, worktree `/home/user/wt/g1`, head `ef82ff36e7886ee0fa8865951a96ce4866d65f9c`
- Reviewed: `9a99e6b9` (test rewrite) and `ef82ff36` (packet README), against parents `1ae9192e` and `284abdc4` (original test)
- Source read: `app/Domain/Customers/ProductionIdentity/SchemaProofFrame.php`, `CatalogLease.php`, `CurrentCatalog.php`, `IdentityMetadataAdmission.php` (requireCurrent), every `SchemaProofFrame::prove` and `CatalogLease::open` call site in `app/`
- Reviewer: Claude (independent subagent), 2026-11-04

## Decision: APPROVE WITH CONDITIONS

Condition before merge: fix P2-1 (the preserved failure log is not in git). The P3 items are recommendations. None of them blocks merge.

## Answers to the five questions

**1. Is the explanation of the STOP correct? Yes, with one precision.**
- `SchemaProofFrame::prove` (SchemaProofFrame.php:96-105) goes to `consult()` whenever a frame is registered for the PDO. `consult()` opens `CatalogLease::open($this->primary)` at :148, before `consultCurrent()` at :149. The token and remembered-proof lookup are at :167-173, so the lease always runs first on a framed consult.
- `CatalogLease::open` with the default `boundary=true` calls `CurrentCatalog::enter()` (CatalogLease.php:42-43). `enter()` calls `verifyLive()` only when `boundaries === 0` (CurrentCatalog.php:165-166). The frame's own lifetime lease is opened with `boundary=false` (SchemaProofFrame.php:82), so a top-level framed consult does see `boundaries === 0`.
- `verifyLive()` (CurrentCatalog.php:622-636) re-runs `context()`, whose `CONTEXT` selects `@@SESSION.character_set_results charset_results` (:46). It then compares the whole row with the captured identity (:628).
- Precision: the refusal actually comes first from `context()`'s fixed pin `charset_results !== 'utf8mb4'` (:661). That check runs before the identity comparison at :628. Both checks refuse, and the catch at :635 rethrows the generic refusal. This matches the preserved trace: 738 ← 635 ← 166 ← CatalogLease:43 ← SchemaProofFrame:148 ← :104 ← test:172. `a2144d4a` touches only `CatalogSocketBinding`, so these line numbers hold at the reviewed head.

**2. Is rewriting the test legitimate? Yes. Refusal is intended, fails closed, and does not hide a defect.**
- The `utf8mb4` pin in `context()` is explicit and deliberate. It sits in the same list as REPEATABLE-READ, STRICT_MODE, `+00:00`, `foreign_key_checks=1` and the default `sql_select_limit`. A session with a non-`utf8mb4` result charset cannot capture a catalog, open a frame or pass a top-level framed consult.
- On refusal, `consult()` closes the frame permanently (:150-154) and rethrows. No remembered proof answers.
- The original premise ("the complete proof reruns and passes under latin1") was a source assumption. The 1ae9192e packet itself pre-registered a refusal as STOP/UNPROVEN and said not to relax assertions or claim token-only isolation. The author recorded the STOP, kept the original source in history (284abdc4, 7f467682) and claims no mutant kill. That honours the preserve-failures rule, apart from P2-1.
- The rewrite asserts refusal, which is a stronger safety outcome than "reran and passed", not a relaxed one. It also still pins the primary control. If `charset_results` were removed from `CONTEXT` or from `context()`, the consult would reach the token, miss, rerun the proof and probably pass. `refused()` would then fail with "Guard unexpectedly passed".

**3. Does the revised test assert the real N-1 property, and is any of it fragile?**
- Proven: (a) an unchanged session reuses the proof (calls stay 1); (b) after changing only `character_set_results`, with the other token legs sampled as unchanged, the framed consult refuses and the callback never runs, so no answer comes from memory; (c) after restoring, the guards run for real; (d) a plain transaction passes afterwards (`guardsOutsideAFrame`).
- The post-restore consult does run unframed. `close()` unregisters the frame (:138-140), so `prove()` takes the `$prove()` branch at :99-102. `$prove` calls `$this->guards($pdo)`, which runs `MembershipSchema::assertOwned` and `IdentityRows::from` in full. The old frame lease was released at holders 0 and invalidated, so those guards capture a fresh `CurrentCatalog` under the restored charset. They do not reuse the failed one. The `calls === 2` result therefore reflects a real complete proof.
- The unframed result is also expected: `prove()` without a frame always calls the callback. One more consult would be needed to show that the frame really closed rather than merely lost its memo (P3-2).
- The test checks only the exception class (P3-3), and the `$changed = 'utf8mb4'` branch can never run (P3-4).
- Assertion count matches the reported 14 (1 + 2 + 4 + 2 + 3 + 2). The original run's 7 assertions before the error also match.

**4. Is "closed by the lease's context check; the token leg is defence in depth" accurate? Mostly, but "every framed consult re-reads the context" is too strong.**
- Unframed consults: there is no memo, so the token does not matter. Terminal phase: `token()` is skipped and `$prove()` always runs (:167-170), so the token does not matter there either. Top-level framed consults: the lease refuses before the token is read.
- Residual path where only the token leg protects: a framed consult nested inside a boundary that is already open on the same `CurrentCatalog` (`boundaries > 0`). There `enter()` skips `verifyLive()`, and `retain()`/`requireCurrent()` check no session charset (`IdentityMetadataAdmission::requireCurrent` is PHP-object state only). Concrete instances:
  - `BillingSchema::inspect` opens a boundary lease (BillingSchema.php:82), then calls `MembershipSchema::assertOwned` (:87), which runs a framed consult for `membership`.
  - The test's own callback: the outer `result_charset` consult holds a boundary while `$this->guards()` runs nested framed consults for `membership` and `identity`.
- In both cases the outermost `enter()` ran `verifyLive()` with no SQL in between, and the outermost `leave()` runs `verifyLive()` again (:177-178). A charset that is still changed is therefore refused before control returns to the caller. I found no production path that changes `character_set_results` inside an open boundary. Today the token leg is defence in depth, as stated. But it is the only frame-local defence for nested consults, and nothing tests it (P3-1).

**5. Census: confirmed.** The method name is unchanged at test:138. `scripts/ci/database-sqlite-skips.json` has 337 `methods` entries, no duplicates, and the last entry is exactly `[Tests\Feature\ProductionMembershipBilling\MembershipSchemaProofFrameTest, test_native_result_charset_alone_invalidates_a_remembered_complete_proof]`. `9a99e6b9` and `ef82ff36` do not touch any census file. `database-mysql-selection.json:132` already includes the file.

## Findings

### P2-1: the "preserved" original failure log is not in git
- Where: docs/verification/g1-token-runbook-20261103/README.md:43 links `original-failure-native-positive-284abdc4-ipv6.log`. The 9a99e6b9 commit message says "The original failure log is preserved alongside."
- Failure path: `.gitignore:1` (`*.log`) ignores the file (`git check-ignore -v` confirms; `git status --ignored` shows `!!`). It exists only in this worktree. After push or merge, the link is dead and the STOP evidence that AGENTS.md says to preserve exists only in commit prose. The README and the commit message both make a false claim.
- Fix: `git add -f` the log, or rename it to `.txt`, or inline the PHPUnit error block (exception, trace, `Tests: 1, Assertions: 7, Errors: 1`) in the README. Then re-point the link.

### P3-1: no test covers the token leg; the nested-boundary path relies on it alone
- Where: SchemaProofFrame.php:232-233 (the token leg); CurrentCatalog.php:165 (`verifyLive` only at `boundaries === 0`); BillingSchema.php:82-87; README.md:47 and :54.
- Failure path: a future change drops `character_set_results` from the token (the 2ba71e16 mutant). The suite stays green because the revised test survives it. If a later caller then changes the charset inside an open boundary before a framed consult, that consult answers from memory. Only the outermost `leave()` would catch it, and only if the charset is still changed.
- Fix (optional, kills the mutant): add a native variant that holds an outer boundary, `$outer = CatalogLease::open($pdo)`, after the first remembered consult. Change the charset, consult, and expect `calls === 2` (a real rerun; the mutant gives 1). Restore the charset before `$outer->close()` so that `leave()`'s `verifyLive` passes. At minimum, change README:47 to say `verifyLive` runs when no other boundary is open (`boundaries === 0`), name the nested residual, and note that the refusal comes first from the fixed `utf8mb4` pin (CurrentCatalog.php:661).

### P3-2: one consult after close does not show the frame is closed
- Where: MembershipSchemaProofFrameTest.php:180-181.
- Failure path: a regression that cleared `proofs` on failure but left the frame registered would also give `calls === 2` there. The message "A closed frame never answers from memory again" claims more than the test checks.
- Fix: consult once more and `assertSame(3, $calls)`. A still-registered frame would remember and stay at 2.

### P3-3: the refusal assertion checks only the class
- Where: MembershipSchemaProofFrameTest.php:174, using `refused()` at :381-391.
- Failure path: any `LogicException` passes, such as "Native captured primary required." or a refusal unrelated to the charset. Causality now rests only on the unchanged-legs sample.
- Fix: also assert the message `Current private catalog admission required.`, or catch and check it inline.

### P3-4: the `$changed = 'utf8mb4'` branch cannot run, and the test name is now loose
- Where: MembershipSchemaProofFrameTest.php:146 and :138.
- Failure path: `context()` refuses any non-`utf8mb4` result charset at capture, so `$results === 'latin1'` can never open a frame. The branch misleads readers. The method name says "invalidates a remembered complete proof", but the behaviour is now refuse-and-close.
- Fix: replace the ternary with `assertSame('utf8mb4', $results)` and `$changed = 'latin1'`. Keep the name, since the census pins it, but add a docblock saying the native result is refusal.

### P3-5 (adjacent, not in this diff): the sibling test's comment is stale, and the same redundancy likely applies to other token legs
- Where: MembershipSchemaProofFrameTest.php:117-121.
- Failure path: the comment says the complete proof refuses after `foreign_key_checks=0`, `sql_select_limit=2` or `character_set_results=utf32`. All three are fixed values in `context()` (CurrentCatalog.php:658-662), so the lease refuses first. The test still passes (it accepts `LogicException` or `Throwable`) but documents the wrong mechanism. By the same reasoning, the token's `DATABASE()`, `CONNECTION_ID()`, `foreign_key_checks`, `sql_mode` and `sql_select_limit` legs are also mirrored in `CONTEXT`, so their mutants probably survive for top-level consults too.
- Fix: record this as a follow-up for the G1 lane, not in this PR.

### P3-6 (information): the tested source is not the branch head
- Native runs used `eb1cd7b7` = `9a99e6b9` + `a2144d4a` (lane ACC-IPV6, `CatalogSocketBinding` only). `ef82ff36` is documentation only. The README records this honestly. Merging without `a2144d4a` is fine for a host with IPv6; the native result was obtained only with it.

## Verification I ran
- `git show` of 1ae9192e, 284abdc4, 9a99e6b9, ef82ff36 and 2ba71e16 (confirmed the mutant is the one-line token SELECT change). Confirmed 7f467682 and eb1cd7b7 are 284abdc4 and 9a99e6b9 plus `a2144d4a`.
- SQLite run of the file, exact command from the brief, at head ef82ff36: `OK, but some tests were skipped! Tests: 9, Assertions: 53, Skipped: 1` (the native method skips by design).
- `php -l` on the test: no syntax errors. `vendor/bin/pint --test` on the test: passed.
- Census check with python: 337 entries, last entry matches, no duplicates.

## Not checked
- I did not run any native MySQL check. I did not reproduce the 14-assertion pass, the 7-assertion STOP or the surviving mutant; I relied on the packet and the preserved log. I did not start or touch MySQL.
- I did not run the P3-1 nested-boundary variant, so I have not verified that the complete proof passes under latin1 inside an outer boundary.
- I did not audit every production code path for a same-session `SET character_set_results` inside an open boundary lease. I inspected only the `CatalogLease::open` and `SchemaProofFrame::prove` call sites listed above.
- I did not run the full suite, Foundation CI, the census-validator script or a MySQL selection or partition recount.
- I did not review `a2144d4a` itself.
