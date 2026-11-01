# Order-ready email (transactional mail adapter)

Status: **built default-off (lane CX, FP-075); nothing is sent.** No sender or provider is chosen (U-07; completion plan owner input #9). Evidence: [lane CX packet](verification/customer-comms-cx-20261024/README.md).

## What it does

The private test order-ready intents (`transactional_notices`, type `test_order_ready`, see [the outbox record](verification/transactional-notification-outbox.md)) become at most one queued email each:

1. `TransactionalMailAdapter::queueOrderReady(noticeId)` (or `php artisan vasey:transactional-mail-queue --limit=50` for every intent without a delivery) checks the configuration, re-verifies the intent against current customer and purchase authority, records one `transactional_mail_deliveries` row (`queued`) and dispatches `DeliverTransactionalMail` (one try, after commit, carrying only the delivery id). A repeat returns the existing delivery.

   The command queues at most `--limit` emails per run, oldest intent first, and examines at most four times as many intents. An intent whose customer access version has since moved on (access withdrawn or the account erased; the version only increases) can never verify again: it leaves the pending set without any row being written and is reported as `superseded=N`. Any other refusal records nothing, is counted under `skipped` with its reason (`authority_withdrawn` when the customer, purchase or delivery checks refuse, for example an email that is no longer verified), and stays pending for the next run; an unexpected error on one intent is counted as `error` (only its exception class is logged). Neither stops the run, but each run starts again from the oldest pending intent: once `4 × --limit` (200 at the default) or more intents are refused on every run, without being superseded, a run spends its whole budget on them and newer intents wait. That case needs an operator (or a stored cursor, a recorded follow-up); `skipped` reaching the budget on consecutive runs is the signal.
2. The job claims the row (`queued → submitting`) in a committed transaction, re-verifies authority, decrypts the intent's recipient and order reference, and sends one plain-text message through the configured SMTP mailer: a `TEST ORDER — REHEARSAL ONLY` notice with the order reference, an instruction to sign in, and support@vasey.audio. It contains no link, token, price, file name or legal text.
3. Outcome: `accepted`, or `failed` (configuration missing or changed, customer access withdrawn, intent unreadable — all before any transport call), or `uncertain` when the transport raised an error after the attempt began. Nothing leaves `queued` twice, and an `uncertain` delivery is never retried automatically: check the provider's logs before acting. The customer's order page remains the source of truth for a paid order whose email failed.

**A delivery stuck in `submitting`.** If a worker dies after committing `queued → submitting` and before the transport call or the final transition (process killed, host restart), the row stays `submitting`. It is never resent: nothing moves a row out of `submitting` except the job that claimed it, and the claim only starts from `queued`. Because the transport may or may not have been reached, treat it like `uncertain`: check the provider's logs for the delivery id (the `X-Vasey-Delivery` header) before acting. Operators list such rows with the read-only command `php artisan vasey:transactional-mail-stuck --minutes=15` (independent CX review F5): it prints `stuck=N`, then one line per delivery (delivery id, notice id, claim time in UTC, oldest first, at most 100 or `--max`, with `more=N` beyond that) and writes nothing.

Logs carry the delivery id and exception class only; audit events carry ids, states and reasons. No email address or message body is logged.

## Refusal unless an operator configures it

`TransactionalMailPolicy` refuses, recording nothing, unless all of these hold:

- `VASEY_TRANSACTIONAL_MAIL_ENABLED=true` (default `false`; held false by the staging deploy gate through `scripts/ops/capability-boundary.php`).
- `VASEY_TRANSACTIONAL_MAIL_MAILER` names an `smtp` mailer in `config/mail.php` without a DSN `url`, with a real host (not `.test`, `.invalid` or `.localhost`) and port.
- `VASEY_TRANSACTIONAL_MAIL_SENDER` is a plain address on a real domain.
- The operator ran the existing read-only preflight on the declared facts, `python3 scripts/ops/email-operations-preflight.py --facts /path/facts.json > /path/preflight.json`, and set `VASEY_TRANSACTIONAL_MAIL_FACTS_PATH`, `…_FACTS_SHA256`, `…_PREFLIGHT_RECEIPT_PATH` and `…_PREFLIGHT_RECEIPT_SHA256`. Both files must be regular files (not links, at most 32 KiB) matching their hashes; the report must be the operator-mode `DECLARED_FACTS_VALID` result with every check passing; the declared planned sender, host, port and security (`starttls` for `smtp`, `implicit_tls` for `smtps`) must equal the configured mailer and sender.
- In `local` and `testing` the mail facade must be faked (`Mail::fake()`): no test or local run can reach a real transport.

A configuration change after a delivery was queued fails that delivery (`configuration_changed`) instead of sending under different settings. The binding is declared facts, not observation: it proves no DNS, SPF/DKIM/DMARC, TLS, credential, provider account scope or delivery. Those, and the choice of sender and provider, remain Sean's (`docs/ops/email-operations-runtime.md`).

## Records

Migration `2026_10_24_268300_transactional_mail_deliveries` (additive, forward-only, `down()` refuses): one row per intent (unique notice id; the intent is proven by the insert trigger rather than a foreign key so the notification family's own migrations keep their rollback shape), forward-only states, no deletes.

## Not built

Receipts for production orders (the production checkout lane PCC has no order-ready producer yet), contact-form operator notification by email, ESP or marketing email, bounce or complaint processing, suppression synchronization (FP-081/FP-091, U-13).
