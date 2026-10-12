DECISION: APPROVE WITH CONDITIONS — source approved; completed native/SQLite evidence and final documentation delta pending.

# Independent review: current attestation consumption reads

- Reviewer: independent Codex reviewer `/root/mfa_current_read_review`, 2026-10-12.
- Reviewed application/test candidate: `abe2956bef770bf8bedbe02e0c49500e9b2065da`.
- Baseline: `cb69203e3c7947366ee8a75758cd2f0f927bcfed`.
- Worktree: `/workspace/VA-Studio-mfa`.
- Scope: the MFA follow-up batch only. Read root `AGENTS.md`, the October 11 handoff and carried review conditions, the actual diff, callers, audit model/schema, Laravel connection naming behavior, regression fixture, native-selection implementation, and retained evidence. No tests rerun, database operations performed, or repository files edited by this reviewer.

## Findings

No actionable correctness or security findings. No P0, P1, P2 or P3 finding is open in this batch.

## Assessment

1. `StaffWriteAuthority::consume()` now executes `SELECT id ... LIMIT 1 FOR UPDATE` instead of a consistent-read existence query. Under InnoDB REPEATABLE READ this is a current read, so a transaction with an older consistent snapshot cannot miss an already committed consumption. The existence criterion and refusal behavior remain equivalent when no snapshot race exists; existing rows produce a non-null model and are refused.
2. The absent-marker case still requires serialization because there is no unique consumption key. All application calls to `requireFor()` go through `ManageRightsScope::authorize()`, which locks the current operator row before checking authority and consuming. Register, link and block run this inside their existing transaction, with the operator identity checked by `binds()`. The change preserves this precondition and the atomic consumption/write rollback semantics. The public helper still relies on its documented caller lock; this is an existing API constraint, accurately retained in the threat note.
3. The new regression is valid for the reported failure. It starts with no transaction, asserts REPEATABLE READ and distinct MySQL connection IDs, establishes a primary read snapshot, commits the real register operation on a second connection, proves its consumption exists there but remains invisible to a primary consistent read, and invokes the real register caller within the older primary transaction. It requires the exact MFA refusal, preserves the outer transaction level, and verifies one committed marker and unchanged winner audit/scope evidence after rollback. It tests a deterministic two-connection stale-snapshot interleaving; it does not claim simultaneous blocking/wait coverage for attested writers.
4. The secondary connection now overrides the copied `name`. Laravel `ConnectionFactory::parseConfig()` preserves an existing name, `Connection::getName()` returns it, and Eloquent can bind inserted models to that name. The explicit override is therefore necessary for this fixture. Candidate test source is unchanged from `1c9b445243da2ae4fdb70d883e1bd67251d9341e`, whose application file equals the baseline.
5. Both full attestation suites are added to the reviewed MySQL include list. The sharder selects complete discovered files, and the existing concurrency file remains selected through the SQLite skip census. Adding the new exact method to that census records its real MySQL-only condition; it does not exclude it from native execution. The retained selection proof reports 1,846 selected cases in 166 files of 7,801 discovered cases.
6. The HMAC documentation now accurately limits its claim to binding the reference without copying it into the attestation row. It acknowledges the unkeyed control audit hash, plaintext rights references and immediate invalidation by application-key rotation.

## Evidence inspected so far

| Evidence | Observed result and limit |
| --- | --- |
| `mysql-red.txt`, `mysql-red.xml` | One test, eight assertions, one intended failure, zero errors/skips. The failure is at the exact refusal assertion: the unchanged application admitted the stale-snapshot replay (`null` refusal). PHP 8.4.26 / PHPUnit 12.5.34 are in the transcript. The lead identifies this as the fixed-fixture red run at `1c9b4452`; the source comparison confirms unchanged baseline application code and candidate-equivalent regression source. |
| `mysql-setup-stopped.txt`, `.xml` | Incomplete setup attempt: text contains the PHPUnit/runtime/configuration header, XML is empty. It is not red evidence. The lead reports the original copied connection name caused this stopped attempt; the fixture correction is independently supported by the Laravel source and `1c9b4452` diff. |
| `native-selection.txt` | 1,846 selected native cases in 166 files, 7,801 total discovered cases, eight nonempty shards. Three missing timing entries use fallback weights; coverage proof succeeds. |
| `pint.txt` | Tool reports passed. |
| `sharder-policy.txt`, `receipt-policy.txt`, `focused-policy-final.txt` | Report 52, 61 and 50 passing policy tests respectively. These are inspected lead-run logs, not independent reruns. |
| `mysql-green.txt`, `.xml` | Still running at this review checkpoint; no completed result claimed. |

## Conditions before final approval

1. Inspect the completed focused native MySQL evidence, including the new regression and both complete attestation suites, and the planned focused SQLite evidence. Bind the runtime/version, command, source SHA and exact results in the dated evidence packet. Resolve any failures.
2. Inspect the final documentation/evidence delta and confirm application/test source remains identical to `abe2956bef770bf8bedbe02e0c49500e9b2065da` (or review any change). At this checkpoint, `279f10eb32712974760215c4781e28b9e77f7ff7` differs from that candidate only in workflow comments and handoff ledgers; its updated selection counts agree with the retained selection log.
3. The exact proposed merge head still needs the repository's cheap preflight. This review does not authorize skipping an enforced check or imply Foundation/final acceptance.

No live host/provider/account, interactive console, browser, Stripe, full Foundation matrix or launch-readiness claim is made. The additional row/range locking cost on the audit table has not been load-tested; this narrow change does not introduce a unique consumption key or remove the caller's operator-lock requirement.
