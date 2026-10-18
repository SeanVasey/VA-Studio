# M-07 Stripe TEST drills: application-side evidence (2026-10-18)

Lane K4. Branch `harness/stripe-test-drills-20261018`, based on `origin/main` `c488277c`. Tested source:
`7e9207a9a38691be4ccbe5e7ce1f848847ad7685` (commits `46e9147d` feature, `7e9207a9` tests). The documentation commit
that adds this file changes no tested source.

**Scope.** This records local, synthetic proof that the application handles each M-07 drill, plus the operator runbook
[`docs/ops/stripe-test-drills.md`](../../ops/stripe-test-drills.md) and the read-only evidence helper
`vasey:test-commerce-evidence`. Every result uses the existing synthetic Stripe gateway and fixtures. No network call
reached Stripe, no real key was used, and nothing ran on the hosted staging host. **This is not M-07 acceptance**: the
hosted drill run remains open and is the actual evidence.

## Drill matrix

"Existing" proofs were already in the suite at `c488277c`; this lane cites them and did not change them. "New" proofs
were added at `7e9207a9`. Native means an independent-process race on MySQL 8.4; SQLite does not prove MySQL concurrency.

### (a) Declined card or failed payment

| Proof | What it proves |
| --- | --- |
| Existing `StagingTestCommerceProfileJourneyTest::test_declined_card_leaves_the_session_open_and_nothing_is_verified_or_granted` | Staging profile only: an open unpaid session (no PaymentIntent yet) reconciles as `pending`, nothing is verified or granted, and a retry in the same session completes the chain. |
| Existing `TestPaymentProcessingTest::test_payment_authorization_or_pending_status_is_not_a_success` (5 data sets) | `requires_payment_method`, `processing`, `requires_action`, `requires_capture` and `canceled` PaymentIntents never confirm. |
| Existing `TestPaymentProcessingTest::test_pending_receipt_can_later_confirm_without_overwriting_its_earlier_observation`, `::test_paid_session_conflicting_with_pending_payment_retries_without_claiming_confirmation` | A pending observation is kept when payment later succeeds; conflicting reads retry instead of confirming. |
| **New** `StripeTestDrillTest::test_declined_card_is_pending_with_an_honest_retry_state_and_the_same_session_retry_confirms_once` | The realistic decline shape (session open and unpaid, PaymentIntent `requires_payment_method` with `last_payment_error`): reconcile `pending`; no payment, finalization or grant; reservation untouched; retained evidence keeps no decline code, message or card digits; the buyer sees `open` / `not_verified` with the same Stripe session link; the snapshot reports the same. A `4242` retry on the same PaymentIntent confirms once and keeps the earlier observation; the completed webhook processed afterwards adds no payment or grant. |
| **New** `StripeTestDrillTest::test_failed_async_payment_event_is_never_success_and_the_order_stays_unpaid` | First test of `checkout.session.async_payment_failed`: signed receipt, `pending`, work `retry`, no payment or grant, reservation untouched, buyer sees `not_verified`. |

Only the hosted run can prove: the real session and PaymentIntent after a Stripe decline, that Stripe sends no
subscribed event for it, the decline wording and the return page as rendered.

### (b) Expired checkout session

| Proof | What it proves |
| --- | --- |
| Existing `StagingTestCommerceProfileJourneyTest::test_expired_session_is_recorded_without_payment_and_reconcile_keeps_observing_it` | `checkout.session.expired` finishes as `expired`, no payment or grant, status `expired`; each later reconcile sweep appends one observation (known finding). |
| Existing `StagingTestCommerceProfileJourneyTest::test_an_abandoned_order_keeps_its_offer_reserved_while_a_paid_order_releases_it` | On the staging profile an expired order's reservation stays `pending`, so the offer revision cannot be ordered again. |
| Existing `TestPaymentProcessingTest::test_expired_or_unpaid_session_never_infers_success_from_the_event_name`, `PaymentWebhookPreparationChecksTest::test_out_of_order_events_converge_on_authoritative_state_without_revoking_or_duplicating` | Event names and snapshots are locators only; a stale expiry event never revokes a confirmed payment. |
| Existing `TestUnpaidReleaseTest::test_release_retains_original_evidence_and_is_idempotent_without_provider_writes`, `::test_nonterminal_incomplete_or_foreign_evidence_never_releases_resources`, `::test_late_confirmed_money_is_retained_without_reopening_released_resources_even_with_pre_cutoff_inspection` | The staff-reviewed unpaid release (not enabled by the staging profile) releases only on GET-proven terminal unpaid evidence; late confirmed money after a release becomes a retained paid exception. |
| Existing `TestOrderFinalizationTest::test_confirmation_at_or_after_cutoff_is_a_retained_paid_exception`, `StagingTestCommerceProfileJourneyTest::test_payment_before_expiry_observed_after_expiry_remains_a_paid_exception` | Confirmation after the payment window is a paid exception with no grant. |
| **New** `StripeTestDrillTest::test_expired_session_keeps_its_reservation_and_a_later_completed_event_for_it_stays_expired` | A later signed `checkout.session.completed` for the expired session, whose snapshot claims payment, is processed as `expired`; the claimed PaymentIntent is never fetched; no payment or grant; reservation unchanged; the snapshot found by event ID shows both receipts and their outcomes. |
| **New** `StripeTestDrillTest::test_a_declined_attempt_left_to_expire_is_a_terminal_unpaid_outcome_without_a_grant` | Decline, then expiry with the PaymentIntent canceled: receipt outcome `canceled` (terminal, unpaid), no grant, status `expired`. |
| **New** `StripeTestDrillTest::test_provider_payment_reported_after_a_recorded_expiry_is_quarantined_without_a_grant` | If a provider read ever contradicts a recorded expiry by reporting the session paid, the receipt is quarantined `payment_changed`, reconcile repeats `payment_changed`, the terminal checkout observation is not regressed and no payment or grant exists. |

**Difference from the lane brief.** The brief expected the reservation or hold to be released on expiry. Under the
existing rules it is not: the staging profile deliberately omits `VASEY_TEST_UNPAID_RELEASE_*`, so an expired order's
reservation stays `pending` as evidence, and release is a separate staff action (Studio → Test unpaid release) that
needs GET-only proof of a terminal unpaid session. The tests pin the existing rule; nothing was changed.

Only the hosted run can prove: the expire API response, Stripe's delivery timing for `checkout.session.expired` after an
explicit or natural expiry, and the real expired-session shape.

### (c) Duplicate webhook delivery

| Proof | What it proves |
| --- | --- |
| Existing `StripeWebhookTest::test_duplicate_delivery_retains_the_original_receipt_even_when_formatting_and_pending_webhooks_change`, `::test_same_event_id_with_changed_payment_data_conflicts_without_rewriting_evidence` | One immutable receipt per event ID; reformatting and the delivery counter are ignored; changed content is `409` and never rewrites evidence. |
| Existing `PaymentWebhookPreparationChecksTest::test_replayed_event_id_is_one_receipt_one_work_row_and_one_confirmation` | Redelivery after processing: one receipt, one work row, one observation, one confirmation, no new provider calls. |
| Existing `StagingTestCommerceProfileJourneyTest::test_guest_purchase_runs_from_quote_to_exact_file_download_using_only_the_staging_profile` | Staging profile: the same event delivered twice before processing gives one receipt and one grant through download. |
| Existing `TestPaymentProcessingTest::test_distinct_receipts_and_stale_provider_observations_preserve_one_successful_confirmation`, `::test_retry_budget_is_finite_and_explicit_replay_recovers_the_same_receipt`; `TestOrderFinalizationTest::test_payment_and_checkout_replays_preserve_historical_grants_after_resource_consumption` | Distinct events for one payment give one confirmation; explicit replay is audited and bounded; replays after finalization keep the original grants. |
| Existing native `StripeWebhookConcurrencyTest::test_simultaneous_equivalent_deliveries_acknowledge_one_committed_receipt`, `::test_simultaneous_conflicting_deliveries_retain_one_winner_and_reject_the_conflict` | Two processes delivering the same event at once: one committed receipt; a conflicting body loses with `409`. |
| Existing native `TestPaymentConcurrencyTest::test_two_workers_for_one_receipt_share_one_claim_and_one_confirmation`, `::test_distinct_receipts_racing_for_one_payment_cannot_duplicate_confirmation` (plus the three lease/stale cases) | Simultaneous processing of one receipt, or of two receipts for one payment, gives one verified payment. |
| **New** `StripeTestDrillTest::test_dashboard_resend_after_the_order_completed_is_one_receipt_and_explicit_replay_changes_no_grant` | The operator drill after a finalized order: a resend minutes later with a fresh signature and changed delivery counter is `200`, the receipt is byte-identical, a full sweep finds no work, an explicit `--replay` prints `awaiting_finalization`, is audited, adds one observation and leaves payments, finalization, grants and entitlements identical; snapshot totals are unchanged. |

Only the hosted run can prove: Stripe's actual redelivery bytes and signature, how its automatic retries interleave with
a manual resend, and real network timing.

### (d) Reconcile without webhook

| Proof | What it proves |
| --- | --- |
| Existing `StagingTestCommerceProfileJourneyTest::test_reconcile_without_any_webhook_completes_the_same_chain`, `::test_unpaid_read_just_before_payment_is_reconciled_one_minute_later_within_the_quote_window` | Staging profile: the runner's reconcile stage completes the chain with no receipt, including a session read unpaid one minute before payment. |
| Existing `TestPaymentProcessingTest::test_known_session_reconciliation_covers_a_missing_webhook_after_checkout_policy_withdrawal`, `::test_missing_webhook_operator_reconciliation_uses_only_known_owned_intents`, `::test_reconciliation_cursor_can_pass_a_permanent_provider_failure_and_reach_later_work` | Reconciliation reads only known owned sessions, survives checkout withdrawal, prints no provider IDs and pages past failures. |
| Existing `TestOrderFinalizationTest::test_payment_and_checkout_replays_preserve_historical_grants_after_resource_consumption` | A webhook arriving after reconcile and finalization keeps the original grants. |
| **New** `StripeTestDrillTest::test_reconcile_without_webhook_keeps_reconciliation_evidence_when_the_webhook_arrives_later` | The verified payment's decrypted evidence records `source=reconciliation` and no receipt; one `commerce.payment.test_verified` audit event. A later webhook is processed as `awaiting_finalization` and leaves the confirmation (still reconciliation-sourced), finalization, grants and audit count unchanged; observations read reconciliation then receipt, as the snapshot shows. |
| **New** native `TestPaymentConcurrencyTest::test_reconciliation_racing_a_receipt_worker_for_one_payment_records_one_confirmation` | The reconcile path and a receipt worker, in independent processes on separate MySQL connections, both read the paid session before either commits: both report `awaiting_finalization`, one verified payment, two confirmed observations (one per source), one processed work row, business evidence unchanged, and no provider call inside a transaction. |

Only the hosted run can prove: disabled-endpoint behaviour, whether Stripe ever delivers events created during the
outage, and the real time from payment to reconciliation against the 15-minute window.

### Evidence helper

`TestCommerceEvidenceCommandTest` (4 methods, 14 cases): all four reference forms give the same snapshot; only `SELECT`
statements run (no locking reads); every table is byte-identical afterwards; no gateway call; no buyer name or email,
owner key, `sk_test_` value, webhook secret, checkout URL, ciphertext, hash or payload in either output form. A write
attempted by a model listener during the read is refused by the statement guard ("Evidence snapshot unavailable.
Nothing was written.") and ordinary writes work again afterwards. An order without a session binding reports only what
exists. Unknown, malformed and other-account references disclose nothing. Ten refusal cases (configured production,
running production, differing environments, outside test commerce, Stripe live mode, live key, restricted key, live
production funds, live production key, missing account) refuse before any statement.

## Commands and results

All from `/home/user/wt/k4`, PHP 8.4.26, PHPUnit 12.5.34, `public/build` absent.

### SQLite, at `7e9207a9`

```sh
env APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array \
  SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M \
  -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- \
  --display-skipped tests/Feature/StripeTestDrillTest.php tests/Feature/TestCommerceEvidenceCommandTest.php \
  tests/Feature/TestPaymentConcurrencyTest.php tests/Feature/StagingTestCommerceProfileJourneyTest.php \
  tests/Feature/PaymentWebhookPreparationChecksTest.php
```

| File | Tests | Assertions | Failures | Errors | Skipped |
| --- | --- | --- | --- | --- | --- |
| `StripeTestDrillTest` (new) | 7 | 153 | 0 | 0 | 0 |
| `TestCommerceEvidenceCommandTest` (new) | 14 | 202 | 0 | 0 | 0 |
| `TestPaymentConcurrencyTest` | 6 | 0 | 0 | 0 | 6 (MySQL-only, by design) |
| `StagingTestCommerceProfileJourneyTest` (regression) | 9 | 249 | 0 | 0 | 0 |
| `PaymentWebhookPreparationChecksTest` (regression) | 13 | 113 | 0 | 0 | 0 |
| **Total** | **49** | **717** | **0** | **0** | **6** |

Time 2:52. Pint `--test` passes on the three new PHP files. `tests/Feature/TestPaymentConcurrencyTest.php` and
`tests/Support/payment-race-worker.php` already fail Pint's default preset at `c488277c` with the same fixers; the
additions follow those files' existing compact style and were not reformatted.

### Native MySQL 8.4.11

Private Oracle MySQL 8.4.11 (`/opt/mysql-8.4.11`, `Ver 8.4.11 for Linux on x86_64`), datadir
`/var/lib/va-mysql84-k4/data`, `127.0.0.1:33592`, own socket, `REPEATABLE-READ`, disposable database `vaseyaudio_k4`
admitted through `VA_LOCAL_DISPOSABLE_MYSQL`. The disposable root password was local to that instance.

```sh
env APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33592 DB_DATABASE=vaseyaudio_k4 \
  DB_USERNAME=root DB_PASSWORD=<disposable> DB_URL= DB_SOCKET= VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_k4 CACHE_STORE=array \
  SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array php -d memory_limit=512M \
  -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <selection>
```

| Run | Source | Selection | Tests | Assertions | Failures | Errors | Skipped | Time |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Working tree before commit; the race test, worker, `PaymentRace` and application payment code were identical to `7e9207a9` | `tests/Feature/TestPaymentConcurrencyTest.php` (all 6, including the new race) | 6 | 83 | 0 | 0 | 0 | 18:11 |
| 2 | `7e9207a9` | `--filter 'test_reconciliation_racing_a_receipt_worker\|test_simultaneous_'` over `TestPaymentConcurrencyTest.php` and `StripeWebhookConcurrencyTest.php` | 3 | 44 | 0 | 0 | 0 | 7:06 |
| 3 | `7e9207a9` | `--filter 'test_every_reference_form_gives_the_same_minimized_snapshot_using_only_select_statements\|test_a_write_attempted_while_the_snapshot_reads'` over `TestCommerceEvidenceCommandTest.php` | 2 | 147 | 0 | 0 | 0 | 5:11 |

Run 2 exercised the new race and the two existing simultaneous-delivery races at the exact tested commit; run 3 exercised
the evidence helper's read-only and guard cases natively. Each case migrates a fresh schema, and the container was
shared with other lanes (load average about 3–4), hence the times. The server was then shut down cleanly with
`mysqladmin shutdown` ("Shutdown complete (mysqld 8.4.11)").

### Retained failures

None. No defect in payment handling was found. Every new test passed on its first run; two temporary mutations
confirmed the new tests can fail: counting `pending_webhooks` in the event fingerprint made the resend drill fail with
`409`, and a write inserted into the snapshot made the evidence test fail through the statement guard. Both mutations
were reverted before committing.

## Census and CI notes for the lead

- New MySQL-only method `Tests\Feature\TestPaymentConcurrencyTest::test_reconciliation_racing_a_receipt_worker_for_one_payment_records_one_confirmation`
  must be added to `scripts/ci/database-sqlite-skips.json`. The file is already selected for MySQL through that census.
  This lane did not edit census files or workflows.
- `tests/Support/payment-race-worker.php` (a driver-branching helper) gained a reconcile mode; its branching is unchanged.
- `StripeTestDrillTest` and `TestCommerceEvidenceCommandTest` do not branch on the driver and run on SQLite. They have
  no timing entries yet (`scripts/ci/phpunit-timings-sqlite.json`), so sharding uses the mean per-case cost until the
  timings are refreshed.

## Untested conditions

- Hosted staging: no host exists yet; nothing ran on Forge, nginx, PHP-FPM, the queue workers or the systemd runner.
- Real Stripe test mode: request and response shapes, decline objects, expire API, event delivery timing, Dashboard
  resend and `stripe events resend`, disabled-endpoint behaviour and events created while disabled.
- Real concurrency between Stripe's automatic retries, a manual resend, the queue worker and the runner on MySQL under
  production-like load (the native races cover two processes with controlled barriers only).
- Contract issuance, fulfillment activation and downloads are outside these drill tests; they are covered by the
  existing staging journey test (run here as regression) and their own suites.
- The evidence helper's refusal, not-found and unbound-session cases ran on SQLite only; two of its fourteen cases ran
  natively (run 3).
