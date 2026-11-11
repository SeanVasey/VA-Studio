# Staging guest test purchase (Stripe test mode)

Status: **run once end to end on 2026-11-10 on staging, in Stripe test mode** (see [First real run](#first-real-run-2026-11-10)). Before that, every step was exercised only with synthetic provider fixtures (see [Evidence](#evidence)). One run is not a soak: the four failure drills below have not been run against real Stripe, and the runbook fixes and [known follow-ups](#known-follow-ups) from that run are recorded here.

This guide is for Sean and the staging operator. It covers one guest purchase on the private staging host, from the storefront to downloading the purchased files, plus four failure drills. Everything here is **test mode only**. No real card is charged, no live key is used and no production path is turned on.

## What runs where

| Step | Who or what | Proof that it happened |
| --- | --- | --- |
| Quote, pricing, order review, order | Buyer's browser | Order reference shown in the cart; `orders` row |
| Stripe Checkout (test) | Stripe's hosted page | Stripe Dashboard → Payments (test mode) |
| Event delivery | Stripe Dashboard test-mode endpoint → `POST /webhooks/stripe` | `php artisan vasey:stripe-inbox` |
| Payment verification | `payments` queue worker, or the pipeline runner | Runner log `receipts <id> awaiting_finalization` |
| Finalization (grant + pending entitlements) | Queue worker, or the runner | Runner log `finalize <order> paid` |
| Test contract PDF (`test-buyer-pdf-v2`) | `contracts` queue worker, or the runner | Runner log `contracts <grant> ready`; Studio → Test commerce → Test contract issuance |
| Fulfillment activation | The runner only | Runner log `activate <order> activated` |
| Download enable | **The operator, per order** | `vasey:control-test-delivery` output `enabled version=1` |
| Download of the exact files | Buyer's browser, same session | Browser download; server re-hashes each file before streaming |

A return to the store from Stripe proves nothing about payment. Only the signed event or an explicit reconcile, followed by Stripe API reads, records a verified payment.

## One-time setup

Do these once, before the first purchase. Steps 1–3 need Sean.

### 1. Stripe Dashboard, in test mode

1. Switch the Dashboard to **Test mode**.
   - Prerequisite: confirm **Link** is enabled for the account (Settings → Payment methods; the checkout request names `card` and `link`). If Link is disabled, creating the session, or the check that the returned session echoes exactly the requested methods, fails closed and no checkout opens. The staging sandbox account's default payment method configuration was read through the Stripe API in test mode on 2026-11-10 and showed Link available and on, card on, and bank debit off; that was a configuration read, no Link payment has been exercised.
2. Note the account ID (`acct_…`) of the account you will use. It must be the account that owns the key in step 3. Do not use a connected account.
3. Create or reveal a **standard secret key** (`sk_test_…`). Restricted keys (`rk_test_…`) are refused by the application.
4. Add a webhook endpoint (Developers → Webhooks, or Workbench → Event destinations):
   - **Events from:** your account (not connected accounts).
   - **Payload style:** snapshot (not thin events).
   - **Endpoint URL:** `https://<STAGING_HOST>/webhooks/stripe`. This route is exempt from the site's basic auth and is protected by the signature check instead.
   - **Events:** `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`. Other events are stored and marked `unsupported`, so selecting only these keeps the inbox clean.
   - **API version:** any. The application ignores the event body except for the Checkout Session ID and re-reads the session from Stripe at its own pinned version (`2026-08-26.dahlia`).
5. Copy the endpoint's **signing secret** (`whsec_…`).

Send the account ID, secret key and signing secret to the operator through the agreed secret channel, never chat, email or Git.

### 2. Seller name and assent text

Sean supplies:

- `<SELLER_LEGAL_NAME>`: the seller name printed on the order review and the test contract (up to 160 characters).
- `<ASSENT_TEXT>`: the sentence the buyer accepts before ordering (up to 4,000 characters). It must not contain a single quote (`'`), because the value sits inside single quotes in `.env`. Escape a double quote as `\"`.

Both are captured into each immutable test order. Changing them later affects new orders only.

### 3. Apply the profile on the host

1. Open `ops/staging/test-commerce/env.test-commerce.example`. Copy every key into the host's private `.env`, keeping exactly one copy of each key. Section 1 (runtime) and section 6 (the twelve capability flags, each stated `false`, and the optional unpaid-release flag, also `false` until you [switch it on](#switching-it-on-once-by-the-lead)) normally already exist in the base staging `.env` (`ops/staging/env.staging.example` states them the same way); make sure the values match and keep one copy. The validator refuses a profile that omits any of the thirteen lines, naming the missing keys, so a `.env` copied before section 6 existed needs those lines added.
2. Replace every placeholder: `<STAGING_HOST>` (host name only), `<acct_ID>` (twice: `STRIPE_ACCOUNT_ID` and inside `VASEY_TEST_PRICING_POLICY`), `<sk_test_KEY>`, `<whsec_SECRET>`, `<SELLER_LEGAL_NAME>`, `<ASSENT_TEXT>`.
3. Validate, as the application user, from the application root:

   ```sh
   php scripts/ops/validate-test-commerce-profile.php .env
   ```

   Expect 38 `PASS` lines and `RESULT: PASS`. Each `FAIL` names the responsible check (for example `commerce.pricing_zero_test_tax`). Secure and HttpOnly session cookies are required because the guest session owns order and download access. The script reads only the file you pass it: inherited application variables and foreign configuration caches cannot mask its values. It never prints a value and makes no Stripe request. This validates the file; deploy/cache/workers must actually use that same file without shell overrides.
4. Optional, read-only Stripe probe. This makes one `GET /v1/account` request with the test key and confirms it belongs to `STRIPE_ACCOUNT_ID`:

   ```sh
   php scripts/ops/validate-test-commerce-profile.php --probe --i-understand-this-calls-stripe .env
   ```

   Expect `PASS stripe.probe_account`. The probe runs only if every other check passed.
5. If the host caches configuration, rebuild the cache, then restart the workers so they read the new values:

   ```sh
   php artisan config:cache
   php artisan queue:restart
   ```

About `vasey:stripe-preflight`: run it **without** `--probe`. It checks the Stripe SDK and API version pins and needs no configuration change:

```sh
php artisan vasey:stripe-preflight
```

Its configuration rows describe the *production* checkout family (`PRODUCTION_CHECKOUT_*`), which this profile deliberately leaves unset, so those rows report `blocked` and the command exits non-zero on staging. That is expected; read the four `pin` rows only. Its `--probe` mode reads only the production checkout variables and needs `PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED=true`. Do not set production checkout variables on staging for this. Use the validator's `--probe` above instead, which exercises the test path's own gateway.

### 4. Start the pipeline runner

The runner drains five console commands in order, following each command's `NEXT_AFTER` cursor so a backlog larger than one page is fully processed:

1. `vasey:process-stripe-receipts`: retained webhook receipts (missed dispatch, due retries, expired leases).
2. `vasey:reconcile-test-payments`: sessions without a verified payment, even if no webhook arrived. Its default minimum interval is one minute (`RECONCILE_INTERVAL_SECONDS=60`), well inside the 15-minute quote window. It re-reads unpaid sessions from Stripe; keep abandoned drill orders few and do not increase the interval to the payment window.
3. `vasey:finalize-test-payments`
4. `vasey:issue-test-contracts`
5. `vasey:activate-test-fulfillment`

It never enables downloads and never re-sends Checkout Session creation. It makes only Stripe GET requests and local work.

Choose one way to run it, as the application user (the owner of `storage/app/private` and the queue workers):

With the Forge kit, use the reviewed script from `/srv/vasey-staging/current`, never the Forge mirror
or an older copied runner. Each sweep takes the kit's shared writer lock before reading its root-owned
admission gate. `ctl quiesce` closes the gate durably; future timer/cron invocations skip without touching
private state until a healthy `ctl resume` reopens it. If a sweep is already active, quiesce refuses and
leaves admission closed: wait for it to finish, inspect `ctl status`, then retry quiesce. Do not bypass the
gate or resume after a failed backup/migration. For this kit, choose the current-based oneshot service/timer
or scheduler; avoid a daemon that retains old script code across release switches.

- **systemd:** install `ops/staging/test-commerce/vasey-test-commerce-pipeline.service` and `.timer` after replacing `<APP_USER>` and `<APP_ROOT>` (for the kit, `forge` and `/srv/vasey-staging/current`; instructions inside the unit). Logs: `journalctl -u vasey-test-commerce-pipeline.service -f`. If that prints "No journal files were found", see [Reading the journal on the Forge host](#reading-the-journal-on-the-forge-host).
- **Forge scheduler:** add a job that runs every minute as the site user:

  ```sh
  PHP_BIN=/usr/bin/php8.4 bash /srv/vasey-staging/current/scripts/ops/run-test-commerce-pipeline.sh >> /srv/vasey-staging/current/storage/logs/test-commerce-pipeline.log 2>&1
  ```

- **Daemon for other installations:** `bash scripts/ops/run-test-commerce-pipeline.sh --loop` runs 60 sweeps a minute apart, then exits. The Forge kit uses the current-based choices above.

A non-blocking lock prevents overlapping sweeps. The log shows only opaque IDs and bounded outcomes, for example:

```text
2026-10-12T14:03:01Z test-commerce-pipeline finalize 6d0c…-… paid
2026-10-12T14:03:02Z test-commerce-pipeline contracts 3fda…-… ready
2026-10-12T14:03:03Z test-commerce-pipeline activate 6d0c…-… activated
```

Each cursor stage saves progress when it reaches the page bound, so unresolved early rows cannot starve later work. After reaching the end it clears that cursor and revisits earlier unresolved rows on the next sweep. A command failure resets its saved cursor; retained domain effects remain idempotent.

Cursor and cadence writes are atomic. A failed state write or removal fails the sweep with a bounded diagnostic; it never reports saved progress when persistence failed.

The queue workers (Lane A: `payments`, `contracts`, `default`, `media`) give faster results, but the runner alone completes the chain within about two minutes of the event.

### 5. Make each offer purchasable: link its rights scope

Order preparation reserves every selected offer revision against a rights scope (`ReserveQuoteInventory`). After publishing a track and its offers, use the reviewed operator command from a trusted interactive console as the application user. An unlinked revision still fails "Prepare order" with `INVENTORY_SCOPE_UNAVAILABLE`.

List the current published revisions that need linking, then register the actual rights identity and link each relevant revision. Replace the placeholders with the verified catalog staff account's numeric ID, the chosen stable scope key, the **revision** ID from `list`, and non-secret references to the retained private evidence:

```sh
php artisan vasey:rights-scope list
php artisan vasey:rights-scope register --actor-id=STAFF_ID --scope=SCOPE_KEY --reference=EVIDENCE_REFERENCE --attest
php artisan vasey:rights-scope link --actor-id=STAFF_ID --scope=SCOPE_KEY --revision=REVISION_ID --reference=LINK_REFERENCE --attest
php artisan vasey:rights-scope list
```

- Staging requires staff multi-factor authentication, and a console has no code challenge, so each write needs `--attest`. Without it the command refuses the write and records nothing. With it, after the password check, the command writes an append-only `staff.console_attestation` audit event (operator, time, command, action, scope key, offer revision, a keyed hash of the reference, environment, and whether the operator is enrolled in the panel factor), prints `attestation=recorded audit_event=N`, and the write's own audit event links to it through `attestation_audit_id`. Each attestation admits that one write: the admitted write also appends a `staff.console_attestation_consumed` audit event, so every run records a fresh attestation. The attestation stays on record even when the write is then refused, and the refusal then reads "No rights-scope write occurred; the attestation audit record (audit_event=N) remains." It records who made the write; it is not a second factor, so shell access to the host remains the controlling credential. `--attest` is invalid with `list`.

- Each write confirms the staff password at a hidden prompt. Writes refuse `--no-interaction` and terminals that cannot hide input. Never pass passwords or evidence contents as arguments.
- Related variants of the same actual right share a scope only after the operator verifies that relationship. Unrelated rights need distinct scopes. A quote cannot select two variants of one scope, and a reservation blocks every linked variant of that scope.
- An exact repeated request is harmless; changing its immutable reference conflicts. A successor commercial revision needs a fresh explicit link to its actual rights identity.
- The service rechecks current staff authority and track/offer/license/rights/media readiness. A link is an audited operator assertion, not proof of ownership. See [shared-rights command and uncertainty recovery](../shared-rights-inventory.md#operator-command-for-test-purchases).
- If a write reports an unconfirmed result, inspect and retry the **exact original request**, preserving scope, revision and reference. A nonzero exit does not prove rollback.

**An unpaid order keeps its offer reserved.** Preparing an order moves the offer revision's reservation to `pending`, and it stays `pending` until the order is finalized or a staff member releases it. A paid order releases it. An abandoned, declined-then-abandoned or expired order does not release it by itself: staff release it with the optional unpaid-release capability once Stripe shows its checkout session expired unpaid (see [Releasing an abandoned test order](#releasing-an-abandoned-test-order)). A late-paid order (a paid exception) and an order whose Stripe checkout was never opened cannot be released this way. While a reservation is held or pending, nobody else can prepare an order for that offer revision: "Prepare order" fails with `INVENTORY_UNAVAILABLE`. So:

- Two buyers cannot prepare orders against the same rights scope at the same time, including other linked variants.
- Run the failure drills on a dedicated drill offer, not on an offer you want to keep buying.
- An abandoned reservation is resolved only through [Releasing an abandoned test order](#releasing-an-abandoned-test-order), which keeps the original order and its evidence. Publishing a successor or inventing a replacement scope does not establish that the original reservation is resolved. Never change a rights identity to bypass occupancy.

## The purchase

Use one browser, signed in to nothing. The guest's order ownership lives in that browser's session (idle timeout `SESSION_LIFETIME`, 120 minutes by default). Downloads work only in the same session, unless the order is claimed into a test account (see [Keeping access](#keeping-access-after-the-session)).

**Timing.** A quote lives 15 minutes from the moment the cart review creates it, and the order's payment window ends with it. Complete payment within about 10 minutes of opening the review. Eligibility uses the application's verified observation time, so payment at Stripe before expiry can still become a *paid exception* if a missed webhook is not reconciled until after expiry. The one-minute default reduces that risk; long sweeps, backlogs and payment near the deadline can still exceed it. A paid exception retains evidence and issues no license/download (drill 5).

| # | Do | Expect | Verify on the host |
| --- | --- | --- | --- |
| 1 | Open `https://<STAGING_HOST>`, pass basic auth, open a track, choose a license, press **Add license**. | The cart shows the license and price. | — |
| 2 | Open the cart and press **Review selection**. The cart then offers **Review test order**; press it. | The price with zero test tax, the full license terms, the seller name and the assent text. | — |
| 3 | Enter a name and a test email you control, tick the assent, press **Prepare test order**. | An order reference appears, with an **Open Stripe test checkout** button. | If it fails with `INVENTORY_SCOPE_UNAVAILABLE`, do setup step 5. |
| 4 | Press **Open Stripe test checkout**, then **Continue to Stripe test checkout**. | Stripe's hosted page shows "VASEY.AUDIO test license 1", the same amount and card payment (Link, and Apple Pay or Google Pay where the browser offers them, may also appear; bank debit, Klarna and similar are not requested. See "Payment methods seen on 2026-11-11" below). | The Stripe page address contains the `cs_test_…` session ID; Dashboard → Developers → Logs shows the `POST /v1/checkout/sessions` request. |
| 5 | Pay with card `4242 4242 4242 4242`, any future expiry, any CVC, any postcode. | Stripe redirects back to the store's return page. It says the result is not verified yet. | `php artisan vasey:stripe-inbox --limit=5` lists a `checkout.session.completed` receipt. Dashboard → Webhooks shows a `200` delivery. |
| 6 | Wait up to two minutes, then press **Check Stripe test checkout status** or reload. | Payment `verified`; contract and fulfillment progress. | Runner log, only for the work the runner itself does: `receipts <id> awaiting_finalization`, `finalize <order> paid`, `contracts <grant> ready`. The queue workers usually get there first, as on the 2026-11-10 run, and then the runner log shows only `activate <order> activated`. In that case the evidence is `php artisan vasey:stripe-inbox`, Studio → Test commerce → Test contract issuance ("Original recorded") and the order status on the return page. |
| 7 | Operator enables delivery for this order (below). | — | Output `<control id> enabled version=1`. |
| 8 | On the return page (or the cart's order status), press **Check Stripe test checkout status**. The downloads section appears once payment is verified, the order is paid and the contract is issued. Download the contract and each file. | `<grant>-contract.pdf` and the purchased files (`master_wav`, `download_mp3`, `stems_zip` as the license requires). Each click gives one 60-second, single-use authorization; at most three per minute per order. | The server re-hashes every file before streaming. Optional check below. |

**The return page refreshes itself.** After payment the order can stay at "finalization pending" for up to about a minute while the server finishes, because the page catches up only once activation has run. While the order is waiting on the server, the page re-reads its status about every 5 seconds for up to 3 minutes and announces the change; it stops when the order settles, when a read fails or after 3 minutes. The **Refresh test order status** and **Check Stripe test checkout status** buttons still work at any time, and after an automatic stop you press one of them to check again.

**Stripe's "Save my information with Link" box.** Stripe's hosted page may show it pre-ticked and then ask for a phone number. Untick it for a test purchase; nothing in this guide needs a Link account.

**Payment methods seen on 2026-11-11.** The run on that date showed Bank and Klarna as well as card on Stripe's page. The store's checkout request names only `card` and `link` (`CheckoutPaymentMethods::OFFERED`; unit and wire-level tests assert it), and a returned session that does not echo exactly that set is refused, so those two extra options are not explained by this repository. Before the next run, compare the `POST /v1/checkout/sessions` request body in Dashboard → Developers → Logs (it must contain `payment_method_types[0]=card` and `[1]=link`) and note which build was deployed. If the body is right, the extra options come from Stripe's side (for example Link's own funding choices) and are not a store defect.

### Enable delivery for one order (operator)

Enabling downloads is a deliberate per-order decision and is never automatic. The order must already be activated (runner log `activate <order> activated`). Provision the control blocked, then enable it:

```sh
php artisan vasey:control-test-delivery <ORDER_UUID> block  --expected-version=0 --reference=staging-YYYYMMDD-01
php artisan vasey:control-test-delivery <ORDER_UUID> enable --expected-version=0 --reference=staging-YYYYMMDD-01
```

Expected output: `<control id> blocked version=0`, then `<control id> enabled version=1`. A direct `enable` without the first `block` fails, by design. To withdraw access later: `block --expected-version=1`. The reference is free text (letters, digits, `._:/-`); only its hash is audited.

The order UUID is the reference shown to the buyer, and appears in the runner log.

### Optional: compare a download with the stored file

On the host, print the stored hash of the purchased master and compare it with the downloaded file (`shasum -a 256 <file>` on a Mac):

```sh
php artisan tinker --execute='echo App\Domain\Media\Models\MediaAsset::where("track_id", TRACK_ID)->where("role", "master_wav")->where("status", "ready")->value("sha256"), PHP_EOL;'
```

### Keeping access after the session

The profile enables test customer accounts. The buyer can create a test account and claim the guest order into it, so downloads survive a new browser session. No email is sent: the one-time link is written to `storage/app/private/customer-identity-capture/<uuid>.json` on the host. The buyer signs up with a reserved test address such as `name@example.test`, and the operator relays the link with `vasey:test-identity-links` ([runbook section 6a](staging-runbook.md#6a-test-customer-sign-in-links)). See [Test customer enrollment and recovery](../customer-test-self-service.md).

### Reading the journal on the Forge host

On the Forge staging host `journalctl -u vasey-test-commerce-pipeline.service` printed "No journal files were found", because the host's `/etc/machine-id` differs from the directory name under `/var/log/journal/`, and journalctl looks for a directory named after the machine id. Point it at the directory that exists (there is one):

```sh
ls /var/log/journal/
journalctl -D /var/log/journal/<dir> -u vasey-test-commerce-pipeline.service
```

Or read `/var/log/syslog`. Do not change `/etc/machine-id` to match.

## Test cards

All from Stripe's documented test set; use any future expiry, any CVC and any postcode.

| Card | Result | What the store shows |
| --- | --- | --- |
| `4242 4242 4242 4242` | Succeeds | Completes the full chain. |
| `4000 0000 0000 0002` | Declined (generic) | Stripe shows the decline on its page. The session stays open; nothing is verified. |
| `4000 0000 0000 9995` | Declined (insufficient funds) | As above. |
| `4000 0025 0000 3155` | 3D Secure required | Stripe opens an authentication window. **Complete** → succeeds. **Fail** → declined, session stays open. |
| `4000 0000 0000 3220` | 3D Secure 2 required | As above. |

Checkout offers card and Link. Confirmation is not assumed synchronous (Link can be bank-funded and land at `processing`), so `async_payment_succeeded` and `async_payment_failed` can occur. They are locators only: the event triggers a retrieval, a `processing` PaymentIntent is retried and never granted, and a grant happens only after a paid session and a `succeeded` PaymentIntent with the exact amount are verified from Stripe.

## Drills

Run each drill on a fresh order, using a dedicated drill offer: drills 1 (if abandoned), 2 and 5 leave that offer revision reserved. "Sweep" means waiting for the runner or running `bash scripts/ops/run-test-commerce-pipeline.sh` once by hand as the application user (`RECONCILE_INTERVAL_SECONDS=0` forces the reconcile stage).

### 1. Declined card

1. Prepare an order and open Stripe Checkout. Pay with `4000 0000 0000 0002`.
2. Expect: Stripe shows "Your card was declined" and stays on its page. No `checkout.session.completed` event is sent.
3. Run a sweep with `RECONCILE_INTERVAL_SECONDS=0`. Expect `reconcile <intent> pending`, and no `finalize` line for the order.
4. On the same Stripe page, pay with `4242 4242 4242 4242` within the payment window. Expect the normal chain to complete.

### 2. Expired session

1. Prepare an order, open Stripe Checkout and do not pay. Copy the `cs_test_…` ID from the Stripe page's address bar.
2. Expire it from a machine with the Stripe CLI logged in to the test account (this is a test-mode provider write):

   ```sh
   stripe post /v1/checkout/sessions/cs_test_…/expire
   ```

   Or wait for the 60-minute session expiry.
3. Expect a `checkout.session.expired` delivery, then `receipts <id> expired` in the runner log. The store's checkout status becomes `expired`. No payment, grant, contract or download exists.
4. The offer revision now stays reserved: a new order for it fails with `INVENTORY_UNAVAILABLE` (setup step 5) until staff release it ([Releasing an abandoned test order](#releasing-an-abandoned-test-order)). With unpaid release on, release it and confirm that a new order for the offer can be prepared.
5. Note: the expired intent stays in the reconcile selection. Every eligible reconcile sweep (one-minute minimum by default) reads it from Stripe again and stores one more observation row. This is expected for now (see the findings in the handoff); keep drill orders few.

### 3. Duplicate webhook

1. After a successful purchase, open the delivery in Dashboard → Webhooks → your endpoint, and choose **Resend**. Or: `stripe events resend evt_…`.
2. Expect HTTP `200` again. `php artisan vasey:stripe-inbox --limit=5` still shows **one** receipt for that event ID; the second delivery is matched to the first by event ID and fingerprint.
3. Expect no second payment, grant or contract. Check:

   ```sh
   php artisan tinker --execute='echo DB::table("verified_payments")->count(), " ", DB::table("license_grants")->count(), PHP_EOL;'
   ```

   The counts do not change after the resend.

### 4. Reconcile without a webhook

1. In the Dashboard, **disable** the webhook endpoint.
2. Prepare an order and pay with `4242 4242 4242 4242`. Expect the return page to stay "not verified".
3. Within the payment window, reconcile now instead of waiting for the next scheduled sweep:

   ```sh
   php artisan vasey:reconcile-test-payments --limit=25
   ```

   Expect `<intent> awaiting_finalization`. The next sweep prints `finalize <order> paid`, then the contract and activation lines.
4. Re-enable the endpoint. Do not count on Stripe delivering events created while it was disabled. Optionally resend that event from the Dashboard: it is processed as a duplicate (`awaiting_finalization`) and changes nothing.

### 5. Late payment (optional)

1. Prepare an order, open Stripe Checkout, wait 16 minutes, then pay with `4242 4242 4242 4242`.
2. Expect `finalize <order> paid_exception`. No grant, contract or download. Studio → Test commerce → Test payment exceptions lists it.
3. The test payment stays captured in Stripe. The application has no refund workflow yet; refunding in the Stripe Dashboard changes nothing in the store.

## Releasing an abandoned test order

Optional, off by default (Sean authorized it for the staging alpha on 2026-11-11). It releases the `pending` reservation (and a pending promotion use) of one test order whose Stripe test checkout ended without payment, so the offer can be bought again. The order, its attempt, its checkout evidence and the release itself are kept; nothing is deleted. It never writes to Stripe: it does not expire a session, cancel a PaymentIntent, capture or refund. Full contract: [test unpaid release](../test-unpaid-release.md).

### When a release is possible

All of these must hold, and the application checks each one itself; staff cannot override any of them:

- The order belongs to the configured Stripe test account and has a recorded Stripe Checkout Session, that is, the buyer pressed **Open Stripe test checkout**. An order that was prepared but never sent to Stripe has no session and cannot be released (result `unavailable`).
- No verified payment and no finalization is recorded for the order (otherwise `payment_recorded`). A late-paid order (paid exception) keeps its reservation; this path refuses it.
- Two reads of the session from Stripe both show `status: expired` and `payment_status: unpaid`, with `after_expiration` and `recovered_from` both null, and the same order, attempt, amount, currency, payment methods and line items as the retained request. Its PaymentIntent is absent, or `canceled` with nothing received or capturable.

A session expires 60 minutes after checkout was opened. Until then the result is `not_unpaid`. To release sooner, expire the session first, from a machine with the Stripe CLI logged in to the test account (a test-mode provider write, as in [drill 2](#2-expired-session)): `stripe post /v1/checkout/sessions/cs_test_…/expire`. Expiry is final at Stripe: an expired session cannot be paid, so nothing can be charged for that order afterwards.

### Switching it on (once, by the lead)

In Forge → Environment, keep `STRIPE_MODE=test`, `STRIPE_ACCOUNT_ID` and `STRIPE_TEST_SECRET_KEY` as the profile sets them, replace the `VASEY_TEST_UNPAID_RELEASE_ENABLED=false` line and add the policy line exactly as shown in `ops/staging/test-commerce/env.test-commerce.example` section 6 (single quotes around the JSON, one line):

```dotenv
VASEY_TEST_UNPAID_RELEASE_ENABLED=true
VASEY_TEST_UNPAID_RELEASE_POLICY='{"schema_version":1,"purpose":"test_unpaid_resource_release","version":"test-unpaid-release-v1","provider":"stripe","mode":"test","proof":"bound_expired_unpaid_session_and_absent_or_canceled_zero_payment","recovery":"disabled","resources":"pending_to_released","late_confirmation":"paid_exception_preserve_released","provider_writes":"forbidden"}'
```

**The release that knows this rule must already be live.** The first gate of a deploy runs before the candidate is built, so it uses the validator of the release being served. If the served release predates this capability (before 2026-11-11, PR #333), a deploy with the flag `true` is refused with `FAIL runtime.flag_off_vasey_test_unpaid_release_enabled` before the quiesce, and nothing changes on the host (seen on staging, 2026-11-11). In that case deploy once with the two lines absent, then add them and deploy again.

Then **Deploy Now**. The deploy gate (`ops/staging/validate-runtime.php`) accepts the flag only as exactly `false`, or as exactly `true` with Stripe test mode, an `acct_` account and this exact policy; anything else fails the deploy with `FAIL runtime.optional_vasey_test_unpaid_release_enabled` and nothing changes on the host. As the application user, `php scripts/ops/validate-test-commerce-profile.php .env` then reports `PASS boundary.optional_capabilities_admitted`. The production validator refuses the flag in any value but `false` (`holds.vasey_test_unpaid_release_enabled`), and the policy class refuses production in code as well. To switch it off, set the flag back to `false` and deploy; releases already recorded stay recorded.

### Release one order (staff, signed in with MFA)

1. Note the order reference: the buyer's order page, the cart's order status, or step 2.
2. Optional, to see every order that still holds a reservation, as the application user (read only):

   ```sh
   php artisan tinker --execute='foreach (DB::table("inventory_reservations as r")->join("order_attempts as a", "a.inventory_reservation_id", "=", "r.id")->join("orders as o", "o.id", "=", "a.order_id")->leftJoin("checkout_intents as i", "i.order_id", "=", "o.id")->leftJoin("checkout_sessions as s", "s.checkout_intent_id", "=", "i.id")->where("r.state", "pending")->orderBy("r.id")->get(["o.public_id", "r.pending_at", "s.id as session"]) as $x) echo $x->public_id, " pending_since=", $x->pending_at, " stripe_session=", $x->session ? "yes" : "none", PHP_EOL;'
   ```

   `stripe_session=none` means the order cannot be released with this path. A paid order is never `pending` here, but a paid exception is: the release reports `payment_recorded` for it.
3. Studio → **Test commerce** → **Test unpaid release**. Search for the order reference. Listing an order does not mean it is unpaid.
4. **Release history** shows the current state: "Eligible unpaid status has not been established" (no release yet), "A release is recorded" (done) or "Payment is recorded".
5. **Verify unpaid status and release**, then confirm. The dialog makes the Stripe reads (GET only) and, only if every condition above holds, records the release and frees the reservation in one database transaction.
6. Read the notification:

   | Result | Meaning | Next |
   | --- | --- | --- |
   | Test resources released | Released now, or a release was already recorded for this order | Prepare a new order for the offer to confirm |
   | Unpaid status was not established (`not_unpaid`) | Stripe shows the session still open, complete, or with a payment in progress | Wait for the 60-minute expiry or expire the session, then close the dialog and start a new check |
   | Release evidence needs attention (`attention`) | Stripe's answer is incomplete or contradicts the retained order | Do not retry blindly; keep the order as evidence and report it |
   | Unpaid verification is unavailable (`unavailable`) | No Stripe session for the order, Stripe could not be read (key, network, account), or the reads did not match each other or the order | Check `php scripts/ops/validate-test-commerce-profile.php --probe --i-understand-this-calls-stripe .env`; an order without a session cannot be released |
   | Payment is already recorded (`payment_recorded`) | A verified payment or finalization exists | Not an abandoned order; nothing to release |
   | Release check is in progress (`busy`) | Another check of this order holds its 2-minute lease | Wait, then retry the same dialog |
   | Release review is out of date (`stale`) | The history moved since the dialog opened | Close the dialog and start again |

7. **"Release was not confirmed. The outcome is uncertain."** means the request failed part-way (a lost connection, a timeout, a changed record) and the application cannot say whether the release committed. Nothing is assumed either way. Press confirm again in the **same** dialog: it carries the same request identifier, so a release that already committed is reported as released and is never applied twice. If the dialog was closed, open **Release history**: "A release is recorded" means it committed; otherwise start a new check.

Every review and check writes an audit event (`commerce.unpaid_release.reviewed`, `.requested`, `.observed`) naming the staff member. A released order cannot be checked out again (its status reads `expired`). If a payment for it were ever verified later, it would be kept as money and finalized as a paid exception with reason `released_attempt`, with no license, contract or download, and the reservation stays released.

## Troubleshooting

| Symptom | Likely cause | Check |
| --- | --- | --- |
| Cart says checkout is not available | A profile flag or policy is off or invalid | `php scripts/ops/validate-test-commerce-profile.php .env` |
| `INVENTORY_SCOPE_UNAVAILABLE` on Prepare order | Offer revision not linked | Setup step 5 |
| `INVENTORY_UNAVAILABLE` on Prepare order | A held or pending reservation occupies the actual rights scope, including linked variants | List the pending orders ([Releasing an abandoned test order](#releasing-an-abandoned-test-order), step 2). An unused hold expires by itself; a pending abandoned order is released by staff once its Stripe session has expired unpaid. A paid exception or an order without a Stripe session cannot be released: use dedicated drill content and preserve the original evidence (setup step 5). |
| `PRICING_UNAVAILABLE` | Pricing policy invalid or outside its window | `commerce.pricing` check |
| `PRICING_CHANGED` on an open cart | The pricing policy changed after the cart was priced | Start a new cart |
| Webhook delivery `400 STRIPE_WEBHOOK_INVALID` | Wrong `STRIPE_WEBHOOK_SECRET`, host clock more than 5 minutes off, or both the Dashboard endpoint and `stripe listen` in use | Re-copy the secret; check `timedatectl` |
| Webhook delivery `503 STRIPE_WEBHOOK_UNAVAILABLE` | Webhook disabled, not `local`, not test mode, or malformed secret | Validator `stripe.*` checks |
| Webhook delivery `401` | Basic auth also covers `/webhooks/stripe` | Exempt that one route in nginx |
| Runner `<stage> FAILED` every minute | The stage's policy is unavailable | Validator; then `php artisan <command> --limit=1` by hand |
| `receipts <id> retry` repeatedly | Stripe API not reachable, or key/account mismatch | Validator `--probe` |
| `contracts <grant> retry` or `quarantined` | Renderer runtime or private storage problem | Validator `contracts.renderer_runtime`; ownership and mode `0700` of `storage/app/private` |
| `activate <order> pending_contracts` | Contracts not issued yet | Wait for the next sweep |
| `activate <order> asset_unavailable` | A purchased file is missing or changed on disk | Restore the exact file; never re-upload over it |
| Download shows unavailable | Delivery not enabled, or a different browser session | "Enable delivery for one order"; use the same browser |

## Stopping test commerce

Set `STRIPE_TEST_CHECKOUT_ENABLED=false` to stop new checkouts. Leave processing, finalization, contract, activation and delivery enabled until open orders finish, then set those to `false` too, run `php artisan config:cache` if used, and `systemctl disable --now vasey-test-commerce-pipeline.timer`. Disable the Dashboard endpoint. Never delete receipts, orders, grants, contracts or delivery records: they are retained evidence.

## Fallback: Stripe CLI forwarding

Use only if the Dashboard endpoint cannot reach the host. `ops/staging/test-commerce/stripe-listen.service` runs `stripe listen --forward-to https://<STAGING_HOST>/webhooks/stripe` for the four events, using the test key from a root-only environment file. The CLI signs forwarded events with its own `whsec_…` (shown by `stripe listen --print-secret`), which must replace `STRIPE_WEBHOOK_SECRET`. Disable the Dashboard endpoint while the fallback runs, because the application accepts one signing secret. Instructions are inside the unit file.

## First real run (2026-11-10)

One guest purchase was run end to end on the staging host in Stripe test mode with card `4242 4242 4242 4242`. Outcomes, without order, session or customer identifiers:

- Payment was verified about 3 seconds after the webhook arrived.
- The test contract was issued.
- Fulfillment activation followed after about 40 seconds.
- Delivery was enabled by an operator for that one order.
- The downloaded contract and MP3 matched the stored hashes.

Only a card payment was exercised: no Link payment, no declined-card, expired-session, duplicate-webhook or late-payment drill has run against real Stripe. The queue workers finalized and issued the contract before the runner did, so the runner log showed only the activation line (see step 6).

## Known follow-ups

Found on that run and not yet fixed. Docs only for now; neither changes payment evidence.

- The buyer's email is not passed to Stripe Checkout (`customer_email`), so the buyer types it again on the Stripe page.
- Each **Check Stripe test checkout status** click on an already-verified order makes another reconcile read of Stripe.

## Evidence

Recorded 2026-10-08–09 on PHP 8.4.26 with synthetic fixtures only. Current and historical selections, including SQLite and native MySQL 8.4.11 journeys, are distinguished in the [verification record](../verification/staging-test-commerce-20261009/README.md):

- `tests/Unit/TestCommerceProfileValidatorTest.php`: the committed template passes all 39 checks (38 before the FP-077 `delivery.v2_session_database` check) in template mode; a filled synthetic profile passes against the real policy classes; broken variants each fail on the responsible check, including script-readable cookies; safe exports cannot mask unsafe files or permit the optional account probe; no value is printed.
- `tests/Unit/TestCommercePipelineRunnerTest.php`: cursor following, full-page repetition, page bounds with a saved reconcile cursor, reconcile cadence, failure handling, lock, bounded loop and log redaction, against a scripted stand-in for artisan.
- `tests/Feature/StagingTestCommerceProfileJourneyTest.php`: configured only from the profile, a guest purchase runs quote → order → checkout → signed webhook (sent twice) → the runner's five commands → operator enable → download of the contract and master WAV with matching SHA-256. Also: reconcile without webhook, declined card, expired session, late payment, the missing scope link, and an abandoned order blocking its offer revision while a paid order releases it.
- A real-process run of `run-test-commerce-pipeline.sh` with real artisan on a disposable SQLite database finalized, rendered a real `test-buyer-pdf-v2` contract and activated a seeded synthetic order.

The synthetic fixtures above do not establish real Stripe interoperability; the one real staging run is recorded under [First real run](#first-real-run-2026-11-10). Record further runs, their runner logs and any defects.
