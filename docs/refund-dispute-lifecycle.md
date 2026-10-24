# Refund and dispute lifecycle (test commerce, default-off)

Lane A5 (T19/T21, parity rows FP-040 and FP-068). This adds a refund and dispute state machine, a durable observation ledger, policy-governed access effects and a read-mostly staff customer/order view to the Stripe-test commerce chain. **It is default-off, admits only test commerce environments with Stripe test mode, and every write refuses until Sean sets all four policy values.** It sends no refund, accepts no dispute, revokes no license and changes no catalog availability.

## Boundary

- **Provider facts.** Only the existing authenticated GET path: `VerifyTestPayment::inspect` (own account, Checkout Session and PaymentIntent binding), then `StripeFinancialInspectionGateway::financialState` (PaymentIntent, Charge, complete refund and dispute lists filtered by PaymentIntent, repeated Charge), validated by `PaymentFinancialEvidence::capture`. The pinned SDK (21.3.2, API `2026-08-26.dahlia`) is unchanged. Provider I/O never runs inside an application transaction. Webhooks, redirects and clients are never a source.
- **Scope.** Granted (`paid`) test finalizations for the configured own account. Paid exceptions keep their existing refund-resolution workflow ([test-refunded-exception-resolution.md](test-refunded-exception-resolution.md)). The production checkout chain (`ProductionPaidOrderSourceV1`/Tax V2 with `FinancialReadPlan`) is not wired to this ledger yet.
- **Originals.** Orders, lines, verified payments, finalizations, grants, exclusive sales, contracts, reservations and promotion uses are never written. Refunds and disputes are new evidence.

## Policy

`config/refund-dispute.php` reads `VASEY_TEST_REFUND_DISPUTE_ENABLED` (must be the boolean `true`) and four values with **no defaults**. The supported values are exactly `MachinePolicyV1`'s `refunds_and_disputes` choices; an unset, empty, unknown or extra value refuses every write before any row is locked or any provider call is made.

| Key | Supported values | Mechanism |
| --- | --- | --- |
| `refund_authority` | `authoritative_provider_operator_review` | Refunds count only from an accepted provider observation. A fully refunded line produces a review effect; downloads change only when staff suspend that line. |
| `partial_refunds` | `exact_line_allocation` | A full refund (succeeded refunds equal the paid amount) and a single-line order each have one exact allocation and are allocated automatically. Any other partial refund needs a staff allocation by line position that sums exactly to the refund and never exceeds a line's remaining total (tax included, from the verified original order payload). |
| `dispute_access` | `temporarily_block_while_reconciling` | An open dispute places one order-wide hold automatically. Won or closed releases it automatically; lost keeps it and records a review effect. Staff cannot release it while the dispute is open. |
| | `operator_review` | An open dispute records a review effect only. Staff may place an order-wide hold while a dispute is open or lost, and release it. |
| `exclusive_reopening` | `remain_sold` | A refunded or lost exclusive line records that the sale is retained. Reopening decisions refuse. |
| | `qualified_operator_review` | Staff may record a reopening decision only when the exclusive line is fully refunded or the dispute lost, no dispute is open, and the grant's downloads are held. The decision lists every other grant on the same rights scope as retained. The exclusive sale stays recorded and catalog availability is not changed; that is owner decision U-08. |

The proposed refund terms in `docs/legal/TERMS.md` section 10 are not approved and are not encoded. No supported value authorizes license revocation or inventory restock.

## State machine

Each accepted observation is a complete snapshot, so the case state comes from the newest snapshot, not from event arrival order (`RefundDisputeState`). Against the previous accepted snapshot an object may not vanish, change amount or currency, or move backwards (refund `pending → requires_action → succeeded/failed/canceled`, `succeeded → failed`; dispute warning/needs-response/under-review → won/lost/warning_closed, terminal states stay terminal). Any violation, a refund total above the paid amount, a currency mismatch, an incomplete list or unsupported data is recorded as `attention` with a review effect and no other effect; holds are never released by an attention observation. A succeeded refund that later fails is accepted, keeps its allocation and holds, and records `refund_reversed_review_required`.

Derived states: refund `none | partial | full` (succeeded only); dispute `none | open | lost | won | closed` (open wins over lost, lost over won).

## Ledger and effects

Additive migration `2026_10_24_264000_refund_dispute_lifecycle` (forward-only; `down()` always refuses) adds seven tables with SQLite and MySQL guards:

| Table | Contents |
| --- | --- |
| `refund_dispute_work` | Per-order ledger sequence and at most one leased provider-read claim (monotonic, undeletable). |
| `refund_dispute_observations` | Append-only, one per staff request: actor, request UUID, sequence, outcome, states, amounts, policy hash and encrypted canonical evidence (provider IDs only inside the ciphertext). |
| `refund_dispute_objects`, `refund_dispute_statuses` | Deduplicated provider refunds/disputes (hashed IDs) and the first observation of each status. Replays add nothing. |
| `refund_line_allocations` | Exact allocations of succeeded refunds to original lines; automatic sources carry no actor, staff ones must. |
| `refund_dispute_effects` | Append-only effects with a unique key per order, so replays add nothing. |
| `refund_dispute_holds` | Technical download holds (order-wide or one grant); the only change permitted is one release bound to a release effect. |

Parent rows (orders, lines, grants) are immutable and undeletable on their own; the new tables check their existence and order binding in insert guards instead of foreign keys, which keeps the existing empty-table roundtrips of the order migrations working (those tests drop the empty A5 tables first with `RefundDisputeFixtures::dropEmptyTables()`).

Writers lock actor User → Order → coordination row → current reads of the case. Every staff command is idempotent by request UUID (same actor and same arguments replay; anything else conflicts), requires the current sequence, refuses while a provider read is leased, and requires the latest ledger entry to be an accepted observation. Each write records an audit event (`commerce.refund_dispute.*`) with no provider IDs, buyer data or ciphertext.

## Access enforcement

`RefundDisputeAccess::assertClear` runs inside `IssueTestDelivery` and `RedeemTestDelivery` after their Order row lock, which every hold writer also takes first. A committed hold refuses new download authorizations and the redemption of authorizations issued before it (`DeliveryException('blocked')`). `ReadTestOwnerDelivery` reports an order with an order-wide hold as unavailable; a single-grant hold is enforced at issuance and redemption only. Paid-grant, Tax, free and member delivery paths are not wired to holds.

## Staff administration

Filament **Test commerce → Test customers** (read-only list, customer detail) and **Test orders and refunds** (order evidence, plus the seven commands above). Both pages reauthorize `administer-catalog` and the panel MFA rule on every Livewire request; every command reaches the domain service, which rechecks authority, MFA (locked re-read), policy and sequence. Guest customers appear by a keyed reference, never by their owner key.

## Verification

Focused SQLite suites: `tests/Feature/RefundDispute/RefundDisputeLifecycleTest.php`, `RefundDisputeMigrationTest.php`, `StaffCommerceAdministrationTest.php`, `tests/Unit/RefundDisputeStateTest.php`. Native MySQL: `tests/Feature/RefundDispute/RefundDisputeConcurrencyTest.php` (independent processes; skipped on SQLite and listed in the SQLite skip census). Results, timings and mutation checks are recorded in [the A5 packet](verification/refund-dispute-a5-20261024/README.md).
