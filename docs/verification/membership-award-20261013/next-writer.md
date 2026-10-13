# Next C2 batch: per-paid-period reservation lifecycle

Begin after the first-award batch merges, rebased on its actual merge commit. This is a development scope, not implemented reservation behavior or approval to activate membership commerce. Sean’s commercial inputs remain outstanding; explicitly authored synthetic documents support development only.

The previous policy reviewer recommended the same boundary: explicit retained
redemption terms, real eligibility, complete ledger audit, owned reserve and
reservation proof operations, bounded honor-expiry release and available-credit
expiry. Consume remains exclusively part of later C4 activation.

## Smallest coherent reviewable PR

Implement **one selected paid period's reserve/retry/release/expire lifecycle**,
with current eligible-license evidence and one complete ledger audit shared by
award replay and reservation operations. Keep the whole batch unmounted and
default-off, with the full four-capability gate unchanged. It should be usable
through real retained policy/invoice/catalog producers in explicit synthetic
tests, not merely structural rows or fabricated authority values.

This boundary closes a real balance lifecycle without prematurely adding member
original rendering/storage/downloads, automatic period selection, renewal,
cancellation, refunds, rollover, or consume. Batching the audit and policy work
with the writer avoids an intermediate reservation implementation whose own
award replay cannot read the events it creates. Do not split small follow-up P2s
into isolated pushes after freezing this candidate.

Success means: a current owner can reserve a policy-derived credit amount from
an explicitly selected, actually awarded period; exact retries return the same
retained identity; conflicting retries refuse; no second process can spend the
same last credit; expired or released reservations cannot regain authority; and
existing awards remain readable and replayable after valid later events.

## Concrete dependency findings

| Existing source | Consequence for the next implementation |
| --- | --- |
| `MembershipPolicyDocument` accepts only `membership-award-policy-v1` and `scope=paid_period_award`; binding has only `['paid_period_award']` | Add an explicit new document version for redemption operations. Do not silently fill its null eligibility/honor hashes or reinterpret old documents. |
| `MembershipAwardContext::match()` currently requires the exact award-only operation list | Version-aware award support must admit an explicit v2 award operation without weakening the real policy/invoice/terms match. |
| `MembershipPeriodRecords::verify()` requires exactly one event | Factor a complete retained ledger verifier before any reserve writer. Preserve the original first-award projection and seal bytes, then audit later events. |
| `MembershipLicenseBinding` contains four hashes and a deadline, but no trusted cost selector | Extend the producer binding with authenticated rule/license/revision identity sufficient to select a retained policy cost. A hash is not a tier and caller amounts are never accepted. |
| `MembershipReservationAuthority::reserve()` accepts source proofs plus Rows | A standalone Rows object must not make this public method a writer entry. Require an owned one-use invocation permit, following the repaired award boundary. |
| Schema257 already has redemption identity/intent/honor fields and all event balance columns/unique constraints | Prefer authenticated payload additions using existing columns. Any necessary constraint/guard change requires a forward retained-data migration, not editing the historical installed definition in place. |
| `MemberGrantSchema` activation coupling already joins the exact consume/reserve/origin/readiness/purpose graph | Preserve that guard and its tests. Do not expose a generic consume method now. |

The old implementation-plan sentence describing first-event `prior_seal` as the
period seal is stale. Actual schema and the new award writer use **64 zeroes**.
Likewise, the old plan's advice to amend uninstalled schema in place does not
authorize rewriting the now-integrated retained schema or migration history.

## Implementation split and ownership

These are coordinated lanes within one PR, not concurrent edits to shared files.
Root should assign exact ownership and a fresh worktree after the award merge.

1. **Policy and eligibility lane.** Own new `MembershipRedemptionTerms`, explicit
   version dispatch in `MembershipPolicyDocument`, the corresponding typed
   `MembershipPolicyBinding` fields/operations, and `CurrentEligibleLicenses` plus
   its issuer-owned proof. Coordinate shared `MembershipPolicyVersions` and
   `MembershipLicenseBinding` changes with root before editing. Preserve v1
   canonical bytes/hashes and keyed seals. Author v2 with current MFA staff and
   existing retained policy mechanisms. New v2 plans require matching immutable
   subscription approval and real paid-period evidence; no retrofit of old v1
   plans, subscriptions, or periods. Explicit grandfathering is a later policy
   feature, not an inference.
2. **Ledger and lifecycle lane.** Own a new `MembershipLedgerAudit`, internal
   event builder, retained redemption record verifier, `MembershipReservations`,
   private reservation token/registry, release and expiry services. Root owns
   the coordinated extraction from `MembershipPeriodRecords` so first-award
   creation/seals and later replay remain consistent. Verify all row columns,
   purpose/versioned payloads, original buyer, policy, sequence/prior seals,
   referenced rows, balance transitions, and terminal event uniqueness.
3. **Owned operation/integration lane.** Root owns reservation/release/expiry
   entry operations, exact operation permits, original deadlines, callback and
   committed receipt composition, fixtures, focused independent-process races,
   source-bound evidence, native selection additions, docs and merge ledgers.
   Reuse proven primitives carefully; do not broaden the award permit into an
   arbitrary callback that can run any credit mutation. A shared generic
   operation primitive is justified only if its per-operation permitted effect
   and capability requirements remain exact and independently tested.
4. **Independent reviewer.** A separate non-author session reviews the final
   tested application SHA, native race evidence and final evidence/doc delta.
   Root must not approve its own payment/credit authority composition.

If a lane discovers missing public current-read contracts for catalog evidence,
resolve that dependency with its owner inside this coherent scope before making
an unsupported eligibility claim. Do not copy a browser snapshot or paid order
token to make the tests pass.

## Typed policy and eligible-license boundary

V2 should explicitly contain bounded eligibility rules, each rule's credit cost,
and reservation honor semantics. Start with exact authored eligible revisions
and asset/license identities rather than implicitly admitting every new offer
revision or treating paid price as a credit conversion. Bind the rule identity,
plan policy hash, selected offer/license revision, and retained member-license
terms to the eligibility proof. Version additions are ordinary authenticated
payload changes unless a concrete schema need is found.

Use `CurrentSelection::load`, `proveBytes` and `proveCurrent` with the captured
`MembershipRows::current()` reader as the existing current publication/rights/
approved-offer/license/media foundation, subject to confirming its bounded
current-reader guarantees for this consumer. It presently admits a narrow
non-exclusive, positive-USD paid-offer catalog shape. That is a supported catalog
boundary, **not membership pricing or a rule that one dollar buys one credit**.
It also performs file/renderer callbacks; the owned terminal fence must reprove
raw catalog state and its original deadline after that work.

`ReadTrackPublicationManifest::handle()` is an MFA staff standalone operation,
with ordinary child reads and cached file evidence. It is not a substitute for
the customer's captured current eligibility proof. `CurrentSelection` values are
not themselves membership authority: the adapter must mint an issuer-owned,
non-clonable/nonserializable token tied to exact Rows/PDO/frame, original
readiness deadline and the real retained policy rule.

Specify the internal selection locator and `expectedRevision` meaning before
writing the adapter (for example, an owned offer locator plus exact immutable
offer-revision ID). Reject ambiguous or unsupported selectors. No client field
may supply credit amount, eligibility, manifest hashes, or replacement terms.

Keep membership-plan original terms distinct from the eligible member-license
original terms. The latter must agree with redemption and eventual C4 definition/
profile evidence; matching unrelated plan text hashes would not prove the grant.

## Operation, lock and retained-history design

Use this common lock order: current T23 user/account/origin/history → Billing
fixed fence → plan/subscription/invoice and current observations/hints → current
catalog footprint → selected paid period → its redemptions/events → later C4
member-original rows. Confirm it against existing publication/rights writers so
no reverse ordering is introduced. Metadata/schema inspection follows the exact
captured primary; catalog caching must remain operation-local and invalidated by
the schema's actual binding, not a cross-operation authority cache.

Reserve within one owned outer transaction, audit the complete period history,
derive the cost and balances under the period lock, and insert redemption plus
reserve event atomically. Domain-separated request-key scope should bind original
buyer origin and selected period. Its retained intent must bind the full policy,
invoice identity, owner, selection/revision, license/assets/terms and derived
cost. Same key/same full intent returns original IDs; same key/changed intent
refuses. A duplicate-key error is not permission to return an unaudited row.

Mint reservation proofs only from that retained graph; registry entries bind
issuer, Rows/PDO/original transaction, current principal/actor, source seals and
the original honor/readiness/operation deadlines. Retry/lock/proveCurrent cannot
renew any original deadline or adopt another customer's/transaction's token.
The retained original owner may use a legitimately recovered current credential,
but owner/origin or original historical binding cannot be reassigned.

Release an unconsumed reservation once. Existing guards move reserved credit
back to available strictly before period expiry, and to expired at/after expiry.
That behavior must be explicitly admitted by the authored policy, not presented
as Sean's chosen rule. Honor expiry and available-credit expiry are bounded,
idempotent owned operations. Available-credit expiry never moves already reserved
credit; its eventual release takes the terminal expired path. No scheduler or
public route is mounted in this batch. A staff release path, if included, needs
current MFA authorization and an authenticated reason; no invented automatic
cancellation/refund release rule.

The auditor walks events in **sequence order**, checks the 64-zero first prior
seal, exact event predecessor seal, conservation of allowance, valid deltas,
redemption joins, and at most one release/consume terminal per reservation.
Preserve the original keyed purpose/table seals; never reseal old rows. Later
consume rows may be recognized only with the exact C4 coupling/audit contract;
this batch must not mint a consume or claim a structurally shaped grant is ready.

Preserve normal Laravel beginning/committing/afterCommit/committed callbacks and
the exact owned frame. Complete callback-capable source/file/clock work before
final raw policy, billing, catalog, identity and effect closure. Carry one
original admission deadline through the separate committed read-only frame; a
later producer lease cannot extend it. Return only a prebuilt plain projection
after that frame finishes. Postcommit refusal means the write may be durable;
never retry automatically or claim rollback.

## Missing Sean inputs and explicit development limits

Already-requested commercial inputs remain outstanding; do not ask again during
this preparation and do not infer answers from fixtures or BeatStars marketing.
The direct reservation blockers are:

- Eligible tracks, license versions/tiers, offer revisions and asset sets.
- Credit cost for each eligible rule.
- Reservation honor duration and whether/how that duration is capped by credit
  expiry, plus the intended behavior when the two boundaries differ.
- Actual plan catalogue, allowance, price/currency/billing facts and award expiry
  remain production inputs even though their explicit document types now exist.

Legal member-license terms, original profile and retention rules additionally
block C4. Rollover, grandfathering, cancellation, refunds/disputes, dunning,
upgrades/proration and period allocation/FIFO remain outside this boundary; none
gets a default. Until Sean supplies reviewed facts, tests may author clearly
labelled synthetic v2 policies and catalog fixtures in local/testing only. They
must not be reclassified as verified production or activated for customers.

## Regression and verification plan

**Policy compatibility:** unchanged v1 bytes/hash/seal and replay; v1 refuses
redemption; v2 strict key/type/bounds/unknown-operation checks; current MFA
authoring; no forged rule/cost or old-period upgrade; no withdrawal/rebinding
between full capability admission and captured baseline; nonshared direct
factories supported and unsupported alias chains refused consistently.

**Eligibility:** unpublished/withdrawn rights, stale expected revision, expired
license, changed authored terms, ineligible exact rule, changed file bytes,
asset-role/stems lineage mismatch, missing/incomplete graph, deadline boundaries,
foreign issuer, serialization/clone and cross-frame proof reuse. Verify actual
retained catalog fixtures rather than using structural grant rows as authority.

**Ledger and retry:** award-only audit stays valid; award replay after reserve,
release and expiry returns the original award; same request returns the same
redemption/event; altered selection/cost/rule/terms conflicts; wrong owner refuses;
insufficient credit has no effects; tampered payload/row/seal, missing/duplicate
sequence, wrong prior seal, bad balances, orphan redemption, duplicate terminal
or invalid grant joins refuse. Preserve idempotence after fresh identical billing
retrieval without confusing retrieval metadata with semantic invoice identity.

**Owned outcome and boundaries:** direct context/writer construction and
reentrant second invocation refuse before writes; changed source/config/PDO and
commit/reopen cannot be adopted; ordinary committing mutation rolls back both
effects; afterCommit/committed mutation refuses the response but reports retained
effects honestly. Cover honor equality, period expiry equality, release on each
side, no credit resurrection, no receipt after the original monotonic deadline,
and current owner recovery without old binding adoption. Virtual-time tests must
isolate intended elapsed time from slow native execution and label their
synthetic timing; ordinary native operations must retain the real clock.

**Private MySQL races:** own datadir/port/socket and record version/source/command.
Use independent PID/connection workers, readiness barriers before owned entry
(T23/fixed fence already serialize), bounded waits, and final full-chain audit:

1. Allowance one, different request keys: one saved, one insufficient; one
   redemption/reserve event; available zero, reserved one.
2. Same request key/intent: exactly one redemption and reserve, both successful
   responses name it when admitted; a bounded loser refusal is reported precisely.
3. Duplicate release: one terminal effect, no balance inflation.
4. Expire versus reserve at the policy boundary: available and expired cannot
   count the same credit; conserved final ledger.
5. Release at/after period expiry: credit goes to expired and cannot be reserved
   again. Keep consume-versus-release for C4, where the real activation exists.

Do not claim the period lock alone caused serialization when identity/fence locks
acquired earlier already ordered workers. A SQLite pass does not prove these
races. Re-run the affected whole SQLite files and meaningful focused native
cases, retain any regressions/failures/setup errors, and update native selection
and exact census when new cases are added. Use local checks and cheap exact-head
preflight; no routine Foundation or GitLab dispatch.

## C4 handoff that this PR must preserve

C4 later prepares actual private member originals and artifact readiness under
its own approved member definition/profile/retention facts. Its activation
transaction must reprove invoice, policy, eligible license, reservation, current
owner and actual original readiness, append the consume event, then insert the
matching activation **in the same owned transaction**. Existing activation
guards require the exact reserve seal, consume/redemption/period/origin,
readiness receipt and `production-member-license-grant-v1` purpose. Both effects
commit or neither does. Retain the existing coupling schema tests; raw synthetic
consume fixtures are structural tests only and cannot become an operative
consume API or proof of a real original.

After this next PR passes independent review and exact-head preflight, update
CLAUDE-PLAN §5 and the deferred ledger at its actual merge. State explicitly that
reservation lifecycle is complete only within recorded synthetic/development
coverage and that C4 consume/activation, actual commercial facts, runtime
mounting and release/host/provider conditions remain open.
