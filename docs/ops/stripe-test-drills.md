# Stripe TEST drills (M-07): operator runbook and evidence capture

Status: **prepared, not yet run against Stripe.** The application side of every drill is proven locally with synthetic
provider fixtures ([verification record](../verification/stripe-test-drills-20261018/README.md)). Nothing here has run on
the hosted staging host or against a real Stripe test account. The first hosted run is the evidence; expect
interoperability findings and record them for M-08.

This runbook covers the four M-07 drills in [`MONDAY-PLAN.md`](../handoff/2026-10-08/MONDAY-PLAN.md): declined card,
expired session, duplicate webhook and reconcile without a webhook. It adds exact evidence capture to the drills in the
[guest purchase walkthrough](staging-test-purchase.md#drills), which remains the guide for setup and the normal purchase.
The drill numbers match.

## Boundary: test mode only

- Every step uses the Stripe Dashboard in **Test mode**, `sk_test_` keys and the private staging host. Stop if the
  Dashboard shows live mode, if anyone supplies a `sk_live_`/`rk_live_` key, or if a step would touch production, DNS
  or BeatStars. Refuse such instructions; this runbook grants no live-payment authority.
- The application refuses live mode on its own: the webhook receiver accepts only `livemode=false` own-account events,
  every test-commerce gate requires `STRIPE_MODE=test`, and the evidence helper below refuses production and live
  configuration. Do not work around a refusal.
- Two steps are deliberate Stripe **test-mode writes** from the operator's Stripe CLI: expiring a session (drill 2) and
  disabling or re-enabling the webhook endpoint (drill 4). Resending an event (drill 3) asks Stripe to deliver again.
  None of them moves money.
- Never weaken a signature, dedupe, payment or authorization check to make a drill pass. A drill that fails is a finding.

## Before the first drill

1. Staging is deployed and the test-commerce profile validates: `php scripts/ops/validate-test-commerce-profile.php .env`
   prints 36 `PASS` lines and `RESULT: PASS` ([setup step 3](staging-test-purchase.md#3-apply-the-profile-on-the-host)).
2. The pipeline runner is installed and logging ([setup step 4](staging-test-purchase.md#4-start-the-pipeline-runner)).
3. A **dedicated drill offer** is published and linked to its rights scope. Drill 2, and drill 1 if abandoned, leave
   that offer revision reserved for good, because the staging profile has no unpaid release
   (`VASEY_TEST_UNPAID_RELEASE_*` is deliberately absent). Buy the success paths from a different offer, or use one
   drill offer per drill.
4. The operator machine has the Stripe CLI logged in to the drill account (`stripe login`) for drills 2–4. CLI commands
   run in test mode unless `--live` is given: never pass `--live`. The Dashboard works too where noted.
5. Use the staging runbook's alias, as the application user in the served release:

   ```sh
   alias art='sudo -u forge php8.4 /srv/vasey-staging/current/artisan'
   ```

6. Create the evidence directory on the host, private to the operator:

   ```sh
   EVIDENCE=/srv/vasey-staging/evidence/m07-$(date -u +%Y%m%d)
   sudo install -d -m 0700 -o forge -g forge "$EVIDENCE"
   ```

Record every command as the [activation packet §7](production-activation-packet.md#7-evidence-and-redaction-rules-for-every-stage)
requires: the command, exit code, UTC time, deployed SHA (`sudo /usr/local/sbin/vasey-staging-ctl status`) and operator.
Append; never overwrite. Keep failed and refused runs and label them. Label everything **synthetic Stripe test mode**.

## Read-only commands used as evidence

| Command | Shows | Writes? |
| --- | --- | --- |
| `art vasey:test-commerce-evidence <REF> [--json]` | One order's minimized state: reservation, checkout status, payment (verified, source, confirming event), payment observations, webhook receipts with their work state and outcome, finalization, grant count, fulfillment, unpaid release and account-wide test totals | No: SELECT statements only, no Stripe request, no decryption |
| `art vasey:stripe-inbox --limit=10` | Recent receipts: event ID, type, session ID, received time | No |
| `art vasey:doctor --json` | Redacted installation report | No |
| `php scripts/ops/validate-test-commerce-profile.php .env` | Profile checks; no values printed | No (reads the file you pass) |
| Runner log (`journalctl -u vasey-test-commerce-pipeline.service`, or the Forge log file) | `<stage> <opaque id> <outcome>` lines | — |

`<REF>` for the evidence helper is any of: the order UUID shown to the buyer, the checkout intent UUID (runner
`reconcile` lines), the Stripe session ID (`cs_test_…`, from the Stripe page address) or a Stripe event ID (`evt_…`,
from the Dashboard or `vasey:stripe-inbox`). All four give the same snapshot.

The helper refuses to run, and reads nothing, when `APP_ENV` is production, when the configured and running
environments differ (for example `--env`), outside `local`, `testing` and `staging`, unless `STRIPE_MODE=test` with an
`sk_test_` key or none, when any live production checkout configuration is present, or without `STRIPE_ACCOUNT_ID`. It
prints no buyer name or email, owner key, checkout URL, ciphertext, hash or credential. It does print the Stripe test
object IDs (`cs_test_…`, `pi_…`, `evt_…`) and the internal receipt number, so the packet can be matched to the Dashboard
and an explicit replay can name its receipt. Records of any other Stripe account are reported as not
found.

These commands **do** change state and are used deliberately below; record each use:
`bash scripts/ops/run-test-commerce-pipeline.sh` (one sweep), `art vasey:reconcile-test-payments` and
`art vasey:process-stripe-receipts` (Stripe GETs, then retained observations and at most one verified payment).

Capture a snapshot with:

```sh
art vasey:test-commerce-evidence ORDER_UUID --json | sudo -u forge tee "$EVIDENCE/drill-1/02-after-decline.json" >/dev/null
```

`RECONCILE_INTERVAL_SECONDS=0 bash scripts/ops/run-test-commerce-pipeline.sh` forces a sweep, including the reconcile
stage, as the application user from `/srv/vasey-staging/current` (the kit's writer gate still applies). Otherwise wait for
the timer; the default reconcile cadence is one minute.

## Drill 1: declined card

Locally proven: [matrix row (a)](../verification/stripe-test-drills-20261018/README.md#drill-matrix).

| Step | Do | Expect | Record |
| --- | --- | --- | --- |
| 1.1 | Prepare an order for the drill offer and open Stripe test checkout. Copy the `cs_test_…` ID from the address bar. | Stripe's page shows the order amount, card only. | Order UUID, `cs_test_…`, UTC time. Snapshot `01-open.json`: `checkout.latest_status=open`, `payment.verified=false`, `reservation.state=pending`. |
| 1.2 | Pay with `4000 0000 0000 0002`, any future expiry, any CVC, any postcode. | "Your card was declined." Stripe stays on its page. Dashboard → Payments lists a failed test PaymentIntent. Dashboard → Webhooks shows **no** `checkout.session.*` delivery for it. | Dashboard screenshot of the PaymentIntent (`pi_…`) and its decline code. |
| 1.3 | Force a sweep, or wait one minute. | Runner: `reconcile <intent> pending`; no `finalize` line for the order. | Runner log lines. Snapshot `02-after-decline.json`: `payment.verified=false`, `observation_outcomes` has `pending`, `payment.observations[].source=reconciliation`, `checkout.latest_status=open`, `grants.count=0`, `finalization=null`, `reservation.state=pending`. |
| 1.4 | On the store's return page press **Check Stripe test checkout status**. | Payment not verified; the checkout link still opens the same Stripe session. | Screenshot. |
| 1.5 | On the same Stripe page, pay with `4242 4242 4242 4242`. | Redirect to the store's return page, "not verified yet" until the pipeline runs. Within about two minutes: `receipts <id> awaiting_finalization` (or `reconcile <intent> awaiting_finalization` if the sweep got there first), then `finalize <order> paid`, `contracts <grant> ready`, `activate <order> activated`. | Runner log. Snapshot `03-after-retry.json`: `payment.verified=true`, `payment.payment_intent_id` equals the Dashboard's `pi_…` from 1.2, `grants.count=1`, `finalization.outcome=paid`, `reservation.state=consumed`. |

Variants, each on a fresh order: `4000 0000 0000 9995` (insufficient funds) and `4000 0025 0000 3155` with **Fail** on the
authentication window behave like 1.2–1.3. If the buyer abandons after a decline, the reservation stays pending
(see drill 2).

Only the hosted run can show: the real session and PaymentIntent shape after a decline, that Stripe sends no subscribed
event for it, the decline wording, and the return page as the buyer sees it.

## Drill 2: expired session

Locally proven: [matrix row (b)](../verification/stripe-test-drills-20261018/README.md#drill-matrix).

| Step | Do | Expect | Record |
| --- | --- | --- | --- |
| 2.1 | Prepare an order for a drill offer, open Stripe checkout, do not pay. Copy the `cs_test_…` ID. | Open session. | Snapshot `01-open.json`; note `checkout.provider_expires_at` (60 minutes after checkout started). |
| 2.2 | Expire it from the operator machine (test-mode write): `stripe post /v1/checkout/sessions/cs_test_…/expire`. Or wait until `provider_expires_at`. | CLI returns the session with `"status": "expired"`. Dashboard → Webhooks: one `checkout.session.expired` delivery answered `200`. | CLI output (it contains no secret; drop any customer fields if present), delivery screenshot, `evt_…`, UTC time. |
| 2.3 | Wait for the next sweep. | Runner: `receipts <id> expired`. If a declined attempt preceded the expiry, Stripe cancels its PaymentIntent and the line reads `canceled`; both are terminal unpaid outcomes. | Snapshot by event: `art vasey:test-commerce-evidence evt_… --json` → `02-expired.json`: `checkout.latest_status=expired`, one receipt `checkout.session.expired` with `work_state=processed`, `work_outcome=expired`, `payment.verified=false`, `grants.count=0`, `finalization=null`, `reservation.state=pending`, `unpaid_release=null`. |
| 2.4 | Press **Check Stripe test checkout status** on the return page. | Status `expired`; no checkout link. | Screenshot. |
| 2.5 | Try to prepare a new order for the same offer revision. | `INVENTORY_UNAVAILABLE`. Expected on staging: the reservation stays pending as evidence, and unpaid release is a separate, staff-reviewed path that this profile does not enable. | Screenshot of the error. |
| 2.6 | Leave the runner going for a few minutes, then take another snapshot. | Each eligible reconcile sweep reads the expired session again: `reconcile <intent> expired`, and `payment.observation_total` grows by one per sweep. Known behaviour, recorded in the walkthrough; keep drill orders few. | Snapshot `03-later.json`. |

**Late "completed" after expiry.** Stripe cannot complete an expired session, so the hosted run cannot produce one
honestly. If an out-of-order or replayed `checkout.session.completed` arrives for an expired session, the application
re-reads the session, still sees `expired`, and the receipt finishes as `expired` with no grant (locally proven). If a
Stripe read ever reports the expired session as paid, the receipt is **quarantined** as `payment_changed`, the
reconcile line repeats `payment_changed`, no payment is verified and no grant exists. Stop and escalate (see
[Attention outcomes](#attention-outcomes)); do not replay it.

Only the hosted run can show: the expire API response, how long Stripe takes to deliver `checkout.session.expired` (after
an explicit expire or a natural expiry), and the real expired-session shape.

## Drill 3: duplicate webhook

Locally proven: [matrix row (c)](../verification/stripe-test-drills-20261018/README.md#drill-matrix), including native
MySQL 8.4 races for simultaneous deliveries and simultaneous processing.

| Step | Do | Expect | Record |
| --- | --- | --- | --- |
| 3.1 | Use a completed purchase (drill 1's retry, or a normal `4242` purchase). In the Dashboard, open its `checkout.session.completed` event and copy the `evt_…` ID. | Order finalized and activated. | Snapshot `01-before.json` by event ID. Note `totals` and the receipt's `receipt_id`, `work_state=processed`, `work_outcome=awaiting_finalization`, `attempts`. |
| 3.2 | Resend it: Dashboard → the event (or Webhooks → your endpoint → the delivery) → **Resend**; or `stripe events resend evt_… --webhook-endpoint=we_…` (omit `--webhook-endpoint` if the account has one endpoint). | A new delivery answered `200` with `{"received":true}`. | Delivery screenshot showing both deliveries and their times. |
| 3.3 | `art vasey:stripe-inbox --limit=5`. | Still **one** row for that `evt_…`. | Output. |
| 3.4 | Wait for a sweep. | No new `receipts` line for it: processed work is not run again. No new `finalize`, `contracts` or `activate` line for the order. | Runner log for the window. Snapshot `02-after-resend.json`: same `totals`, same single receipt row (same `attempts`), `grants.count=1`, `payment.confirming_event_id` unchanged. |
| 3.5 | Optional, simultaneous deliveries: press **Resend** twice in quick succession, or Resend while `stripe events resend` runs. | Every delivery `200`; still one receipt; same totals. | As 3.2–3.4. |
| 3.6 | Optional, explicit operator replay (audited): `art vasey:process-stripe-receipts RECEIPT_ID --replay` with `receipt_id` from the snapshot. | Output `RECEIPT_ID awaiting_finalization`. One more payment observation (source `receipt`); no second payment, finalization, grant or contract. | Output and snapshot `03-after-replay.json`: `totals` unchanged, `payment.observation_total` up by one, `attempts=1`. |

A changed body for the same event ID is answered `409 STRIPE_EVENT_CONFLICT` and the original receipt is kept; the
Dashboard would show that delivery as failed. That is a finding to record, not something to retry around.

Only the hosted run can show: Stripe's actual redelivery bytes and signature, how its automatic retries interleave with
a manual resend, and real network timing.

## Drill 4: reconcile without a webhook

Locally proven: [matrix row (d)](../verification/stripe-test-drills-20261018/README.md#drill-matrix), including a native
MySQL 8.4 race between the reconcile stage and a webhook worker.

| Step | Do | Expect | Record |
| --- | --- | --- | --- |
| 4.1 | Disable event delivery: Dashboard → Webhooks (or Workbench → Event destinations) → the endpoint → **Disable**; or `stripe post /v1/webhook_endpoints/we_… -d disabled=true`. With the CLI-forwarding fallback instead, `sudo systemctl stop stripe-listen`. | The endpoint shows disabled. | Screenshot or CLI output, UTC time. |
| 4.2 | Prepare an order and pay with `4242 4242 4242 4242`. | Return page stays "not verified". No delivery in the Dashboard. | Order UUID, `cs_test_…`, UTC payment time. |
| 4.3 | Within the 15-minute payment window, reconcile now: `art vasey:reconcile-test-payments INTENT_UUID` (intent from a snapshot), or `art vasey:reconcile-test-payments --limit=25`. The runner's own reconcile stage will also find it within a minute; record which one did. | `<intent> awaiting_finalization`. The next sweep prints `finalize <order> paid`, then `contracts` and `activate`. | Output, runner log. Snapshot `01-reconciled.json`: `payment.verified=true`, `payment.source=reconciliation`, `payment.confirming_event_id=null`, `receipts=[]`, `grants.count=1`. |
| 4.4 | Re-enable delivery: Dashboard **Enable**, `stripe post /v1/webhook_endpoints/we_… -d disabled=false`, or `sudo systemctl start stripe-listen`. | Endpoint enabled. Do not count on Stripe delivering events created while it was disabled. | UTC time. |
| 4.5 | Optional: resend the purchase's `checkout.session.completed` (drill 3, step 3.2). | `200`; runner `receipts <id> awaiting_finalization`; nothing else changes. | Snapshot `02-late-webhook.json`: `payment.source` still `reconciliation`, one receipt with `work_outcome=awaiting_finalization`, `payment.observations` show a `reconciliation` row then a `receipt` row, same `totals`, `grants.count=1`. |

Paying close to the deadline and reconciling after it gives a **paid exception** (no grant, listed under Studio → Test
commerce → Test payment exceptions), as the walkthrough's drill 5 describes. That is correct behaviour, not a drill
failure; note it if it happens.

Only the hosted run can show: Stripe's disabled-endpoint behaviour, whether events created during the outage are ever
delivered, and the real time from payment to reconciliation against the 15-minute window.

## Attention outcomes

Stop the drill, take a snapshot and record it when you see any of these. Do not replay, re-run or work around them;
they are M-08 inputs.

| Signal | Meaning |
| --- | --- |
| `receipts <id> payment_changed` or `receipt_changed`; snapshot `work_state=quarantined` | Provider object or retained evidence disagrees with the frozen order. No payment is verified. |
| `retry_exhausted` | Eight attempts failed; the receipt is quarantined. Check the validator's `--probe` and Stripe status first. |
| Webhook delivery `400 STRIPE_WEBHOOK_INVALID` | Wrong signing secret, host clock more than five minutes off, or both the Dashboard endpoint and `stripe listen` in use. |
| Webhook delivery `409 STRIPE_EVENT_CONFLICT` | Same event ID with different content. The original is kept. |
| `payment.verified=true` with `grants.count=0` and `finalization.outcome=paid_exception` | Confirmation observed after the payment window. Correct, but record why it was late. |
| Any `totals` value grows after a resend or replay | Duplicate effect. Defect; stop immediately. |

## Evidence packet

One directory per drill under `$EVIDENCE`, each with:

- `commands.log`: every command with exit code, UTC time, SHA and operator;
- the snapshots named above (`*.json`), plus `vasey:stripe-inbox` output where used;
- the runner log excerpt for the drill window;
- Dashboard screenshots of PaymentIntents, events and deliveries (test mode visible);
- `result.md`: order UUID, intent UUID, `cs_test_…`, `pi_…`, `evt_…`, expected versus observed per step, PASS or FAIL,
  and every deviation.

Keep the packet on the host or another private store. It contains no buyer details or secrets, but order and Stripe
test IDs are still operational data. Summarize the result in the verification record without copying the raw files.
