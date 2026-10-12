DECISION: APPROVE

# Independent final evidence and documentation review: MFA current consumption

- Reviewer: independent Codex reviewer `/root/mfa_current_read_review`, 2026-10-12.
- Final approved candidate: `c5fbb6bf1c0c406293a28f5e438524d30f14784a`.
- Application/test source: `abe2956bef770bf8bedbe02e0c49500e9b2065da`, previously reviewed against `cb69203e3c7947366ee8a75758cd2f0f927bcfed`.
- Evidence/documentation review: `08042d465d946a7892e0bfb0564e3891978818f9`, then corrected `2fadc60b01de661d7397b660cffa29b00538b88a`, then final whitespace-only receipt normalization at `c5fbb6bf`.
- This supplements `independent-review-abe2956b-DECISION.md`. No tests rerun, database access performed, or shared repository files edited by this reviewer. Verification here used Git comparisons, source reads, hashes, log comparisons and XML parsing.

## Findings and closure

No open P0, P1, P2 or P3 findings. The source assessment remains unchanged: the current locking consumption read closes the old REPEATABLE READ snapshot hole; existing operator serialization and atomic rollback remain required and preserved.

One P3 evidence-format issue was found during final review at `08042d46`: path normalization inserted literal `<worktree>` and `<sqlite-worktree>` strings into XML attributes, making all three nonempty archived JUnit reports invalid XML. It is **closed** at `2fadc60b`: plain `WORKTREE`, `SQLITE_WORKTREE` and `SHARED_VENDOR` path placeholders parse correctly and preserve every test result. The final `c5fbb6bf` delta only documents trailing-whitespace normalization, removes a trailing blank line from the stopped setup log and adds the missing final newline to Pint output. `git diff --check abe2956b..c5fbb6bf` passes.

## Source continuity and evidence

The complete diff from `abe2956b` through the approved candidate is documentation, retained evidence and workflow comment counts only. Application, tests, selection scripts/policies, configuration, routes, migrations and dependencies are unchanged. Recorded SHA-256 values for the baseline authority class, fixed authority class and regression file independently match Git object contents:

- Baseline authority: `4910b74278cb232421f3982f8fcf9801d602c7b2338e42b2f4f28adc9979c36c`.
- Fixed authority: `cc51104c56eb77e30d95a0eb29eafe85190fcbea3e5b7eebbc7545c8343607e2`.
- Regression: `7739df0b3df14795e39d7b876c1c5a97b41ee6f18306f3b64694e2bc0a66155b`.

Parsed both raw and corrected archived JUnit and reconciled individual test cases/assertions with reported totals:

| Run | Verified result |
| --- | --- |
| Old-source native regression | 1 test, 8 assertions, 1 intended refusal-assertion failure, zero errors/skips. Corrected fixture source equals the candidate regression; application equals the baseline. |
| Fixed focused native run | 21 tests, 381 assertions, zero failures/errors/skips. Exactly the complete 13-case `RightsScopeStaffMfaTest`, complete 7-case `RightsScopeCommandAttestationTest`, and new regression (12 assertions). |
| Focused SQLite | 83 cases, 918 assertions, zero failures/errors, 11 skips. Exactly 72 executed cases pass. All 11 skipped case identities belong to the concurrency file and match the reviewed SQLite skip census. |

The execution packet records private Oracle MySQL 8.4.11, PHP 8.4.26 and PHPUnit 12.5.34, exact commands, the fixed/red source commits and a separate exact-source SQLite worktree. The retained native-selection proof reports 1,846 selected cases in 166 files of 7,801 discovered cases; workflow comments match. The 61 receipt, 52 sharder and 50 final focused-policy checks and Pint result are represented accurately. Selection discovery is correctly distinguished from executing that selection.

At `2fadc60b`, every archived `.txt`/`.xml` is byte-identical to its raw counterpart after precisely the disclosed ANSI removal and path substitutions; the subsequent final newline changes are disclosed and contain no result alteration. The stopped setup attempt, empty setup XML, bad policy filename and initial census-order failure remain retained and correctly excluded from passing evidence. The final packet and changelog accurately bound the results and preserve unresolved separate work.

## Approval boundary

The earlier completed-evidence and final-documentation conditions are satisfied. Approval covers this focused development batch at `c5fbb6bf1c0c406293a28f5e438524d30f14784a`; a subsequent receipt-only commit can carry it forward after confirming its delta. A green cheap preflight on the exact proposed merge head remains the repository merge gate, not a test result established by this review.

The evidence proves a deterministic two-connection older-snapshot interleaving, not simultaneous attested worker lock waits or audit-table load behavior. The two full attestation suites ran natively, but the other ten existing cases in the concurrency file did not run in this focused native selection. No live host/provider/account, interactive-console, browser, full Foundation/GitLab matrix or launch-readiness acceptance is implied. The operator lock remains required; there is no unique consumption key. Separate `PrepareExclusiveOffer`, panel-only writer and membership work remains outside this batch.
