# C4c shared contract proposal — atomic member consumption and activation

2026-10-13. **Proposal for independent contract review before any C4c implementation release.** This is not implemented code, an application DECISION, a test result or authority to activate production memberships. Only this runtime Markdown file was written. No database statement, application test, native workload, provider call, repository edit or production action belongs to this task.

Inspected final frozen C4b reference: `453c1af816592fffb856b7cb47b2c891a14c58c1` in `/workspace/VA-Studio-member-originals`. Its named application/test/dependency inputs are byte-identical to integrated correction `32f083d28db0609a52cade793849a8767a855ae3` and native withdrawal source `ec90cc1a097db8925d87689d0ac62fdff0165c5a`. Author correction `9399dd998cc3d972b94f6753e576e2c756438657` has identical application/test/resource inputs but differs on two CI policy JSON files, explicitly recorded below. This is frozen source reconciliation, not a merge assertion. The original inspected checkpoint `f4732dbe21b9e40eb386b6115be1283ff563f511` and its 24 hashes remain historical context. Basis: `/workspace/runtime/member-originals-c4c-design-20261013.md`. The later independently reviewed C4b head is `a108aca947ae0d3f661e9feef8a19441d5c3a165`; the same named runtime/test/dependency trees match 453c1af8 exactly. C4b publication is currently blocked by host Git authentication, which the lead is handling separately. The lead authorizes **isolated local C4c implementation on that exact reviewed tree only after this contract receives independent approval and the lead releases the owners**. C4c integration/publication still requires actual C4b preflight, merge and delta reconciliation. This is an authorized local-base exception, not a merged-base claim; no source work is authorized by this planning note alone.

The earlier native `committed_source_refused` result (1 test / 16 assertions) remains preserved in the C4b packet. It is no longer a running blocker: the actual corrected native preparation/exact-repeat case passed **1 / 48**, and the separate native materialization-withdrawal case passed **1 / 30**, each with zero failures/errors/skips and exit 0. This planning task checked the committed XML/result receipts; it did not rerun tests. These narrow C4b evidence statements do not establish C4c activation or host/provider verification.

## 1. Decisions this proposal makes, and inputs it does not invent

Implement one unmounted synchronous activation workflow, with an explicit v3 policy and an independently admitted owner-confirmation producer. It obtains real C4b materialization, then writes origin → complete artifact list → consume → activation in one owned final transaction. The response follows committed graph verification and read-only original observation. The existing C4b candidate remains a value; it is not an authorization input.

The proposed authorization mode proves only **an explicit authenticated owner request to activate the exact member license and spend its retained reserved credit**. It does not claim a verified legal identity, delivery/readership of a disclosure, legal assent, an effective date, marketing consent or commercial approval. Sean has not selected the commercial/legal authorization rule. Nothing here selects it for production. If the required legal rule calls for separately evidenced assent, signatures, representation, guardian consent or another producer, this technical mode is insufficient and production activation stays disabled until that producer and policy version are agreed. No new question repeats the already outstanding request for those inputs.

A real synthetic implementation can exercise review → literal confirmation → fresh activation through actual T23/catalog/facts producers under rehearsal provenance. That is a genuine technical producer with explicitly synthetic inputs, not a passing authority stub or evidence of production assent. No production policy document, approved hash, flag, route or runtime service binding is supplied. Existing prices, allowance, retention, cancellation, continuity and honor rules are not defaulted.

The proposed schema choice is to add no installed tables for this **same-process confirmation-to-activation** contract, subject to independent review: its confirmation evidence is retained atomically in the final authenticated origin/activation payloads. A detached confirmation token is process-local and is not serialized for a queue. A durable pending-authorization inbox, asynchronous handoff, durable revocation/accounting, or legally separate retained assent is a different requirement; decide its forward schema first if needed. Do not hide such a requirement in profile rows or an unsigned sidecar.

## 2. Exact successor policy shape

Namespace `P = App\Domain\Memberships\Production`, `M = App\Domain\Grants\Member`. All new `MemberActivation*`, `MemberActivatedOriginal`, `MemberRetained*` and `MemberLicense*` types below belong to M; `Membership*` types belong to P. `Principal` means `App\Domain\Customers\ProductionCustomerPrincipal`, `User` means `App\Models\User`, and `IdentityCommittedFrame` is the existing production-identity class. Reuse the current strict canonical JSON, UUIDv4, native integer, UTC19, provenance and hash validators. Reject unknown keys, wrong types, invalid UTF-8/NUL, overflow and unknown versions; do not coerce or normalize submitted terms.

Add `MembershipPolicyDocument::CONSUMPTION_VERSION = membership-credit-policy-v3`. Its exact top-level fields are:

```
schema_version, scope, provenance, plan_name, terms_reference, terms_text,
credit_allowance, billing_price_minor, billing_currency, period_source,
award_terms, redemption_terms, consumption_terms
```

`schema_version` is that v3 value; `scope = paid_period_credit_activation`; the other existing fields retain their v2 validators. `period_source` remains `verified_invoice_line_service_period`. Keep the v1 and v2 parsers, canonical hashes and supported-operation arrays byte-compatible. V3 is a new retained plan and invoice/period/reservation identity, never an upgrade of an existing v2 period. Outstanding v2 continuity is outside this batch.

`MembershipConsumptionTerms::from(array): self` has these exact keys:

```
schema_version = membership-consumption-terms-v1
purpose = production-member-license-grant-v1
reference: nonempty UTF-8, <=160 bytes
text: explicit authored UTF-8, <=65536 bytes
retention_policy: exact member-original-retention-facts-v1 object from C4b
source_rule = current_selected_catalog
authorization_policy: object below
```

`authorization_policy` has exact keys:

```
schema_version = member-license-authorization-policy-v1
mode = explicit_owner_confirmation_v1
action = activate_member_license
record_claim = owner_requested_activation
display_reference: explicit nonempty UTF-8, <=160 bytes
display_text: explicit nonempty UTF-8, <=65536 bytes
required_sources = selected_license_and_consumption_terms
```

These constants define one proposed technical interpretation, not legal wording or a production default. There is no `auto`, `implicit`, `reservation_is_assent`, `paid_is_assent` or hash-only path. The mode is selected only by a complete authored v3 document. Retain the whole exact retention document and require its hash to equal the selected member definition's `retention_policy_hash`; v2's null retention binding stays null. Storage ownership remains the definition's separate authored storage policy. Current catalog eligibility is the only supported new-effect rule; no inferred grandfather exception.

Every new v3 document, consumption/authorization terms value, review/authorization document and binding must detach all nested PHP references before retaining caller data. In a v3 binding this includes the supportedOperations array and caller-supplied awardTerms/redemptionTerms objects: reconstruct those older term values from bounded, detached scalar/array copies as well as retaining immutable consumptionTerms. A readonly reference to an older terms object does not detach references inside its facts. A readonly array property is insufficient: reconstruct validated scalar/list/map values recursively by value, or use an equally strict canonical encode/decode copy that preserves the validated types and canonical bytes. Reject unsupported values before copying. Return detached copies from value accessors; modifying the caller's original referenced variable or a returned nested array must never change the retained value, hash, supported-operation set or private registration. Add nested-reference mutation checks around construction and value access. Preserve all legacy v1/v2 canonical bytes and accepted validation semantics; this is not a legacy reserialization migration.

At the inspected checkpoint, the 122880-byte canonical-document cap applies to **v2 only**. Preserve v1 parser acceptance, field validation and canonical/hash behavior exactly; do not retroactively apply that v2 cap to v1. Keep v2's existing 122880-byte cap and explicitly apply the same cap to the new v3 branch. The separate retained policy-record plaintext cap remains 131072 bytes for its existing producer/record path. Individual field maxima are not promises that all maxima fit simultaneously. Reject an over-cap v3 document; never truncate text, tighten legacy acceptance or enlarge legacy envelopes incidentally.

The new value exposes `value(): array`, `hash(): string`, `retentionHash(): string`, `authorizationHash(): string`. Append `?MembershipConsumptionTerms $consumptionTerms = null` to `MembershipPolicyBinding` for constructor compatibility, with strict combinations:

| Binding kind / retained version | Supported operations | Consumption / retention |
| --- | --- | --- |
| Constructor-only non-authority baseline, not a retained policy version | `[]` | null / preserve existing validated nullable hash fields |
| v1 | original award-only array | null / null |
| v2 | original `paid_period_award,reserve,release,expire` array | null / null |
| v3 | `paid_period_award,reserve,release,expire,consume`, in that order | actual v3 terms / exact terms retention hash |

The constructor-only empty-operation branch is already accepted by `MembershipPolicyBinding` and used by `MembershipPreparationTest`. Preserve that exact legacy non-authority branch: `supportedOperations=[]`, `redemptionTerms=null`, `consumptionTerms=null`, with the existing acceptance of optional `awardTerms` and validated nullable hash fields. Do not newly require its retention/cancellation/grandfather/rollover hashes to be null. It has no retained version, grants no operation, and cannot be elevated to v3 by supplying a terms object or hash. Keep this compatibility fixture; a shaped binding remains non-authority in every branch.

For the retained v1/v2/v3 document-produced bindings, keep cancellation/grandfather/rollover fields null unless a later explicit version defines them. Consumption amount comes from the authenticated reservation. Its credit-expiry treatment is already determined by the retained honor rule: a previously reserved credit may consume before honor, including beyond credit expiry only where that reservation's existing authored rule allows it. No new boolean extends honor or admits a late reservation.

## 3. A genuine authorization producer, not another shaped DTO

No member-specific confirmation/assent producer exists at frozen 453c1af8. Paid/free family producers and their records cannot be reused as member authority. Introduce a real concrete `MemberLicenseAuthorizations` with a separate exact registered capability. It uses the same actual owner/reservation/catalog/member-facts inspection sources; it never accepts prebuilt bindings as proof.

Proposed public domain APIs, all unmounted:

```php
// Value: strict exact keys {display_hash, affirmed}; affirmed must be literal boolean true.
MemberLicenseConfirmationInput::from(array $input): self;

// Read-only current review; the returned display/value/hash is not authority.
MemberLicenseAuthorizations::review(string $definitionId, string $redemptionId,
    Principal $principal, User $actor): MemberLicenseReviewDisplay;

// Owns a fresh source/owner read and normal commit proof; returns a private registered ticket.
MemberLicenseAuthorizations::confirm(string $definitionId, string $redemptionId,
    MemberLicenseConfirmationInput $input, Principal $principal, User $actor)
    : MemberLicenseAuthorization;

// New effects need that exact issuer's ticket; no boolean/hash/path is accepted here.
MemberGrantActivations::activate(string $definitionId, string $redemptionId,
    MemberLicenseAuthorization $authorization, Principal $principal, User $actor)
    : MemberGrantActivationResult;

// Explicit historical read; never creates or repairs an effect or regenerates an original.
MemberGrantActivations::readActivated(string $definitionId, string $redemptionId,
    Principal $principal, User $actor): MemberGrantActivationResult;
```

Proposed exact assembly is `new MemberGrantActivations(SealedMemberGrantFacts $facts, MembershipReservations $reservations, MemberOriginalRendererProcess $renderer, MemberOriginalFiles $files)`. It composes the exact internal source/artifact issuers, real `MemberLicenseAuthorizations` and `SealedMemberGrants`, without admission, I/O or container installation in constructors. Read-only getters `authorizations(): MemberLicenseAuthorizations`, `artifactAuthority(): MemberOriginalArtifacts` and `grantAuthority(): SealedMemberGrants` expose only those exact instances for explicit registration by an authorized caller/fixture. The proposed additional capability key is the concrete `MemberLicenseAuthorizations` class; a second interface or first-arbitrary-consumer assembly is not proposed. Complete existing membership/member capability sets plus that exact issuer and real grant authority must be captured before callbacks and rechecked for rebinding/alias changes. This assembly/API refinement still needs independent acceptance before code.

`Principal` is current `ProductionCustomerPrincipal`; `User` is current `App\Models\User`. Strict input validation is separate from PHP's coercing scalar argument rules. Neither a literal true nor an echoed hash alone is authority: `confirm` authenticates current T23 owner, real retained v3 plan and complete nonterminal reservation, current catalog and selected member facts; reconstructs the exact display; compares its digest; completes all combined current/committing/committed closures; and only then registers the detached ticket. It records precisely the affirmed action, not an inferred legal assertion.

The exact review display hash preimage is:

```
{schema_version: member-license-review-display-v1, family, purpose, provenance,
 action: activate_member_license, record_claim: owner_requested_activation,
 buyer_binding, render_source_hash,
 definition: {id, hash}, profile_hash,
 redemption: {id, seal, credit_amount, honor_deadline},
 plan: {id, policy_hash, consumption_terms_hash},
 authorization_policy, original_terms, consumption_reference, consumption_text}
```

Every field comes from actual admitted sources. `original_terms` is the selected license's C4b canonical object, not plan text. `authorization_policy` and consumption reference/text are the exact authored v3 values. `buyer_binding` retains the original T23 verification observation ID/hash. `render_source_hash` is the unchanged C4b source document hash. No current worker time, private path, active token, free-family display hash or fresh C3 observation identity enters this display. The public review value makes no HTTP delivery/readership claim.

`confirm` starts one `MemberOriginalBudget` before any callback and retains it through activation. The prior review value has no transferable live lease: confirm rebuilds its display from freshly admitted sources. Its original workflow/honor cap can only shorten. The ticket binds the exact concrete issuer, configuration/capability identities, principal/actor object identities, confirmed semantic intent, original budget, and normal committed confirmation receipt. It contains copied values, not live Rows/PDO/producers. Clone, serialization, foreign issuer and direct construction cannot populate its private registry. Activation consumes the ticket claim exactly once at the start of every attempted workflow, including an admitted retained replay or a later failure. Only its separate private write permit is conditional on a new effect. Activation obtains fresh required owner/history proofs and, for a new effect, live source proofs. A failed workflow requires a newly admitted confirmation; it cannot refresh that old ticket's clock.

The retained confirmation document has exact keys:

```
{schema_version: member-license-authorization-record-v1, family, purpose, provenance,
 authorization_id, action: activate_member_license, record_claim: owner_requested_activation,
 affirmed: true, display_hash, intent_hash, policy_hash, consumption_terms_hash,
 authorization_policy_hash, definition_id, definition_hash, profile_hash,
 redemption_id, redemption_seal, render_source_hash, original_terms_hash,
 buyer_binding, actor_binding, confirmed_at}
```

`authorization_id` is an issuer-minted UUIDv4, `confirmed_at` is the captured trusted application business wall instant (not caller time, reservation date or legal effective date), and `actor_binding` is the actual T23 actor evidence used by the producer. Build confirmed_at, the exact display and A **inside the owned confirmation inspection**, before its combined terminal callback/raw phases and normal committed proof. Carbon/UUID/encryption or other callback-capable work must not occur after capture returns as a shortcut to minting A. The successful capture retains detached S/A and the original Budget; register it and the returned ticket only after its post-finish checks, with callback-free registration of those already proved values. Synthetic clock fixtures must be labelled; physical deadlines remain monotonic. Reconstruct the display from retained source + retained policy when auditing, so this small record need not duplicate legal text. Bound its canonical plaintext at 65536 bytes.

`intent_hash` has exact preimage:

```
{purpose: member-license-confirmation-intent-v1, buyer_binding,
 definition_id, definition_hash, redemption_id, redemption_seal,
 render_source_hash, consumption_terms_hash, authorization_policy_hash, display_hash}
```

It excludes authorization UUID/time so two independently confirmed identical requests can converge on the first retained activation. The first successful effect retains its actual authorization document; a loser never replaces that evidence. An existing graph with another semantic intent/definition/owner refuses. Read-only `readActivated` requires current owner admission plus authenticated retained history and bytes, not a new confirmation or current honor. It does not call the live-only reservation lock.

Confirmation itself cannot require an activation authorization claim: that would make the producer circular. Add a distinct, read-only `MemberLicenseConfirmationCapture` owned by `MemberLicenseAuthorizations`, with a private one-use `MemberLicenseConfirmationEntryPermit` binding the exact producer/configuration, definition/redemption locators, confirmation input, principal/actor and original budget. A separately named source-capture entry admits that permit and uses the actual C4b source inspectors to expose copied source values for display/confirmation after complete normal-commit proof. It does no rendering, materialization, readiness or effect insertion. It is neither the activation capture nor a public caller-selected operation mode. Review uses its own read-only entry, without recording affirmation. Neither entry can register `MemberOriginalArtifacts` readiness or return an activation grant receipt. The confirmation ticket is registered only after that capture's successful committed/post-finish closure; the source capture is then retired.

Proposed exact source-owner entry names make this seam reviewable before implementation:

```php
MemberOriginalSourceCapture::captureForReview(MemberLicenseReviewEntryPermit $entry,
    string $definitionId, string $redemptionId, Principal $principal, User $actor,
    MemberOriginalBudget $budget): MemberLicenseReviewCapture;
MemberOriginalSourceCapture::captureForConfirmation(MemberLicenseConfirmationEntryPermit $entry,
    string $definitionId, string $redemptionId, MemberLicenseConfirmationInput $input,
    Principal $principal, User $actor, MemberOriginalBudget $budget)
    : MemberLicenseConfirmationCapture;
```

Neither signature accepts an authorization claim or an activation/effect permit. The exact authorizations instance privately registers each entry before capture. Each capture carries a separately registered, combined owner/facts/reservation/configuration inspection: all callbacks precede all raw checks at entry/current/committing/committed boundaries, only its owner finishes the one committed frame, then post-finish configuration and original elapsed/honor checks run. A confirmation result is unusable until that closure succeeds. The source implementation must explicitly admit these distinct read-only entry classes; using the existing `MemberOriginals`-only permit or accepting a generic caller object is not an implementation of this proposal.

Operation-owned authorization **value codecs**, separate from its genuine issuer:

```php
MemberLicenseAuthorizationRecords::display(MemberOriginalSourceValues $source,
    MembershipPolicyBinding $policy): array;
MemberLicenseAuthorizationRecords::create(MemberOriginalSourceValues $source,
    MembershipPolicyBinding $policy, array $actorBinding, string $authorizationId,
    string $confirmedAt): array;
MemberLicenseAuthorizationRecords::verifyRetained(array $authorization,
    MemberOriginalSourceValues $source, MembershipPolicyBinding $policy): array;
```

These functions validate and detach the exact display/A values and their relationships. They neither authenticate a present caller nor register a ticket, allocate a time/UUID or confer authority. The history lane uses `verifyRetained` only with the winner's retained A/S/P. The real confirmation issuer alone chooses the actual observed actor, literal confirmation, UUID and captured time inside the owned inspection described above.

Private issuer phase API needed by the activation owner:

```php
claim(MemberLicenseAuthorization $ticket, MemberActivationEntryPermit $permit,
    Principal $principal, User $actor): MemberLicenseAuthorizationClaim;
lock(MemberLicenseAuthorizationClaim $claim, MemberOriginalSourceValues $source,
    Principal $principal, User $actor, MembershipRows $rows): MemberLicenseAuthorizationProof;
closure(MemberLicenseAuthorizationProof $proof, Principal $principal, User $actor,
    MembershipRows $rows): MemberLicenseAuthorizationClosure;
currentCallbacks(MemberLicenseAuthorizationProof $proof, MembershipRows $rows): void;
currentRaw(MemberLicenseAuthorizationProof $proof, MembershipRows $rows): void;
committedCallbacks(MemberLicenseAuthorizationClosure $closure, IdentityCommittedFrame $frame): void;
committedRaw(MemberLicenseAuthorizationClosure $closure, IdentityCommittedFrame $frame): void;
postFinish(MemberLicenseAuthorizationClosure $closure, IdentityCommittedFrame $frame): void;
retire(MemberLicenseAuthorizationClaim $claim): void;
```

`claim` checks but does not consume the exact active entry-permit tuple, and atomically removes the ticket's issuance before callbacks. The entry tuple binds the original ticket, owner/services, locators and principal/actor. The initial `captureForActivation` consumes that source-entry admission exactly once and additionally requires the registered claim and its exact original budget. The historical branch retires the unused source admission. `completeActivation` consumes a separate private completion permit bound to the same claim/budget. A finally path retires every remaining registration on success or failure. No shared permit is consumed twice and no new public claim-permit/budget path is introduced.

These proof/closure classes are opaque issuer identities, never PHP callbacks or caller-built authority. `lock` requires the exact active activation-inspection registration binding that source object, Rows/PDO, claim, principal/actor and owner permit; publicly constructible `MemberOriginalSourceValues` alone cannot authenticate it. `closure` preserves that exact registration and original claim budget/configuration/caps. No authorization method finishes the shared committed frame. Its one-use `postFinish` runs only after the owner's single successful finish, checks the same original claim/configuration/elapsed bound and does not refresh a producer or extend honor. The operation runs authorization/facts/reservation callbacks first, then all raw checks. A pure retained-document verifier used by history performs no current owner/capability/provider/filesystem lookup and grants no current authority.

**Production gate remains explicit:** implementing this technical producer does not settle whether Sean accepts this mode, its exact displayed text, or whether separate license assent is required. Keep production defaults off and approval hashes null. If this proposed mode is rejected, revise the contract/producer before an operative success test; do not replace it with a successful fake or relabel a login as confirmation.

## 4. Required bounded history reader and pure audit API

Use a new fixed `MemberActivationHistoryReader` instead of broadening `MembershipRows` or accepting caller SQL. It owns `MemberGrantCurrentSchema` pin/current/committed proof and qualified captured PDO predicates over the five 258 tables plus the already owned 257 period/redemption/event scope. The existing required-guard/current table proof is preserved; this does not settle the separately documented current inventory of unknown extra triggers.

```php
MemberActivationHistoryReader::capturePeriod(string $periodId, MembershipRows $rows)
    : MemberActivationHistorySnapshot;
MemberActivationHistoryReader::currentCallbacks(Snapshot $s, MembershipRows $rows): void;
MemberActivationHistoryReader::currentRaw(Snapshot $s, MembershipRows $rows): void;
MemberActivationHistoryReader::committedCallbacks(Snapshot $s, IdentityCommittedFrame $f): void;
MemberActivationHistoryReader::committedRaw(Snapshot $s, IdentityCommittedFrame $f): void;
MemberActivationHistoryReader::retire(Snapshot $s): void;
Snapshot::value(): MemberActivationHistory; // verified retained values, not writer/readiness authority
Snapshot::scope(): array; // exact detached {period, events, redemptions}; lists sorted by primary ID
```

`Snapshot` denotes the dedicated class above, not an array supplied by a caller. The reader privately registers it to the exact reader instance and original Rows/PDO/schema capture. Its same snapshot identity serves as the exact registered committed closure; a caller-created or retired snapshot cannot select a frame. `scope()` retains the complete 257 row sets too, despite their exclusion from the new member-only byte budget; operation compares this scope with its independently retained 257 scope using canonical scalar-row normalization and deterministic ordering. Expected data never chooses query predicates. Committed callbacks register/admit the exact read-only frame; committed raw uses its nonlocking READ COMMITTED form. No reader phase finishes the frame or creates a new workflow/honor/observation clock. Retire invalidates only its own registrations, not caller-owned transactions. Callbacks may decrypt/authenticate retained envelopes and pure documents. Raw phases compare complete captured row sets/current schema using captured PDO only. They never perform crypto, renderer/package/filesystem work or finish the frame. New effect admission must load the real empty history too; an omitted/default optional history is forbidden.

Exact pure-policy lane handoff:

```php
MembershipLedgerAudit::verify(array $period, array $events, array $redemptions,
    MembershipInvoiceBinding $invoice, MembershipPolicyBinding $policy, int $originId,
    MemberActivationHistory $history): array;
MembershipPeriodRecords::verify(array $period, array $events, MembershipInvoiceBinding $invoice,
    MembershipPolicyBinding $policy, int $originId, array $redemptions,
    MemberActivationHistory $history): array;
MembershipPeriodRecords::historicalInvoice(array $period, array $firstAward,
    MembershipPolicyBinding $policy, int $originId): MembershipInvoiceBinding;
MembershipReservationRecords::consumeEvent(array $audit, string $redemptionId,
    MembershipPolicyBinding $policy, array $readinessValue, array $actorBinding, string $at): array;
MembershipReservationRecords::consumeKey(string $redemptionId): string;
MembershipReservationRecords::consumeRequest(array $period, array $redemption,
    array $reserve, array $readinessValue): string;
```

`historicalInvoice` authenticates the original sealed period/first-award relationship and returns a semantic-only historical binding; it cannot supply a current provider invoice identity, observation or deadline. The last two consume helpers return the exact key/request digests in §5. All are value/audit functions, not writer admission. `MembershipPeriodRecords::verify` removes its old optional redemption argument as well as requiring history; operation-owned production call sites must be integrated together, never bridged by a default empty history.

Make `MemberActivationHistory $history` a **required explicit argument** to `MembershipLedgerAudit::verify` and `MembershipPeriodRecords::verify`. Update every production caller at once, including award replay and reservation current/committed paths. A pure unit fixture may supply an empty value only as a pure audit input; it proves no DB absence. Production roots must derive it from the complete reader snapshot and include that snapshot in the final raw closure.

Exact history value/audit/binding handoff:

```php
MemberActivationHistory::fromRows(array $origins, array $artifacts, array $activations,
    array $profiles, array $definitions, array $plans, int $redemptionCount): self;
MemberActivationHistory::empty(): self; // pure fixtures only; never DB absence proof
$history->rows(): array;
$history->verifyConsume(array $period, array $redemption, array $reserve, array $consume,
    MembershipInvoiceBinding $invoice, MembershipPolicyBinding $policy,
    int $identityOriginId): MemberGrantBinding;
$history->assertComplete(array $consumeEventIds): void;
$history->original(string $redemptionId): MemberActivatedOriginal;
```

`rows()` returns six detached primary-key-ordered lists under exact keys `origins`, `artifacts`, `activations`, `profiles`, `definitions`, `plans`. `fromRows` authenticates/decrypts their exact records and creates immutable indexes, so it belongs to callback-capable work; it performs no DB read, current policy/catalog/renderer admission or clock lookup. Retained plan parsing uses its authenticated old version, not current approval. A value factory never substitutes for a captured reader snapshot. The complete ledger verifies event seals/order/links/balances, calls `verifyConsume` for every consume, then calls `assertComplete` with the full consume ID list, even when empty. `assertComplete` derives its expected set from immutable authenticated history and never accumulates a visited set. These methods never call back into `MembershipLedgerAudit`; repeated audits are equivalent. V1/v2 consume refuses on both sides. `MemberActivatedOriginal` is a detached package of retained S/F and original activation identities, not proof of current owner or the complete 257 period.

For planned capacity, build a separate immutable history from exactly old `rows()` plus fixed planned rows and any needed retained supporting rows. Run the full post-effect period audit. After insertion, capture a **new real snapshot** and require its complete normalized member rows and 257 scope to equal exactly old + planned effect. Neither mutate an old snapshot nor call a generic expected-row setter. A merely fresh graph cannot replace the expected comparison.

Current limits are `MAX_EVENTS=10000`, fewer than 10000 redemptions, and `MAX_CREDITS=1000000`. Let `R` be the complete authenticated period redemption count. Proposed v1 history limits are:

- Origins <=R; activations <=R; artifacts <=R because this member version has exactly one PDF per origin. Distinct referenced definitions/profiles/plan rows <=R (with zero sets when R=0).
- Each fixed query requests bound+1 and rejects overflow. Deduplicate overlapping scope queries by exact identical primary row; conflicting bytes for the same identity refuse. Never truncate or use a query's first page as a complete proof.
- Fixed bounded key batches <=256 where IDs are needed; stable ORDER BY primary key; use native current locking reads in the effect frame and the distinct nonlocking committed-frame path. Avoid an RR-consistent subquery silently defeating a current outer reader; native acceptance must exercise the actual query form.
- The two proposed 64 MiB budgets apply **only to member-effect history**: unique `(table, primary key)` rows from the five 258 member tables reached by this scope, plus their referenced retained plan documents. Count each physical row once even if several fixed scope predicates find it. The raw budget counts the exact canonical retained-row representation including ciphertext; the decoded budget counts each authenticated canonical plaintext once. Derived cross-reference indexes/copies are not new historical rows, and this is not a claim that total process memory is at most 64 MiB. Reject before unbounded fetch/decode; named bounded projections must refuse an oversized field rather than accept truncation.
- The 257 period, redemption and credit-event row sets are **excluded from those new 64 MiB budgets**. They keep their existing row/envelope limits, `MAX_EVENTS` checks and reservation terminal/expiry-slot guarantee. No new shared aggregate byte ceiling is introduced for them in C4c. The referenced plan document counted above is immutable member verification support, not a way to include the growing 257 event chain in the member budget.
- New-origin plaintext <=2MiB/ciphertext <=4MiB; artifact plaintext <=65536/ciphertext <=262144; activation plaintext <=262144/ciphertext <=524288. Preserve existing C4b profile/definition limits. These are new codec maxima, not changes to installed LONGTEXT columns.
- A new activation must prove the **post-effect member graph** fits those same member-only row/byte budgets before inserting. Otherwise refuse `history_capacity`, leaving existing reservations/effects unchanged. Only an activation adds in-scope member rows; ordinary later reserve/release/expire operations add no member history and cannot exhaust this byte budget. A separately authored, unreferenced profile/definition does not enter an old period's history until a new activation explicitly references it. Every member-history-growing writer must use the same post-effect check; future operations must not silently broaden the scope or budget.
- Preserve guaranteed 257 cleanup capacity independently: reserve retains one future terminal slot per live reservation and the existing finite-period expiry slot. Consume spends the already reserved terminal slot for its own redemption and reduces the live-reservation count by one; it must not borrow another reservation's terminal or the period's expiry slot. Re-audit this invariant after the planned consume. A new shared 257/member byte cap, if desired later, requires worst-case remaining terminal/expiry byte headroom and admission checks in every growing writer first; it is expressly outside this proposal.

Do not load only the rows named by consume events. Fixed scope closure must include:

1. All period redemptions and events, including every non-null declared grant origin ID.
2. Origins selected by any of: redemption in that period; origin ID equal to a period redemption ID; matching the period source invoice hash; declared grant origin ID; an activation whose redemption belongs to the period.
3. Every activation whose origin is in that origin set **or** whose redemption is in the period; every artifact whose origin is in that set.
4. All referenced retained profiles/definitions and their exact retained plan document; missing, foreign, extra, duplicate or cross-period joins refuse.

Implement that closure with fixed separately current predicates/joins and complete-set comparisons, not an arbitrary passed list that can omit a surplus row. Reading an incorrectly cross-linked foreign row is reason to refuse, never permission to adopt it. Unrelated valid origins for other periods remain out of scope. Standalone consume, orphan origin, extra artifact, unconsumed activation and a row with misleading cross-links are required negative cases.

Pure audit requires a bijection among consume events, origins and activations, exactly one PDF artifact for each accepted origin, one terminal event per reserve, exact row/payload/seal/hash relationships and conserved nonnegative balances. Non-consume events retain null grant columns. V1/v2 reject consume regardless of shaped history. The v3 consumed balance is the sum of its fully verified consume amounts, not an exception to the chain equations.

Historical verification uses retained versions and activation-time constraints. It does not re-run today's catalog eligibility, current approved-profile hash, unexpired honor, current paid-provider status or today's authorization confirmation. A missing original file may block a byte-bearing response but cannot undo consumed history. Use the policy-owned `MembershipPeriodRecords::historicalInvoice` decoder for the original semantic invoice when replay has no live invoice proof: it verifies the period seal/canonical original payload and first-award joins, returns only a historical `MembershipInvoiceBinding` value, and preserves original T23 verification fields. It is not fresh paid-invoice authority and cannot enter the new-effect route. Do not satisfy replay by refreshing or inventing a current C3 settled proof.

## 5. Exact acyclic hash and record contracts

All hashes below are lowercase SHA-256 of the exact strict `CanonicalJson::encode` preimage. Maps use canonical key ordering and lists retain order. Table/record keyed seals authenticate every physical row column other than the seal; ciphertext's authenticated plaintext binds every semantic/public column. Existing 257 event seal domain stays unchanged. New 258 effect records use their own versioned, table-separated seal domain; never reuse profile/definition authoring domains.

Define `S` as the exact C4b source document, `H(S)` its existing render source hash, `A` as the fully verified authorization document above, `P` as the actual retained v3 policy document/binding, and `M` as the **recomputed canonical manifest preimage** `{schema_version: member-original-artifact-manifest-v1, family, purpose, origin_id, originals}` used by C4b. Its `H(M)` must equal the actual `MemberOriginalArtifactManifest.manifestHash`; a constructed manifest DTO is not verification. C4b manifest preimage and PDF/path stay unchanged. `originId = S.redemption.id`, with the existing UUIDv4 validation.

**Readiness commitment R**, exact keys:

```
{schema_version: member-activation-readiness-v1, family, purpose, provenance,
 origin_id, redemption_id, redemption_seal, period_id, period_seal,
 reservation_event_id, reservation_event_seal, render_source_hash,
 source_invoice_hash, policy_hash, consumption_terms_hash, retention_policy_hash,
 authorization_document_hash, authorization_intent_hash,
 definition_id, definition_hash, profile_id, profile_hash,
 artifact_manifest_hash}
```

For a **new effect**, every value is recomputed from its admitted sources/materialization and the exact admitted authorization document. `H(A)` includes that confirmation's random authorization UUID and captured time, so R, its readiness hash and the consume request are deliberately specific to that successful authorization; they are not stable across independently confirmed equivalent intents. Winner recognition compares the stable `intent_hash`, exact source/definition/redemption/owner bindings and the complete audited stored graph. It then reuses the winner's retained A, R, consume row and D without substituting the loser's authorization or requiring the loser's freshly computed readiness/request hash to equal the winner's. The history auditor recomputes R from the **winner's retained A/S/M**, not from a fresh ticket. This preserves both truthful first-author evidence and same-intent convergence. Historical T23 verification identities remain transitively and explicitly bound through S/A. Exclude fresh C3 retrieval IDs/seals, live proof IDs, monotonic observations, child PIDs, random artifact/activation-row IDs and future ciphertext/seals. They stay private current admission evidence, not deterministic commitments. Set consume `grant_receipt_hash` and activation `readiness_receipt_hash` to `H(R)`, with the existing member purpose.

**Consume key** is exactly `H({purpose: membership-credit-consume-event-v1, redemption_id})`. **Consume request** is exactly:

```
{purpose: membership-credit-consume-v1, period_id, period_seal,
 redemption_id, redemption_seal, intent_hash, reservation_event_id,
 reservation_event_seal, grant_origin_id, readiness_receipt_hash,
 authorization_intent_hash, honor_deadline}
```

Add a pure `MembershipReservationRecords::consumeEvent(audit, redemptionId, policy, readinessValue, actorBinding, at): array`. `readinessValue` is a pure recomputed value, never a SQL permit. Builder requires actual v3 terms, nonterminal reserve, original amount, sufficient reserved credit and `at < honor_deadline`. It changes only reserved-=amount and consumed+=amount; available/expired stay equal. It uses the exact prior tail/next sequence, reserve ID, origin ID, member purpose and existing event seal. This helper is not a public consume writer.

All three new effect record plaintexts have exactly this envelope shape, with no extra top-level keys:

```
{schema_version, document, row_binding}
```

The exact envelope `schema_version` is `member-origin-record-v1`, `member-original-artifact-record-v1` or `member-activation-record-v1` for its corresponding table. `document` is the exact semantic document specified below; `row_binding` is a distinct exact object containing **all** of that table row's physical column names and values except `payload_ciphertext` and `seal`. Required nullable columns remain present with null values; neither missing nor extra keys are accepted. Normalize physical `row_binding` scalars exactly as existing `MemberGrantRecords::strings`: every non-null physical column becomes its canonical string representation; null remains null. Validate every column strictly before that representation step, including the allowed canonical decimal form of native/PDO integer columns. This is cross-driver row normalization, not coercion of semantic document integers or admission of booleans/unknown scalar shapes. Builders and readers use the identical normalization so PDO string/int transport does not change authenticated meaning. The table-specific codec fixes these names from the inspected schema, authenticates the full envelope and binds its exact table/version in the row seal. The envelope's canonical plaintext, including both document and row binding, must fit that record's declared plaintext bound. Semantic document hashes never silently include a surrounding envelope or a later ciphertext.

**Origin semantic document O:** `schema_version = member-origin-v1`, exact keys:

```
{schema_version, family, purpose, provenance, origin_id,
 source, render_source_hash, artifact_manifest, readiness_commitment,
 authorization, policy: {plan_version_id, policy_hash, consumption_terms_hash,
                        retention_policy_hash},
 actor_binding, created_at}
```

`source` is S, manifest is M, readiness commitment is R, and authorization is A. Verify all public columns against these objects, including account/user, **numeric durable T23 identity-origin row ID** (not the public UUID), invoice identity, selected manifests, intent, honor, artifact count/hash, definition/profile and provenance. Resolve the numeric identity-origin from the real historical owner producer. The origin envelope must retain that numeric ID as the actual `row_binding.identity_origin_id`; it must not be guessed from `buyer_binding.origin_id`, which is a UUID. The origin plaintext is exactly `{schema_version: member-origin-record-v1, document: O, row_binding}`; do not append row-binding keys to O or outside the stated envelope.

**Artifact semantic document F:** `schema_version = member-original-artifact-v1`, exact keys:

```
{schema_version, family, purpose, provenance, artifact_id, origin_id,
 ordinal: 0, role: license_pdf, sha256, bytes, storage_policy_hash,
 render_source_hash, profile_hash, derived_relative_path, created_at}
```

Its UUIDv4 is minted once inside the owned effect. The relative path is derived exclusively from admitted source and must exactly equal the unchanged C4b layout; no caller path or permanent dev/inode claim. Artifact row IDs, seals/ciphertext/time do not enter M, PDF bytes or candidate path. The artifact plaintext is exactly `{schema_version: member-original-artifact-record-v1, document: F, row_binding}`. **F.profile_hash means the technical render-profile hash** `H(S.profile.document.render_profile)`, matching C4b file observation; R/D, the authored definition/profile identity and `MemberGrantBinding.profileHash` instead bind the full authored profile hash `H(S.profile.document)`. The full authored profile remains retained in S and bound through R/D. These are intentionally distinct hashes; no extra F key or frozen profile change is introduced.

**Activation document D**, exact keys:

```
{schema_version: member-license-activation-v1, family, purpose, provenance,
 activation_id, origin: {id, seal}, redemption: {id, seal},
 period: {id, seal}, reservation_event: {id, seal},
 consume_event: {id, seal},
 definition: {id, hash}, profile: {id, hash},
 render_source_hash, artifact_manifest_hash, readiness_receipt_hash,
 authorization_document_hash, authorization_intent_hash,
 consumption_terms_hash, retention_policy_hash,
 artifacts: [{id, seal, ordinal: 0, role: license_pdf}],
 actor_binding, activated_at}
```

`MemberGrantBinding.originalArtifactHash = H(M)` and `activationHash = H(D)`. Activation record plaintext is exactly `{schema_version: member-activation-record-v1, document: D, row_binding}`; its row seal includes ciphertext. `activationHash` excludes activation ciphertext/seal and excludes itself. No hash cycle exists:

```
S/A/M -> R -> origin row/seal -> artifact row/seal -> consume row/seal
      -> D / activationHash -> activation row/ciphertext/seal
```

Origin must not include consume/activation row IDs or hashes; otherwise this ordering becomes cyclic. The activation row joins `reservation_event_hash = reserve.seal`, the exact member purpose and manifest/readiness hashes. Capture one trusted `effectAt` before effect encryption/UUID callbacks and require it >= confirmation time, reservation time and prior-tail time, and < retained honor. Refuse clock regression instead of taking max(now, future row time). All three semantic effect times may equal effectAt; the actual final raw boundary still checks current business honor and original monotonic limits after waits/callbacks. An old precomputed timestamp never licenses a late write.

Existing public `MemberGrantBinding` can remain unchanged. The new issuer mints its provisional receipt only from this complete planned graph and current readiness, before the fixed writer consumes its one-use permit. Retained committed replay reads actual stored IDs/D hash; it never rebuilds random IDs to predict the winner.

Exact effect-codec/readiness/binding handoff (M namespace):

```php
MemberActivationReadiness::from(array $value): self;
MemberActivationReadiness::forSource(MemberOriginalSourceValues $source,
    array $authorization, MembershipPolicyBinding $policy,
    MemberOriginalArtifactManifest $manifest): self;
$readiness->value(): array;
$readiness->hash(): string;
MemberActivationRecords::readiness(array $value): array;
MemberActivationRecords::origin(MemberOriginalSourceValues $source, array $authorization,
    MembershipPolicyBinding $policy, MemberOriginalArtifactManifest $manifest,
    int $identityOriginId, array $actorBinding, string $effectAt): array;
MemberActivationRecords::artifact(array $origin, MembershipPolicyBinding $policy,
    string $artifactId, string $effectAt): array;
MemberActivationRecords::activation(array $origin, array $artifact, array $consume,
    MembershipPolicyBinding $policy, string $activationId, array $actorBinding,
    string $effectAt): array;
MemberActivationRecords::readOrigin(array $row, MembershipPolicyBinding $policy): array;
MemberActivationRecords::readArtifact(array $row): array;
MemberActivationRecords::readActivation(array $row): array;
MemberActivationRecords::binding(array $origin, array $artifact, array $consume,
    array $activation, MembershipPolicyBinding $policy): MemberGrantBinding;
```

`from`/`readiness` are strict detached shape validators; `readiness` delegates to `MemberActivationReadiness::from($value)->value()`. Policy consume helpers use that validator plus their own period/reservation/policy joins. The operative value factory is `forSource`, which revalidates A using the operation-owned codec, recomputes M from actual source/materialization and constructs R. No pure factory mints readiness authority. The origin builder recomputes R/M rather than trusting an input digest; artifact derives its values from authenticated O/S/M. The operation mints artifact/activation UUIDs once and supplies them. Builders return exact physical rows, and readers return detached O/F/D after complete envelope/seal validation. Encryption/authentication may invoke callbacks and occurs before terminal raw checks. Builders never insert rows or admit a transaction.

`binding` checks the complete supplied origin/artifact/consume/activation graph and returns existing non-authority `MemberGrantBinding` with H(M)/H(D), exact retained invoice/definition/profile/manifests and original buyer binding. It cannot replace complete period audit, current issuer registration or committed proof. The provisional writer receipt is registered only by the actual operation against its exact owned planned graph; retained replay uses the stored winner graph.

## 6. One-way live-to-exact-effect transition

The reconciled 453c1af8 source retains two relevant facts: `MemberOriginalSourceCapture::capture/complete` consumes permits registered by `MemberOriginals`, and `MemberOriginalInspection` is a final read-only class whose member source requires `terminal === null`. A new caller cannot simply invoke those internals or append a consume and then run that same pre-effect proof unchanged.

The only product delta from f4732dbe is the author 9399dd99 / integrated 32f083d2 private initialization correction:

- `MemberOriginalInspection` no longer calls a duplicate full `proveCurrent()` after constructing detached source values. Those values remain inside its exact registered inspection. `MemberOriginalSourceCapture::readyInspection()` still runs the complete combined current proof before readiness can be issued or reproved. The normal committing observer independently runs that full proof after ordinary delegates, before a source ticket can leave its capture.
- `MembershipReservationContext` defers its constructor's duplicate full proof only for an exact `MembershipMemberCapture` supplied through `inspectMember()` after consuming the issuer permit. The private inspection permit binds that capture identity along with all existing Rows/configuration/principal/actor/arguments/deadline identities. A caller cannot opt in using a shaped object or boolean. Public inspection and reserve/release/expire retain their full constructor proof; non-inspection operations require the optional member-initialization value to be null.
- The private deferred branch immediately calls `checkDeadlines()` and `admit()`. It captures and enforces original paired honor/authority limits before later member-facts callbacks, rather than delaying those limits until readiness. The outer inspection still shortens its original budget to the captured honor bound.

C4c must preserve this boundary: constructor completion alone never issues confirmation, readiness, a source ticket or a write permit. Its proposed review/confirmation capture and activation inspection each require their private entry registration and complete combined proof at the actual publication/readiness boundary. Do not add a general public pending-initialization flag, waive another producer's raw closure, or restore repeated constructor proof loops merely to call the old path. Existing full current ordering remains facts callbacks → reservation callbacks → all raw checks → configuration/frame/elapsed checks. The distinct committed path retains all callbacks followed by all raw checks, exact frame ownership, one finish, and post-finish configuration/elapsed admission. New authorization/history phases must occur before terminal raw checks.

The 60-second source-frame cap, original 300-second workflow budget, captured physical observation limit and min-only honor carry remain unchanged. No constructor deferral restarts or extends them. Initial facts-withdrawal still refuses through the capture's committing closure; final facts-withdrawal refuses in readiness before attempting the final normal commit. The two new Composition regressions and direct forged-initialization refusal case preserve those distinct boundaries. All committed/post-finish checks remain required after constructor/readiness success.

Keep the existing C4b path complete and read-only. Add separately named activation entry/capture permits and a separate `MemberActivationInspection`. Do not introduce a generic public operation string, callback writer, Context getter or `setExpectedRows(array)` API. A private registry admits only actual instances registered by `MemberGrantActivations`, with exact complete arguments, authorization claim, service instances, original budget, Rows/PDO and original observer.

Proposed narrow shared seam (all token classes opaque/private-registry owned):

```php
// C4b source owner; separate activation-owned registration, never first-arbitrary-consumer binding.
MemberOriginalSourceCapture::captureForActivation(MemberActivationEntryPermit $entry,
    string $definitionId, string $redemptionId, Principal $principal, User $actor,
    MemberLicenseAuthorizationClaim $authorization, MemberOriginalBudget $budget): MemberOriginalSource;

// Separately registered final owned frame; the initial capture above remains effect-free.
MemberOriginalSourceCapture::completeActivation(MemberActivationCompletionPermit $entry,
    MemberOriginalMaterialization $materialized, string $definitionId, string $redemptionId,
    Principal $principal, User $actor, MemberLicenseAuthorizationClaim $authorization,
    MemberOriginalBudget $budget): MemberGrantActivationResult;

// Reservation issuer; these cannot be called with ordinary C4b capture identities.
MembershipReservations::beginActivationCapture(MembershipActivationCapturePermit $permit,
    string $redemptionId, Principal $principal, User $actor, MembershipRows $rows)
    : MembershipActivationCapture;
MembershipReservations::planActivationEffect(MembershipActivationCapture $capture,
    MemberActivationEffectPermit $permit, MembershipRows $rows): MembershipActivationTransition;
MembershipReservations::proveInsertedActivation(MembershipActivationTransition $transition,
    MembershipRows $rows): void;
```

The effect permit refers to the exact private planned effect registered by the activation Context; there is no caller-supplied record-array setter. Add the corresponding named current/committed callback/raw/post-finish methods for the new capture. They retain the original invoice/catalog/owner/plan proofs and caps. A source-owner return type that must cover both read-only and activation inspections may use the explicit two-class union; arbitrary implementations never enter the private active registry. `MemberOriginalArtifacts` still requires exact registered issuer/inspection/intent/facts/Rows/principal/actor matches, with all its original observation/workflow checks.

The current reservation Context requires `terminal === null` in its ordinary inspect/reserve deadline branch, and its generic new-event branches distinguish reserve/release/expiry. Activation therefore requires a **separate privately registered transition/newConsume state**, not relabeling consume as an ordinary newEvent or globally relaxing terminal checks. Inserted state permits only the exact planned consume while keeping original honor/caps; authored honor beyond credit expiry remains meaningful for already reserved credit. Ordinary writers and C4b keep their existing branches.

The operation lane owns the complete changes on both sides. Transition states are:

1. **live**: full pre-effect ledger/history and nonterminal reservation, actual materialization and same-frame readiness, actual fresh authorization proof.
2. **planned**: all origin/artifact/consume/activation rows, IDs, ciphertext and hashes built; post-effect capacity/audit validated as pure data; all callback work complete; one raw source/config/schema/owner/frame/deadline closure. The write permit is single-use and cannot escape.
3. **inserting**: fixed captured-PDO inserts only, in the required order. No external success or committing admission accepts this partial state. A reentrant commit/callback or repeated permit refuses.
4. **inserted**: exact complete physical rows reread and verified against the plan; advance once. Expected retained state becomes exactly old history + those new rows and old credit events + that one consume, with the same period/redemption bytes. Every other added/changed row refuses. The ordinary C4b inspection cannot enter this state.
5. **committed/retired**: current tokens die with their original frame. A distinct registered committed proof checks the exact persisted effect, finishes one committed frame, then performs post-finish configuration/elapsed admission. No branch renews the original workflow, physical observation or honor cap.

`SealedMemberGrants implements MemberGrantAuthority` keeps the existing public prepare/proveReadyCurrent signatures, but `prepare` consumes the exact private registration from this activation Context. It is not a second public activation route. Its readiness token is provisional and coupled to the exact planned effect and transition; the public standalone result is produced only after committed verification. Any constructor or directly invoked prepare without registration refuses before writes.

All callback-capable authorization, facts, reservation, retained-history and configuration phases precede every terminal raw closure. Preserve C4b's min-only `memberHonorDeadline()` carry through detached work and `memberPostFinish()` elapsed/configuration check after the single finish. A future consumed-state proof does not re-run a live-only `reservationBinding` check; it compares the fixed expected terminal effect and unchanged pre-effect sources instead. This is a new controlled state, not a relaxation of ordinary reservation admission.

## 7. Replay, byte observation and error outcomes

`MemberGrantActivationResult` contains exactly originId, redemptionId, definitionId, consumeEventId, activationId, artifactManifestHash, activationHash, and status=`activated`. It is a value with no path/download credential or live proof. New success and exact retained replay use the same retained IDs/hashes; an optional `replayed` diagnostic must stay outside semantic identity if added later.

Under current owner admission, inspect the complete retained graph before deciding new versus replay. `activate` consumes the exact issuer-registered ticket claim once per attempted workflow and checks its bound stable semantic intent before this branch. The separate effect/write permit is consumed only for a new effect. A repeated ticket cannot become replay authority; a caller without a fresh, genuine confirmation ticket uses the separately admitted `readActivated` API. An exact already-activated request returns only after historical/committed read proof and an actual **read-only** original observation; it does not consume another authorization or credit and does not rerender. A competing confirmed request with the same stable semantic intent/source/definition/owner can return the first activation, retaining the winner's original A/R/D and all row IDs. Do not compare the loser's authorization-document hash, readiness hash or consume request hash to the winner's: those include distinct confirmation evidence even when intent is identical. Another definition, owner or semantic intent refuses. Partial graphs refuse corruption, never repair/adoption. If a new-effect workflow loses a race after rendering, any fresh lookup is an explicitly bounded read of the winner, not an automatic retry of the writer or a renewed original deadline. Preserve/report any bounded loser refusal precisely.

Both entry paths decide retained replay under current T23 owner admission and complete retained history **before** attempting any live-only reservation lock. A consumed replay must not enter `MemberOriginalInspection`, current invoice/catalog/member-facts admission or the new-effect approval/renderer gate. Add a separate `MemberActivationHistoryInspection` and read configuration for the sequence: current owner + retained graph → detached read-only byte observation → fresh final owner + same retained graph proof → shared committed closure → value result. Retained historical documents supply old policy/profile/source facts; this path requires neither current approved hashes nor current renderer availability nor live honor. Its configuration/capability/owner/physical observation checks remain real and bounded. The same configured service cannot silently switch from this read path to writes. This named historical workflow is an explicit proposal refinement awaiting review.

Provide a separate named retained-original adapter that verifies the sealed historical relative path, size/hash/profile/source/storage identities without calling storeOrVerify, creating directories, repairing or rerendering. A new read workflow can start a bounded observation for an existing original; it never makes an old readiness token live. Missing/changed bytes cause a read/receipt refusal while credit and activation remain durable. Historical readers use retained member profiles purely; they do not require today's approval hash or a functioning current renderer to recognize old history.

Exact history-lane original-observation handoff:

```php
MemberRetainedOriginals::observe(MemberActivatedOriginal $original, MemberOriginalIoFrame $idle,
    MemberOriginalBudget $budget): MemberRetainedOriginalObservation;
MemberRetainedOriginals::verify(MemberActivatedOriginal $original,
    MemberRetainedOriginalObservation $observation, MemberOriginalIoFrame $idle,
    MemberOriginalBudget $budget): void;
MemberRetainedOriginals::assertCurrent(MemberActivatedOriginal $original,
    MemberRetainedOriginalObservation $observation, MemberOriginalBudget $budget): void;
```

`observe`/`verify` are idle I/O work before the fresh final DB receipt inspection; they may resolve configuration and inspect native wrappers. They open only an existing derived private path, checking native ownership/permissions/single-link/device/inode, bounded PDF framing/size/hash, historical source/technical profile/storage relations and unchanged bytes. They never create directories, store/repair/rerender or return download authority. The new `MemberRetainedOriginalObservation` omits pageCount because retained F/M deliberately omit it; do not fabricate a count or rerender to satisfy `MemberOriginalFileObservation`. A minimum existing read-helper extraction from `MemberOriginalFiles.php` requires lead coordination; frozen resources/render-profile members stay unchanged.

Only `assertCurrent` runs around the final raw receipt closure and after finish. It is terminal-safe: exact private adapter/original/observation/budget registration plus native monotonic time against `min(first native read + 60 seconds, original budget)`. It performs no configuration/facade/filesystem/frame-getter call and never renews a cap. The new observation has its own bounded history-read lifetime and cannot revive old readiness. Current owner/complete graph/committed proof remain the operation's responsibility.

Before commit, an error rolls back only the original owned transaction; preserve a callback-created foreign transaction/delegate. After commit, a graph/clock/configuration/file receipt error is `committed_activation_receipt_refused` (proposed exact reason), with no automatic compensation, release, refund, second consume or file deletion. A later explicit read can recover the existing result. Database and filesystem observation remain non-atomic; no continuous host-integrity or durable-storage claim follows.

## 8. Split, review gates and first acceptance cases

No code starts before independent approval of this revised contract and explicit lead release. The lead-authorized exception permits isolated local implementation on the exact reviewed `a108aca947ae0d3f661e9feef8a19441d5c3a165` tree while host publication authentication is restored. C4c integration/publication still waits for actual C4b preflight/merge and source-delta reconciliation. The lead proposes five exclusive lanes. `P/` is `app/Domain/Memberships/Production/`; `M/` is `app/Domain/Grants/Member/`. Names below are proposed source/API ownership, not a claim that new files exist. A separate owner edits each shared existing file; lanes exchange typed API contracts and commits.

| Lane | Owned source/files | Handoff |
| --- | --- | --- |
| Pure policy — billing_award_fence | Existing `P/MembershipPolicyDocument.php`, `MembershipPolicyBinding.php`, `MembershipReservationRecords.php`, `MembershipLedgerAudit.php`, `MembershipPeriodRecords.php`; new `P/MembershipConsumptionTerms.php`. New `tests/Unit/MembershipConsumptionPolicyTest.php`, `MembershipConsumeLedgerAuditTest.php`, `MembershipHistoricalInvoiceTest.php`; existing `tests/Unit/MembershipLedgerAuditTest.php` and `tests/Support/MembershipPolicyFixtures.php` only as needed. | Strict detached v3 terms/binding; exact consume builders; mandatory history audit inputs; authenticated historical semantic invoice decoder. No live Context or DB-reader edits. |
| Effect codecs and retained history — member_activation_history | New `M/MemberActivationReadiness.php`, `MemberActivationRecords.php`, `MemberActivationHistory.php`, `MemberActivationHistoryReader.php`, `MemberActivationHistorySnapshot.php`, `MemberActivatedOriginal.php`, `MemberRetainedOriginals.php`, `MemberRetainedOriginalObservation.php`; focused codec/history/read-only file tests. Any minimal existing `M/MemberOriginalFiles.php` native-read extraction needs lead coordination. | Exact §4/§5/§7 value/codec/reader/observation APIs. The snapshot itself is the registered committed closure; no separate broad closure class or effect setter. No live operation entry or credit-event writer. |
| Authorization and operation — membership_award_operation | Existing `M/MemberOriginalSourceCapture.php`, `MemberOriginalArtifacts.php`; existing `P/MembershipReservations.php`, `MembershipReservationContext.php`, `MembershipAwardContext.php`; `tests/Support/MembershipReservationFixtures.php`. New `M/MemberGrantActivations.php`, `MemberGrantActivationResult.php`, `SealedMemberGrants.php`, the activation/authorization types enumerated below and associated issuer/phase/transition tests. | Owns the whole genuine review/confirm issuer, assembly, exact entry/observer/inspection/transition and all current/committed audit callers. Shared C4b capture/artifact edits are limited to named entry and exact inspection type support; C4b wrappers remain complete. No passing fake producer or optional empty-history bridge. |
| Real-producer integration | New `tests/Support/MemberActivationFixtures.php` and focused `tests/Feature/ProductionMemberGrants/` integration/replay/native-race files plus a dedicated native worker if needed; coordinate any shared-fixture change before editing. | Real retained v3 plan/billing/award/reservation, real MFA member facts, actual review/literal-confirmation issuer, real C4b child/files and actual writer. Synthetic business inputs remain explicit; native work uses the lead's serialized slot. |
| Lead, evidence and separate review | CI selection/skip/census files, verification packet and plan/deferred ledgers; independent API/source/evidence decisions. | Freeze exact APIs after independent review; release isolated local owners only on the reviewed base; reconcile and integrate after actual C4b preflight/merge, then focused source-bound checks, cheap preflight and exact-head merge. No historical migration changes, frozen renderer/profile re-pins, mounted routes/downloads or activation-default changes. |

Operation-owned new type files under `M/` are `MemberActivationConfiguration`, `MemberActivationInspection`, `MemberActivationObserver`, `MemberActivationCommittedProof`, `MemberActivationEntryPermit`, `MemberActivationCompletionPermit`, `MemberActivationEffectPermit`; `MemberLicenseAuthorizations`, `MemberLicenseConfirmationInput`, `MemberLicenseReviewDisplay`, `MemberLicenseAuthorizationRecords`, `MemberLicenseAuthorization`, `MemberLicenseAuthorizationClaim`, `MemberLicenseAuthorizationProof`, `MemberLicenseAuthorizationClosure`, `MemberLicenseReviewEntryPermit`, `MemberLicenseConfirmationEntryPermit`, `MemberLicenseReviewCapture`, `MemberLicenseConfirmationCapture`; and separate `MemberActivationHistoryInspection` and `MemberActivationReadConfiguration` types. Under `P/`, it owns new `MembershipActivationCapture`, `MembershipActivationTransition` and `MembershipActivationCapturePermit`. Each type uses a same-named `.php` file. These two previously unnamed helper files are fixed as `M/MemberActivationReadConfiguration.php` and `P/MembershipActivationCapturePermit.php`; no shared generic transaction primitive, renderer or profile-registry changes are planned.

The history and operation owners supplied compatible API notes and the policy owner confirmed the shared readiness validator. This is an implementation handoff proposal, not the separate independent contract approval or lead release. The independent review should specifically assess the new confirmation/completion/replay seams, one-use permit lifetimes, canonical row-binding representation, distinction between authored and technical profile hashes, and terminal-safe retained observation.

The lead prepared the clean isolated worktree `/workspace/VA-Studio-member-activation` on `harness/member-activation-20261013` at reviewed `a108aca947ae0d3f661e9feef8a19441d5c3a165`; read-only verification confirms that HEAD and a clean worktree. No implementation has started. The accepted evidence protocol is `/workspace/runtime/member-originals-c4c-evidence-protocol-20261013.md`, SHA-256 `9a3cde278e976762f42dbf9836d4a59845107b009f8e6669095a6f4640294d21`. Its eventual execution remains subject to the lead's release and serialized database slots.

The operation owner holds all existing live caller edits; pure audit and history owners may supply signatures and reference diffs but must not concurrently edit those callers. The proposed concrete authorization capability, private confirmation/completion seams and historical-only inspection are explicit refinements awaiting independent review. They do not settle commercial authorization or grant permission to start implementation.

First required tests: constructor-only empty-operation compatibility; nested-reference detachment for new v3 values/bindings; v1/v2 exact parser/canonical acceptance compatibility and consume refusal; the v2/v3-only total-byte cap; strict v3 modes/unknown keys; exact three-key effect envelopes and exact document/row-binding keys; real owner review then explicit confirmation with stale/wrong display/source/owner refusals; one successful graph and conservation; real MySQL `origin.id === redemption.id` joins; all four write-step rollbacks; exact post-consume audit in award/reserve/release/expire callers; independently confirmed same-redemption races with distinct authorization UUID/time and exact winner A/R/D reuse; near-cap member history followed by successful reserved-terminal/expiry cleanup without member-byte growth; consume versus release before/at honor; all surplus/missing/mislinked history graphs; old honor/current delisting replay; postcommit refusal with one durable graph; no regeneration of a missing retained original; private guard/table/shadow currentness; and no callback after another producer's final raw closure. Synthetic clock and confirmation fixtures must be described as such.

Unresolved gates are independent approval of this revised contract and lead release before any isolated implementation, actual C4b publication/preflight/merge plus delta reconciliation before C4c integration/publication, and the unchanged unanswered production authorization/commercial inputs. The proposed 64 MiB history capacity and new envelope limits need explicit review and focused boundary tests before freezing APIs. The owner-confirmation technical mode and its legal applicability are separate decisions: the former is an implementable proposed contract; production legal/commercial approval remains an unanswered input. Later async authorization, assent-specific storage, cancellation/continuity, effective-date changes or different PDF content require their own reviewed version/schema/profile; none is silently included here.

## Historical inspected source inventory at f4732dbe

These 24 hashes preserve the original checkpoint used by the earlier contract review. They are historical; the corrected final inventory below explicitly records changed application files and additional inspected observer/regressions. Inventories bind source inspection, not tested behavior.

Design MD SHA-256: `7ba1c969df51c1a3ae454c03bb5bb81e6eafe5941d29f5e6324199b7bc23f31f`.

| Path | SHA-256 at f4732dbe |
| --- | --- |
| `app/Domain/Memberships/Production/MemberGrantBinding.php` | `e8ec6951a419ed46ecffb537aca2ac323367b2b346efe1ca939c8585a0a3bc61` |
| `app/Domain/Memberships/Production/MemberGrantReceipt.php` | `ee87a595896faba469ef06f563e39c79f68248bbbb7c53c64aa6c684baa37926` |
| `app/Domain/Memberships/Production/MembershipPolicyBinding.php` | `96b62f5f6b3fc4603fd9df5f1a4c0c5f6060e20e54c3e8249ce04e0d999c8e86` |
| `app/Domain/Memberships/Production/MembershipPolicyDocument.php` | `1545367e9c167458e401ba90950c286e8fadb7aa9918360de9170328e613092e` |
| `app/Domain/Memberships/Production/MembershipPolicy.php` | `21528d642460d812965a4302096699b6736a328bdf2af63d01af4e195e28fc7f` |
| `app/Domain/Memberships/Production/MembershipLedgerAudit.php` | `8c44b2d53d8b0178ac3a0c9657f856712523cb68c56d62e3f3013043d1980443` |
| `app/Domain/Memberships/Production/MembershipReservationRecords.php` | `949fa4a9276bebc4f19dbfe93b5c538e593a317075dc3084bdfa26fdd888a2ea` |
| `app/Domain/Memberships/Production/MembershipPeriodRecords.php` | `8ff55a353e824b55e93e5407fc28851e880b4dc0e5bd7afecc26dc4fa79a9f5d` |
| `app/Domain/Memberships/Production/MembershipAwardContext.php` | `af9136988eb12239f9cb84015bfb44c92527364a1bf8424c4b0b2feebd6c221e` |
| `app/Domain/Memberships/Production/MembershipReservationContext.php` | `8645feeb3e020f71a66f741e6e45f22a81e0a751898a6305d94e11822325d37a` |
| `app/Domain/Memberships/Production/MembershipReservations.php` | `491df724395f74fe91a066b56e77e7811e678734138b0b1a5c8fb5912caa11b2` |
| `app/Domain/Memberships/Production/MembershipRecords.php` | `c46a4699d7075e4de83519ac82cbe45708091a85040dfc2dcb221d0675a1f4ec` |
| `app/Domain/Grants/Member/MemberGrantSchema.php` | `b5c6fcb8f1c1e1bcf553400b0b4410a381609b078c2cb8df484fd5bdbb58da3a` |
| `app/Domain/Grants/Member/MemberGrantRows.php` | `dda1fd3f59e3d49413c047fda118060bec1332192e9626f8ce1b5c41f615e2f1` |
| `app/Domain/Grants/Member/MemberGrantRecords.php` | `d13120373154423fb09181448d8790c87c7ee3c05fc553810c0b5788ab8667dd` |
| `app/Domain/Grants/Member/MemberOriginalSourceCapture.php` | `0b769153caed6b5164b2eed2bfaf2eb6c95cfeeae42d77c4b6d079996aa57f2f` |
| `app/Domain/Grants/Member/MemberOriginalInspection.php` | `976f961bb6ff90d498c42b71da055a4c1ded9fc397994aa752372835936e2627` |
| `app/Domain/Grants/Member/MemberOriginalArtifacts.php` | `73e586cd321da31a929fcd5a4bf96d9a4d86a415cfee889ecddbefebfc976451` |
| `app/Domain/Grants/Member/MemberOriginalSourceValues.php` | `3aaf06cf5d36fe419e300e4413933edb12ee3ecec705b6e71116c89284de7748` |
| `app/Domain/Grants/Member/MemberOriginalText.php` | `959d90054dfb6513dff278359d4d27709da4b0131faef674e2cdd6a6689902da` |
| `app/Domain/Grants/Member/MemberOriginalArtifactManifest.php` | `796fb3bd257c84a857821209a6714f7fe2b6fddb05ae1930f117d6fc4526ef60` |
| `app/Domain/Grants/Member/MemberOriginalSource.php` | `f6287644e3ed625f3e1fe457212c9ce44a34ac8be5f301a8deca28008e1edcad` |
| `app/Domain/Grants/Member/MemberOriginalBudget.php` | `fc8d0306fdec3f0235e2b225ff6789f31301490cded976330cffb700036aff2a` |
| `tests/Feature/ProductionMembership/MembershipPreparationTest.php` | `04a983c3d976b6914203a40998cea009b34acc1b02416a118d1d01807800b8ba` |

## Reconciled frozen source inventory at 453c1af8

The later reviewed `a108aca947ae0d3f661e9feef8a19441d5c3a165` has identical hashes for all 27 source entries below and the same complete named runtime/test/dependency trees. Its later review/evidence documentation is not silently substituted for the six 453c1af8 receipt/document blobs recorded here.

Read-only exact Git comparison over `app/`, `bootstrap/`, `config/`, `database/`, `scripts/`, `tests/`, `resources/`, `composer.json`, `composer.lock` and `phpunit.xml` proves those named trees/inputs identical at integrated 32f083d2, native-withdrawal ec90cc1a and frozen 453c1af8. Author9399dd99 differs from frozen 453c1af8 only in `scripts/ci/database-mysql-selection.json` and `scripts/ci/database-sqlite-skips.json` within that scope; its application/test/resource inputs match. Relative to historical f4732dbe, exactly the two product files and two regression files marked changed below differ. This scope excludes docs/workflow commentary and does not reassign earlier runs to another commit.

| Path | SHA-256 at 453c1af8 | Delta from f4732dbe |
| --- | --- | --- |
| `app/Domain/Memberships/Production/MemberGrantBinding.php` | `e8ec6951a419ed46ecffb537aca2ac323367b2b346efe1ca939c8585a0a3bc61` | unchanged |
| `app/Domain/Memberships/Production/MemberGrantReceipt.php` | `ee87a595896faba469ef06f563e39c79f68248bbbb7c53c64aa6c684baa37926` | unchanged |
| `app/Domain/Memberships/Production/MembershipPolicyBinding.php` | `96b62f5f6b3fc4603fd9df5f1a4c0c5f6060e20e54c3e8249ce04e0d999c8e86` | unchanged |
| `app/Domain/Memberships/Production/MembershipPolicyDocument.php` | `1545367e9c167458e401ba90950c286e8fadb7aa9918360de9170328e613092e` | unchanged |
| `app/Domain/Memberships/Production/MembershipPolicy.php` | `21528d642460d812965a4302096699b6736a328bdf2af63d01af4e195e28fc7f` | unchanged |
| `app/Domain/Memberships/Production/MembershipLedgerAudit.php` | `8c44b2d53d8b0178ac3a0c9657f856712523cb68c56d62e3f3013043d1980443` | unchanged |
| `app/Domain/Memberships/Production/MembershipReservationRecords.php` | `949fa4a9276bebc4f19dbfe93b5c538e593a317075dc3084bdfa26fdd888a2ea` | unchanged |
| `app/Domain/Memberships/Production/MembershipPeriodRecords.php` | `8ff55a353e824b55e93e5407fc28851e880b4dc0e5bd7afecc26dc4fa79a9f5d` | unchanged |
| `app/Domain/Memberships/Production/MembershipAwardContext.php` | `af9136988eb12239f9cb84015bfb44c92527364a1bf8424c4b0b2feebd6c221e` | unchanged |
| `app/Domain/Memberships/Production/MembershipReservationContext.php` | `a02b8246e8e9247c3095a8f90b38a13a45ddd0569441278702277bf398e37624` | changed |
| `app/Domain/Memberships/Production/MembershipReservations.php` | `491df724395f74fe91a066b56e77e7811e678734138b0b1a5c8fb5912caa11b2` | unchanged |
| `app/Domain/Memberships/Production/MembershipRecords.php` | `c46a4699d7075e4de83519ac82cbe45708091a85040dfc2dcb221d0675a1f4ec` | unchanged |
| `app/Domain/Grants/Member/MemberGrantSchema.php` | `b5c6fcb8f1c1e1bcf553400b0b4410a381609b078c2cb8df484fd5bdbb58da3a` | unchanged |
| `app/Domain/Grants/Member/MemberGrantRows.php` | `dda1fd3f59e3d49413c047fda118060bec1332192e9626f8ce1b5c41f615e2f1` | unchanged |
| `app/Domain/Grants/Member/MemberGrantRecords.php` | `d13120373154423fb09181448d8790c87c7ee3c05fc553810c0b5788ab8667dd` | unchanged |
| `app/Domain/Grants/Member/MemberOriginalSourceCapture.php` | `0b769153caed6b5164b2eed2bfaf2eb6c95cfeeae42d77c4b6d079996aa57f2f` | unchanged |
| `app/Domain/Grants/Member/MemberOriginalInspection.php` | `fe6ffc866a9f257b7bd98e4cece6e472af3c61c97aeaaf57d4acae27a04eb295` | changed |
| `app/Domain/Grants/Member/MemberOriginalArtifacts.php` | `73e586cd321da31a929fcd5a4bf96d9a4d86a415cfee889ecddbefebfc976451` | unchanged |
| `app/Domain/Grants/Member/MemberOriginalSourceValues.php` | `3aaf06cf5d36fe419e300e4413933edb12ee3ecec705b6e71116c89284de7748` | unchanged |
| `app/Domain/Grants/Member/MemberOriginalText.php` | `959d90054dfb6513dff278359d4d27709da4b0131faef674e2cdd6a6689902da` | unchanged |
| `app/Domain/Grants/Member/MemberOriginalArtifactManifest.php` | `796fb3bd257c84a857821209a6714f7fe2b6fddb05ae1930f117d6fc4526ef60` | unchanged |
| `app/Domain/Grants/Member/MemberOriginalSource.php` | `f6287644e3ed625f3e1fe457212c9ce44a34ac8be5f301a8deca28008e1edcad` | unchanged |
| `app/Domain/Grants/Member/MemberOriginalBudget.php` | `fc8d0306fdec3f0235e2b225ff6789f31301490cded976330cffb700036aff2a` | unchanged |
| `tests/Feature/ProductionMembership/MembershipPreparationTest.php` | `04a983c3d976b6914203a40998cea009b34acc1b02416a118d1d01807800b8ba` | unchanged |
| `app/Domain/Grants/Member/MemberOriginalObserver.php` | `a1f05c4d1584a6fea8857110e831bcd79a0edf2b4628db9f485a6045f5892a64` | unchanged |
| `tests/Feature/ProductionMemberOriginals/MemberOriginalCompositionTest.php` | `0acebb7ac58f663180318b1a5538b463a20f89e655a2b44103685421f4b92e94` | changed |
| `tests/Feature/ProductionMemberOriginals/MemberOriginalIssuerOwnershipTest.php` | `569b4741c21363a8ab132c368f65ca11f86cddb21e3979044879c855a562adb8` | changed |

These six committed document/receipt blobs were read to reconcile C4b source and completed native outcomes. Preparation/exact repeat is 1 test / 48 assertions at author 9399dd99; withdrawal is 1 / 30 at native ec90cc1a. Both XML files report zero errors/failures/skips; both result records exit 0 with no changed captured sources. Whole invocation times are not individual frame measurements or performance guarantees. Earlier refusals remain in the C4b packet.

| Receipt path | SHA-256 at 453c1af8 |
| --- | --- |
| `docs/verification/member-originals-20261013/README.md` | `bab676657ce019ac9bbbce3d720bf3737f8eb76f2c748924c10c3cb01438f64f` |
| `docs/verification/member-originals-20261013/source-map.md` | `2617c3b3dac219c29b229d45fcbf3d798fb9efc529f3f53fec2327d910156607` |
| `docs/verification/member-originals-20261013/evidence/operation/native-preparation-correction/native-positive-final.xml` | `fbbd371ff97ca4849c729f25fae67d5709099f8aedfcca49163396aa7e8e139c` |
| `docs/verification/member-originals-20261013/evidence/operation/native-preparation-correction/native-positive-final.result.json` | `bebda4dd327ad3cccf7a199bf0efa42c42d4c9707def0a453438cdef62e6a9ff` |
| `docs/verification/member-originals-20261013/evidence/root/native-withdrawal-ec90cc1a/native-source-withdrawal-ec90cc1a.xml` | `6ed0442ec78fdb4167f7a7c4f5106132d99b0a04ea52af8da7e047312309737e` |
| `docs/verification/member-originals-20261013/evidence/root/native-withdrawal-ec90cc1a/native-source-withdrawal-ec90cc1a.result.json` | `bfc510fac35b1569980b1892235777dd53cf1c31a2bac7980e8e0e0a9d84e6f0` |
