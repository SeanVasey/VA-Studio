# Order-ready email (transactional mail adapter)

Status: **built default-off (lane CX, FP-075; transport shape and staff requeue added by BL-47); nothing is sent.** No sender or provider is chosen (U-07; completion plan owner input #9). Evidence: [lane CX packet](verification/customer-comms-cx-20261024/README.md).

## What it does

The private test order-ready intents (`transactional_notices`, type `test_order_ready`, see [the outbox record](verification/transactional-notification-outbox.md)) become at most one queued email each:

1. `TransactionalMailAdapter::queueOrderReady(noticeId)` (or `php artisan vasey:transactional-mail-queue --limit=50` for every intent without a delivery) checks the configuration, re-verifies the intent against current customer and purchase authority, records one `transactional_mail_deliveries` row (`queued`) and dispatches `DeliverTransactionalMail` (one try, after commit, carrying only the delivery id). A repeat returns the existing delivery.

   The command queues at most `--limit` emails per run, oldest intent first, and examines at most four times as many intents. An intent whose customer access version has since moved on (access withdrawn or the account erased; the version only increases) can never verify again: it leaves the pending set without any row being written and is reported as `superseded=N`. Any other refusal records nothing, is counted under `skipped` with its reason (`authority_withdrawn` when the customer, purchase or delivery checks refuse, for example an email that is no longer verified), and stays pending for the next run; an unexpected error on one intent is counted as `error` (only its exception class is logged). Neither stops the run. Runs no longer restart from the oldest pending intent: the command keeps a durable scan position (`transactional_mail_scan_progress`, migration `2026_11_03_268400`), reserves each next slice in a short transaction before any adapter work, and continues from that position on the next run. Refused or interrupted intents are revisited after the scan wraps around, so a set of permanently refused intents can no longer starve newer ones. A crash after a reservation, an uncertain commit or a rollback delays the affected intents by at most one pass; a run examines at most `4 × --limit` intents, and when a peer run holds a conflicting reservation the run stops, reports `error` and exits non-zero (no retry).
2. The job claims the row (`queued → submitting`) in a committed transaction, re-verifies authority, decrypts the intent's recipient and order reference, and sends one plain-text message through the configured SMTP mailer: a `TEST ORDER — REHEARSAL ONLY` notice with the order reference, an instruction to sign in, and support@vasey.audio. It contains no link, token, price, file name or legal text.
3. Outcome: `accepted`, or `failed` (configuration missing or changed, customer access withdrawn, intent unreadable — all before any transport call), or `uncertain` when the transport raised an error after the attempt began. Nothing leaves `queued` twice, and an `uncertain` delivery is never retried automatically: check the provider's logs before acting. The customer's order page remains the source of truth for a paid order whose email failed.

**Queued work whose job was lost.** If the broker push failed after the delivery committed, the row stays `queued` with no job. `php artisan vasey:transactional-mail-wake-queued --limit=50` (1 to 100) requests the original job again for `queued` rows only, using its own scan position; it never reopens a `submitting`, `failed`, `uncertain` or `accepted` row, and a broker acknowledgment is not a transport receipt. It has no age threshold, so a row whose original job is merely still waiting gets a second job; the job's `queued → submitting` claim makes that harmless (one transport at most), but run it as an operator step after a broker incident, not on a schedule. It refuses inside an open transaction and, like the queue command, in `local`/`testing` unless Mail is faked.

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

## Transport shape (BL-47, DD-43)

Order-ready mail uses its own mailer, `transactional` in `config/mail.php`, kept apart from `MAIL_MAILER` (which stays `log`). It is SMTP over implicit TLS on port 465 by default. Every value is per host and none is committed (empty or commented in `.env.example`, `ops/staging/env.staging.example` and `ops/production/env.production.example`):

| Key | Default | Notes |
| --- | --- | --- |
| `VASEY_TRANSACTIONAL_MAIL_MAILER` | unset | set to `transactional` to use this mailer |
| `VASEY_TRANSACTIONAL_MAIL_SMTP_HOST` | unset | provider SMTP host; with none set the policy refuses |
| `VASEY_TRANSACTIONAL_MAIL_SMTP_PORT` | `465` | production refuses any other port |
| `VASEY_TRANSACTIONAL_MAIL_SMTP_SCHEME` | `smtps` | implicit TLS; production refuses any other scheme |
| `VASEY_TRANSACTIONAL_MAIL_SMTP_USERNAME`, `…_PASSWORD` | unset | provider credentials; the password is a secret and lives only in the host environment |
| `VASEY_TRANSACTIONAL_MAIL_SMTP_EHLO_DOMAIN` | the `APP_URL` host | |
| `VASEY_TRANSACTIONAL_MAIL_SENDER` | unset | the From address; must equal the declared planned sender in the preflight facts |

Owner inputs (Sean, PCL-10): the provider account and credentials, the sender domain and its SPF, DKIM and DMARC records. DD-43 says the sender sits on a dedicated subdomain; Sean's later SQ-08 decision names a transactional provider stream on `mail.vasey.audio` with From `orders@vasey.audio`. The code enforces neither domain (it checks only that the sender is a plain address on a real domain that matches the declared facts), so the host value is whichever Sean confirms. In `production` the policy additionally refuses unless the mailer scheme is `smtps` and the port is 465; staging mail stays `log` and other environments are bound only by the declared facts.

## Paid but the email failed: recovery (BL-47)

The email is a convenience, not the record. An order that is paid whose message failed, or never arrived, stays fully reachable:

- **Customer.** Signing in to the account shows the order in `/orders/history` and opens its status, items and delivery (`/orders/{order}/status`, `/items`, `/delivery`). Nothing depends on the email or on a link in it (the message carries none).
- **Staff.** The admin page "Order-ready emails" (`StaffMailDeliveryResource`) lists delivery attempts and offers **Requeue email** on the newest attempt of a notice when it is `failed` or `uncertain`. The action calls `TransactionalMailAdapter::requeueOrderReady`, which requires a staff user with the admin panel's MFA enrollment (re-read under lock), the same admitted mail configuration as the first send (so nothing is queued while the mail is off), and an intent that still verifies against current customer and purchase authority. The page hides the button for other states; the server rechecks everything.
- **Idempotent, no duplicate send.** A requeue creates a new attempt row (migration `2026_11_11_000400`; the earlier row is evidence and stays unchanged). A requeue while the newest attempt is `queued` or `submitting` returns that attempt and queues nothing. If any attempt for the notice was `accepted` the requeue refuses (`already_delivered`). A `failed` attempt failed before any transport call, so it is requeued directly. An `uncertain` attempt may have been delivered, so staff must tick that the provider's logs for its delivery id show it was not; the audit event `notification.mail.requeued` (staff user, previous delivery id and state, attempt number, `provider_log_checked`) records the statement. Each attempt still sends at most once through the existing `queued → submitting` claim.

Not done: automatic retry, bounce handling, and a customer-facing "resend my email" button.

## Records

Migration `2026_10_24_268300_transactional_mail_deliveries` (additive, forward-only, `down()` refuses): one row per delivery attempt (BL-47: unique `(notice_id, attempt)`, attempt 1 unless staff requeued, migration `2026_11_11_000400`; originally unique per notice id; the intent is proven by the insert trigger rather than a foreign key so the notification family's own migrations keep their rollback shape), forward-only states, no deletes.

## Not built

Receipts for production orders (the production checkout lane PCC has no order-ready producer yet), ESP or marketing email, bounce or complaint processing, suppression synchronization (FP-081/FP-091, U-13).

The contact-form operator alert is a separate default-off transport that reuses this configuration, so a host that enables alerts has `VASEY_TRANSACTIONAL_MAIL_ENABLED=true` and is also armed for order-ready mail wherever a producer exists; see [contact inquiries](contact-inquiries.md#operator-alert-email-fp-013-default-off).
