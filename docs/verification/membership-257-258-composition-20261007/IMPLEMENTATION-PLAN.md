# Membership operative implementation plan: 257 writer, Billing259, 258 activation

Status: plan only. Written by the independent reviewer of composed commit
`695d041f` on `harness/membership-257-258` (base `d20d4394`). Nothing here is
implemented, registered or approved. Every price, currency, allowance, credit
cost, rollover, cancellation, dunning, refund and retention value is a fact
Sean must supply (section 0.3); no step may invent a default for one.

Read together with `DECISION.md` (same directory), the 257/258 frozen
verification documents, and the Billing259 direction on
`origin/codex/membership-billing-operations-handoff-20261007`
(`docs/handoff/production-membership-billing-259-20261007.md`).

## 0. Dependency order

| Step | Work | Blocks | Owner |
| --- | --- | --- | --- |
| 0.1 | Close the Rows SQLite application-function finding (DECISION F1) | every operative step | membership author, then independent review |
| 0.2 | Couple 258 activation to an actual 257 consume event (DECISION F2) | step 5 | membership author, then independent review |
| 0.3 | Sean supplies the policy facts (list below) | steps 1.4, 2.1 onward | Sean |
| 0.4 | Root confirms the reviewed T23 committed APIs on the integrated base (`IdentityCommittedFrame`, `ProductionCustomerAccess::proveCommitted`) are the ones to consume | steps 1–5 | root |
| 1 | Billing259 schema, gateway, settlement evaluator, paid-invoice authority (default-off) | step 2 | new owner |
| 2 | Policy facts producer and the 257 award writer (one paid period per source invoice) | step 3 | new owner |
| 3 | 257 reservation authority (`reserve`/`lock`/`proveCurrent`), release, expire, native races | step 5 | new owner |
| 4 | Current T25 eligible-license adapter | step 5 | new owner, coordinate with catalog/rights owners |
| 5 | 258 member-original renderer, private storage, readiness, activation + consume in one owned transaction | step 6 | new owner |
| 6 | Root registration, private session-bound HTTP/UI, config, final integrated review | release | root |

Steps 1 and 2.2 to 3 can be developed in parallel against the test-only
rehearsal invoice authority (1.9), but step 2 must not merge as operative before
step 1 is reviewed.

### 0.1 Rows successor (finding F1)

`app/Domain/Memberships/Production/MembershipRows.php` (successor of 3cd):

- Before any SQL in the constructor and at the start of `context()`, on SQLite
  also refuse when an application function or collation is registered on the
  captured handle. The PDO API cannot enumerate them, so use SQLite's own
  catalog: `SELECT name FROM pragma_function_list WHERE builtin = 0` and
  `PRAGMA collation_list` against the expected built-in set. Those statements
  call no function (no `lower()`, no `LIKE`). Check `pragma_function_list`
  availability on the bundled SQLite (`SELECT sqlite_version()`); if it is
  compiled out, refuse SQLite outright.
- Independently, refuse `provenance = verified_production` unless the driver is
  `mysql` (check in `MembershipPolicy::current()` and `MembershipRows`), so the
  SQLite path can only serve local/testing rehearsal.
- Regression: move the review probe
  `adversarial/MembershipRowsSqliteFunctionCallbackReviewTest.php` into
  `tests/Feature/ProductionMembership/MembershipRowsFunctionClosureTest.php`
  unchanged in substance (zero callbacks, reader refuses, policy still enabled).
  Add `createCollation('NOCASE'|'BINARY')` and `createAggregate` variants.
- The same primitive probably affects other captured readers that run `lower()`
  on SQLite (`MemberGrantSchema`, identity, checkout readers). File it with root
  as a cross-cutting follow-up; do not edit identity/checkout in this lane.

### 0.2 Activation/consume coupling (finding F2)

The 258 activation guard checks only `length(reservation_event_hash) = 64`, and
the 257 consume guard checks only the shape of `grant_origin_id` and
`grant_receipt_hash`. Neither table requires the other. Both migrations are
unregistered and have never run outside test schemas, so amend 258 in place
(re-review required) rather than adding a successor migration:

- `MemberGrantSchema::guards()` activation insert condition adds:
  `EXISTS (SELECT 1 FROM production_membership_credit_events c JOIN production_membership_credit_events r ON r.id = c.reservation_event_id WHERE c.kind = 'consume' AND c.redemption_id = NEW.redemption_id AND c.grant_origin_id = NEW.origin_id AND c.grant_receipt_hash = NEW.readiness_receipt_hash AND c.grant_purpose = NEW.purpose AND r.kind = 'reserve' AND r.seal = NEW.reservation_event_hash)`.
- Write order in the owned transaction: consume event, then activation. Both
  commit or neither does. MySQL triggers cannot defer, so the order is fixed.
- Invert the review characterization into
  `tests/Feature/ProductionMemberOriginals/MemberActivationCouplingSchemaTest.php`:
  activation without consume is refused; consume for another origin, another
  redemption, a released reservation or a wrong purpose is refused.

### 0.3 Facts that must come from Sean

None of these may be guessed, defaulted, copied from BeatStars marketing copy or
taken from fixture values. Each lands as an MFA-authored, versioned,
hash-sealed policy version (step 2.1), never as config.

1. Plan catalogue: plan names, plan versions, which plans exist at launch.
2. Price per billing period for each plan, in integer minor units, and the
   currency or currencies. Whether amounts are tax-inclusive, and whether Stripe
   Tax is used (this decides which invoice amount the settlement check compares).
3. Billing interval(s) and anchor rules; trials (allowed or not, length).
4. Credit allowance per paid period for each plan.
5. Credit cost per eligible license tier, and which licenses, tracks and asset
   sets are eligible for member redemption.
6. Rollover: none, capped (cap value) or unlimited; credit expiry rule.
7. Late invoice: whether an invoice paid after its service period ends still
   awards credits, and any grace window.
8. Cancellation: immediate or end of period; what happens to unused credits and
   open reservations.
9. Refunds and disputes: effect on awarded, reserved and consumed credits and
   on licenses already granted. (The plan never auto-revokes a grant; Sean
   decides whether a manual review queue is wanted.)
10. Dunning: retry schedule, grace period, member status while past due, and
    when access stops.
11. Upgrades, downgrades and proration.
12. Coupons, discounts, zero-amount and partially paid invoices: allowed or
    refused.
13. Grandfathering and migration of existing BeatStars members and their
    obligations.
14. Reservation honor window (how long a reserved credit is held for a pending
    original).
15. Member license terms and profile (legal text, member-only purpose) and the
    retention period for member originals.
16. The Stripe account and mode to bind (test mode until live is separately
    authorized), and who may author policy versions (staff role + MFA).

## 1. Billing259: provider evidence adapter (default-off)

Namespace `App\Domain\Memberships\Billing`. Migration
`database/migrations/2026_10_07_259000_production_membership_billing.php`
(reserved by root). Config `config/production-membership-billing.php`:
`enabled => false`, `provider_io_enabled => false`, `account_ref => null`,
`mode => null`, `approved_subscription_policy_hash => null`. The API version and
SDK version are class constants, not config.

### 1.1 Pins

- `BillingProviderPin` constants: `SDK_PACKAGE = 'stripe/stripe-php'`,
  `SDK_VERSION = 'v21.3.2'`, `SDK_REFERENCE = '0d8b075e1a97d15c5324353a5277d0ea686ea525'`,
  `API_VERSION = '2026-08-26.dahlia'`.
- `assertInstalled()`: `Composer\InstalledVersions::getPrettyVersion` and
  `getReference` equal the pins, and `Stripe\Util\ApiVersion::CURRENT === API_VERSION`.
  Every client is built with `['stripe_version' => API_VERSION]`.
- The Checkout gateway's pins (`StripeSdkCheckoutGateway::API_VERSION`) give no
  Billing semantics. Billing pins its own provenance: record the official
  Invoice, InvoiceLineItem, InvoicePayment, Subscription, PaymentIntent, Charge
  and BalanceTransaction schema files of this API version under
  `docs/verification/membership-billing-259/provider-schema/` with SHA256s.
- Use the locked SDK's models as they are: `Invoice::$parent->subscription_details->subscription`
  (not a flat `invoice.subscription`), `Invoice::$payments` / `InvoicePayment`
  (not a flat `invoice.payment_intent` or `charge`) and `InvoiceLineItem::$period`
  as the service period (not `invoice.period_start/end`, which the SDK
  documents as the item-association window).

### 1.2 Tables (`BillingSchema`, same owned-prefix/global-namespace algorithm as `MembershipSchema`)

| Table | Purpose | Key constraints |
| --- | --- | --- |
| `production_membership_billing_subscriptions` | Immutable server-approved binding: account_id, user_id, identity_origin_id, provider_account_hash, mode, customer_ref_hash, subscription_ref_hash, price_ref_hash, plan_version_id → `production_membership_plan_versions`, approval_binding_hash, provenance, payload_ciphertext, seal | unique(subscription_ref_hash); immutable guards |
| `production_membership_billing_invoices` | One row per provider invoice identity: subscription_binding_id, invoice_ref_hash, source_invoice_hash (the value 257 stores) | unique(invoice_ref_hash), unique(source_invoice_hash) |
| `production_membership_billing_observations` | Append-only, hash-linked retrievals: invoice_id, sequence, outcome `settled`/`not_settled`/`unknown`/`refused`/`reversed`, facts_hash, line_period_start/end, amount_minor, currency, retrieved_at, freshness_deadline, api_version, sdk_reference, prior_seal, seal | unique(invoice_id, sequence) |
| `production_membership_billing_events` | Webhook dedup and hints: provider_event_ref_hash, type, received_at, payload_hash, disposition | unique(provider_event_ref_hash) |

No foreign key or trigger reaches into any `production_identity_*` child.
Customer and buyer values are bound by runtime T23 proof, not SQL.

### 1.3 Classes

- `BillingPolicy`: default-off gate (raw config read like `MembershipPolicy`,
  rehearsal only in local/testing, real I/O only when `provider_io_enabled`).
- `BillingProviderGateway` (interface): `retrieveInvoice(string $ref)`,
  `listInvoicePayments(string $invoiceRef)`, `retrievePaymentIntent`,
  `retrieveCharge`, `retrieveBalanceTransaction`, `retrieveSubscription`. It
  returns bounded typed snapshots and refuses incomplete collections
  (`has_more` must be false within a fixed bound, or paginate to the bound and
  refuse beyond it).
- `StripeSdkBillingGateway`: the only class that touches `Stripe\StripeClient`.
  Retrieval only; no create, update or pay calls exist in this class.
- `BillingSettlement` (pure evaluator): from snapshots to `settled` facts or a
  named refusal. It requires `invoice.status = paid`, exactly one subscription
  line of the bound price (unless Sean approves multi-line), one `InvoicePayment`
  with status `paid` whose `payment.type = payment_intent`, a `succeeded`
  PaymentIntent, a captured, unrefunded, undisputed Charge, and an `available`
  balance transaction, all under the same provider account and mode, in the
  approved currency and amount. Out-of-band paid, zero, partial, mixed-payment,
  discounted, credit-balance and FX invoices are refused unless a typed approved
  fact enables them.
- `BillingSubscriptionApprovals`: staff writer (current MFA staff proof,
  audited) that binds a provider customer and subscription to a T23 account and
  a plan version. There is no adoption by email, amount match or Checkout
  `OrderSourceV1`.
- `BillingWebhookIntake`: verifies the signature, deduplicates by event id,
  records a hint and schedules a retrieval. It never awards; a valid signature
  is not proof of payment.
- `BillingReconciliation` and job `App\Jobs\RetrieveMembershipInvoice`: perform
  retrieval outside any database transaction, then append an observation in a
  short transaction. Timeouts or ambiguous provider responses append `unknown`
  and keep the same invoice identity and idempotency scope. Nothing is reversed
  automatically.
- `StripeMembershipPaidInvoiceAuthority implements MembershipPaidInvoiceAuthority`
  plus a private `final class StripeMembershipPaidInvoiceProof implements MembershipPaidInvoiceProof`
  (private constructor, `__serialize`/`jsonSerialize`/`__clone` refuse). `lock()`
  reads the latest complete `settled` observation inside the caller's captured
  `MembershipRows` transaction, binds PDO, transaction marker, observation seal,
  freshness deadline and the current principal/actor. `proveCurrent()` re-reads
  the same rows and refuses a moved observation, a newer non-settled
  observation, an expired deadline or a changed owner. It never renews the
  original deadline.
- `BillingException`.

### 1.4 Tests (`tests/Feature/ProductionMembershipBilling/`)

- `BillingSchemaPreparationTest`: owned prefix, global namespace, immutability,
  `down()` refuses.
- `BillingProviderPinTest`: installed SDK version and reference, `ApiVersion::CURRENT`,
  and the `Stripe-Version` header on a mocked HTTP client. No network.
- `BillingSettlementTest`: table-driven over synthetic snapshots for paid,
  open, partial, two payments, out-of-band, refunded, disputed, pending balance,
  wrong currency, wrong amount, wrong account, wrong mode, proration line, two
  subscription lines, zero amount and discount.
- `BillingWebhookIntakeTest`: bad signature, replayed event id, out-of-order
  events; none awards.
- `BillingUnknownOutcomeTest`: gateway timeout gives an `unknown` observation,
  no proof, and a retry with the same identity.
- `BillingPaidInvoiceAuthorityTest`: lock/proveCurrent positive path; moved
  observation, newer refusal, expired deadline, other owner, serialization,
  clone and cross-transaction reuse are refused.
- `BillingNativeDedupRaceTest` with worker `tests/Support/membership-billing-race-worker.php`:
  two processes record the same invoice, giving one invoice row and two
  ordered observations at most.
- Test-only `tests/Support/RehearsalBillingGateway.php` (synthetic snapshots,
  `synthetic_rehearsal` provenance only, refuses outside testing).

## 2. 257: policy facts and the award writer

### 2.1 Policy facts producer

- `App\Domain\Memberships\Production\MembershipPolicyVersions`: staff writer
  (current MFA, audited role) that inserts an immutable
  `production_membership_plan_versions` row whose encrypted payload holds the
  facts from section 0.3 and whose `policy_hash` is the canonical hash of
  those facts.
- `SealedMembershipPolicyFacts implements MembershipPolicyFactsAuthority` with a
  private `MembershipPolicyFactsToken implements MembershipPolicyFactsProof`.
  `lock(planVersion, rows)` decrypts and verifies seal and hash and returns a
  `MembershipPolicyBinding`. `MembershipPolicy::current()` must still match
  `approved_policy_hash`.
- Tests: `MembershipPolicyFactsTest` (tampered ciphertext, wrong seal,
  unapproved hash, non-MFA author, rehearsal facts in production provenance).

### 2.2 Award writer

`App\Domain\Memberships\Production\MembershipAwards::award(MembershipPaidInvoiceProof, MembershipPolicyFactsProof, ProductionCustomerPrincipal, User, MembershipRows): array`

- Inside one captured transaction: `proveCurrent` on both proofs, then insert
  the `production_membership_paid_periods` row (`source_invoice_hash` unique,
  period from the invoice line period, allowance and credit expiry derived from
  policy facts, owner binding from T23) and the first `award` credit event
  (sequence 1, prior seal of the period seal).
- Idempotent replay: a duplicate-key error on `source_invoice_hash` is caught,
  the existing period is re-read, and identical bindings return the existing
  award. Any difference refuses with `conflicting_invoice`. There is never a
  second award for one invoice.
- `MembershipCreditEvents` (internal): appends hash-linked events. It computes
  sequence, before/after balances from the last event under the period lock,
  `prior_seal` and `seal = sha256(CanonicalJson(event without seal))`.
- Tests: `MembershipAwardTest` (one period per invoice, replay returns the same
  award, conflicting replay refused, proof from another transaction refused).

## 3. 257: reservation authority, release, expire, native races

### 3.1 Classes

- `MembershipReservations implements MembershipReservationAuthority`
  - `reserve(invoiceProof, licenseProof, requestKey, principal, actor, rows)`:
    1. `proveCurrent` invoice, license and policy facts.
    2. Lock the period row (`SELECT ... FOR UPDATE` on MySQL; on SQLite the
       captured write transaction is enough because SQLite serializes writers).
    3. Derive `credit_amount` from the policy facts' cost for the license tier
       and the honor deadline from the honor policy. There is no caller amount.
    4. `request_key_hash = sha256(origin id ‖ period id ‖ requestKey)`. On a
       duplicate request hash, return `lock()` of the existing redemption.
    5. Insert the redemption and the `reserve` event (the guard checks
       `before_available >= amount`).
    6. Return a private `MembershipReservationToken implements MembershipReservationProof`.
  - `lock(redemptionId, ...)`: retained same-origin retry only. It re-reads the
    redemption and its reserve event and refuses when the owner differs, the
    reservation is terminal or the honor deadline has passed.
  - `proveCurrent(proof, ...)`: same captured transaction (rows object, marker,
    PDO), unchanged redemption/reserve seals, no terminal event, deadline not
    passed, and the current principal/actor.
- `MembershipReleases::release(redemptionId, reason, staffProof|systemProof, rows)`:
  appends a `release` for an unconsumed reservation (honor expiry or staff).
  It is idempotent by `key_hash`.
- `MembershipExpiry` and command `membership:expire-credits` (scheduled): append
  an `expire` for periods whose `credit_expires_at` has passed. They are
  idempotent and serialized by the period lock.
- Consume is never public. Only the 258 activation (step 5) appends `consume`.

### 3.2 Native races (MySQL, independent processes)

Worker `tests/Support/membership-production-race-worker.php`, following
`tests/Support/production-checkout-race-worker.php`: boot the kernel, refuse
unless `testing` + `mysql` + `VA_MEMBERSHIP_RACE_ONLY=1`, read JSON from STDIN,
set `innodb_lock_wait_timeout`, write `ready`, wait for `start`, run one
operation, and print `{result, ids, error_class, connection_id, pid, transaction_level}`.
Harness `tests/Support/MembershipProductionRace.php`.

`tests/Feature/ProductionMembership/MembershipNativeRaceTest.php`:

1. Duplicate invoice: two processes award the same invoice, giving one period
   and one award. The other process replays to the same result or is refused.
2. Last credit: allowance 1, two processes reserve different request keys,
   giving one `saved` and one `denied` (insufficient). Balances are conserved.
3. Same request key: two processes, one redemption, both see the same id.
4. Consume vs release on one reservation: exactly one terminal event (unique
   `reservation_event_id` is the backstop).
5. Expire vs reserve at `credit_expires_at`: never both available and expired.

SQLite cannot prove these. Run them on an isolated MySQL 8.4 server. Base
`CapabilityMigrationOwnership` scans `information_schema.TRIGGERS` across the
whole server, so concurrent sessions' schemas on one shared server make
`migrate:fresh` refuse at migration 238 (DECISION O2). This review used a
private loopback `mysqld` 8.4.11. Each race test must also drop the empty 258
tables before any 257 parent-table fixture (DECISION D1). Each case asserts the final ledger by a hash chain
walk (`MembershipLedgerAudit::verify(periodId)`, a read-only helper also used
by operators).

## 4. Current T25 eligible-license adapter

`App\Domain\Memberships\Production\CurrentEligibleLicenses implements MembershipEligibleLicenseAuthority`
with a private `EligibleLicenseToken`. `lock(selection, expectedRevision, ...)`
re-proves current publication, rights, approved license offer, asset revision
and terms for the selection, applies Sean's eligibility facts (0.3 item 5) and
captures the original microsecond deadline. It reuses no paid-checkout
selection token or discovery evidence. This needs the catalog/rights owners'
current read APIs; coordinate with them and do not duplicate publication logic.
Tests: `CurrentEligibleLicensesTest` (unpublished, rights withdrawn, revision
moved, ineligible tier, deadline passed).

## 5. 258: member originals, private storage, readiness, activation

### 5.1 Facts and profile

- `MemberGrantDefinitions`: staff MFA writer for `production_member_profiles`
  and `production_member_definitions` from Sean-approved member terms/profile
  (0.3 item 15).
- `SealedMemberGrantFacts implements MemberGrantFactsAuthority` with a private
  proof.

### 5.2 Renderer: reuse paid252 by exact hash where appropriate

Paid252 source is `origin/codex/paid-grant-fixture-portability-20261007` (head
`972a76c5` at the time of this plan; the paid handoff names `e27ca2dd` as the
latest checkpoint, so confirm the final reviewed source with root first).
SHA256 at `972a76c5`:

| File | SHA256 |
| --- | --- |
| `app/Domain/Grants/Paid/PaidGrantPdfRenderer.php` | `8a0fce94ed52d65eb31ebf67941976cd31676a47300d9a5ed4b7289d64209c44` |
| `app/Domain/Grants/Paid/PaidGrantRendererProcess.php` | `d138d616268483762beb2f45ffcd9489eddcdb59c0f4568852d7a40a485ad64f` |
| `app/Domain/Grants/Paid/PaidGrantRenderInput.php` | `b3d2eb372fce8d87cc9cfea0cd1f35d18b609ef2636cef4b77a29e4c5541c5cc` |
| `app/Domain/Grants/Paid/PaidGrantRenderProfile.php` | `8b89c67ea0266ac326691dadd19ebdea8e3127a73faf02754cb46e96d3cdb472` |
| `app/Domain/Grants/Paid/PaidGrantText.php` | `9eabe757ed8daa9618de7af7cd22911fd971a0f5174d5cb6eac7294c1e0c5411` |
| `app/Domain/Grants/Paid/PaidGrantTransfer.php` | `5303f4cc893912c8be95368cea68f6e59dc5b3fb49c4382640418946128d9443` |

- Reuse unchanged, pinned by hash: the process isolation (`PaidGrantRendererProcess`,
  a `ContractRenderer`) and the PDF engine (`PaidGrantPdfRenderer`), only if a
  read confirms they take purpose, text and profile entirely from `$input` and
  `$profile`. `PaidGrantTransfer` can be reused for streaming. Add
  `MemberGrantTechnicalPins::assert()`, which hashes these files at boot of the
  member renderer and refuses on mismatch.
- New, member-specific: `MemberGrantRenderProfile` (own manifest hash; fonts
  reused by exact font manifest hash), `MemberGrantRenderInput`,
  `MemberGrantText` (member purpose and terms from approved facts). Paid
  `PaidGrantText`/`PaidGrantRenderProfile` are not reused because the purpose
  and terms differ.

### 5.3 Storage and readiness

- `MemberOriginalFiles`: private disk only (`local`, `serve => false`). The path
  is derived from origin id + role and is never stored in a row or URL. Write
  temp, fsync, rename, then verify sha256 and bytes. Refuse symlinks and foreign
  owners.
- `MemberOriginalArtifacts implements MemberOriginalArtifactAuthority`:
  - `prepare(intent, facts, ...)`: transaction A inserts the pending origin
    (258 guard enforces the exact redemption → period → owner → invoice join)
    and its artifact manifest rows. Rendering and storage run outside any
    transaction. A failure leaves the origin pending and the reservation
    reserved, and a retry reuses the same origin.
  - `proveReadyCurrent(receipt, ...)`: re-hash stored bytes against the manifest
    in the consumer's captured transaction.
- `MemberGrantActivation implements MemberGrantAuthority` and `activate(...)`:
  one owned transaction that runs `proveCurrent` for invoice, license,
  reservation, facts and artifact readiness, appends the `consume` event
  (`grant_origin_id` = origin, `grant_receipt_hash` = readiness hash,
  `grant_purpose` = member purpose), then inserts the activation row (coupled by
  0.2). Commit happens through the captured frame; current owner, flags and
  deadline are re-checked after commit callbacks and before any response.
- `MemberGrantDownloads`: a read consumer that requires an activation and a
  short-lived download authorization, reusing `PaidGrantTransfer` by hash.

### 5.4 Tests (`tests/Feature/ProductionMemberOriginals/`)

- `MemberOriginalRendererTest` (pinned hashes, member text, no paid purpose).
- `MemberOriginalStorageTest` (private path, tamper detection, partial write).
- `MemberActivationTest` (atomic consume+activate; render failure keeps the
  reservation pending; retry reuses the origin; wrong owner, invoice or released
  reservation refused).
- `MemberActivationCouplingSchemaTest` (from 0.2).
- `MemberActivationNativeRaceTest` + worker `tests/Support/member-activation-race-worker.php`:
  two processes activate one reservation, giving one consume and one
  activation; activation racing a staff release gives exactly one terminal.

## 6. Root-owned integration (not this lane)

Service provider bindings for the four 257 authorities and the 258 authorities,
config registration, private session-bound routes/controllers, Filament staff
screens for policy versions and subscription approvals, scheduler entry for
`membership:expire-credits`, and the final exact-SHA Foundation verification.

## Non-goals

No live Stripe calls, no live keys, no real money, no customer import, no
automatic award reversal, no relabelling of `synthetic_rehearsal` data as
`verified_production`, and no change to identity/checkout runtime from this lane.
