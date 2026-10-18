DECISION: APPROVE WITH CONDITIONS at `18f7ed98f92ab3f9d509acf3222882c7bf079784`

There are no P0 or P1 findings, so this SHA can merge under the freeze-and-batch rule. One condition: fix P2-1 before an operator runs the hosted drills. It can ride in the lane's deferred batch.

## Findings

**P2-1: the merge left stale operator claims.**
- `docs/ops/stripe-test-drills.md:29` says the profile validator prints 36 `PASS` lines. Merged K2 (`ae362d16`) raised that to 38: see `tests/Unit/TestCommerceProfileValidatorTest.php:118` and `docs/ops/staging-test-purchase.md:61`.
- `docs/ops/stripe-test-drills.md:33` and the packet's `README.md:45` say `VASEY_TEST_UNPAID_RELEASE_*` is "deliberately absent" or "omitted". At HEAD the profile sets `VASEY_TEST_UNPAID_RELEASE_ENABLED=false`, and the validator requires that line (`ops/staging/test-commerce/env.test-commerce.example:137`, `scripts/ops/validate-test-commerce-profile.php:65`).
- The behaviour is the same (unpaid release stays off), but an operator checking the precondition will see a mismatch.
- **Remedy:** change 36 to 38, and reword to "set to `false` (validator-required)".

**P3-1: the evidence command's guard could be stronger** (`app/Console/Commands/TestCommerceEvidence.php:72-77`).
- What it covers: every Laravel query path (`select`, `statement`, `unprepared`, `cursor`) goes through `Connection::run`, so all of them are guarded, including writes from model events (proven by the test).
- What it does not cover: raw PDO and non-default connections. Neither is used by the command or its models; I found no `$connection` overrides and no `retrieved` hooks.
- Locking reads (`FOR UPDATE`, `FOR SHARE`) and `INTO` would pass the regex. None are issued today, and the test asserts that (`TestCommerceEvidenceCommandTest.php:118`).
- The callback stays registered on the connection after the command ends. It does nothing once `reading=false`, but closures pile up if the command is called repeatedly in one process.
- About 20 separate SELECTs can straddle a concurrent commit, so a snapshot can be internally inconsistent.
- **Remedy:** on MySQL, read inside `START TRANSACTION READ ONLY` at REPEATABLE READ. That makes read-only enforced by the database and gives one consistent snapshot. Also reject locking and `INTO` clauses in the regex.

**P3-2: one docstring claim is loose.** "Plaintext ledger columns only" is not quite true: `CheckoutIntent` is loaded with every column (`:143-160`), including `request_ciphertext` and `idempotency_key`. Neither is printed or decrypted. **Remedy:** select an explicit column list.

**P3-3: output is not escaped.** JSON and text output pass through Symfony's tag formatter unescaped (`:97`, `:262-284`). This is safe today because every printed value is checked upstream: event ID, type and object ID (`VerifyStripeWebhook.php:64-66, 103-104`), session ID and status (`CheckoutEvidence.php:93-105`), and app-generated outcomes. **Remedy:** use `OUTPUT_RAW` for JSON and `OutputFormatter::escape()` for text.

**P3-4: drill 4 never says to re-enable the webhook endpoint on abort.** Drill 4 disables the endpoint (`stripe-test-drills.md:159`), and "Attention outcomes" says to stop (`:174`). This is test mode only and moves no money, but an endpoint left disabled would make later drills reconcile-only. **Remedy:** "always perform 4.4 before stopping; record the endpoint state at the end of the session."

**P3-5: method count is off.** Packet `README.md:82` says "4 methods, 14 cases". There are 5 methods: four plain ones plus one with a 10-case data provider.

## Assessment against the brief

1. **Evidence command.**
   - All refusals run before any statement (`:106-132`). I confirmed this by hand with the database pointed at port 1: production, `STRIPE_MODE=live`, an `sk_live_` key, live production funds, `APP_ENV=demo` and an `--env` mismatch all printed "Refused" and never connected.
   - The one admitted case failed safe and logged only the exception class.
   - Output contains no buyer data, URLs, secrets, payloads or decrypted values.
   - References are matched against anchored regexes (`/D`) and passed as query bindings.
2. **Drill tests.** Every mutation was caught; details are under Commands. All 37 cited test methods exist. I read seven for accuracy and all matched their descriptions: the 5-case `nonSuccessfulPayments` set, the staging declined, abandoned and expired proofs, the duplicate-delivery proof, the replayed-event proof, and the cutoff proof.
3. **Brief deviation.** This is the existing rule: `staging-test-purchase.md:150,154`, and `UnpaidReleasePolicy` sits behind a staff Filament action. The runbook explains it clearly (`:31-34`, `:103-104`, `:119`), apart from the P2-1 wording.
4. **Native race.**
   - It is a real race: three distinct MySQL connections and three PIDs (`PaymentRace.php:27-29, 56`). Both workers park at the provider read and are released together (`:44, 49`; `payment-race-worker.php:39-43`), and the commits then serialize on the row locks (`VerifyTestPayment.php:107-108, 144`).
   - Removing those locks makes the test fail.
   - Existing race modes are unchanged: the new branch runs only when `intent` is set (`payment-race-worker.php:38-40, 51-52`), and no existing input sets it.
5. **Census.**
   - 281 entries. The first 280 are identical to both the merge parent and the base, and exactly one entry is appended.
   - It is the only new SQLite skip: both new test files ran with 0 skipped, and the 6 concurrency skips are 5 existing entries plus the new one.
6. **Runbook safety.** It uses test cards only (`4242…`, `…0002`, `…9995`, `…3155`), says to stop on live mode, forbids `--live` and never mentions production. The only gap is P3-4.

## Commands and counts

I ran everything in a scratch copy made with `git archive 18f7ed98`. Its tracked files were byte-identical to HEAD (`diff -rq`), and I deleted it afterwards.

One setup note for other reviewers: symlinking the whole `vendor` directory makes Composer resolve `App\` from the worktree, so mutations in the copy silently have no effect. I copied `vendor/composer` and `vendor/bin` into the scratch copy and symlinked the rest.

**SQLite, with the given env command:**
- `StripeTestDrillTest`: 7 tests, 153 assertions, OK.
- `TestCommerceEvidenceCommandTest`: 14 tests, 202 assertions, OK.
- `StagingTestCommerceProfileJourneyTest` and `TestPaymentConcurrencyTest` together: 15 tests, 249 assertions, 6 skipped (MySQL-only), OK.

**Native MySQL**, private Oracle 8.4.11 on port 33594 at REPEATABLE-READ: `--filter 'test_reconciliation_racing_a_receipt_worker|test_simultaneous_'` over `TestPaymentConcurrencyTest` and `StripeWebhookConcurrencyTest` gave 3 tests, 44 assertions, OK in 11:30. The server was then shut down cleanly ("Shutdown complete").

**Mutations.** The first six ran on SQLite against `StripeTestDrillTest`; each was reverted after its run.

| Mutation | Result |
| --- | --- |
| M1: a declined payment counts as confirmed | 3 failures, first at `:66` (`awaiting_finalization` instead of `pending`) |
| M2: removed the terminal-observation guard (`HostedCheckout.php:211-213`), so an expired session can flip to complete | caught at `:208` (`awaiting_finalization` instead of `payment_changed`) |
| M3b: reconciliation recorded as source `receipt` (`VerifyTestPayment.php:134`) | caught at `:94` and `:263` |
| M3a: a later webhook overwrites the reconciliation evidence, via Eloquent and via raw `DB::table` | caught at `:279`; the existing immutability already turns the overwrite into `retry` |
| M3c: the snapshot always reports a webhook source | caught at `:274`, by the drill test only |
| M4: decline details retained in evidence | caught at `:76` |
| M5 (native): removed the four commit locks | the race fails with `payment_changed` at `:65` |

**CI scripts and Pint:**
- CI self-tests: `test-focused-tests.py` 53 OK (including the pinned first-150 hash), `test-phpunit-shards.py` 52 OK, `test-database-receipts.py` 61 OK, `test-ci-scope.py` 27 OK, `test-workflow-cadence.py` 14 OK. The cadence test needs git, so I ran it in the worktree with `python3 -B`.
- `phpunit-shards.py --mysql-native-selection` reports 2450 of 9373 in 233 files. The new race lands in shard 7. Generated files deleted.
- `pint --test` passes on the three new PHP files. The two touched helpers fail with exactly the same fixers they already failed with at `c488277c`.

## Not verified

- Hosted staging, real Stripe test mode, and real Stripe CLI and Dashboard behaviour.
- The evidence command's cases on native MySQL (the author ran 2 of the 14).
- The other four `TestPaymentConcurrencyTest` races natively.
- Foundation CI and the full suite.
- The runner's `contracts` and `activate` lines in the runbook.
- Main's PaidTax changes, beyond the staging journey regression.

Worktree `/home/user/wt/k4` is untouched: HEAD is still `18f7ed98`, status is clean, there is no stash, and I made no GitHub writes. The datadir `/var/lib/va-mysql84-rev2` is still on disk, shut down, with its password file removed.
