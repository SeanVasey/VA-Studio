# C4e test-gap preparation — November 3, 2026

Base: exact clean DEF-OPS preliminary `b5d1467bbe00743d0d7298dc2a6d9687c9fec388`; isolated branch `codex/c4e-test-gaps-prep-20261103`. Source-only preparation, C integration remains after MIG → A5 → DEF-OPS. All runtime/lint/tests/mutations are UNRUN.

## Contract before edits

Own only MembershipCustomerIndexAvailabilityTest (prepared R3 patch), MembershipPeriodAddressedOperationsTest (N5 conservative refusal/foreign ordering), MemberActivationCommitWitnessTest (only a faithful real active-PDO commit-failure control if feasible) and this packet. No production, policy, environment-key, schema definition, mount, fixture-original, dependency, vendor, census or shared-ledger changes.

Keep all existing assertions/test bodies unchanged. R3 must detect a newly added writer or a changed locking opt-in, not merely mirror one currently named class. N5 must use the actual period/domain entry and billing decrypt seam, retain zero writes/private generic outcome-unknown response and an operative positive after fault removal. It documents existing read-before-owner behavior, not an authorization reorder.

N4 distinction: the original review's surviving R9 concerns the idle-PDO fallback after PDO::commit itself throws; the documented active-PDO throw is another branch. Callback throws are not PDO commit failures. An existing audit_events.actor_id FK can defer a real SQLite constraint failure until commit without replacing the admitted PDO/connection or changing schema. Before treating this as coverage, require original terminal guards, a real PDO::commit trace/SQLSTATE, successful fault injection, rollback/effect retention and clean state. A SQLite-only active-PDO control cannot close the idle-PDO R9 branch; that condition remains explicit. No new mock/harness or production fault seam is permitted.

## Prepared behavior and the incorrect alternatives it catches

- **R3:** applied the existing external test-only patch (SHA256 `08ada4e49031651921085b66fd796d284d8ad87ee104496255abbab2413e0b37`). It adds a closed recursive app/ census of seven literal history/definition calls across five files, including MemberGrantDefinitions; parser controls fail on changed flags, future writers and unparsed syntax. The original locking-default reflection, named-writer and real index/proof assertions remain independent and unchanged. Incorrect behavior caught: a new writer silently opting into nonlocking reads, an existing writer changing its call, or parser omission hiding a call. The parser is deliberately conservative lexical matching, not universal PHP type inference.
- **N5:** two new period-addressed domain tests use the existing Crypt interception pattern only while both actual SealedMembershipPaidInvoice::lock and MembershipBillingEvidence::read are on the argument-free stack. Identity decrypts delegate normally. A deliberate private billing failure must become operation_refused/503 with exact opaque outcome-unknown body/Retry-After, unchanged retained period/event/redemption effects and clean rollback; the same owner can reserve after fault removal. For a foreign principal, an unknown period never enters billing, while a known retained foreign period currently reaches billing before the owner comparison and yields the conservative503 under that fault. Removing the fault must restore invoice_owner_mismatch/no writes. Incorrect behavior caught: fabricated success, accidental committed classification, raw exception/detail disclosure, collapsing outcome-unknown to404, skipped rollback, or a silent change to the documented read-before-owner contract. This tests/documentation does not endorse an authorization reorder or claim the small existence distinction was repaired.
- **N4 active-PDO branch, narrowly:** one SQLite-only control keeps the original internal PDO/connection/schema, checks foreign_keys=ON, and uses an ordinary TransactionCommitting delegate to enable deferred FK checks and insert an invalid existing audit actor reference. The delegate returns; the original observer still runs its full terminal proof. The test requires successful injection plus a PDOException/23000 with an actual internal PDO::commit frame, source_refused, unchanged original effects/audits, and product rollback/deferral cleanup observed BEFORE test-only recovery. A callback/INSERT/setup failure cannot satisfy these assertions. Incorrect behavior caught: claiming committed-unknown merely because the commit call began when the actual commit failed and the original PDO remained transactional, or retaining provisional effects/transaction state. No production guard is relaxed to force this fault to reach commit. If original guards refuse the injected state earlier, retain the failed test as a setup/branch-coverage failure and keep the branch pending; do not change guards to make the test pass.

**N4 still open:** removing the original `(committing && idle PDO)` fallback (review mutant R9) will not be killed by an active-PDO failure. This batch does not reproduce an actual internal commit exception that leaves PDO idle without the owned positive witness. Replacing PDO/connection or throwing a delegate would exercise a different branch and is rejected. A faithful existing-driver seam for that idle/unknown outcome remains necessary before claiming full N4 closure. No schema, policy or mock-commit mechanism is proposed.

## Engine routing and later ROOT execution — all UNRUN

Four new methods are ordinary shared tests; the new commit-failure method is explicitly SQLite-specific because MySQL has no deferred FK equivalent. No existing test is skipped or weakened. This author did not edit census files. Before final integrated engine discovery, ROOT's normal census integration must record this exact new opposite-engine pair in the sorted reviewed MySQL-skip inventory:

```
["Tests\\Feature\\ProductionMemberGrants\\MemberActivationCommitWitnessTest", "test_sqlite_real_commit_constraint_failure_keeps_the_activation_pre_commit_refusal"]
```

The SQLite census/pinned prefix is unchanged. This documents genuine engine scope, not a native pass or quarantine. Do not run the SQLite fault as a claimed MySQL commit control.

After A5/DEF-OPS verification and exact-source/loader qualification, ROOT should first lint the three changed PHP test files, discover the affected selection and run these five new methods individually on the admitted SQLite profile:

1. MembershipCustomerIndexAvailabilityTest::test_r3_census_classifies_every_history_and_definition_hash_call_under_app
2. MembershipCustomerIndexAvailabilityTest::test_r3_census_detects_writer_opt_ins_new_writers_and_unparsed_calls
3. MembershipPeriodAddressedOperationsTest::test_unexpected_billing_failure_keeps_unknown_outcome_private_and_rolls_back
4. MembershipPeriodAddressedOperationsTest::test_known_foreign_period_reads_billing_before_owner_refusal_but_unknown_period_does_not
5. MemberActivationCommitWitnessTest::test_sqlite_real_commit_constraint_failure_keeps_the_activation_pre_commit_refusal

Then run the three affected existing classes on SQLite, preserving original fixture/guard/budget/origin/capture rules. For native regression scope, the N5 shared methods and existing original commit-witness controls can be selected normally; SQLite does not establish native failure/locking semantics. No new native framework, server, timeout or full matrix is justified by this source prep. ROOT chooses the finite actual native scope after inspecting the real candidate and engine routing.

Meaningful later mutations (each isolated/restored; UNRUN): R3 new writer/flag/default/parser controls as in the existing prep; N5 map an unexpected billing failure to a not-found classification or remove the generic refusal, requiring private503/no receipt; N4 replace the active-PDO-aware classification with unconditional committing=true, requiring source_refused to detect the wrong durable classification. Original R9 remains explicitly surviving/uncovered, not a fabricated kill. Preserve prior failures, setup errors and exact source attribution; do not alter production behavior or expected outputs to make a new test pass.

## Actual source-only checks and status

- Isolated worktree/branch was absent before creation; exact DEF-OPS preliminary base was clean and unchanged.
- `git apply --check` and application of the supplied R3 patch succeeded.
- `git diff --check` passed. Production/config/schema/mount/dependency/census/shared-ledger paths have no changes; the diff adds to three tests and this packet only. All existing test method bodies/assertions remain unchanged.
- No PHP lint, PHPUnit, parser/mutation test, SQL, Docker, vendor operation, materialization, install, build, generator, CI, push, PR or merge was executed. Five new test methods are source definitions, not executed cases or acceptance evidence. Runtime and final independent review remain with ROOT's later coherent C batch; no new B/A5/DEF-OPS gate is created.
