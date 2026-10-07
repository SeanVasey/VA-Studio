# Paid grant consumer contract — October 7, 2026

This is the proposed T24/T25 implementation contract for a new
`App\Domain\Grants\Paid` child and migration
`2026_10_07_252000_paid_grant_origins`. It is not an executable grant, independent
approval, production activation, or completion receipt. The isolated branch is
`codex/paid-grant-origins-20261007`, from root main
`4150b858d05c837cf85801cf0ee1537a3c98464f`.

## Owned scope

New paid origins, bounded preparation work, first private originals, complete-order
fulfillment proof, authorizations and committed stream attempts are additive.
They do not mutate `license_grants`, previous test paid records, free definitions,
free grants, producer orders/payment evidence, or historic contracts. The child
owns dedicated domain/controller/privacy/routes, an actual mounted owner library,
its separate paid-purpose renderer/profile, focused tests and receipts. The parent
owns shared bootstrap/route/navigation/robots registration.

The implementation should preserve original affirmative buyer assent rather than
ask the browser to mint replacement terms. An actual server command may finalize
the verified retained order idempotently, then prepare the exact original PDFs and
assets. It must not infer rights from a success redirect, provider session label,
amount submitted by a browser, email address, generic identity history, or exception
payment. The initial producer accepts verified-account non-exclusive USD orders
without promotions; this consumer does not expand that offer surface.

## Dependencies and typed source

The executable preparation currently borrows a separate immutable snapshot of
`f0a1615be0833804959662fad5d7a7a8e1389ee0`. It repairs the original 9f
autocommit/transaction-continuity boundary, but later failed the genuine expired
same-PDO lazy callback case. The producer's callback repair is frozen separately at
`b6f1dfbb7b92cee5319eac79443b88bf24178ab2`, with independent review pending.
Its additive committed-read receipt and the required T23 witness are still being
implemented. Public signatures or local preparation results confer no dependency
approval; the final consumer must bind exact reviewed successor bytes.

T23 supplies a privately minted `ProductionCustomerPrincipal`, original session
verification, current raw actor/account/origin proof, and historical verification
prefix proof. Its `eaaa55be3bf35193e68a3ad8cac3cc99eec85e5e` candidate is also a
provisional dependency; independent review later found a parent-key migration
admission defect. Borrowed actual source is recorded by exact commit/hash and
never called approved production identity.

The producer contract is deliberately two step:

1. `ProductionPaidOrderLocatorV1::locate(orderUuid)` runs outside transactions. It
   returns a server-located original buyer binding and order commitment; it takes
   no order lock and grants no authority.
2. Inside one consumer-captured transaction, independently acquire current buyer
   authority, compare its stable user/account/origin ownership to the located
   original, then prelock
   `ProductionCustomerAccess::verifyHistoricalBinding(binding, CurrentRows)`.
3. Prove and release/renew the earliest consumer marker immediately after the
   current/historical prelock, before the producer creates its younger anchor.
   Capture one `CurrentRows` object, then immediately call
   `ProductionPaidOrderSourceV1::lockedRead(locator, CurrentRows,
   prelockedHistoricalRaw)` on that same captured primary. The private,
   nonserializable producer object verifies the complete original order/review/
   basis/candidate/lines/attempt/intent/session/confirmed on-time payment graph.
4. Extract all `line(position)` projections before terminal proof. Store their
   canonical source hashes and original evidence in the owned encrypted origin.
   Any evidence decryption or dependency resolution belongs before final raw reads.
5. After all own writes, audits, policy/adapter callbacks and full consumer graph
   comparisons, make the current-owner comparison, then call
   `proveRetainedCurrent(CurrentRows)` and recheck pure flags/frame. Consumer
   completion must not release the older marker after source capture, because
   SQLite also expires younger savepoints. The producer's final commit observer
   must retain its original anchor through all ordinary committing callbacks.

The separate proposed producer receipt is
`committedReadReceipt(CurrentRows, int originalDeadlineNs)`. At most two opaque,
one-use siblings bind the same original reader/source/frame/deadline. One closes
the producer financial graph immediately after commit; the second closes it after
response work and before first bytes. Neither an expired SourceV1 nor stored raw
arrays can replace that receipt. Current preparation closes the owned origin and
current owner after commit, but this producer receipt binding is unfinished and
blocks final private-response/stream acceptance.

Line evidence includes original producer/order/line/payment UUID/hash, provider
payment/account/mode and receipt provenance, buyer verification binding and original
declared name, actual affirmative assent/review hash, full frozen license and asset
identities, integer line/tax/order amounts, candidate commitments and inventory
attempt binding. The tuple producer + order + line is unique. Exact same-source
replay returns the original; a changed source/payment conflicts. `paid_exception`
and late confirmations cannot mint this source or a grant.

Persist producer/order/line/payment identities as immutable owned UUID/hash values.
Do not add direct FKs, SQL views, triggers or routines referencing the producer's
ten tables: its installer deliberately refuses foreign dependencies on retry.
Generic stable user/account FKs are separate and require exact key compatibility.

## Authority and transaction closure

Resolve injectable owner/policy/adapter dependencies before the terminal raw fence.
Only the current original-session principal may act for a buyer. Historical
verification authenticates the original purchased act after recovery; it cannot
grant current paid-feature authority. A recovered session still needs a fresh
current proof and the same permanent account/origin, not the old credential stamp.

Capture the exact Connection, PDO, driver, schema and prefix before callbacks.
Require exactly one framework transaction and an active physical transaction for
mint/fence/writes. Qualify permanent tables and reject temporary/case aliases.
A private transaction marker must detect commit-and-reopen, connection/prefix
replacement, and expired Laravel transactions without undoing another participant's
valid writes. Raw current proofs and producer reproof must not resolve another
framework service after their snapshots. Final projections use already decoded,
validated data only.

Lock order is current user/account/origin → original historical identity prefix →
producer order and retained financial graph → owned order lines in deterministic
position → work/original/fulfillment → authorization/attempt. Metadata locators take
no order lock. Read endpoints do not change assent or grant state.

## Purpose, rendering and physical fulfillment

The origin family distinguishes `synthetic_rehearsal` from `verified_production`.
It also retains the original funds mode, payment evidence origin and identity
provenance. Rehearsal may render only a clearly identified rehearsal paid purpose;
it cannot select a production profile or certify actual seller/payment/legal facts.
Both flags default off. Operative admission needs the reviewed current T23 and
producer contract, verified production source and explicit server delivery policy.
No production credential, provider request, money or external email occurs here.

Use a new paid-purpose profile/manifest and script, preserving the PR24 offline
renderer input/font/package/environment/timeout/private-path safeguards. Freeze
original buyer declaration, affirmative assent and full original license/assets/
money/source hashes. Never substitute current catalog/customer/license data or
reuse free renderer input/profile. The technical delivery policy is explicit
server-authored bounded input, separate from license permissions; there are no
invented prices, terms or default contractual download allowances.

Claims are bounded by attempts/lease. Exact byte checks and rendering/private
exclusive writes run outside transactions. On reentry, verify the winning claims,
original source/identity and every order line. Record one immutable complete-order
fulfillment proof only after every first PDF and exact captured asset manifest has
passed physical checks. A partial failure activates no line. Replay cannot replace
completed original bytes; missing originals require exact restore.

## Owner library and delivery

A deliberate bounded current-owner library projects original line/license/asset/
assent metadata and truthful preparation/fulfillment status without private paths.
It includes token-free bounded authorization/committed-attempt status and explicit
preparation retry after exact claim expiry. A status read does not assert physical
durability or completed browser receipt.

Each download requires complete retained fulfillment, fresh current owner authority,
exact origin/file hash and a short-lived hash-only authorization. Browser UUID/role
selects a frozen member; no caller-supplied owner/path or source DTO is accepted.
Preparation opens the exact private held descriptor outside transactions and then
rechecks authority/source/deadline/attempt cap before one committed redemption.
Native POST attachment carries only token and CSRF, never a token URL. Denial,
departure/unmount and late responses clear private inputs, tokens and pending form
state. Errors/headers/reporting preserve the existing private-module safeguards.

## Planned evidence

Focused proof will cover actual local-SMTP identity and retained synthetic provider
source without minting browser authority; exact replay/conflicting source, late-paid
exception exclusion, all-lines atomicity, recovery under the same permanent origin,
cross-customer/stale-session refusal, late resolver/policy/credential withdrawal,
temporary shadows and transaction commit/reopen; immutable SQL guards and actual
Migrator failure/retry/ledger preservation; real offline PDF/exact native attachment
bytes and original restore-only semantics; bounded statuses and mounted frontend
denial/departure/retry behavior. Native races run only for a concrete serialization
boundary on the separate `vaseyaudio_paid_grants` author schema, with observed
InnoDB waits. SQLite never certifies those races or native DDL.
