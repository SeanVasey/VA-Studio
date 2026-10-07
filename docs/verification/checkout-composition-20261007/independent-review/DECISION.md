# Independent security and financial review: checkout composition `d20d4394`

**Decision: APPROVE WITH CONDITIONS** for exact commit `d20d4394edcc84bf3c5a193f598d9b044b0753aa`
(branch `claude/stoic-archimedes-xza96q`; base `origin/main` `523267bb`). The conditions are listed in §5.

- Reviewer: independent Claude Code reviewer subagent. No source was edited, and no commit, push or merge was made.
- Worktree: detached `/home/user/VA-Studio-review` at `d20d4394`. Evidence lives in `/home/user/VA-Studio-review/review-evidence/` and is untracked.
- Date: 2026-10-07 (UTC). Runtime: PHP 8.4.26, PHPUnit 12.5.34, Laravel v13.34.0 (locked). Native server: MySQL 8.4.11 Community at 127.0.0.1:3306, schema `vaseyaudio_review`.

## 1. Scope

**This decision covers:**

- **Composition fidelity.** It checks that main + c6 `c6d82345` + 2ec `2ec1854c` were composed without drift, including the clean three-way merge of `Records.php`.
- **The new guard.** It covers the one guarded line in `OriginalCommitDispatcher::capture` and its regression test, `tests/Feature/ProductionCheckoutObserverExclusionTest.php`.
- **Composed runtime behavior,** for the items below:
  - mutual exclusion of the two financial observers;
  - original-frame cleanup when a committing listener throws;
  - deadline caps;
  - which identity dependency the receipt path runs on.
- **Unchanged canaries.** The four archived canaries were re-executed on the composed source.

**This decision does NOT cover:**

- It does not approve Paid252 or any other paid or member grant consumer.
- It does not approve grant, fulfillment or download authority.
- It does not approve Tax255 or any tax facts.
- It does not approve live payments, provider I/O, merchant or account facts, deployment, DNS or release readiness.
- It is not an independent line-by-line approval of every c6 `CheckoutWriteAdmission` plan or selector. c6 had no prior independent approval. Coverage of c6 here is limited to items 1–7, the c6 tests and the canaries.
- It is not full acceptance. Every run below is a focused check. Hosted Foundation CI (MySQL 8.4 + SQLite + browser) on the final integrated exact SHA has not been run and remains a release gate.

## 2. Findings

| ID | Severity | Finding |
| --- | --- | --- |
| F-1 | **Medium** | A refused commit leaves Laravel's transaction-manager state behind (proven on both drivers; see below). |
| F-2 | **Low** (process / evidence) | The commit message claims evidence that the commit does not contain. |
| F-3 | Info | The new guard is correctly placed, uses a consistent reason code, and is defense-in-depth. |
| F-4 | Info | The reverse direction (c6 refusing the receipt observer by class-name string) is sound. |
| F-5 | Info | Cleanup is fail-closed but cannot undo a privileged physical commit or a dropped marker. These limits were already documented and are confirmed. |
| F-6 | Info | A wrapper left embedded around a used observer makes later commits fail closed (liveness only). |
| F-7 | Info | Native evidence was first blocked by contention on the shared MySQL server. |
| F-8 | Info | Two harness gaps in `mkworktree.sh`, plus a file the 209 canary writes. |

### F-1 (Medium): a refused commit leaks after-commit callbacks into the next commit

**What happens.** A committing-listener exception reaches Laravel's `handleCommitTransactionException`. That code drops the depth to 0 but never updates `DatabaseTransactionsManager` (`vendor/.../ManagesTransactions.php:232-251`).

`CheckoutCommandFrame::abort()` then rolls back the original transaction with raw PDO calls. That is physically correct, but the framework's pending `DatabaseTransactionRecord` stays registered. Three things follow:

1. Any `DB::afterCommit` callback registered in the refused frame runs on the **next, unrelated** successful commit on that connection.
2. Until that commit, an `afterCommit` registered outside any transaction is deferred instead of running immediately (`DatabaseTransactionsManager::addCallback` uses the stale pending list).
3. After-rollback callbacks of the refused frame never run.

**Proof.** Adversarial test `test_b_refused_new_order_frame_does_not_leak_after_commit_callbacks_into_next_commit`:

- A committing listener registers `DB::afterCommit(insert review_leak_probe)` and withdraws `fresh_checkout_enabled`.
- The new order is correctly refused, with zero order, line and attempt rows.
- A later unrelated `DB::transaction` then fires the leaked callback, which persists a durable row.
- Result: RED on SQLite and RED on native MySQL 8.4.11.

**Impact.**

- This does not bypass the financial guard: no checkout order, line or attempt row becomes durable.
- No code at `d20d4394` in `app/Domain/Commerce/ProductionCheckout` registers after-commit work (checked with grep), and the queue connections use `after_commit=false`.
- The same leak applies to the receipt lane, whose documented contract tells the consumer to run a raw `$pdo->rollBack()` after refusal.
- It is a real hazard for any future NEW-write consumer that registers after-commit work inside a command or receipt frame: queued `afterCommit` jobs, `ShouldDispatchAfterCommit` events, grant or fulfillment notifications. Such side effects could run for a rolled-back order or grant.
- The root cause is upstream Laravel behavior. It is not new code in this composition.

### F-2 (Low): claimed red evidence is not in the commit

The commit message of `d20d4394` says the red result on the unguarded composition "is retained under docs/verification/checkout-composition-20261007/".

- That folder does not exist at `d20d4394`: `ls docs/verification/checkout-composition-20261007/` in the review worktree returns "No such file or directory".
- It exists only as an **untracked** folder in the integrator tree `/home/user/VA-Studio`. There, `observer-exclusion-red-sqlite.xml` records 2 tests / 11 assertions / 1 failure on source `2f3d1151`, with test SHA256 `949241fe…0ae7e`. That matches the reviewed test file byte for byte.
- That folder also holds the integrator's native result `exclusion-mysql84-d20d4394.xml` (2/18), likewise uncommitted.

### F-3 (Info): the guard is correct and is defense-in-depth

`OriginalCommitDispatcher::capture` is the only place a receipt observer gets installed. The guard refuses a `CheckoutCommandCommitDispatcher` delegate with `committed_read_frame`, the same reason code as the other refusals there. It does not affect legitimate flows: the receipt tests pass at 12/56 and 2/12 natively.

I traced the other ways a receipt observer could end up stacked on a command observer:

- **Without the guard, stacking was already fail-closed.** `CommandTransaction.php:128` calls `$frame->prove(1)`. That requires the connection dispatcher to be the command observer itself (`CheckoutCommandFrame.php:96-98`). With a receipt observer on top, it would refuse with `write_frame` and roll back. So the guard adds an earlier, clearer refusal and stops the receipt from capturing a younger savepoint that the command anchor would destroy. It does not close an exploitable durable-write hole. This point is traced from the code, not tested.
- **A third-party wrapper hiding the command observer.** The guard checks only the immediate delegate, but the case still fails closed. Each observer's identity check (`requireCurrent`: dispatcher `=== $this`; `frame->prove`: dispatcher `=== observer`) refuses at the commit boundary, because only one object can be the connection dispatcher.
- **A receipt observer captured before `CommandTransaction::run`.** `run` requires depth 0 and no PDO transaction. A receipt observer left installed after its commit is invalidated by the `TransactionBeginning` event and restores its delegate before the command frame is captured.
- **Nested transactions.** Both observers invalidate on `TransactionBeginning`, and the depth checks require exactly 1.
- **The `instanceof self` reuse path.** It returns only an existing receipt observer whose state is still `held`. Because the guard blocks the first install on a command frame, that path can never produce a receipt observer stacked on one.
- **No legitimate stacked commit is possible.** For both observers to commit, both identity checks would have to pass at once, which cannot happen.

### F-4 (Info): the reverse-direction check is sound

c6 refuses with `$delegate::class !== __NAMESPACE__.'\\OriginalCommitDispatcher'`. The receipt observer class is declared `final`, so an exact class-name comparison is equivalent to `instanceof`. Comparing the name string avoids an autoload dependency on 2ec, which c6 predates. The regression test `test_new_write_observer_refuses_frame_already_holding_receipt_observer` is green on both drivers.

### F-5 (Info): cleanup limits are confirmed, not new

- **Both paths:** a listener that commits the PDO directly makes rows durable before detection. The existing test `test_committing_direct_physical_commit_reopen_refuses_and_preserves_replacement_frame` asserts exactly this.
- **c6 path (`abort()`):** if the original marker was dropped by a privileged actor while the original PDO transaction is still open, `abort()` deliberately leaves it untouched. The connection then has depth 0 with an open PDO transaction, and the next `CommandTransaction::run` or `DB::transaction` refuses or throws. No data is committed.
- **Receipt path:** `invalidate()` never touches the PDO, so the transaction owner must abort.

### F-6 (Info): embedded observers make later commits fail closed

If a third party installs a wrapper over either observer, `restore()` and `invalidate()` leave the observer embedded, because they only restore when the observer is the current dispatcher. Every later commit on that connection is then refused: the phase is no longer `held`, or the observer is invalid. This affects availability only, never safety, and requires a privileged wrapper.

### F-7 (Info): native contention on the shared server

My first native attempt errored in migration setup with `Unexpected production capability external or additional table guard`. The cause was another session's schema `vaseyaudio_p2` holding `ptp_*` triggers on the same table names; the guard deliberately rejects those server-wide. This is an environment collision, not a product defect.

- The failed attempt is archived in `env-blocked-native-attempt1/`: receipt 2/0/2 errors, exclusion 2/0/2 errors.
- I dropped and recreated only my own `vaseyaudio_review` schema.
- I waited for 60 seconds of server quiet (`native-wait.log`), then re-ran. The re-runs are the results in §3.
- Other agents' stale schemas `vaseyaudio_features253` and `vaseyaudio_paid252` (104 tables each) were not touched.

### F-8 (Info): harness gaps

- `mkworktree.sh` omits `vendor/bin` and `vendor/composer/InstalledVersions.php`. Both failed runs are archived in `harness-error-vendor-bin-missing/` and `harness-error-installedversions-missing/`.
- I copied the byte-identical `InstalledVersions.php` into the worktree (SHA256 `1e4525…96e4`) and ran PHPUnit through `run-phpunit.sh`, which uses the worktree's own Composer autoload.
- The 209 canary writes an untracked `snapshot.json` into its archived docs folder. I moved it to `canary-209-terminal-generated-snapshot.json`; tracked originals are unchanged. The integrator tree has the same untracked file, which should not be committed over the original evidence.

## 3. Assessment of the assigned items

### 1. Composition fidelity: PASS

I compared all 60 runtime, test, route, config and migration paths changed in `origin/main..d20d4394` using `git diff --quiet <commit> d20d4394 -- <path>`:

- **c6-owned files** are byte-identical to c6, apart from the two exceptions below: `CheckoutCommandCommitDispatcher`, `CheckoutCommandFrame`, `CheckoutWriteAdmission`, `CommandTransaction`, `FreshCheckoutPolicy`, `ProductionCheckout`, and the tests `ProductionCheckoutFreshPolicyTest` and `ProductionCheckoutWriteCommitTest`.
- **2ec-owned files** are byte-identical to 2ec: `CommittedReadContext`, `HeldSourceTransaction`, `ProductionPaidOrderCommittedReadReceiptV1`, `ProductionPaidOrderConsumerCommitAdmissionV1`, `ProductionPaidOrderSourceV1`, `ProductionPolicy/CurrentRows`, `config/production_checkout.php`, and the tests `CommittedReceipt`, `CommittedRows` and `ReceiptParent`.
- **Every other checkout file** is identical to both c6 and 2ec.
- **Branch tips:** the c6 → `abb85f00` and 2ec → `b76f7752` ranges contain no `app`, `tests`, `routes`, `config` or `database` changes.
- **Overlap:** the only file both branches changed relative to their common base `b6f1dfbb` is `Records.php`. `git merge-file -p c6 base 2ec` exits 0 and reproduces the `d20d4394` `Records.php` byte for byte, so it carries both c6's `frame`/`commandFrame()` and 2ec's `committed()`.
- **The guard:** `OriginalCommitDispatcher.php` differs from 2ec only in the guarded `require` line and its comment (+2/−1).
- **`CurrentRows`:** main's commit `e5565837` is already contained in `b6`, since `git diff b6f1dfbb origin/main -- CurrentRows.php` is empty. Taking 2ec's file therefore drops no main change.
- **Identity dependencies:** all 16 identity paths 2ec touched equal main (= e8 `e8f74416`), except `IdentityCommittedFrame.php`, which carries exactly the 5053 hunk (+4/−2), and the frame test (+14).

### 2. Identity dependencies: PASS

The 2ec receipt and witness path never references `IdentityCommittedFrame`. `grep -rn IdentityCommittedFrame app` finds only `ProductionCustomerAccess`, `ProductionAccountFeatureAccess` and `IdentityRows`.

- The receipt path reads through `CurrentRows::committedReadOnly`, which is transaction-free and non-locking.
- `IdentityOriginalCommitWitness`, `IdentityHistoricalCommittedReceipt` and the seal open no transaction or savepoint and set no pragma. Their `invalidate()` methods only change phase state.
- Nothing in the receipt path depends on `Frame.close()` behavior from before 5053. If 5053 leaves SQLite `query_only=1` pending, the only effect would be to fail writes closed.
- Evidence: SQLite identity witness + frame tests 31/154, 0 failures, 2 skips. Both skips are named native-only cases: `test_native_plain_closed_prefix…` and `test_native_validation_reads_current…`.

### 3. The new guard: PASS, see F-3 and F-4

### 4. Original-frame cleanup: PASS (physical), with the F-1 framework-level gap

`CommandTransaction::run` catches the exception, calls `abort()`, and calls `restore()` in `finally`. `abort()` checks `committed` and `inTransaction()`, then checks that the PDO is an internal class, then runs `RELEASE SAVEPOINT <random marker>` followed by `rollBack()`. Any error is swallowed so a replacement is left untouched.

**SQLite**

- Releasing the outermost marker inside the BEGIN transaction keeps the transaction open, and `rollBack()` then discards the whole original frame.
- After a direct commit/reopen, the marker is missing, `RELEASE` fails, and the replacement is left alone.
- After a direct commit with no reopen, `inTransaction()` is false and nothing happens.

**MySQL**

- The same sequence applies. A missing marker raises error 1305, which is caught.
- `inTransaction()` follows the server transaction state, so an implicit DDL commit also leads to a no-op.

**Receipt path.** `invalidate()` touches no PDO and restores the delegate only if the receipt observer is still the dispatcher, so it can never touch a caller's replacement transaction or dispatcher. When the receipt is minted inside a `CommandTransaction` frame with no NEW-write observer, `CommandTransaction::abort()` acts as the transaction owner. Adversarial test A shows that cleanup is complete and automatic on both drivers.

### 5. Deadlines: PASS

- **Command frame:** the deadline is `hrtime` at construction + 300 s. `capDeadline` applies `min()` and is the only mutator (callers: `CheckoutWriteAdmission.php:81` and `ProductionCheckout.php:66,116`).
- **Receipt:** `CommittedReadContext::deadline` returns `min(original, capturedAtNs + 300 s)` from a `readonly` capture time. The receipt observer's `deadlineNs` is `readonly`. Sibling reuse requires an identical deadline, so a sibling cannot extend it.
- `hrtime` is monotonic. No path raises, resets or renews a deadline. The two deadlines are enforced independently: the command deadline at closure end and at commit when the observer is registered, and the receipt deadline at every `requireCurrent`.

### 6. Required runs: green. Item 7 (adversarial): see §4

## 4. Commands and results

All commands ran from `/home/user/VA-Studio-review`. `R=review-evidence/run-phpunit.sh`, which runs:

```sh
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- "$@"
```

Every JUnit file is in `review-evidence/`. "Asserts" is the assertion count; "Fail", "Err" and "Skip" are failures, errors and skips.

| Driver | Selection | JUnit | Tests | Asserts | Fail | Err | Skip |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SQLite | `$R tests/Feature/ProductionCheckoutObserverExclusionTest.php` | `sqlite-observer-exclusion.xml` | 2 | 18 | 0 | 0 | 0 |
| SQLite | `$R tests/Feature/ProductionCheckoutWriteCommitTest.php` | `sqlite-write-commit.xml` | 12 | 90 | 0 | 0 | 0 |
| SQLite | `$R tests/Feature/ProductionCheckoutCommittedReceiptTest.php` | `sqlite-committed-receipt.xml` | 12 | 56 | 0 | 0 | 0 |
| SQLite | c6 physical canary, `--filter test_physical_committing_policy_withdrawal_prevents_new_order_rows` | `sqlite-canary-c6-physical.xml` | 1 | 5 | 0 | 0 | 0 |
| SQLite | 209 terminal canary, `--filter test_terminal_private_media_resolution_withdrawal_refuses_new_order_and_rolls_back` | `sqlite-canary-209-terminal.xml` | 1 | 5 | 0 | 0 | 0 |
| SQLite | 2ec parent canary, `--filter test_plain_receipt_rejects_late_app_arrayobject_parent_without_executing_offsets` | `sqlite-canary-2ec-parent.xml` | 1 | 5 | 0 | 0 | 0 |
| SQLite | 4th canary (c6 password/module), `--filter 'test_committing_persisted_password_withdrawal_rolls_back_order_and_original_password\|test_committing_identity_module_withdrawal_rolls_back_order'` on `docs/verification/checkout-write-commit-admission-20261007/ProductionCheckoutWriteCommitTest.php` | `sqlite-canary-c6-password-module.xml` | 2 | 16 | 0 | 0 | 0 |
| SQLite | Identity witness + 5053 frame (`IdentityHistoricalCommittedReceiptTest.php`, `ProductionIdentityCommittedFrameTest.php`) | `sqlite-identity-witness-frame.xml` | 31 | 154 | 0 | 0 | 2 (native-only) |
| SQLite | Adversarial `review-evidence/ReviewAdversarialCompositionTest.php` | `sqlite-adversarial.xml` | 2 | 22 | **1 (B)** | 0 | 0 |
| MySQL 8.4.11 | `--filter 'test_typed_consumer_admission_runs_after_late_delegate\|test_same_typed_admission_accepts_two_siblings'` on `ProductionCheckoutCommittedReceiptTest.php` | `native-committed-receipt-selected.xml` | 2 | 12 | 0 | 0 | 0 (385.4 s) |
| MySQL 8.4.11 | `ProductionCheckoutObserverExclusionTest.php` | `native-observer-exclusion.xml` | 2 | 18 | 0 | 0 | 0 (332.2 s) |
| MySQL 8.4.11 | Adversarial (A + B) | `native-adversarial.xml` | 2 | 22 | **1 (B)** | 0 | 0 (337.2 s) |

**Canary file integrity.** Each canary was verified byte-identical before execution:

| Canary | SHA256 |
| --- | --- |
| c6 physical | `ce1149920fe12f7e393d659d83355ca11bf2b524059bc5cdbc868e5bbb7407aa` |
| 209 terminal | `1c9ebf899a1cce43238b6eef7bef035177cd360d926493df311115318c935712` |
| 2ec parent | `db34517b2eb744aa74abcc77bb288a3e75c40b2edc9edfee9db6aee3cc553472` |
| c6 password/module snapshot | `d700297ef799272adda65b65fbab0d263fcecfa7ff8af8b4656468d5359f71f1` |

**Native runs.**

- Native env, as instructed: `APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=vaseyaudio_review DB_USERNAME=root DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`. The password is not reproduced; the script is `run-native.sh`, gated by `wait-then-native.sh`.
- `native-server.txt` records `8.4.11 MySQL Community Server - GPL`. The schema had 0 tables after each run (`db:wipe` teardown).
- The run times of 332–385 s per pair, against about 1 minute for the same selection on SQLite, are consistent with full native migrations.

**Archived failed attempts.** These are not counted as evidence:

- `harness-error-vendor-bin-missing/` and `harness-error-installedversions-missing/` (F-8).
- `env-blocked-native-attempt1/` (F-7).

**Adversarial results (item 7).**

- **A — `test_a_receipt_on_command_frame_withdrawn_admission_rolls_back_whole_frame_and_never_closes`: GREEN** on both drivers. A receipt observer is the sole observer on a `CommandTransaction` frame with no NEW-write observer. The typed consumer admission is withdrawn by a committing listener that changes the password. Results:
  - the commit is refused with `consumer_admission`;
  - `CommandTransaction::abort` itself leaves the PDO with no open transaction and depth 0, and the dispatcher is restored;
  - the consumer write is not durable and the password is unchanged;
  - `proveClosed()` refuses with `committed_read_frame`;
  - payment rows = 1, license_grants = 0.
  So no durable rows and no closed receipt appear after the withdrawal on this path.
- **B — the leak test: RED** on both drivers (F-1). No checkout order, line or attempt row became durable. A refused-frame callback persisted a row on a later unrelated commit.

## 5. Conditions of approval

1. **(F-2)** Before merge, commit `docs/verification/checkout-composition-20261007/`, holding the red result on `2f3d1151` and the integrator's results, as a docs-only follow-up. Otherwise, correct the claim in the commit or PR text. Do not let the commit message stand as the only record.
2. **(F-1)** Before any NEW-write consumer (Paid252, member grants, fulfillment) composes on a command or receipt frame, and in every case before release:
   - make refused-commit cleanup also clear framework transaction-manager state for that connection, for example `app('db.transactions')->rollback($connection->getName(), 0)` after the physical abort, which also runs rollback callbacks;
   - extend the documented consumer abort contract to the same requirement;
   - add adversarial test B, or an equivalent, as a regression on both drivers.
   This composition may merge for development without the fix only because no code at `d20d4394` registers after-commit work in these frames. Record F-1 as an open release blocker.
3. Keep the integrator's untracked 209 `snapshot.json` out of the commit (F-8).
4. Final integrated exact-SHA Foundation CI, and independent review of the Paid252, Tax255 and live-payment work, remain separate gates. This decision approves none of them.

## 6. SHA256 of relied-on sources

The full list is in `review-evidence/source-sha256.txt`: 84 repository paths hashed from `git show d20d4394:<path>` and checked equal to the worktree bytes (0 mismatches), plus 3 locked vendor files. The key entries:

```
ed529e8f69b2ae6733d767aeae6bebd693faa114c55cd1c5c3a3a5824ddcd372  app/Domain/Commerce/ProductionCheckout/OriginalCommitDispatcher.php
6846dccc351879e66be3e4b3009b84399f15aa6e17bec2ad99bc02230c1aac8b  app/Domain/Commerce/ProductionCheckout/CheckoutCommandCommitDispatcher.php
026a1cfef68d71f6566892b6271b8f5ec7d01d36445ebf5b4ea318ea8654bd52  app/Domain/Commerce/ProductionCheckout/CheckoutCommandFrame.php
e2172b0fc144cd2c25468daa2a2e23cc7b063fcfbdc0498b2fad14a7550d5d69  app/Domain/Commerce/ProductionCheckout/CommandTransaction.php
2f99ec62aa67bd36b1e9d3db855a64e1184833196dcd9f5884d9197450d6508e  app/Domain/Commerce/ProductionCheckout/Records.php
99f00ee5251072fa9c257e96bb65e2370e40806f85dc7885523e1f4e9e99aabd  app/Domain/Commerce/ProductionCheckout/CommittedReadContext.php
5689637577c6ffe61cec52eb60d7445432f992a2246be133be8e0dd424b13da7  app/Domain/Customers/ProductionIdentity/IdentityCommittedFrame.php
949241fe744871157f29c21c570af0ad64779b40eaccf3c565195f908be0ae7e  tests/Feature/ProductionCheckoutObserverExclusionTest.php
cc74fa1fb9816b1c1fa1fb3e2214648263672401a9433af9ed32d19daeba38ee  review-evidence/ReviewAdversarialCompositionTest.php
```

`review-evidence/evidence-sha256.txt` hashes every evidence artifact in this folder.

---

## Addendum: re-review of `e86381bf139b4351bba534f3fe0b26f4f8efbdd0` (2026-10-07)

The original decision for `d20d4394` above is unchanged. This addendum covers only commit `e86381bf139b4351bba534f3fe0b26f4f8efbdd0` (branch `claude/stoic-archimedes-xza96q`). I checked it out detached in the same worktree. Its evidence is in `review-evidence/e86381bf/`.

**Decision for `e86381bf`: APPROVE.** The scope and exclusions in §1 still apply. Both conditions from the original decision are resolved. Nothing remains open on this commit. Some requirements carry forward to downstream work and to release; they are listed in part (d).

### (a) Diff `d20d4394..e86381bf`: confirmed

`git log d20d4394..e86381bf` lists four commits: `4d147c29`, `aa514349`, `4a8cec40` and `e86381bf`.

`git diff --name-only d20d4394 e86381bf | grep -v '^docs/'` returns exactly four paths. `git diff --stat ... -- app tests routes config database` reports 2 files, +93 lines.

| Path | Change |
| --- | --- |
| `app/Domain/Commerce/ProductionCheckout/CommandTransaction.php` | +14: the cleanup in the catch block, plus three `use` lines |
| `tests/Feature/ProductionCheckoutRefusedFrameCallbackTest.php` | new, 79 lines; my probe B |
| `scripts/dev/mkworktree.sh` | new; links `vendor/bin` and copies `InstalledVersions.php` |
| `CLAUDE.md` | one line in Project Notes, pointing to `CLAUDE-PLAN.md`; outside §1–§12, no runtime effect |

Everything else is under `docs/`. Within that:

- Condition 1 is resolved. `docs/verification/checkout-composition-20261007/` is committed, including the original red `observer-exclusion-red-sqlite.xml`.
- My `review-evidence/` was copied to `independent-review/`. A `cmp` of all 16 files against my originals found every one byte-identical.
- The 209 canary snapshot is archived under a new name. The tracked originals in `cloud-checkout-terminal-policy-independent-20261007/` are unchanged (`git diff --quiet` succeeds).
- `OriginalCommitDispatcher.php` is unchanged; its SHA256 is still `ed529e8f…aabd`.

The retained red `evidence/refused-frame-callback-red-sqlite-4a8cec40.xml` records 1 test, 10 assertions and 1 failure ("leaked into a later unrelated commit"). It ran against `CommandTransaction.php` with SHA256 `e2172b0f…da5d69` at `4a8cec40`, which is identical to the file at `d20d4394`. So the red was produced on the unfixed code.

### (b) Runs on `e86381bf`

| Driver | Selection | JUnit | Tests | Assertions | Failures | Errors | Skips |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SQLite | `review-evidence/ReviewAdversarialCompositionTest.php` (probe A + probe B) | `e86381bf/sqlite-adversarial.xml` | 2 | 23 | **0** | 0 | 0 |
| SQLite | `tests/Feature/ProductionCheckoutWriteCommitTest.php` | `e86381bf/sqlite-write-commit.xml` | 12 | 90 | 0 | 0 | 0 |
| SQLite | `ProductionCheckoutRefusedFrameCallbackTest.php` + `ProductionCheckoutObserverExclusionTest.php` | `e86381bf/sqlite-refused-frame-and-exclusion.xml` | 3 | 29 | 0 | 0 | 0 |
| SQLite | c6 physical canary (`--filter test_physical_committing_policy_withdrawal_prevents_new_order_rows`) | `e86381bf/sqlite-canary-c6-physical.xml` | 1 | 5 | 0 | 0 | 0 |
| MySQL 8.4.11 (`vaseyaudio_review`) | Probe A + probe B | `e86381bf/native-adversarial.xml` | 2 | 23 | **0** | 0 | 0 (269.5 s) |

- Probe B went from **red** at `d20d4394` (on both drivers) to **green** at `e86381bf` (on both drivers). Probe A stays green.
- The native run started only after 60 s of server quiet (`e86381bf/native-wait.log`).
- `e86381bf/native-server.txt` records `8.4.11 MySQL Community Server - GPL`.
- The schema had 0 tables after the run.
- The runner is `review-evidence/run-phpunit.sh`, unchanged. The native script is `e86381bf/run-native-e86.sh`.

### (c) Can `rollback(name, 0)` at depth 0 discard someone else's record, and is the Reflection read acceptable?

**Precondition argument: sound.**

`CommandTransaction::run` refuses to start unless `transactionLevel() === 0` and `! $primary->inTransaction()`. At entry, then, no live Laravel or PDO transaction exists on this connection.

The cleanup runs only when both of the following hold:

- `$frame !== null`, so the command opened its own frame;
- after `abort()`, `transactionLevel() === 0` on the captured connection object.

At that point every `DatabaseTransactionRecord` for this connection name falls into one of two groups:

1. Records created inside the refused command: its own level-1 record, plus any nested records from inside the closure.
2. Stale records left over before entry. These have no live owner, because the connection was idle at entry.

Discarding both groups is correct. Two specific cases:

- **A listener reopened a transaction through Laravel.** If a committing listener opened a transaction with `DB::beginTransaction()`, Laravel's depth stays at or above 1, so the cleanup is skipped and that record is left untouched. This fails safe: the leak could remain in that privileged case, but nothing belonging to another owner is discarded.
- **A listener reopened a transaction with raw PDO.** A raw-PDO replacement transaction has no manager record, so there is nothing to discard.

`rollback(name, 0)` runs `removeAllTransactionsForConnection`. That fires the refused frame's after-rollback callbacks (the correct semantics) and clears its pending and staged records.

**Normal closure-exception path.** When the closure itself throws, Laravel's `rollBack()` has already cleared the manager. The second call then finds `currentTransaction` null and nothing pending, so no callback runs twice.

**Precedent.** Laravel's own `Connection::disconnect()` (`Connection.php:1079`) makes the same call, `transactionsManager?->rollback($this->getName(), 0)`.

**Residual (Info).** Two cases remain, neither of which can make a refused order commit:

- The manager is keyed by connection **name**. If a privileged listener replaced the named connection during the command, the frame already refuses. The cleanup could then drop manager bookkeeping (not PDO state) belonging to a replacement `Connection` object of the same name. This needs privileged connection replacement mid-command.
- An after-rollback callback that throws would replace the original exception. The command is still refused and `restore()` still runs.

**Reflection versus `app('db.transactions')`: acceptable, and preferable.**

- Reading the connection's own `transactionsManager` targets exactly the manager that holds this connection's records. The container binding could in principle differ from the instance set on the connection.
- `Connection` has no public getter. The property is protected (`Connection.php:155`) in the locked Laravel v13.34.0, and the checkout code already reflects on Container and Repository internals.
- The `instanceof DatabaseTransactionsManager` check also admits Laravel's testing subclass.
- If a future Laravel version renamed the property, `ReflectionProperty` would throw inside the catch. The command would still be refused and the code would fail closed, not open. Pin this coupling with the regression test when upgrading Laravel.

### (d) Status and carry-forward

Both conditions are met. Condition 1 (F-2): the evidence is committed and the snapshot was kept out. Condition 2 (F-1): the fix is in, with the regression test and a retained red. **`e86381bf` is approved without conditions**, within the original §1 scope. Paid252, grants, tax, live payments and release are still not approved.

These requirements are not conditions on this commit. They carry forward:

1. **Receipt-lane consumers.** The receipt observer owns no transaction, so a consumer that runs its own transaction around a receipt (Paid252 and future grant consumers) must perform the same manager cleanup after its physical abort. This fix covers only `CommandTransaction`. Each consumer's review should include a probe-B-style regression on both drivers.
2. **Release gates.** Final integrated exact-SHA Foundation CI, and independent review of downstream consumers, remain release gates.
3. **Worktree helper (Info).** `scripts/dev/mkworktree.sh` defaults `main` to `git rev-parse --show-toplevel`. Run from inside a linked worktree, it chains symlinks through that worktree's vendor directory. Set `VA_STUDIO_MAIN` to avoid this.

Source digests for this addendum are in `review-evidence/e86381bf/source-sha256.txt`:

```
fd8fccde5538dd316bb1d799e91e0a718fd6289cf7a793fc898e6eaaba394be8  app/Domain/Commerce/ProductionCheckout/CommandTransaction.php
a07f2765b7f1a4b7e7a45a9b92418f2d304efd4f829f98a68d23e89259f8c980  tests/Feature/ProductionCheckoutRefusedFrameCallbackTest.php
bdb6fd945cea8265d5534f544ef56214e2bb1796a763efe67112787ec5fde471  scripts/dev/mkworktree.sh
6bbd5bae16d62eee805b444f28d7cd735b38844afbf3eea07cafb4a2f3566a20  CLAUDE.md
ed529e8f69b2ae6733d767aeae6bebd693faa114c55cd1c5c3a3a5824ddcd372  app/Domain/Commerce/ProductionCheckout/OriginalCommitDispatcher.php
```
