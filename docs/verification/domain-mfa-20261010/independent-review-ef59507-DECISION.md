DECISION: APPROVE WITH CONDITIONS

# Independent review: domain staff MFA follow-up (console attestation bind + spend, refusal wording)

- Worktree: /home/user/wt-fu-mfa, branch harness/followup-mfa-20261011
- Reviewed range: 2f1cacc7ede5b766cf9e1d99cd11300a3e6ef9e8..6adaf7c (772fff1 product+tests, 6adaf7c docs)
- HEAD ef5950795ab4253ad754a3dd5f0999ec00b02974: `git diff 6adaf7c ef59507 --stat` shows only docs/legal/README.md,
  docs/legal/TERMS.md, docs/verification/launch-documents-20261010/README.md and its pass17 evidence file (PR #94).
  No product or test file changes in the merge.
- Reviewer: Claude (independent subagent), 2026-10-11. No commit, push or MySQL server. Tree clean and HEAD ef59507 at end.

## Commands and results (SQLite in memory, PHP 8.4.26, PHPUnit 12.5.34, public/build absent)

Runner: the direct runner given in the brief, plus `--colors=never --do-not-cache-result` (scratch `run.sh`).

| Run | Source | Result |
| --- | --- | --- |
| PromotionAdministrationStaffMfaTest, RightsScopeStaffMfaTest, RightsScopeCommandAttestationTest | HEAD ef59507 | OK, 26 tests, 419 assertions (matches the recorded green) |
| RightsScopeCommandTest, StagingEnvironmentAdmissionTest, RightsScopeWriterAuthorityTest | HEAD ef59507 | OK, 46 tests, 499 assertions, rc 0, no skips |
| Red: RightsScopeStaffMfaTest + RightsScopeCommandAttestationTest with the three product files restored from 2f1cacc | tests of HEAD | rc 1, 20 tests, 296 assertions, 6 failures: the five new/changed StaffMfa cases (context fields, tampered reference, reuse, rollback/identical retry, state/version/reference binding) and the command wording case. Matches the evidence file. Product files restored with `git checkout --`; sha256 of all three matched the pre-red values |
| M20 (already-used check removed) | StaffWriteAuthority.php | KILLED: 13 tests, 148 assertions, 2 failures (register replay; replay after the write) |
| M21 (consume() not called) | same | KILLED: 2 failures (same two cases) |
| M22 (reference unbound in binds()) | same | KILLED: 2 failures (retry with another reference; block with another reference) |
| M25 (recorded() ignores reference_hmac) | same | KILLED: 1 failure (tampered `reference`) |

Each mutant was applied by exact unique string replacement, run against RightsScopeStaffMfaTest, then restored with
`git checkout --`; sha256 4910b742...c36c matched after every restore. Results match the evidence file line for line.

Not run by this review: native MySQL (not permitted), the shard-count script behind the workflow comment
7791 -> 7795, Pint, the six fixture-caller suites, the MySQL-only suites.

## Assessment

### Consumption correctness and race safety

- Path: `ManageRightsScope::{register,link,block}` -> `DB::transaction(..., 5)` -> `authorize()` locks the actor
  row FOR UPDATE as the first statement -> `requireFor()` -> `binds()` (operator id must equal the locked row) ->
  `recorded()` -> `consume()` (plain `exists()` then insert). All three writers call `authorize()` first, so no
  plain read precedes the operator lock in a top-level transaction.
- MySQL REPEATABLE READ: InnoDB assigns the read view at the first consistent (non-locking) read, not at `START
  TRANSACTION` (Laravel does not use `WITH CONSISTENT SNAPSHOT`). The first plain read here (`Gate`/scope-key read/
  `recorded()`) happens after the operator lock is granted; a concurrent user of the same attestation holds that
  lock until it commits, so the waiter's snapshot includes the committed consumption row. The plain read is
  therefore sufficient for every current caller. A deadlock retry restarts the whole transaction with a new view.
- The documented residual is accurate: a caller that wraps the service in an outer transaction with an earlier
  consistent read (savepoint nesting) would carry an older snapshot, and a second connection could miss the
  consumption. It is bounded by the full-operation binding (identical operation only, which the domain treats
  idempotently: register/link return the existing row, block hits INVENTORY_CONTROL_CONFLICT after the version
  moves or is a no-op). The only production caller (`vasey:rights-scope`) opens no outer transaction. I accept the
  residual for a staging-only attribution control. A locking read (`lockForUpdate()->exists()` would use the
  `actor_id` FK index, taking next-key locks on that operator's audit rows) or a unique key on a dedicated
  consumption table would remove it; the unique key is the robust option but needs a migration.
- SQLite: `lockForUpdate` is a no-op, but SQLite transactions are serializable with a single writer. Two deferred
  transactions that both read "unused" cannot both commit their insert: the second gets SQLITE_BUSY/"database is
  locked" (a Laravel concurrency error, so `DB::transaction(..., 5)` retries and the retry sees the marker and is
  refused). No double admission. Not exercised by a test (SQLite is local only).
- Lock ordering: the new consumption insert into `audit_events` occurs after the users lock and before the catalog
  locks (tracks/offers/rights_scopes). users-before-audit_events already existed (every writer records its audit row
  after its locks), so no new ordering class; the insert now happens while fewer locks are held. Other code takes
  FOR UPDATE on `audit_events` by unindexed `subject_type/subject_id` (MembershipEvidence, ProductionTrackPolicyAuthoring)
  which on MySQL can gap-lock the table end and delay any insert; that is pre-existing and InnoDB detection plus the
  5-attempt retry covers a deadlock. Not exercised on MySQL for the attested path.

### Rollback semantics

A refusal after admission (SELECTION_CHANGED, INVALID_QUOTE_REQUEST on the reference, INVENTORY_CONTROL_CONFLICT,
INVENTORY_SCOPE_CONFLICT) rolls the marker back, so the value can retry only the exact operation it binds within
900 s. That is the intended and safe behaviour: at most one committed write per attestation, and the retry is the
operation the operator attested. Admitted no-ops (register unchanged, existing link, block already in state) commit
the marker and spend the attestation, which is consistent with "admits one write". The console records a new
attestation per invocation anyway, so the retry window is only reachable by in-process callers.

### HMAC keyed by the application key

`hash_hmac('sha256', $reference, config('app.key'))` (the raw config string, including any `base64:` prefix, is a
valid key). Rotation inside the 900 s window makes `binds()` and `recorded()` fail -> REFUSAL (fail closed);
`APP_PREVIOUS_KEYS` is not consulted. Acceptable. See finding L2 on the confidentiality rationale.

### `recorded()` type comparisons

Context is cast `array`; `true/false` round-trip as PHP bool, integers as int, absent/`null` as null on SQLite JSON
text and MySQL JSON (MySQL normalises key order and whitespace, not scalar types). Strict `===` is correct. Rows of
schema_version 1 lack `reference_hmac` and are refused by the `is_string` guard (fail closed). MySQL round trip of
the new fields is not tested (correctly stated as not claimed in the README).

### Bypass via other callers

`ManageRightsScope` is reached only from `ManageRightsScopes` (register/link) in app/ and routes/; `block` has no
non-test caller. `requireFor()` is called only from `ManageRightsScope::authorize()`. `PromotionAdministration`
calls `require()` only and accepts no attestation. The constructor is private; `attest()` refuses outside the console.
`requireFor()` is public and relies on the caller holding the operator row FOR UPDATE (documented in its docblock);
a future caller that omits the lock would lose the serialization. No bypass found today.

### Command wording

`attestationAuditId` is set only after `attest()` records a row; every later `refuse()` (domain refusals,
AuthorizationException, unregistered scope, missing revision) happens after the domain transaction rolled back, so
"No rights-scope write occurred; the attestation audit record (audit_event=N) remains." is true in each case. Before
an attestation the original wording is kept (new test). The Throwable path keeps its "result is unconfirmed" text,
which is correct because a commit may have happened.

### Docs accuracy

README follow-up section, THREAT-NOTE rows and CHANGELOG match the code and the evidence; the red/green and mutant
results reproduced exactly. Runbook step 5 is accurate. The workflow comment count (7795) was not re-verified here.

## Findings

| ID | Severity | Finding |
| --- | --- | --- |
| L1 | Low | Already-used check is a plain read relying on the operator lock preceding any consistent read. Correct for all current callers; the documented outer-transaction residual is real but bounded to the identical operation. Hardening option: locking read, or a unique constraint on a consumption table (migration). |
| L2 | Low (docs) | THREAT-NOTE/code comment justify the HMAC as keeping a guessable reference out of audit history, but the pre-existing `commerce.inventory.control_changed` audit stores an unkeyed `sha256(reference)` and `rights_scopes`/`rights_scope_offers` store `evidence_reference` in plain text. The HMAC is harmless; the rationale should not imply the reference is otherwise protected. |
| L3 | Low (coverage) | RightsScopeStaffMfaTest and RightsScopeCommandAttestationTest are not in `scripts/ci/database-mysql-selection.json`, so Foundation CI will not exercise the MySQL JSON round trip of `blocked`/`expected_version`/`reference_hmac` or the attested lock sequence. |
| I1 | Info | M31 (`transactionLevel() === 0` guard) survives, as disclosed; defence in depth only. |
| I2 | Info | `requireFor()` is public with a lock precondition stated only in its docblock. |

No Critical, High or Medium findings.

## Conditions

1. Before the attested console path is relied on in staging (or in the final integrated verification), run
   RightsScopeStaffMfaTest and RightsScopeCommandAttestationTest once on native MySQL 8.4, or add them to the
   reviewed MySQL include list. Until then keep "MySQL not tested" in the evidence, as it currently is.
2. Any new caller that passes an attestation to `ManageRightsScope`/`requireFor()` must lock the operator row FOR
   UPDATE before any consistent read in a top-level transaction, or the marker check must first become a locking
   read or a unique key (L1). Record this in the lane's follow-up list.
3. Optional, non-blocking: correct the HMAC rationale wording (L2) in a later docs pass.

None of the conditions requires a code change before a development merge under the CI cost policy.
