# Checkout NEW-write commit admission for intent, basis and authority — 2026-10-07

Branch `harness/checkout-write-admission-all`, base `ece5a9ee48877f0686f9ca1230f8cafe5a77d6a7`.

| Commit | Content |
| --- | --- |
| `6dc890f4e15f323ad020e5c9b480f6611b2a073a` | Red canaries and red evidence, on unchanged source |
| `2c3efc4e06f0019bbd1eadca664bab79f81cbf51` | Fix: executable source under test |
| this docs commit | Green evidence and this README; no executable change |

This is development evidence for independent review. It is **not** an approval, not Foundation CI and not release acceptance. All checkout flags stay default-off. No route file, service provider or `bootstrap/` registration was added, `provider_io_enabled` was not touched, and no real provider was called: every provider contact is the synthetic `ProductionCheckoutGatewayFixture`.

## 1. Findings closed

| Codex | Severity | Path | Defect at `ece5a9ee` |
| --- | --- | --- | --- |
| r4208264406 | P1 | `HostedCheckout::prepare()` / `initiate()` | A `TransactionCommitting` listener that deactivates the offer or closes the capability after prepare()'s policy/selection proofs still commits the NEW intent. initiate() then rechecks only buyer access and the enable flag before provider I/O, so a payable session can be created for an offer withdrawn in the same physical commit. |
| r4208264416 | P2 | `TaxExemptions::qualify()` | The NEW basis write has no commit-time staff admission; `basis()` later checks only that the qualifier ID is listed. |
| r4208109348 | P2 | `ApproveExemptionAuthority::approve()` | The NEW authority write has no commit-time staff admission; `authority()` later checks only the owner-ID list. |

The reviewer also named a plain race with no listener at all: on the first initiate, nothing re-checked policy or selection between prepare()'s commit and the first `gateway->create` (retries did, through prepare(create=true)). Canary (a) covers that case too.

## 2. Red evidence (before any fix)

Canaries live in `canaries/` and run from there, following the pattern of `../checkout-write-commit-admission-20261007/CheckoutPhysicalCommitFreshPolicyCanaryTest.php`. Each asserts the safe outcome, so it is red on unchanged source. Each also writes a JSON snapshot of what happened (`*-snapshots/`).

| Canary | Withdrawal | Observed at `6dc890f4` (both drivers) |
| --- | --- | --- |
| `HostedIntentCommitAdmissionCanaryTest` offer | committing listener sets `offers.is_active = 0` | initiate() returned; intent 1, gateway creates 1, session 1, observation 1 |
| same, capability | committing listener inserts a `production_track_capability_closures` row | intent 1 committed, gateway creates 1; a later unrelated history load then threw `ValidationException` |
| same, race | no listener; offer deactivated at the first gateway contact after prepare() committed | initiate() returned; gateway creates 1, session 1 |
| `ExemptionBasisCommitAdmissionCanaryTest` role / MFA | committing listener clears the qualifier's `is_admin`, or nulls `app_authentication_secret` and recovery codes | not refused; basis rows 1 |
| `ExemptionAuthorityCommitAdmissionCanaryTest` role / MFA | same, for the owner | not refused; authority rows 1 |

| Driver | Canary | JUnit | Tests | Assertions | Failures | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| SQLite | HostedIntent | `red-sqlite-HostedIntent.xml` | 3 | 9 | **3** | 0 |
| SQLite | ExemptionBasis | `red-sqlite-ExemptionBasis.xml` | 2 | 6 | **2** | 0 |
| SQLite | ExemptionAuthority | `red-sqlite-ExemptionAuthority.xml` | 2 | 4 | **2** | 0 |
| MySQL 8.4.11 | HostedIntent | `red-native/red-native-HostedIntent.xml` | 3 | 9 | **3** | 0 |
| MySQL 8.4.11 | ExemptionBasis | `red-native/red-native-ExemptionBasis.xml` | 2 | 6 | **2** | 0 |
| MySQL 8.4.11 | ExemptionAuthority | `red-native/red-native-ExemptionAuthority.xml` | 2 | 4 | **2** | 0 |

Native red ran from a separate detached worktree at exactly `6dc890f4` (`red-native/source-sha.txt`), so the fix could be written meanwhile without touching the code under test. Every native snapshot records `"driver": "mysql"`.

`attempt1-harness/` keeps a first SQLite run, also on unchanged source. In it the capability case ended in a non-checkout `ValidationException` before the refusal assertion was reached, so the run showed an error instead of a clean failure. The canary was then tightened to assert "no provider create" first and to record the refusal class. The archived `.attempt1.php.txt` is the earlier canary text. That run is not counted as evidence.

## 3. Design

c6's rules are kept: ONE command observer per frame; ordinary committing delegates run first; then a sealed capsule compares fixed raw plans; pure fresh admission and the physical anchor come last. A refusal cleanup proves the original marker and rolls back the whole physical transaction, and nothing runs a resolver, decryptor, gate, MFA provider, renderer or provider at commit.

- **`CheckoutCommitAdmission`** (new interface): `belongsTo()`, `proveCurrent()`, `proveFresh()`. `CheckoutWriteAdmission` already satisfied it and now declares it.
- **c6 file changes:**
  - `CheckoutWriteAdmission`: only the `implements` clause.
  - `CheckoutCommandCommitDispatcher`: the constructor and `capture()` take the interface.
  - `CheckoutCommandFrame::register()`: takes the interface; its docblock now says "NEW checkout writes".

  Dispatch order, the single-observer refusal (`observer === null`), the `OriginalCommitDispatcher` exclusion, `abort()` and `CommandTransaction` (F-1 cleanup) are byte-unchanged. `CommandTransaction.php` is still `fd8fccde…`, and `OriginalCommitDispatcher.php` is still `ed529e8f…`.
- **`CheckoutRawPlans`** (new): reproduces c6's private plan/read/definition machinery (captured internal PDO, default `PDOStatement`, qualified `SELECT … ORDER BY id LIMIT n`, `SHOW CREATE TABLE` / SQLite temp-shadow check) and the identity/selection/history selectors. It also adds `staff()` (exact `users` row plus `User` audit rows, as `PacketAuthority::raw` reads them), `row()`, `selector()` and `actor()`. Duplicating this machinery keeps the frozen c6 capsule unchanged. Consolidating the two copies is a separate refactor and needs its own review.
- **`CheckoutIntentAdmission`** (new): installed at the end of `prepare()` **only when it inserts a NEW intent**. It retains:
  - the raw buyer identity and caller `User` attributes;
  - the full selection graph;
  - the capability/source history (approvals and closures included);
  - the authority, basis, review, order, lines and attempt rows;
  - the new intent by id and by `order_id`;
  - empty session, observation and payment sets;
  - `FreshCheckoutPolicy`.

  The frame deadline is capped at the earliest of the attempt expiry, the attestation, the authority policy and the license `effective_until`.
- **`CheckoutStaffWriteAdmission`** (new): installed at the end of `approve()` / `qualify()` **only for a NEW authority/basis**. It retains:
  - the exact raw staff `users` row (`is_admin`, `email_verified_at`, `app_authentication_secret`, recovery codes) and its audits;
  - the actor attributes;
  - the capability history;
  - the written row by id and by its idempotency selector;
  - for qualify, also the buyer identity, the selection graph and the authority row.

  `proveFresh()` reads the retained `Repository` items directly: `exemption_authoring_enabled === true` and the owner still delegated. The deadline is capped by the policy and attestation `effective_until`.
- **Replays install nothing.** An exact authority/basis replay, an intent retry (an intent already exists), `reconcile()`, `status()`, `record()` and `uncertain()` install no observer. A test asserts the dispatcher class seen inside every commit.
- **Belt-and-braces (reviewer condition 6):** `initiate()` now calls `proveCreatable()` after `requireGateway()` and immediately before the first `gateway->create` whenever no session exists yet. This covers both the first call and retries. `proveCreatable()` is a read-only `CommandTransaction` with no observer. It re-proves the following with the existing reason codes (`changed`, `disabled`/503), and it runs outside the provider `try`, so a refusal records no false `uncertain` observation:
  - buyer access;
  - the fresh flag (before and after);
  - the retained order/intent rows;
  - `TaxExemptions::proveRetained`;
  - `CurrentSelection::proveCurrent`;
  - `CurrentPolicy::proveCurrent`.

### Residual: not closed

1. **Withdrawal after the final re-proof.** A withdrawal that commits after `proveCreatable()` returns and before or during the external `create` call crosses the provider boundary. No database transaction can be held across that I/O. Such a session belongs to reconciliation and refund handling, and this change does not claim it is closed.
2. **A privileged listener that commits PDO directly.** It can still make rows durable before detection (c6 F-5, unchanged).
3. **Staff evidence outside the `users` row.** The MFA *requirement* is not re-evaluated at commit. The admin panel decides it with `isRequired: fn () => app()->isProduction()`, which reads the container's `env` instance (`$app['env']`). The frame does not track that instance: it compares only the `config` and `db` instances, the container aliases and the plain config parents, `app.env` among them. So a change to `$app['env']` alone is not compared at commit. What IS compared is the raw MFA enrollment columns (`app_authentication_secret`, `app_authentication_recovery_codes`) in the staff `users` row. Gate and Filament panel objects are also not re-evaluated. *(Wording corrected for review finding F-4; an earlier version wrongly said `app.env` covered the requirement.)*

## 4. Commands and results (exact source `2c3efc4e`)

Runner (worktree autoload, because `vendor/bin` is a symlink into the main checkout):

```sh
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <args>
```

Native env: `APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3421 DB_DATABASE=vaseyaudio_admission DB_USERNAME=root DB_PASSWORD=<ci-only> DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`.

The native server was a **private** `mysqld` 8.4.11 Community, not the shared :3306 server:

- `--no-defaults --port=3421 --bind-address=127.0.0.1 --skip-log-bin --innodb-flush-log-at-trx-commit=2`;
- the datadir lived under the session scratchpad;
- the reduced durability setting affects crash safety only, not transaction semantics.

### SQLite (`final-sqlite/`, `source-sha.txt` = `2c3efc4e`, clean tree)

| Selection | Tests | Assertions | Fail | Err | Skip |
| --- | --- | --- | --- | --- | --- |
| canary HostedIntent | 3 | 20 | 0 | 0 | 0 |
| canary ExemptionBasis | 2 | 10 | 0 | 0 | 0 |
| canary ExemptionAuthority | 2 | 8 | 0 | 0 | 0 |
| archived c6 physical (`--filter test_physical_committing_policy_withdrawal_prevents_new_order_rows`, SHA256 `ce114992…`) | 1 | 5 | 0 | 0 | 0 |
| archived c6 password/module (`d700297e…`) | 2 | 16 | 0 | 0 | 0 |
| archived 209 terminal (`1c9ebf89…`) | 1 | 5 | 0 | 0 | 0 |
| archived 2ec parent (`db34517b…`) | 1 | 5 | 0 | 0 | 0 |
| family: `tests/Feature/ProductionCheckout*.php` (15 files, including new `ProductionCheckoutWriteAdmissionAllTest` 10/63) + `tests/Unit/ProductionCheckout*.php` (2) + `tests/Unit/ProductionHostedEvidenceTest.php` | 188 | 914 | 0 | 0 | 8 |

The 8 skips are native-only by name: 5 `ProductionCheckoutMigrationTest` `test_native_*`, 2 `ProductionCheckoutNativeRaceTest` competing-assent, and 1 `ProductionCheckoutCommittedRowsTest` `test_native_plain_reader_*`.

The 209 canary writes an untracked `snapshot.json` into its archived folder. That file was moved to `final-sqlite/canary-209-generated-snapshot.json`, and the tracked original evidence is unchanged.

### Native MySQL 8.4.11 (`final-native/`)

`source-sha.txt` = `2c3efc4e`; `native-server.txt` = `8.4.11 3421 <scratchpad datadir>`. Every snapshot records `"driver": "mysql"`. The schema had 0 tables after the run (`tables-after.txt`).

| Selection | JUnit | Tests | Assertions | Fail | Err | Skip | Time |
| --- | --- | --- | --- | --- | --- | --- | --- |
| canary HostedIntent | `canary-HostedIntent.xml` | 3 | 20 | 0 | 0 | 0 | 403 s |
| canary ExemptionBasis | `canary-ExemptionBasis.xml` | 2 | 10 | 0 | 0 | 0 | 299 s |
| canary ExemptionAuthority | `canary-ExemptionAuthority.xml` | 2 | 8 | 0 | 0 | 0 | 220 s |
| archived c6 physical canary (unchanged) | `archived-c6-physical.xml` | 1 | 5 | 0 | 0 | 0 | 100 s |
| receipt pair: `--filter 'test_typed_consumer_admission_runs_after_late_delegate\|test_same_typed_admission_accepts_two_siblings'` on `ProductionCheckoutCommittedReceiptTest.php` | `receipt-pair.xml` | 2 | 12 | 0 | 0 | 0 | 436 s |
| new tests selected: `--filter 'test_committing_offer_withdrawal_refuses_new_intent_rolls_back_and_restores_dispatcher\|test_staff_replays_install_no_observer_and_positive_caller_write_survives'` | `new-selected.xml` | 2 | 17 | 0 | 0 | 0 | 348 s |

Native total: 12 tests, 72 assertions, all green. The other 8 tests in `ProductionCheckoutWriteAdmissionAllTest` ran on SQLite only.

`env-blocked-native-attempt1/` is **not evidence**. The first native green attempt lost its server at 15:17:08Z, because the harness stopped the backgrounded `mysqld` at its 30-minute background limit (`mysqld-error.log`: "Received SHUTDOWN … via user signal"). Every case there errored with 2006/2002. I then recreated the schema, restarted the server with the 2-hour limit and reran the same script unchanged.

### Pint

`php vendor/bin/pint --test` on every changed PHP file, canaries included: `{"result":"passed"}`.

## 4a. Independent review conditions (follow-up commits on this branch)

The independent review of `2c3efc4e` (`independent-review/DECISION.md`, written by the reviewer and committed in `8c30ce5b`) returned APPROVE WITH CONDITIONS. Each item is closed in its own commit:

| Item | Commit | Change | Evidence |
| --- | --- | --- | --- |
| C1 (F-1, Medium) | `f4eb4a40` | Ports the basis and authority role/MFA cases and the committing capability-closure case on a NEW intent into `tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php`, each asserting `write_source_changed`. | `conditions/C1-*`. Unmutated 15/87 green. M1 (staff plan removed) gives 4 failures. M3 (intent history plan removed) gives 1 failure. Both mutations were reverted. |
| C2 (F-2, Low) | `5eb82c3f` | The NEW-insert flag is a dedicated boolean `$inserted`, tested with `=== true`, in `TaxExemptions`, `ApproveExemptionAuthority` and `HostedCheckout`. The `$created` timestamps are unchanged. | SQLite: the permanent file 15/87, `ProductionCheckoutExemptionAuthorityTest` 11/50 and `ProductionCheckoutJourneyTest` 7/81 are green. |
| F-3 (Low) | `fe027fe3` | Every refusal now pins its reason: `write_frame` (frame config snapshot), `changed` (`proveCreatable`) or `write_source_changed`. The combined basis test is split, because its shared by-reference flag re-applied the owner withdrawal in the offer iteration. A new test calls the staff capsule's `proveFresh()` directly inside the commit and requires `authority` for both authoring and owner withdrawal. | `conditions/F3-*`. 17/96 green. M5 gives 1 failure and was reverted. |
| F-4 (Info) | this commit | Corrects the wording of residual 3 above. | — |

The `CommandTransaction.php` and `OriginalCommitDispatcher.php` hashes are unchanged (`fd8fccde…`, `ed529e8f…`).

**Native MySQL 8.4.11, conditions selection.** A second private `mysqld` was started on port 3422 with the same flags. The run is archived in `conditions/native/`.

- **Source:** `fe027fe3`. The server was 8.4.11 on port 3422, and the schema had 0 tables after the run.
- **Selection:** `--filter 'test_committing_capability_closure_refuses_new_intent_and_provider_io|test_committing_qualifier_staff_withdrawal_refuses_new_basis|test_committing_owner_staff_withdrawal_refuses_new_authority|test_staff_capsule_fresh_admission_itself_refuses_authoring_and_owner_withdrawal|test_committing_owner_delegation_or_offer_withdrawal_refuses_new_basis'`.
- **Result:** **8 tests, 37 assertions, 0 failures, errors or skips** (1102 s).
- **Not evidence:** a first attempt in `conditions/native-harness-error/` errored in fixture setup (8 errors). Its `TMPDIR` did not exist, so ffmpeg could not write the synthetic WAV, and no checkout code ran. The schema was reset and the run repeated unchanged.
- **SQLite checkout family on `fe027fe3`:** `conditions/sqlite-family/`, **195 tests, 947 assertions, 0 failures, 0 errors**, with the same 8 named native-only skips.
- **Cleanup:** the 3422 server was shut down at 17:16:11Z ("MySQL Server - end"), and its datadir, socket and temporary directories were removed. The other `mysqld` processes on this host belong to other lanes and were not touched.

**Recorded open items (not widened here, per the review):**

- F-5. The authority owner's row is not compared when a basis is created. Also, revoking a qualifier after a basis exists does not invalidate later intents.
- F-6. `CheckoutRawPlans` still needs to be consolidated with the c6 `CheckoutWriteAdmission`.

## 5. Cleanup

- The private `mysqld` (port 3421) was shut down with `mysqladmin shutdown` at 15:48:27Z (error log: "Shutdown complete").
- Its datadir and socket were removed, and no `mysqld` process remains on port 3421.
- The shared :3306 server and other lanes' schemas were never touched.
- The temporary red worktree `/home/user/VA-Studio-admission-red` (detached at `6dc890f4`) was removed with `git worktree remove --force`.
- Temporary snapshot directories were deleted after their JSON was copied here.
- The worktree `/home/user/VA-Studio-admission` remains, on branch `harness/checkout-write-admission-all`. Nothing was pushed.

## 6. What an independent reviewer must check

1. **Genuine red.** The red JUnit ran on `6dc890f4`, whose app tree is identical to `ece5a9ee`: `git diff ece5a9ee 6dc890f4 -- app tests` is empty. `git diff 6dc890f4 2c3efc4e -- docs` is empty, so the canaries are unchanged between red and green.
2. **c6 drift.** `git diff ece5a9ee 2c3efc4e -- app/Domain/Commerce/ProductionCheckout/{CheckoutWriteAdmission,CheckoutCommandCommitDispatcher,CheckoutCommandFrame}.php` should show only the type and interface lines listed in §3.
3. **`CheckoutRawPlans` equivalence.** Its `plan`/`read`/`definition`/`qualified`/`strings`/`set` and the `identity`/`selection`/`history` selectors should match c6's private methods. Check the added `staff()` selector against `PacketAuthority::raw`. Note the 257-row audit limit.
4. **Observer placement.**
   - Every capsule is captured as the LAST step of its closure, after all callback-capable work (decrypt, policy, byte, identity and gate).
   - It is installed only on the NEW-insert branch.
   - It is never installed for replay, read, retry, reconcile or record.
   - Never two observers on one frame.
5. **Plan completeness for qualify and intent.** Are any relied-on rows missing? For example the `customer_accounts` / identity rows for the qualifier (only the users row and its audits are planned), or rows `ExemptionPolicyV1::validate` reads indirectly.
6. **`proveCreatable()` ordering.** It runs after `requireGateway()` and before the provider `try`. Retries also run it. A refusal leaves no `uncertain` observation.
7. **Residual.** §3 states the race that crosses the external call as open. Do not read this work as closing it.
8. **Not covered.** Composition with Paid252 / receipt consumers beyond the unchanged receipt pair; MySQL 8.0; hosted Foundation CI; route/provider registration (root's step after review).

## 4b. Re-review of the condition commits

Addendum 1 in `independent-review/DECISION.md` (evidence in `review-evidence/addendum1/`)
assessed `c70aae57..8c30ce5b`: APPROVE WITH CONDITIONS carries to `8c30ce5b` with C1, C2,
F-3 and F-4 closed and nothing new above Info. The reviewer re-ran the three affected test
files on SQLite (17/96, 11/50, 7/81), re-applied mutations M1 (4 failures), M3 (1), M5 (1)
and a new M6 (basis observer never installed: 5 failures) against the permanent file, and
confirmed the frozen c6 files and every non-test surface are unchanged. Info A1-1 (this
README's "left untracked" wording) is corrected above; A1-2 and A1-3 record why the direct
`proveFresh()` test and the pinned `write_frame` reasons cannot pass by accident. F-5, F-6
and F-7 remain open items, not conditions.


## 4c. Codex P2 r4209950553: NEW-basis deadline ignored the selected license expiry

**Finding.** `CheckoutStaffWriteAdmission::basis()` capped the frame deadline only at the policy and attestation `effective_until`. A selected license that expires while `qualify()` is still running leaves its rows byte-identical, so the raw plans passed. The immutable basis then committed after its selection had stopped being effective. `CheckoutIntentAdmission` already folds in every active selected offer's license `effective_until`.

**Fix (this commit).**

- `basis()` now builds `$until` from the policy and attestation `effective_until`, plus a private `licenseUntil($selection)`. That helper walks the same `$selection['graph']` loop as `CheckoutIntentAdmission`: active offer, then current revision, then license, keeping each non-null `effective_until`.
- `authority()` is unchanged; it has no selection.
- No c6 file and no `CheckoutIntentAdmission` change.
- `tests/Support/ProductionCheckoutFixtures::catalog()` gains an optional `$licenseUntil` that passes through to the existing `ProductionTrackPreparationFixtures::prepared(licenseUntil:)`. The default is unchanged.

**Regression.** `test_new_basis_admission_is_capped_at_the_selected_license_expiry` in `tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php`:

1. The only selected license is published with `effective_until = now + 10 min`.
2. The test clock is frozen 1.5 s before that expiry, so `qualify()` sees an effective selection.
3. A committing listener sleeps 2.5 s of real monotonic time inside the physical commit.

The test requires a `CheckoutException` with reason `write_frame` (`CheckoutCommandFrame::prove()` refuses once `hrtime` passes the capped deadline), 0 basis rows, and no open PDO transaction.

**Runs (SQLite), in `conditions/codex-license-expiry/`:**

| Run | Source | Result |
| --- | --- | --- |
| `red/regression` | app tree of `9f3f1115` (unchanged; `red/app-state.txt`) plus the new test | exit 1, 1 test, 2 assertions, **1 failure**: "A withdrawn NEW checkout write was admitted" (the basis committed) |
| `green/regression` | the fix | exit 0, 1 test, 6 assertions |
| `green/ProductionCheckoutWriteAdmissionAllTest` | the fix | 18 tests, 102 assertions, green |
| `green/ProductionCheckoutExemptionAuthorityTest` | the fix | 11 tests, 50 assertions, green |
| `green/ProductionCheckoutJourneyTest` | the fix | 7 tests, 81 assertions, green |

Pint passes on the three changed PHP files. No native run was made for this item.
