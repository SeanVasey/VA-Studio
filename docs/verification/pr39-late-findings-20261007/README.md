# PR39 late review findings — 2026-10-07

Branch `harness/pr39-late-findings`, based on `d20d4394edcc84bf3c5a193f598d9b044b0753aa`.
One commit per finding. Each finding was reproduced with a failing test before its fix,
and the test stays in the suite. This is focused development evidence under the
October 6 CI cost policy. It is not Foundation CI, an independent review, or release
acceptance.

| Finding | Thread | Commit |
| --- | --- | --- |
| B — identity frame SQLite `query_only` | [r4206829231](https://github.com/SeanVasey/VA-Studio/pull/39#discussion_r4206829231) | `1d41bedf` `fix(identity): restore SQLite query_only after a caller replacement ends` |
| A — suppression target after email change | [r4206829225](https://github.com/SeanVasey/VA-Studio/pull/39#discussion_r4206829225) | the `fix(suppression): ...` commit that adds this file |

## Finding A — `SuppressionDelivery` misses the retained target after an email change

**Finding.** When the account email changes from A to B after a withdrawal but before
delivery, `process()` and `reconcile()` take the recipient from the current `users.email`.
`SuppressionOutbox::graph()` then looks up only HMAC(B) and returns `not_requested`. The
retained pending target for A can't be reached, so A can stay subscribed even though its
withdrawal intent is durable.

**Root cause.** `SuppressionDelivery::context()` used one value for two jobs: the current
email, which the consent and authority proof needs, and the suppression recipient, which
should come from the target captured at withdrawal time.

**Fix** (`SuppressionOutbox::retained()` plus three lines in `SuppressionDelivery`):

- Before the graph is built, the outbox evidence frame captures one row (`LIMIT 1`, the
  lowest `id`, `FOR UPDATE` on MySQL) from this account and purpose's
  `customer_suppression_targets` that still needs the current operation:
  - `process()` phase 1 takes a target with no attempt.
  - the post-commit transport transaction takes the claimed target, matched by
    `public_id`.
  - `reconcile()` takes a target whose attempt matches the current binding hash and has
    no confirmation. When the binding is unbound, nothing is selected.
- The recipient is decrypted from that target's own ciphertext. `graph()` then runs
  unchanged on that recipient. It re-proves the full capture, recomputes the keyed HMAC
  against the stored `recipient_hmac`, and checks the latest intent → withdrawn event →
  event capture chain. A target that isn't authentic still fails with a 503.
- The current email is used only when no target needs work, so existing status reporting
  is unchanged. The consent and authority proof (`ConsentEvidence::prove`) still binds
  the current user row and current email.
- Safeguards are unchanged. The attempt still commits before the only adapter call. An
  attempted target is never selected for sending again, so an unknown outcome is never
  resent. Grants still don't unsuppress, because a target or intent is never removed.
  Confirmation still needs exact receipt evidence for the request. `read()` still shows
  only the current address's status.

**Tests** (`tests/Feature/CustomerSuppressionTest.php`):

- `test_email_change_after_withdrawal_delivers_retained_captured_target_exactly_once`,
  with datasets positive / null receipt / transport throws. It withdraws with A, updates
  the email to B, then runs process → process → (reconcile) → reconcile. It asserts:
  - exactly one send and at most one inspect, with every request using recipient A and
    A's stored HMAC;
  - one attempt on A's target, committed before transport;
  - unknown outcomes are not resent, and reconcile confirms them;
  - exactly one target, one intent and one confirmation, with nothing derived for B;
  - `read()` reports `not_requested` for B.
- `test_retained_and_current_pending_targets_are_each_sent_once_oldest_first`. A is
  pending and B is withdrawn too. Three `process()` calls send A, then B, then nothing.

Before the fix, all four cases fail (`finding-a-before-fix-sqlite.txt`: 4 tests, 4
failures, with `not_requested` where `confirmed`/`unknown` was expected, and the
oldest-first case sent only B).

## Finding B — `IdentityCommittedFrame` leaves SQLite `query_only` on after a single close

**Finding.** If foreign code commits the owned frame and leaves a replacement transaction
open, the usual single `finally { $frame->close(); }` correctly leaves the replacement
read-only (PR38 r4206672462). After that, nothing restores the captured idle default when
the caller ends the replacement. The connection stays `query_only=1`, and the caller's
next ordinary write fails. The existing proof only passed because it called `close()` a
second time.

**Root cause.** Restoration only ran inside `close()`/`finish()`, while the connection was
idle. PHP 8.4 PDO has no commit or rollback hook (`Pdo\Sqlite` has no commit hook or
authorizer), so the frame has no way to see a raw `$pdo->rollBack()`/`commit()` when it
happens.

**Fix.** If `close()` finishes while a foreign transaction is still active (so `closed`
stays false, SQLite only), it registers one settle closure on the frame's captured
Laravel connection, both as `beforeStartingTransaction` and as `beforeExecuting`. The
caller's next framework statement or `DB::transaction()` therefore settles the frame
before that work runs.

How ownership is preserved:

- The closure restores only through the existing guard `restoreSqlite()`, which acts only
  when the connection is idle. While the replacement is active, the closure does nothing.
  The test asserts that a framework `SELECT` during the replacement leaves its
  `query_only=1` in place. The closure never commits, rolls back or changes a foreign
  transaction.
- `used` was already set to true by `close()` and stays true. Settling only sets `closed`
  and restores the setting. `reader()`, `assertActive()` and `finish()` still refuse with
  `committed_frame_required`.
- The owned-savepoint rollback, `finish()` and the MySQL path are unchanged. MySQL uses
  `SET TRANSACTION`, which affects only the frame's own transaction, so it needs no
  restoration.
- An explicit later `close()` still restores straight away. The archived original canary
  is byte-identical (sha256 `9a55aa2e…ee81d`, matching `artifact-sha256.json`) and still
  passes.

**Test.** `test_single_close_restores_idle_default_after_caller_ends_replacement_without_another_close`
in `tests/Feature/ProductionIdentityAdapters/ProductionIdentityCommittedFrameTest.php`.
Its datasets cover rollback or commit by the caller, followed by an ordinary statement or
`DB::transaction()`. The test calls `close()` once during the caller's replacement, and
the caller then ends the replacement. With no further `close()`, the caller's ordinary
write succeeds, `query_only` equals the captured default, and `reader()`,
`assertActive()` and `finish()` all refuse. Before the fix, all four datasets fail with
`attempt to write a readonly database` (`finding-b-before-fix-sqlite.txt`).

**Limit (not fixable without a PDO hook).** Between the caller's raw commit or rollback
and its next framework statement or transaction start, raw-PDO statements still see
`query_only=1`. The same applies when a new frame begins on the same PDO in that gap,
because it captures `1` as its default. Ordinary framework use, or the next settle, fixes
the state afterwards. Each deferred frame stays referenced by the connection's callback
arrays, which have no removal API. This happens only on the adversarial replacement path.

## Commands and results

Environment: PHP 8.4.26, PHPUnit 12.5.34, worktree-local Composer autoload (direct
PHPUnit, no Artisan). SQLite uses the `phpunit.xml` default (`:memory:`). `public/build`
is absent.

Native runs used a private, disposable `mysqld` 8.4.11 on `127.0.0.1:3412` (fresh
datadir, schema `vaseyaudio_p2`). The shared `:3306` server holds other lanes' schemas.
Their triggers name the same capability tables, and
`CapabilityMigrationOwnership` checks every schema's triggers. So every `migrate:fresh`
there failed with `external or additional table guard`: a first attempt on :3306 gave 7
tests, 5 errors. That is environmental, and the private instance avoids it.

```
# SQLite runner
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- -c phpunit.xml <args>
# Native runner: same command with
APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3412 DB_DATABASE=vaseyaudio_p2 DB_USERNAME=root DB_PASSWORD=ci-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
```

| Run | Selection | Result |
| --- | --- | --- |
| B pre-fix, SQLite | `--filter test_single_close` frame test | 4 tests, 16 assertions, **4 errors** (readonly write) — expected |
| B SQLite | `tests/Feature/ProductionIdentityAdapters tests/Feature/ProductionIdentity` | 112 tests, 613 assertions, 0 failures/errors, **9 skipped** (all native-only `markTestSkipped`, listed in `finding-b-sqlite.xml`) |
| B archived canary, SQLite | original test preloaded from `cloud-identity-frame-cleanup-independent-20261007/root-evidence/original/…`, `--filter test_close_preserves_replacement_transaction_read_only_state` | 1 test, 5 assertions, OK |
| B native | `--filter 'test_single_close\|test_close_preserves\|test_native_validation\|test_direct_commit'` frame test | 7 tests, 51 assertions, OK, 0 skipped |
| B native-only | all 9 SQLite-skipped identity cases: `--filter 'test_native_plain_closed_prefix_does_not_wait\|test_native_validation_reads_current_committed\|test_native_dictionary_unicode_guard_alias_on_foreign_table\|ProductionIdentityNativeRaceTest'` on both directories | 9 tests, 2019 assertions, OK, 0 skipped |
| A pre-fix, SQLite | `--filter 'test_retained_and_current\|test_email_change_after'` | 4 tests, 11 assertions, **4 failures** — expected |
| A SQLite | the 9 consent/suppression feature files (`CustomerConsent{Admission,Boundary,Migration,Preferences}Test`, `CustomerSuppression{KeyAdmission,Migration,NativeAdmission,SourceBoundary,}Test`) | 178 tests, 1052 assertions, 0 failures/errors, **3 skipped** (native-only DDL admission cases) |
| A native | `--filter 'test_email_change_after_withdrawal\|test_retained_and_current\|test_grant_does_not_unsuppress\|test_changed_provider_binding\|test_single_positive_attempt'` | 7 tests, 92 assertions, OK, 0 skipped |
| Pint | `php vendor/laravel/pint/builds/pint --test` on the 5 changed PHP files | passed |
| `git diff --check` | | clean |

The post-fix SQLite and native runs executed on the working tree that became the final
commit, with both fixes present. The B pre-fix run had only B's new test applied. The A
pre-fix run had A's runtime changes stashed.

Not run: the full PHP suite, the native consent/suppression DDL admission cases,
Foundation CI and browser specs.

## For the independent reviewer

1. **A, selection predicates.** The `NOT EXISTS`/`EXISTS` subqueries name
   `customer_suppression_attempts`/`_confirmations` without a qualifier. `rows()` checks
   temp-table shadows only for the outer table. A shadow can affect *selection* only.
   `graph()` then captures attempts and confirmations through `rows()`, which refuses a
   shadow with a 503. So a shadow can't cause a resend or a false confirmation, but
   decide whether a non-delivery from a shadow is acceptable. On MySQL the subquery is a
   non-locking read under the outer `FOR UPDATE`. A stale snapshot can only pick a target
   whose attempt `graph()` then sees through a locking read, which gives `unknown` and no
   send.
2. **A, one target per call.** Each call still processes one target and returns that
   target's status. A target that stays unknown under the current binding blocks
   `reconcile()` for later targets of the same account. That matches the earlier
   single-target behaviour.
3. **A, privacy.** The provider now receives old address A, which is exactly the address
   whose withdrawal was captured. No new address input exists. The DTO still has no
   recipient.
4. **B, hook semantics.** Check that `beforeStartingTransaction` runs before
   `createTransaction()`, which it does in `ManagesTransactions::beginTransaction`. Also
   check that the settle closure can't run inside the frame's own active transaction: it
   is registered only from `close()`, after `used=true`. Review the limit described
   above.
5. Previous approvals stay bound to their recorded commits. This change modifies the
   reviewed `IdentityCommittedFrame`, so carrying it forward needs a new independent
   review of `1d41bedf`.
