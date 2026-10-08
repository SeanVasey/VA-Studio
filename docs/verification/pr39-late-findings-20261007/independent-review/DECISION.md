# Independent review: `harness/pr39-late-findings` (PR39 late P2 findings)

Reviewer: independent Claude Code subagent, 2026-10-07. I made no source edits. All evidence files are in this directory, `review-evidence/`, which is untracked in worktree `/home/user/VA-Studio-review-p2`.

| | |
| --- | --- |
| Reviewed head | `bf6e5937785129474b8da3bafa9e5dbc98ac5d86` (detached worktree, made with `scripts/dev/mkworktree.sh`) |
| Base | `d20d4394edcc84bf3c5a193f598d9b044b0753aa` |
| Commits | `1d41bedf` fix(identity) · `bf6e5937` fix(suppression) |
| Toolchain | PHP 8.4.26, PHPUnit 12.5.34, Laravel 13.34.0, MySQL 8.4.11 (private instance) |

## Decisions

| Commit | Finding | Decision |
| --- | --- | --- |
| `1d41bedf` IdentityCommittedFrame | r4206829231 | **APPROVE.** The residual is accepted with notes (F-B1..F-B3). Nothing blocks merge. |
| `bf6e5937` SuppressionDelivery/Outbox | r4206829225 | **APPROVE WITH CONDITIONS.** The original finding is closed. Condition C-A1 (a fail-silent regression that I reproduced) must be fixed, and that fix needs a narrow re-review, before this approval carries to a merge. |

Scope: the two runtime commits; their new tests; the lane README and evidence claims; and keeping the prior `5053af53` approval evidence and archived canary intact.

Non-scope: full PHP suite; Foundation CI; browser specs; native consent/suppression DDL admission cases; the native paid-delivery 409 item; SMTP/provider activation; release readiness. I also did not review the rest of PR39 or the cross-lane `:3306` trigger-guard collision.

---

## 1. `1d41bedf`: identity frame SQLite `query_only` restoration

### What I verified

- **Foreign active transaction is never touched.** `settle` calls only `restoreSqlite()`. Both `settle` and `restoreSqlite()` require `! $this->primary->inTransaction()`. No commit, rollback, savepoint or exec runs while a transaction is active.
  - The lane test shows this (framework `SELECT` during the replacement leaves `query_only=1`).
  - My probe shows a stronger case. A pending settle from frame A fires on a framework statement while a *new* frame B is active. B's read-only stays at 1, `B->assertActive()` still passes, and a raw INSERT is refused.
- **`used` stays true.** `close()` sets `used=true` before deferral. `settle` only sets `closed=true`. `assertActive()` checks `used || closed`, so `reader()`, `assertActive()` and `finish()` refuse. This holds while the settle is pending (probe: A refuses while B is active, and B survives the refusal) and after it settles (lane test).
- **No authority or deadline is renewed.** `settle` doesn't touch `originalDeadlineNs`, `defaults`, `reader` or `used`, and the frame emits no output.
- **MySQL path unchanged.** `deferRestoration()` returns early when `driver !== 'sqlite'`. On native MySQL 8.4.11 my probe counted callbacks by reflection: zero `beforeExecuting` and zero `beforeStartingTransaction` callbacks after a replacement close. `transaction_read_only` of the caller's replacement was 0.
- **Hook order.** In Laravel 13.34.0, `ManagesTransactions::beginTransaction()` runs `beforeStartingTransaction` callbacks before `createTransaction()`, and `Connection::run()` runs `beforeExecuting` before the query. A nested framework `beginTransaction` (savepoint level) sees an active PDO, so the settle does nothing there.
- **Effect on someone else's transaction start.** At a top-level framework transaction start on an idle PDO, the settle sets the captured pre-frame default (normally 0) just before `BEGIN`. That replaces a stale 1 with what the caller would have had without the frame, so the caller's semantics are restored, not altered.
  - One exception (F-B2): a caller that deliberately runs raw `PRAGMA query_only=1` in the gap, before its first framework call, has that setting overwritten once.
  - Once settled, the closure is inert. My probe set `query_only=1` deliberately after settlement, ran a framework `SELECT`, and the value stayed 1.
- **Archived canary is byte-identical and passing.** `root-evidence/original/tests/.../ProductionIdentityCommittedFrameTest.php` has sha256 `9a55aa2e...ee81d`, matching both artifact manifests. All 28 + 17 manifest digests verify. `git diff d20d4394 HEAD -- docs/verification/cloud-identity-frame-cleanup-independent-20261007` is empty. The canary passes on SQLite (1 test / 5 assertions) and on native MySQL (see the command table).
- **Pre-fix reproduction.** I ran the branch test file against the base runtime (`d20d4394` worktree): `--filter test_single_close` gave 4 tests, 4 errors, all "attempt to write a readonly database". This matches the lane's claim.

### Findings

- **F-B1, Low: residual gap is accepted.** Between a caller's raw `commit()`/`rollBack()` and its next framework statement, raw-PDO writes see `query_only=1`. That direction refuses writes, so it fails closed and can't weaken any frame.
  - A new frame begun in that gap captures 1 as its default. My probe ran that interleaving: B began in the gap, A's pending settle stayed a no-op while B was active, and B's `close()` restored its stale 1. The next framework write then ran A's still-pending settle first and healed the connection to A's true default 0.
  - The only way to stay stuck at 1 is pure raw-PDO use with no further framework statement. That is again an availability failure, not a safety one.
  - Every frame's `assertActive()` independently re-checks `query_only=1`, so even a wrongly weakened setting would be refused.
  - No PHP 8.4 PDO commit or rollback hook exists, so closing the gap fully isn't practical. I don't require it.
- **F-B2, Info: one-time override in the gap.** As described above, a deliberate raw `PRAGMA query_only=1` set in the gap before the first framework call is reset once to the captured default. This is the same behaviour as the earlier "explicit second `close()`" contract, so nothing new.
- **F-B3, Low: callback retention.** Each deferred frame leaves two closures on the connection permanently. Laravel has no removal API. The closures hold `$this` (frame → `CurrentRows`, PDO), and each runs a cheap `closed` check on every later statement.
  - Growth is one pair per deferred frame, and only on the adversarial path where foreign code commits the frame and leaves a replacement open. Ordinary closes register nothing: my probe ran 3 ordinary closes and the count stayed unchanged.
  - In a long-lived worker this is a slow leak proportional to adversarial events. Acceptable. Optional hardening: hold a `WeakReference` to the frame inside a static closure.
- **F-B4, Info.** The settle uses the captured `primary`. If the connection's PDO is replaced first (`setPdo`/reconnect), the settle runs `PRAGMA` on the old PDO and marks itself closed. That's harmless: a fresh PDO starts at its own default.

---

## 2. `bf6e5937`: retained suppression target after an email change

### What I verified

- **Exactly once to the old address.** In `process()` phase 1, `retained()` selects the oldest target with no attempt and decrypts A from that target's own ciphertext. `graph(A)` re-proves the capture keys, the keyed HMAC against the stored `recipient_hmac`, the latest intent, the withdrawn event, and the event's own capture email.
  - The attempt is created and committed in phase 1. The transport transaction re-selects by the claimed `public_id`, and the existing check binds `graph.target.public_id` and `attempt.public_id` to the claim.
  - A second `process()` finds no unattempted target. It falls back to B, `graph(B)` returns `not_requested`, and nothing is sent.
  - The database enforces one attempt per target (`attempts_target_unique` plus the insert trigger).
- **Nothing is created for the new address.** Delivery has no target, intent or attempt writer for B. Only `CustomerConsentPreferences::change()` (a real withdrawal) creates targets. The lane test asserts one target and one intent.
- **No resend after an unknown outcome.** `process()` attempts only when `graph.status === 'pending'` (no attempt). The adapter is called once per claim, after the attempt commits. `reconcile()` calls only `inspect()`.
- **Grant never unsuppresses.** Targets and intents can't be updated or deleted (`*_retain_update/delete` triggers raise). Selection never removes a row. The existing `test_grant_does_not_unsuppress` passes natively.
- **Confirmation still needs an exact receipt.** The confirmation path is unchanged: `receipt->confirms($request)`, then the graph re-proof and the stored-bytes fence.
- **Consent authority is unchanged.** `terminal()` still runs `ConsentEvidence::prove(... $context['email'] ...)` on the current user row and current email. No consent is inferred.
- **Proof re-capture is consistent.** The `retained()` capture is registered on `outboxProof`. After the attempt or confirmation is appended, `process()` and `receipt()` start a fresh `SuppressionEvidence`, so the selection predicate isn't re-proved against changed rows. On the paths with no append, the predicate is stable and re-proves.
- **MySQL concurrency.** The outer statement is `FOR UPDATE` and the subqueries are non-locking snapshot reads (REPEATABLE READ, the default; `config/database.php` sets no isolation). If T2 is waiting behind T1's lock, a stale snapshot can still select a target T1 has just attempted. `graph()` then reads the attempt with a locking read, the status is `unknown`, and nothing is sent. Combined with the unique `target_id`, there is no duplicate send.
- **Pre-fix reproduction.** I ran the branch test file against the base runtime: 4 tests, 11 assertions, 4 failures. This matches the lane's claim.

### Findings

- **C-A1, Medium (merge condition): an unauthentic older target silently blocks every later genuine target.**
  - **Cause.** `retained()` picks a row and returns only its decrypted email. `graph()` then looks up `HMAC(email)`, so it is never bound to the selected row.
  - **Trigger.** An older target whose ciphertext email doesn't HMAC to its own `recipient_hmac`. The retention insert trigger checks only hex format and uniqueness, so an out-of-band insert or corruption passes it.
  - **Effect.** `graph()` finds no row and returns `not_requested`, not a 503. Because selection is oldest-first and that row never gets an attempt, every later `process()` for the account selects it again. The account's genuine pending withdrawal target is never delivered, and the status silently reads `not_requested`.
  - **Reproduced** by `ReviewP2SuppressionAdversarialTest`:
    - head `bf6e5937`, SQLite: `outcomes=["not_requested","not_requested"] sent=0 attempts=0`, FAIL;
    - base `d20d4394` runtime with the same probe: `outcomes=["confirmed","confirmed"] sent=1 attempts=1`, OK;
    - native MySQL 8.4.11: the same failure.
  - **Why it matters.** This is a regression, and it contradicts the lane README's claim that "a target that isn't authentic still fails with a 503". The same gap applies to `reconcile()` selection.
  - **Required fix.**
    - Have `retained()` return the selected row's `id`/`public_id` along with the email.
    - In `context()`, refuse with 503 unless `graph['target']` is non-null and its id equals the selected id.
    - Add this probe, or an equivalent, as a regression test.
    - The fix is a few lines and needs a narrow re-review.
- **F-A2, Low: unqualified subquery tables (temp-table shadow).**
  - **Cause.** The `NOT EXISTS`/`EXISTS` subqueries name `customer_suppression_attempts` and `customer_suppression_confirmations` without a schema. On SQLite an unqualified name resolves to `temp` first. On MySQL a same-named temporary table shadows the base table. `rows()` checks shadows only for the outer table.
  - **Effect.** A shadow that hides an unattempted target (a shadow row with its `target_id`) makes selection fall back to the current address. If that address has no target, `graph()` never captures attempts, so no shadow check runs, and the result is a silent `not_requested` (non-delivery). An empty shadow instead selects an already-attempted target. `graph()` then captures attempts through `rows()`, which refuses with 503.
  - **Not affected.** A shadow can't cause a resend or a false confirmation, and it needs DDL on the same connection.
  - **Fix.** Qualify the subquery tables the same way `rows()` does (`main."…"` / `` `db`.`…` ``), or run the shadow check for both tables in `retained()`. Fold this into the C-A1 re-review. I don't consider it blocking on its own.
- **F-A3, Low: head-of-line blocking.**
  - **`reconcile()`.** It always selects the oldest unconfirmed attempt under the current binding. If target A's `inspect()` keeps returning null, a later attempted target B is never reconciled, and its status stays `unknown`. This is a trade-off against the pre-fix code, where the old target was unreachable instead. It isn't a resend or consent risk.
  - **`process()`.** An oldest target whose graph 503s (for example, a missing intent) makes every `process()` for that account return 503. That fails closed and is visible.
  - Acceptable for this change. A follow-up could select the next target after a non-advancing outcome.
- **F-A4, Info: old address may now belong to another account.** The provider now receives the old address A. If A was later reassigned to another account, that account's address gets suppressed. This errs towards less marketing (the consent-safe direction) and matches what happens when delivery runs before the change. `read()` still reports only the current address, which is unchanged.

---

## 3. Commands and results

Common setup:

```
SQLite runner (worktree /home/user/VA-Studio-review-p2):
  php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- -c phpunit.xml <args>
Native env:
  APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3419 DB_DATABASE=vaseyaudio_review_p2 DB_USERNAME=root DB_PASSWORD=ci-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
Private mysqld 8.4.11:
  datadir under scratchpad/mysql-review-p2, port 3419, socket /tmp/claude-0/rvp2-3419.sock (the scratchpad path exceeded the 107-byte socket limit).
public/build was absent for every run.
```

| # | Driver | Selection | Result | Evidence |
| --- | --- | --- | --- | --- |
| 1 | SQLite | `tests/Feature/ProductionIdentityAdapters tests/Feature/ProductionIdentity` | 112 tests, 613 assertions, 0 F/E, **9 skipped**. All 9 are native-only skips (names in xml). | `identity-sqlite.{txt,xml}` |
| 2 | SQLite | the 9 consent/suppression files (`CustomerConsent{Admission,Boundary,Migration,Preferences}Test`, `CustomerSuppression{KeyAdmission,Migration,NativeAdmission,SourceBoundary,}Test`) | 178 tests, 1052 assertions, 0 F/E, **3 skipped** (native-only DDL admission cases) | `consent-suppression-sqlite.{txt,xml}` |
| 3 | SQLite | archived canary preloaded from `root-evidence/original/...`, `--filter test_close_preserves_replacement_transaction_read_only_state` | 1 test, 5 assertions, OK, 0 skipped | `archived-canary-sqlite.{txt,xml}` |
| 4 | SQLite | reviewer frame probe `ReviewP2FrameAdversarialTest` | 1 test, 14 assertions, OK | `adversarial-frame-sqlite.{txt,xml}` |
| 5 | SQLite | reviewer suppression probe on head | **1 test, 1 failure** (C-A1 reproduced): `outcomes=["not_requested","not_requested"] sent=0` | `adversarial-suppression-sqlite.{txt,xml}` |
| 6 | SQLite | same probe on base `d20d4394` runtime | 1 test, 3 assertions, OK: `outcomes=["confirmed","confirmed"] sent=1` | `adversarial-suppression-base-d20d4394-sqlite.txt` |
| 7 | SQLite | pre-fix A: branch test file on base runtime, `--filter 'test_retained_and_current\|test_email_change_after'` | 4 tests, 11 assertions, **4 failures** (expected) | `prefix-A-base-runtime-sqlite.txt` |
| 8 | SQLite | pre-fix B: branch test file on base runtime, `--filter test_single_close` | 4 tests, 16 assertions, **4 errors**, "readonly database" (expected) | `prefix-B-base-runtime-sqlite.txt` |
| 9 | MySQL 8.4.11 | frame `--filter 'test_single_close\|test_close_preserves\|test_native_validation\|test_direct_commit'` | 7 tests, 51 assertions, OK, 0 skipped | `frame-native-mysql.{txt,xml}` |
| 10 | MySQL 8.4.11 | reviewer frame probe (MySQL branch: zero callbacks registered) | 1 test, 4 assertions, OK | `adversarial-frame-native-mysql.{txt,xml}` |
| 11 | MySQL 8.4.11 | archived canary (as in row 3) | 1 test, 4 assertions, OK, 0 skipped. The SQLite-only INSERT refusal branch doesn't apply on MySQL. | `archived-canary-native-mysql.{txt,xml}` |
| 12 | MySQL 8.4.11 | suppression `--filter 'test_email_change_after_withdrawal\|test_retained_and_current\|test_grant_does_not_unsuppress\|test_changed_provider_binding\|test_single_positive_attempt'` | 7 tests, 92 assertions, OK, 0 skipped | `suppression-native-mysql.{txt,xml}` |
| 13 | MySQL 8.4.11 | reviewer suppression probe | **1 test, 1 failure** (C-A1 reproduced natively): `outcomes=["not_requested","not_requested"] sent=0 attempts=0` | `adversarial-suppression-native-mysql.{txt,xml}` |
| 14 | — | `php vendor/laravel/pint/builds/pint --test` on the 5 changed PHP files | `{"result":"passed"}` | `pint.txt` |
| 15 | — | `git diff --check d20d4394 HEAD` | clean (exit 0) | — |
| 16 | — | prior-evidence manifests (`artifact-sha256.json`, `root-evidence/artifact-sha256.json`) vs bytes | 28/28 and 17/17 match; dir unchanged vs base | — |

Not run: full suite; Foundation CI; browser specs; native consent/suppression DDL admission cases; the native identity race cases (the lane ran them; I didn't re-run them).

## 4. SHA256 of files relied on (at `bf6e5937`)

```
865b27132de58d5ad39e036e3bd24989bc3ddb16358b652b368ab0c65ad5c3ae  app/Domain/Customers/Preferences/Suppression/SuppressionDelivery.php
8bb317152f56a9ea1a0a3137859555b02e302d03877022f6131b848394a91117  app/Domain/Customers/Preferences/Suppression/SuppressionOutbox.php
4c9f77aecc60436fd7331a95b59ad56fcf8a2427a84b3914a3fa9dd29d0454d5  app/Domain/Customers/Preferences/Suppression/SuppressionEvidence.php
6dfc9d878ae2adb56812fcfd8997f5be4abf7fa7d5daa54848dca4d4b7eb36d2  app/Domain/Customers/Preferences/Suppression/SuppressionSchema.php
65098069d146e0c4a437acefd672f29ac43e7181541a545c97c3c007cdea8126  app/Domain/Customers/ProductionIdentity/IdentityCommittedFrame.php
c694bf3bc7bd123ceb0eae8d0a1045bd252ea713997043675bbc2e2fbe324457  tests/Feature/CustomerSuppressionTest.php
2e025f4ab8f2ac8bcda22f782805277f1461383b08230f43ad3b3021ebb5b2c8  tests/Feature/ProductionIdentityAdapters/ProductionIdentityCommittedFrameTest.php
83537a6bf8c06399bd41a310e0ba134122109c0409d7fffe0d8ed81d19cba770  docs/verification/pr39-late-findings-20261007/README.md
0f0991fd8bf3f30e908504898a70533b88f5a1fb96055b39c73769c6f324ce1f  docs/development-order.md
9c924ac58822c78bfcb1a66f2cdb89f0668a8c69e4f5251d48b0a19e4e7390e7  docs/verification/cloud-identity-frame-cleanup-independent-20261007/artifact-sha256.json
3cd3385a310f37ce7837b0b56a734e9cc362cafebaf2693e51ed14fde440da7e  docs/verification/cloud-identity-frame-cleanup-independent-20261007/root-evidence/artifact-sha256.json
9a55aa2ebec90dfdbc1a15570431c9e26e31e1358cb110dffb14ef858d3ee81d  docs/verification/cloud-identity-frame-cleanup-independent-20261007/root-evidence/original/tests/Feature/ProductionIdentityAdapters/ProductionIdentityCommittedFrameTest.php
ef4d06e1b405485b1285b402760aa53ecb6f0a2f033025e39cc0cf58ea5c70ad  vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php
7ac2392c7236486fd92040bdb55760cd195f7cbc2b8f753fffcda70a5edaf39c  vendor/laravel/framework/src/Illuminate/Database/Connection.php
b40fd29d809b4fb3827696f6749131cb2f3cef4991813bf5c403b3edc10c10f8  review-evidence/ReviewP2FrameAdversarialTest.php
e5c4281848290653788d74bfd1a619203490cb3f2d907af9b16057068498bde9  review-evidence/ReviewP2SuppressionAdversarialTest.php
```

## 5. Cleanup

- I stopped the private mysqld on 127.0.0.1:3419 with `mysqladmin shutdown`.
  - A TCP connect to 3419 is refused.
  - `ps` shows no mysqld whose arguments contain 3419.
- I deleted the datadir `scratchpad/mysql-review-p2` and the socket `/tmp/claude-0/rvp2-3419.sock`, and `ls` confirms both are gone.
- I never used the shared `:3306` server.
- I removed the temporary base worktree (`scratchpad/base-d20d`, used for the pre-fix and base comparisons) with `git worktree remove --force`.
- The review worktree `/home/user/VA-Studio-review-p2` stays at `bf6e5937` with a clean tracked tree. Its only additions are the untracked `review-evidence/` files: two throwaway probes, logs, JUnit XML and this file. I committed or pushed nothing.

Prior approvals stay bound to their recorded commits. This review is not Foundation CI, full-suite acceptance or release readiness.

---

## Addendum: narrow re-review of `f65d921d0d8dee81b83c9cd4bea38d94748317af` (2026-10-07)

Scope: only `git diff bf6e5937..f65d921d`. It touches `SuppressionDelivery.php`, `SuppressionEvidence.php` and `SuppressionOutbox.php`, adds `tests/Feature/CustomerSuppressionRetainedTargetTest.php`, and updates lane docs and evidence. I made no source edits. Review worktree: `/home/user/VA-Studio-review-p2`, detached at `f65d921d`. The evidence files named below live in its untracked `review-evidence/`.

**Decision for `bf6e5937` + `f65d921d`: APPROVE (exact head `f65d921d0d8dee81b83c9cd4bea38d94748317af`).** Condition C-A1 is satisfied. Low finding F-A2 is also closed. F-A3 and F-A4 stand as recorded above; neither blocks merge.

### Verified claims

1. **C-A1 is closed.**
   - `retained()` now returns `['id' => (string) row id, 'email' => decrypted capture]`.
   - `context()` throws `ConsentException(503)` unless `(string) $graph['target']['id']` equals the selected id. That includes the case where `graph()` returns no target.
   - Every caller of `retained()` uses the new array shape. The only caller is `SuppressionDelivery::context()`; the other `retained(` matches in `app/` belong to different classes.
   - The refusal runs inside the existing `DB::transaction` closure, before any attempt is appended and before any adapter call. A 503 therefore writes nothing and sends nothing. On the claimed transport path the existing claim/public_id binding is unchanged and still applies.
   - For authentic rows, `(account_id, purpose, recipient_hmac)` is unique and HMAC(email) matches the row, so `graph()` always resolves the selected row. Legitimate flows don't change.
   - An unauthentic older row now makes every call for that account fail closed with 503. The genuine target is still not delivered while that row exists, but the failure is now loud instead of a silent `not_requested`, which was the outcome C-A1 required. Removing such a row is an operator remediation; the retention triggers prevent doing it from the app.
2. **F-A2 is closed.**
   - The new public `SuppressionEvidence::qualified()` is the former `rows()` body, moved verbatim: the same closed table allowlist, the SQLite `sqlite_temp_master` shadow refusal, and the MySQL `SHOW CREATE TABLE … CREATE TEMPORARY TABLE` refusal.
   - `rows()` keeps its 1..2 limit guard and now calls `qualified()`.
   - The `NOT EXISTS`/`EXISTS` subqueries use `qualified('customer_suppression_attempts')` and `qualified('customer_suppression_confirmations')` with aliases `a` and `c`. The correlation `customer_suppression_targets.id` still names the outer table, whose shadow `rows()` refuses.
   - Making `qualified()` public exposes only allowlisted names. It still accepts no caller fragment.
3. **No guard is weakened.**
   - The attempt still commits before the adapter call.
   - `process()` still attempts only when `pending`, and `reconcile()` only inspects.
   - The exact-receipt confirmation and its re-proof are unchanged.
   - `ConsentEvidence::prove` still uses the current email.
   - Targets and intents are still immutable.
   - The suppression path has no deadline or timeout code, and this diff adds none.
4. **No secret or address leaks.** The 503 is the generic `ConsentException` message ("Communication preferences could not be confirmed…") with a numeric status. No recipient, HMAC, row id or ciphertext reaches the exception, the DTO or a log.
5. **Finding B is byte-unchanged.** `git diff --quiet bf6e5937..f65d921d -- app/Domain/Customers/ProductionIdentity tests/Feature/ProductionIdentityAdapters` exits 0. The hashes still match my approval: `IdentityCommittedFrame.php` is `65098069…8126` and `ProductionIdentityCommittedFrameTest.php` is `2e025f4a…b2c8`. The `1d41bedf` approval carries.
6. **The regression test retains my probe.** The first method of `CustomerSuppressionRetainedTargetTest` is identical to my probe's method apart from the probe's diagnostic `fwrite` line (`diff` is empty after removing it). I didn't review the second method, the temp-table test, beyond the red/green runs below.

### Commands and results (`R` = the direct PHPUnit runner in section 3)

| Driver | Source | Selection | Result | Evidence |
| --- | --- | --- | --- | --- |
| SQLite | `bf6e5937` runtime (temp worktree) + new test file | `tests/Feature/CustomerSuppressionRetainedTargetTest.php` | **RED, as expected: 2 tests, 5 assertions, 2 failures.** The unauthentic-target case returned `["not_requested","not_requested"]`; the temp-shadow case returned `not_requested`. | `rr-red-bf6e5937-sqlite.txt` |
| SQLite | `f65d921d` | same file | **GREEN: 2 tests, 8 assertions, OK** | `rr-green-f65d921d-sqlite.{txt,xml}` |
| SQLite | `f65d921d` | my original probe | OK. `outcomes=["refused:503","refused:503"] sent=0 attempts=0` | `rr-probe-f65d921d-sqlite.txt` |
| SQLite | `f65d921d` | the 9 consent/suppression files plus `CustomerSuppressionRetainedTargetTest` | 180 tests, 1060 assertions, 0 failures/errors, 3 skipped (the native-only DDL admission cases). This matches the lane's 180/1060. | `rr-consent-suppression-sqlite.{txt,xml}` |
| MySQL 8.4.11 (private, :3421) | `f65d921d` | `CustomerSuppressionRetainedTargetTest` | 2 tests, 8 assertions, OK, 0 skipped | `rr-green-f65d921d-native-mysql.{txt,xml}` |
| MySQL 8.4.11 (private, :3421) | `f65d921d` | `CustomerSuppressionTest --filter 'test_email_change_after_withdrawal\|test_retained_and_current\|test_single_positive_attempt'` | 5 tests, 78 assertions, OK, 0 skipped | `rr-suppression-native-mysql.{txt,xml}` |
| — | `f65d921d` | Pint `--test` on the 3 changed runtime files and the new test | passed | `rr-pint.txt` |
| — | — | `git diff --check bf6e5937..f65d921d` | clean | — |

I didn't re-run the lane's full native 9/100 selection. My native selection is the subset above.

### SHA256 at `f65d921d`

```
ca579a9a3a4cdf2e3cbdef26715f250a6393bc5f48ae670885af3e6a3a829b2b  app/Domain/Customers/Preferences/Suppression/SuppressionDelivery.php
220acdf3d6fe7d0de4d1276e5b0dcf25577f009639fa82b653d182e856309733  app/Domain/Customers/Preferences/Suppression/SuppressionEvidence.php
45d06200401644c2d35d7e55b3125817aa7f20c7a390e79a0f1808dbf030d386  app/Domain/Customers/Preferences/Suppression/SuppressionOutbox.php
70cd761e70532cb5bba3ce9400b1dda2bb74968bd250805f569a84fe5834c954  tests/Feature/CustomerSuppressionRetainedTargetTest.php
c694bf3bc7bd123ceb0eae8d0a1045bd252ea713997043675bbc2e2fbe324457  tests/Feature/CustomerSuppressionTest.php
65098069d146e0c4a437acefd672f29ac43e7181541a545c97c3c007cdea8126  app/Domain/Customers/ProductionIdentity/IdentityCommittedFrame.php
2e025f4ab8f2ac8bcda22f782805277f1461383b08230f43ad3b3021ebb5b2c8  tests/Feature/ProductionIdentityAdapters/ProductionIdentityCommittedFrameTest.php
```

### Cleanup

- I stopped the private mysqld on 127.0.0.1:3421 (datadir `scratchpad/mysql-rr`, socket `/tmp/claude-0/rr-3421.sock`) with `mysqladmin shutdown`. Port 3421 refuses connections, and no `mysqld` process has `--port=3421` (checked via `/proc/*/cmdline`).
- I deleted the datadir and socket.
- I removed the temp worktree `scratchpad/rr-bf6e`.
- I never used the shared `:3306` server.
- I committed and pushed nothing. This addendum lives in the lane worktree for the coordinator to commit.

This is a focused development review. It is not Foundation CI, full-suite acceptance or release readiness.
