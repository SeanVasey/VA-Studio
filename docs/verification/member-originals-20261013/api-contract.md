# C4b shared API and ownership contract — candidate for lead review

2026-10-13. This is a proposed implementation contract, not implemented code,
behavioral evidence or an application DECISION. Only this runtime file was written.
No worktree, source edit, test, database operation or provider call belongs to this
task. Implement against the **actual C4a merge**, once the lead records it.

Basis: reviewed design revision 2 at SHA-256
`3e80f8c6577eeb2e2e4aed392312940c1edc6bb09e622d156cf79c1f3e4d75f7`
and `/workspace/runtime/member-originals-c4b-independent-design-review-20261013.md`.
The relevant existing interfaces/schema were reread at C4a source
`0095e46aefa85463268027d89c829a4886537ad6`. That reference is not a merge claim.
The independent C3/design reviewer remains a non-author for final C4b review.

## 1. Fixed boundary and one concrete identity refinement

C4b authors immutable profiles/definitions and prepares one private candidate PDF.
It writes no member origin/artifact/activation and no credit event. Its standalone
result is a non-authority candidate. C4c must obtain fresh same-frame readiness in
the actual transaction that later inserts origin/artifacts, consumes and activates.
No migration, route, provider registration, grant implementation, commercial
default or production approval hash is introduced. All gates remain default-off.

**Use `originId = authenticated redemptionId`.** `MembershipValues::id()` admits
UUIDv4 spelling only. Do not introduce UUIDv5, widen shared UUID validation or put
version-4 bits on a deterministic digest and call it a random UUID. Inspection of
258 finds per-table primary IDs, a unique origin `redemption_id`, artifact FKs to
the origin and unique `(origin_id, ordinal)`/`(origin_id, role)`, and activation
uniqueness/FKs by origin and redemption. There is no origin-versus-redemption ID
inequality or shared global ID registry in the inspected member/257 schema and
binding paths. The 257 unique `grant_origin_id` still allows exactly one later
consume. Equal UUID bytes in the two named tables fit those constraints.

This refines revision 2's unspecified namespace-derived origin. The lead accepted
the direction conditional on the source inspection above; the composed native
test must still prove actual insertion/join behavior in C4c. Family, purpose,
provenance, definition/profile and render-source digest stay separately bound;
equal UUID bytes never mean equal logical objects across tables/families.

## 2. Namespaces, unchanged contracts and ownership

Below, `M` means `App\Domain\Grants\Member`, `P` means
`App\Domain\Memberships\Production`, `Principal` is the existing
`App\Domain\Customers\ProductionCustomerPrincipal`, `User` is `App\Models\User`,
`Rows` is existing `P\MembershipRows`, and `CommittedFrame` is existing
`App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame`.
All proposed names otherwise live in `M` unless explicitly qualified with `P`.

Keep the existing shapes of `MemberGrantFactsBinding`, `MemberGrantProfileBinding`,
`MemberGrantFactsProof`, `MemberOriginalArtifactManifest` and
`MemberOriginalArtifactReceipt`. They remain values/proof interfaces with their
existing methods. A manifest constructor does not verify its hash; the new issuer
must recompute it. Keep `P\MemberGrantIntent`, `P\MemberGrantAuthority`,
`P\MemberGrantBinding` and `P\MemberGrantReceipt` unchanged and unimplemented for
member activation in this batch. Keep `P\MembershipReservationAuthority`'s public
reserve/lock/proveCurrent API unchanged; the new capture seam is on its concrete
issuer, not a generic new public writer.

| Lane | Exact existing files it alone may edit | New file families it owns |
| --- | --- | --- |
| Facts/authoring | `app/Domain/Grants/Member/MemberGrantSchema.php` (pure expected-plan extraction/current verification compatibility only); `MemberGrantPolicy.php` (separate authoring gate, preserve C4a customer gate); `config/member-grants.php` (default-off authoring flag only) | Member profile/definition/retention/storage documents; member records/rows/current-schema verifier; definitions authoring and facts issuer/token/snapshot/closure; facts/currentness/authoring tests |
| Renderer/materialization | None of the five frozen manifests or their members; normally no existing application file | Member render-profile registry/input/text/PDF/process, child script, new member manifest, member candidate file adapter; their isolated fixtures/process tests |
| Composed operation | `app/Domain/Grants/Member/MemberOriginalArtifactAuthority.php`; `app/Domain/Memberships/Production/MembershipReservations.php`; `MembershipReservationContext.php` (owned phase split, existing wrappers preserved) | Source capture/permit/bridge, artifacts issuer/materialization/ready tokens, budget/I/O barrier, composed current/committed proof and observers, standalone operation/candidate, integration fixtures/tests |
| Lead/shared-contract commit | No PHP required; root owns CI policy/census, ledgers, packet and release record | This reviewed API/ownership document; no placeholder PHP declarations or success stubs |

`MemberGrantFactsAuthority.php` can retain its existing `lock()`/`proveCurrent()`
signatures. Extra proof phases are concrete issuer APIs; no third-party capability
implementation is implied. Facts/operation tests may each add new files under
`tests/Feature/ProductionMemberOriginals/`, with ownership agreed by filename.
The operation lane owns the necessary updates to every existing double affected
by its `MemberOriginalArtifactAuthority` change, including the preparation/source
admission/staging files. Before editing one of those currently root-owned files,
it obtains the lead's explicit file handoff; the facts/renderer lanes never edit
those doubles independently. Change interface and all affected doubles together
in the operation commit, preserving the old focused regressions.
No lane changes `MembershipRows`, `MembershipPolicyDocument`, `MembershipLedgerAudit`,
`ContractIo`, `IdentityCommittedFrame`, historical migrations or frozen profile
members incidentally. A necessary change outside this map is coordinated first.

**Minimum shared PHP before branching: none.** Proposed signatures in this file
are enough for independent implementation. Each declaration is delivered with
its complete implementation by its single owner, not a separate placeholder:

- Facts owns `MemberGrantProfileDocument`, `MemberGrantDefinitionDocument`,
  `MemberGrantFactsSnapshot`, `MemberGrantFactsClosure`, `MemberGrantAuthored`,
  `MemberGrantDefinitions`, `SealedMemberGrantFacts`, `MemberGrantCurrentSchema`
  and `MemberGrantSchemaSnapshot`, plus its internal fixed member records/rows.
- Renderer owns `MemberOriginalRenderInput`, `MemberGrantRenderProfile`, its new
  member registry, `MemberOriginalRendererProcess`, `MemberOriginalFiles` and
  `MemberOriginalFileObservation`, plus its PDF/text/child implementation files.
- Operation owns `MemberOriginalSourceValues`, `MemberOriginalCapturePermit`,
  `MemberOriginalSourceCapture`, `MemberOriginalSource`,
  `MemberOriginalSourceClaim`, `MemberOriginalArtifacts`,
  `MemberOriginalMaterialization`, `MemberOriginalReadyToken`,
  `MemberOriginalCommittedProof`, `MemberOriginalBudget`, `MemberOriginalIoFrame`,
  `MemberOriginals`, `MemberOriginalCandidate` and `P\\MembershipMemberCapture`,
  plus its observer/phase registration internals.

Named `M` classes above map one-to-one to
`app/Domain/Grants/Member/{Class}.php`; the `P` type maps to
`app/Domain/Memberships/Production/MembershipMemberCapture.php`. Pure retention
and storage nested documents are validated by the facts document owner; no
separate PHP type is required. Complete pure types may be handed between lanes
as small commits when dependencies need to execute. Renderer tests needing the
barrier/budget wait for the operation's real implementation; they do not replace
it with a passing production-shaped stub.

## 3. Pure documents and hash contracts

All canonical hashes below are lowercase SHA-256 over the existing
`App\Support\CanonicalJson::encode()` representation: sorted object keys, retained
list order, integer numbers, valid UTF-8. Reject unknown keys, wrong types, duplicate
roles, invalid hashes/UUIDs, excess depth/bytes or out-of-range integers before
hashing. Never silently truncate, coerce floats or normalize submitted legal text.

`MemberGrantProfileDocument::from(array $document): self` and
`MemberGrantDefinitionDocument::from(array $document): self` are pure strict value
constructors with `value(): array` and `hash(): string`. Public construction is not
authority. No constructor reads a database, file, current profile or container.

### Terms-bound profile document

Exact v1 keys: `schema_version`, `family`, `purpose`, `provenance`,
`render_profile`, `original_terms`.

- `schema_version = member-grant-profile-document-v1`; family/purpose equal the
  existing member constants; provenance is explicit and source-matched.
- `render_profile` is the complete new released member technical descriptor,
  including template, technical limits, exact base font/package identity and new
  implementation manifest. Its pure retained-registry validation checks a known
  complete descriptor hash. It does not load `profile-assets.json` inside a frame.
- `original_terms` is exactly the existing selected license's canonical object:
  `purpose = membership-license-original-terms-v1`, `authored_source`,
  `structured_terms`, `renderer_version`, `render_fixture_hash`. The existing
  catalog producer determines these values. It is distinct from plan terms.

`profile_hash` hashes the whole profile document; `original_terms_hash` hashes
`original_terms`. `render_profile_hash` hashes only the technical descriptor.
`implementation_hash` hashes its complete new member implementation manifest;
`font_manifest_hash` hashes the exact retained technical font manifest object
identified by that descriptor. Freeze that object's exact path/key in the first
shared renderer descriptor contract; it cannot mean an arbitrary subset of fonts.
For the current base use `render_profile.base.assets.assets` in full, with its
declared generated-font paths/digests/sizes; package identity stays additionally
bound by the full technical descriptor.

### Member definition document

Exact v1 keys: `schema_version`, `family`, `purpose`, `version`, `provenance`,
`profile_hash`, `plan_version_id`, `policy_hash`, `license_manifest`,
`asset_manifest`, `original_terms_hash`, `retention_policy`, `storage_policy`.

- `schema_version = member-grant-definition-document-v1`, `version = 1`.
- Plan ID/hash refer to the exact retained authenticated 257 policy document.
  The current facts join verifies it; an input pair alone is not that proof.
- License and asset manifests are exact canonical retained catalog values, not
  replacement manifests assembled from caller hashes. Their hashes match the
  selection/reservation. Terms hash matches the profile's exact terms object.
- `retention_policy` is a strict authored value with exact keys
  `schema_version = member-original-retention-facts-v1`, `reference`, `text`.
  Nonempty UTF-8 reference/text are explicitly supplied (bounded to 160/65536
  bytes); no duration or legal meaning is inferred. Its hash is the 258
  `retention_policy_hash`. V2's null retention binding is not filled or relabelled.
- `storage_policy` has exact keys
  `schema_version = member-original-storage-policy-v1`,
  `backend = private_local`, `layout = production-member-candidate-v1`,
  `provenance`, `ownership_reference`, `retention_policy_hash`.
  The nonempty bounded ownership reference is explicit authored input, not a
  host-verification claim. Provenance/retention must agree with the definition.
  The local root/path/configuration are runtime-private captured dependencies,
  not caller path fields. This document's hash is each artifact's storage hash.

Profile/definition row UUIDs, author IDs/bindings, ciphertext, row seals and
creation timestamps are retained record envelopes, excluded from the document
hashes. Authoring deduplicates by document hash and returns existing row IDs after
full exact verification. The definition row separately joins the actual profile
ID found by the exact profile hash. No later change to a document mutates a row.

### Canonical render-source document

`MemberOriginalSourceValues` is a new readonly **value** with `document(): array`,
`hash(): string`, and `renderInput(): MemberOriginalRenderInput`. It contains no
live proof, PDO, Rows, callback, resolved filesystem path or credential. It is
created internally from admitted sources; public construction, if supported for
pure renderer tests, confers no admission. Exact top-level v1 keys:

| Key | Meaning |
| --- | --- |
| `schema_version` | `member-original-source-v1` |
| `family`, `purpose`, `provenance` | Exact member constants and matching source provenance |
| `origin_id` | Exactly authenticated `redemption.id` |
| `buyer_binding` | Exact historical invoice buyer binding, validated by the real owner producer; no inferred legal name/address |
| `invoice` | Existing `MembershipPeriodRecords::semantic($invoice)` output, including stable invoice/graph/provider/policy/buyer identities and paid period/amount/currency |
| `period` | Exact `{id, seal}` of the audited paid period |
| `redemption` | Exact `{id, seal, intent_hash, credit_amount, created_at, honor_deadline}` from the authenticated live reservation |
| `reserve_event` | Exact `{id, seal}` of its nonterminal reserve |
| `selection` | Exact retained reservation selection object: track/offer/revision/license IDs, selection/license/assets/original-terms hashes |
| `definition` | `{id, seal, document}` using the verified full member definition |
| `profile` | `{id, seal, document}` using the verified full terms-bound member profile |

The retained documents already bind complete selected license/assets/terms;
validate their hashes against the selection and invoice policy. Source hashing
**retains** the historical T23 `verification_observation_id` and
`verification_observation_hash` in the exact buyer binding and semantic invoice.
It excludes only fresh per-call C3 retrieval observation IDs/seals/leases and
their freshly issued deadlines, plus current wall time, worker IDs, temporary
names and active proof objects. Retain those fresh observations privately for
authority checks instead. A fresh C3 retrieval observation may differ while the
semantic invoice remains identical. Conversely, an invoice graph/owner,
reservation, selected source or member definition change must change/refuse the
source, not disappear under an overbroad semantic projection.

`render_source_hash = CanonicalJson::hash(sourceDocument)`. The child input v1
has exactly these keys: `schema_version = member-original-render-input-v1`,
`family`, `purpose`, `provenance`, `origin_id`, `render_source_hash`,
`buyer_account_id`, `reservation_created_at`, `selection`, `original_terms`.
`selection` and `original_terms` are the exact already validated source objects;
the remaining values are direct projections of the source above. Buyer display
is the retained account reference with its real provenance, not a claim of
verified legal identity. The PDF's only document date is the labelled retained
reservation source-record timestamp; there is no invented assent/activation date.
No arbitrary caller text, raw row ciphertext, source seals, credential, storage
path, live proof, current time or random row ID belongs in this child input.
The technical descriptor supplies the exact fixed labels and layout; changing
that input/layout contract requires a new released member descriptor/hash.

### Artifact manifest and deterministic storage

The v1 hash preimage has exact keys:
`schema_version = member-original-artifact-manifest-v1`, `family`, `purpose`,
`origin_id`, `originals`. `originals` is a list in profile role order. V1 has
exactly one entry: `{role: license_pdf, sha256, bytes, storage_policy_hash}`.
Digest/size come from actual rendered and reobserved bytes; storage hash comes
from the verified definition above. The source-owned issuer recomputes the
preimage/hash at preparation, fresh readiness admission and retained verification.
The existing manifest DTO's caller-shaped hash is never authoritative.

**Artifact row IDs are not in this preimage, render input or path.** C4c may mint
random UUIDv4 artifact/activation row IDs once inside its owned transaction; it
joins artifact rows by exact origin, ordinal and role and verifies manifest
content independently. A committed retry reuses retained row IDs. A rolled-back
attempt's unused row UUIDs do not alter the PDF or manifest. The same applies to
new row ciphertext/seals/timestamps: none determines candidate bytes.

Derived relative path, only in the private adapter:
`contracts/production-member-v1/{provenance}/{origin_id}/{render_source_hash}/original.pdf`.
All segments come from admitted source, never an API path parameter. Family and
purpose are fixed in source/manifest; definition/profile are hashed into the
source digest. Temporary filenames alone use fresh randomness. Final publication
never overwrites; equal completed candidates require exact trusted rerendered
byte comparison. A pre-unlink crash with `nlink=2` refuses and needs separate
reviewed/authorized exact cleanup, not silent adoption or a new random path.

## 4. Shared types and facts APIs

These are proposed signatures. The shared-contract commit is this document;
each owning lane implements its types and methods together. It must not add fake
successful implementations or change an existing interface before its affected
implementations/doubles are ready.

```php
// Existing interface signatures remain:
interface MemberGrantFactsAuthority {
    public function lock(string $definitionId, Principal $principal, User $actor,
        Rows $rows): MemberGrantFactsProof;
    public function proveCurrent(MemberGrantFactsProof $proof, Principal $principal,
        User $actor, Rows $rows): void;
}

// New concrete facts issuer, owned by facts lane:
final class SealedMemberGrantFacts implements MemberGrantFactsAuthority {
    // ...existing interface methods...
    public function snapshot(MemberGrantFactsProof $proof, Principal $principal,
        User $actor, Rows $rows): MemberGrantFactsSnapshot;
    public function closure(MemberGrantFactsProof $proof, Principal $principal,
        User $actor, Rows $rows): MemberGrantFactsClosure;
    public function currentCallbacks(MemberGrantFactsClosure $proof, Rows $rows): void;
    public function currentRaw(MemberGrantFactsClosure $proof, Rows $rows): void;
    public function committedCallbacks(MemberGrantFactsClosure $proof,
        CommittedFrame $frame): void;
    public function committedRaw(MemberGrantFactsClosure $proof,
        CommittedFrame $frame): void;
    public function retire(MemberGrantFactsClosure $proof): void;
}

final class MemberGrantDefinitions {
    public function author(MemberGrantProfileDocument $profile,
        MemberGrantDefinitionDocument $definition, User $author): MemberGrantAuthored;
}
// Non-authority result fields: profileId/profileHash/definitionId/definitionHash/provenance.

// Narrow pure plan extraction, preserving installed DDL byte semantics:
MemberGrantSchema::expectedPlan(string $driver, string $schema, string $prefix): array;
// New verifier owns and validates the plan internally; callers cannot substitute it.
MemberGrantCurrentSchema::pin(Rows $rows): MemberGrantSchemaSnapshot;
MemberGrantCurrentSchema::assertCurrent(MemberGrantSchemaSnapshot $s, Rows $rows): void;
MemberGrantCurrentSchema::assertCommitted(MemberGrantSchemaSnapshot $s,
    CommittedFrame $frame): void;
```

`MemberGrantFactsSnapshot` is a readonly value of verified profile/definition
documents, bindings and exact source row identities/seals, with no capability.
`MemberGrantFactsClosure` is opaque and private-issuer registered; its registry
retains the original facts proof, exact principal/actor, original PDO/Rows,
configuration, original cap and captured source/raw predicates. Clone/serialize/
JSON/debug leakage are refused. A fresh closure is not a way to renew the original
facts cap. Raw phases perform no clocks with hooks, crypto, filesystem checks,
container resolution or caller callbacks. They validate the prior phase and
captured native dependencies; committed phases use the active committed frame,
never closed Rows operations. No facts method calls `frame->finish()`.

`author()` is a standalone owned entry, not a public closure or provisional Rows
writer. It authenticates current staff/MFA, uses the new explicit
`MemberGrantPolicy::authoringFor(Rows): array` /
`proveAuthoringCurrent(array, Rows): void` gate, rechecks after encryption and
callbacks, and proves the normal commit before returning `MemberGrantAuthored`.
The new authoring flag defaults false; it cannot substitute for the existing
customer `currentFor` gate or enable preparation. Authoring stores complete
retained profile metadata with pure released-registry validation. Physical
runtime/font verification occurs outside transactions during materialization.

The member reader has fixed named methods for profiles/definitions and their
bounded captured predicates, not `one($callerTable, $callerSql)`. The schema
verifier pins all five current 258 tables, then verifies full current SHOW table
structure and all sixteen known SHOW trigger definitions against the installed
plan. Preserve shadow/namespace/SQLite rules and the separately open current
unknown-trigger-inventory condition.

## 5. Source-capture bridge and composed proof phases

Operation owns all new bridge types, the exact signature changes on the two
existing reservation classes, and the single combined finish. Facts must not
independently edit those files.

```php
// New opaque operation-owned permit and reservation-issuer-owned capture identity:
MemberOriginalCapturePermit;
P\MembershipMemberCapture;

// New concrete MembershipReservations methods; its existing interface stays unchanged:
public function beginMemberCapture(MemberOriginalCapturePermit $permit,
    string $redemptionId, Principal $principal, User $actor, Rows $rows)
    : MembershipMemberCapture;
public function memberSourceValues(MembershipMemberCapture $capture, Rows $rows): array;
public function memberCurrentCallbacks(MembershipMemberCapture $capture, Rows $rows): void;
public function memberCurrentRaw(MembershipMemberCapture $capture, Rows $rows): void;
public function memberCommittedCallbacks(MembershipMemberCapture $capture,
    CommittedFrame $frame): void;
public function memberCommittedRaw(MembershipMemberCapture $capture,
    CommittedFrame $frame): void;
public function retireMemberCapture(MembershipMemberCapture $capture): void;

// New fixed orchestration entry; no caller callback or mutation selector:
final class MemberOriginalSourceCapture {
    public function capture(string $definitionId, string $redemptionId,
        Principal $principal, User $actor, MemberOriginalBudget $budget)
        : MemberOriginalSource;
    public function claimForMaterialization(MemberOriginalSource $source,
        MemberOriginalArtifacts $consumer, MemberOriginalIoFrame $idle)
        : MemberOriginalSourceClaim;
    public function proveClaimCurrent(MemberOriginalSourceClaim $claim,
        MemberOriginalArtifacts $consumer, MemberOriginalIoFrame $idle): void;
}

// New issuer-registered one-use claim result, not publicly mintable authority:
final class MemberOriginalSourceClaim {
    public function values(): MemberOriginalSourceValues;
    public function budget(): MemberOriginalBudget; // the exact original object
}
```

Only the source-capture operation mints/registers the permit after complete
standalone/configuration admission. Its private registry binds permit, exact
reservation issuer, arguments, original observer, Rows/PDO, principal/actor and
budget. The reservation issuer consumes that permit before making a real
inspection Context, then privately maps `MembershipMemberCapture` to that exact
Context/deadlines. An independently constructed token/value is not registered.
`memberSourceValues()` returns bounded copied values needed by the canonical
source schema; it never exposes Context, producer tokens or a mutation permit.

`MembershipReservationContext`'s current and committed proof bodies get internal
callback/raw phase extraction. Existing `proveCurrent()` and
`proveCommitted(CommittedFrame)` remain complete wrappers for existing callers;
the latter still finishes exactly once. The concrete issuer uses the owned
member phase path against its private original Context. Any PHP-visible internal
entry must enforce original issuer/capture phase registration; incomplete proof
phases cannot satisfy an existing write observer's complete-proof latch.

The new member observer/composed context enforces this sequence:

1. Open only its owned transaction; build actual reservation inspection and facts
   proof in the established owner → billing → policy → catalog → ledger → member
   order. Capture provisional pure source values, never publish a source ticket.
2. For current and committing proof, run **both** issuers' callback-capable
   phases first, then both raw phases, captured configuration/owner/frame checks
   and original monotonic limits. No callback intervenes in that raw closure.
3. Commit normally through original delegates and scoped afterCommit callbacks;
   require the observer's completed proof. Failure produces no source ticket.
4. Open one original-deadline `CommittedFrame`. Run member and reservation
   committed callback phases, then their raw phases and terminal admission.
   **Only the combined operation calls `frame->finish()`, once.** Do not call
   today's whole reservation committed wrapper and append member checks afterward.
5. Close/restore only owned state, retire bridge/facts closures, run the idle
   native barrier, and only then privately register `MemberOriginalSource` with
   copied source values, exact allowed materializer and the original budget.

The ticket contains no live Rows/PDO/producers, but its issuer registry retains
its intended principal/actor identity and configuration for admission. It is
one-use for materialization and refuses clone/serialization/JSON. A materializer
must be the exact captured registered instance; another source issuer or new
service instance cannot claim it. `claimForMaterialization` does not reprove
expired transaction tokens and does not issue ready authority.
It returns an opaque registered claim retaining the exact original budget, safe
values, owner/configuration dependencies and consumer identity. The composer
calls `proveClaimCurrent` after callback-capable setup and before each physical
step; this checks the retained configuration and remaining original allowances,
not continuously current database sources. A public value copy cannot stand in
for that claim. `MemberOriginalSourceClaim` also refuses clone/serialization/JSON.
The operation constructs the source issuer and artifacts composer, then privately
binds the exact allowed composer once through its owned assembly; capture refuses
before binding and rebinding refuses. Do not resolve the circular ownership by
accepting the first arbitrary consumer or allowing a public replacement setter.

## 6. Materialization, ready proof and standalone return

Renderer owns deterministic technical adapters; operation owns authority-bearing
composition. Keep these distinct so the renderer cannot mint a facts or ready
proof merely by returning `RenderedContract`.

```php
// Replace the unimplemented existing artifact interface explicitly:
interface MemberOriginalArtifactAuthority {
    public function prepare(MemberOriginalSource $source, MemberOriginalIoFrame $idle)
        : MemberOriginalMaterialization;
    public function verifyCurrentBytes(MemberOriginalMaterialization $materialized,
        MemberOriginalIoFrame $idle): void;
    public function admitReady(MemberOriginalMaterialization $materialized,
        P\MemberGrantIntent $freshIntent, MemberGrantFactsProof $freshFacts,
        Principal $principal, User $actor, Rows $rows): MemberOriginalArtifactReceipt;
    public function proveReadyCurrent(MemberOriginalArtifactReceipt $receipt,
        P\MemberGrantIntent $freshIntent, MemberGrantFactsProof $freshFacts,
        Principal $principal, User $actor, Rows $rows): void;
}

// New concrete composer implements that interface (operation lane):
MemberOriginalArtifacts;

// New renderer-owned adapters:
MemberGrantRenderProfile::validateStored(array $descriptor): array; // pure
MemberGrantRenderProfile::verifyRuntime(array $descriptor,
    MemberOriginalIoFrame $idle): void; // actual file/package checks
MemberOriginalRendererProcess::render(MemberOriginalRenderInput $input,
    array $descriptor, MemberOriginalIoFrame $idle, MemberOriginalBudget $budget)
    : App\Domain\Contracts\RenderedContract;
MemberOriginalFiles::storeOrVerify(MemberOriginalSourceValues $source,
    App\Domain\Contracts\RenderedContract $expected,
    MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): MemberOriginalFileObservation;
MemberOriginalFiles::verify(MemberOriginalSourceValues $source,
    MemberOriginalFileObservation $expected, MemberOriginalIoFrame $idle,
    MemberOriginalBudget $budget): void;

// New standalone customer-domain entry (operation lane):
MemberOriginals::prepare(string $definitionId, string $redemptionId,
    Principal $principal, User $actor): MemberOriginalCandidate;
```

`MemberOriginalArtifacts` is constructed with the exact source issuer, facts
issuer, renderer and files objects; the standalone operation uses those same
instances under the captured complete capability gate. No constructors grant
authority, install bindings or read live inputs. `prepare` consumes the source
ticket, verifies the actual runtime profile outside transactions, renders,
publishes/verifies exact bytes, computes the canonical manifest and privately
registers `MemberOriginalMaterialization` with source/profile/owner, observation,
configuration, original workflow budget and service identities.

`MemberOriginalFileObservation` is an internal adapter value with actual size/
hash/path/descriptor checks and observation instants, not a public capability.
`MemberOriginalMaterialization` is opaque; only its exact composer's registry
accepts it. Public construction of any adapter value does not register one.
Its physical window begins **before** the first file observation, not when the
token returns. `verifyCurrentBytes` rechecks actual bytes outside transactions
but can only shorten/preserve the token's original window, never renew it.

`admitReady` requires actual freshly issued invoice/license/reservation and facts
proofs in the supplied Rows, with exact issuer, principal/actor and semantic source
equality. Its private `MemberOriginalReadyToken` binds those exact objects,
Rows/PDO, manifest, original physical/workflow cap and fresh operation/C3 windows.
Every hash is recomputed from captured trusted values. No file/renderer/profile
runtime helper, network work or closed Rows call belongs here. Facts/source
callback phases precede one terminal raw closure; new callbacks cannot appear
after another producer's final raw check. A token from another frame is refused.

The standalone `prepare` performs capture → I/O → fresh owned final inspection
→ committed combined source proof → final external physical observation. It
returns a readonly `MemberOriginalCandidate` containing exactly
`originId`, `redemptionId`, `definitionId`, `definitionHash`, `profileHash`,
`renderSourceHash`, `artifactManifestHash` and the bounded nonsecret artifact
manifest. No private path, materialization token, ready token, Rows or grant
receipt escapes in that result. It may be inspected as values but cannot be
submitted as authority. HTTP serialization remains a future explicitly scoped
projection; this batch adds none.

C4c's future owned entry may compose capture/materialize/admitReady directly in
the same process and original workflow. It must call `admitReady` in its actual
effect frame, not reuse the standalone final-inspection proof. A later process
uses locators to capture/rerender/observe the existing candidate afresh. C4b does
not add a consume-capable operation permit or implementation of `MemberGrantAuthority`.

## 7. Budgets, all-resolved native I/O barrier and committed evidence

```php
final class MemberOriginalBudget {
    public static function start(): self; // original fixed technical workflow cap
    public function deadlineNs(): int;
    public function shortenTo(int $deadlineNs): void; // min only
    public function assertCurrent(): void; // monotonic, no hooked wall clock
}
final class MemberOriginalIoFrame {
    public static function captureIdle(): self;
    public function assertStillIdle(): void;
}
```

Operation starts the original workflow budget before any capture admission;
proposed fixed technical caps from the reviewed design are 300 seconds workflow,
60 seconds renderer and 60 seconds maximum physical-observation age. The new
profile additionally bounds input/output/pages/memory. These are development
technical ceilings, not measured throughput, syscall-interruption guarantees or
commercial honor defaults. Child timeout and I/O checks use remaining original
time. Honor comes only from the retained reservation. Each newly created owned
database frame has at most the existing 60-second admission cap, shortened to
the workflow/physical/honor remainder. Every producer also retains its original
issued C3/eligibility window through that frame's committed proof.

The source registry binds the exact budget object. A caller cannot supply a newly
started budget to renew a source/materialization. New final-frame producer proofs
are new observations, not renewed old tokens. If source changes during detached
work, fresh admission refuses and leaves an orphan; there is no continuous
database-currentness promise while rendering.

`MemberOriginalIoFrame` captures exact container/manager/configuration, raw
resolved-connection map and supported concrete framework connections. It reads
raw transaction counters and all instantiated native write/read PDO handles.
Every counter is zero and every `PDO::inTransaction()` is false. It rejects
unexpected subclasses/slot shapes, changed configuration/map/handles and a late
connection opened after capture. Permitted lazy resolution occurs only in the
earlier callback phase, followed by a fresh complete capture; the final barrier
does not invoke virtual getters or PDO resolver closures. Refusal leaves every
foreign transaction untouched. Call `assertStillIdle()` after callback-capable
setup and immediately before each actual child launch/file write/observation.
`ContractIo` remains unchanged; its framework-only check is not the native gate.

No-I/O applies to technical profile helpers too. Existing registry metadata,
`ContractRenderProfile::validate/hash` and runtime font/package inspection belong
outside member readiness frames. Pure retained descriptor hash validation is
separate. Existing catalog file checks remain their inherited API and are not
silently represented as transaction-free by this member batch.

Current/committing ready proof dies with its transaction. Before commit the owned
operation privately captures a **different** committed-evidence registration:
copied source/manifest dependencies, actual retained effects where applicable,
original observation/workflow/producer caps and observer identity. A new
`MemberOriginalCommittedProof` uses that registration under `CommittedFrame`,
runs all member/reservation callbacks before both raw sets, and finishes once.
Its sole proof entry is `prove(CommittedFrame $frame): void`; only the composed
operation can register its instance, tied to the original completed observer.
This method owns the one combined `finish()` call; the facts/reservation phase
methods never call it. Reuse, a different frame or an incomplete phase refuses.
It cannot write, issue readiness or call `proveReadyCurrent` on closed Rows.
After it closes, the external physical recheck still uses the original allowance.
A refusal after a future committed C4c effect reports uncertainty without
automatic retry, second consume or refund.

The exact file and database state cannot commit atomically under this adapter.
The bounded observation is not continuous storage durability. Mutation after
the last physical observation is detected at a subsequent observation/delivery
boundary; no new work table or held descriptor silently strengthens that claim.

## 8. Dependency order and fixture contracts

1. **Lead-reviewed shared document first:** namespace/type inventory,
   canonical documents/source/manifest, unchanged versus replaced interfaces,
   source permit/bridge phases, pure descriptor validation, byte adapter values,
   original budget/I/O barrier and non-authority candidate result. Declare no
   success-producing stub and no PHP scaffolding is required. All lanes branch
   from the same contract baseline on the actual C4a merge. The operation lane
   changes the existing artifact interface and its doubles together. Complete
   pure value types can land early from their owner only when another lane needs
   to execute them. Frozen renderer pins are produced only after their real new
   implementation is complete and then reviewed; never pin placeholder bytes.
2. **Facts/authoring and renderer technical work can proceed independently.**
   Facts owns pure fixtures for exact documents and seals; renderer owns the real
   released synthetic member descriptor and exact PDF bytes. Coordinate one
   shared fixture descriptor rather than two unrelated hashes. Both treat pure
   fixture values as values, not issuer authority. Operation may build native
   admission/bridge scaffolding against declarations, without claiming readiness.
3. **Compose only actual producers.** Operation-owned
   `tests/Support/MemberOriginalFixtures.php` extends/reuses actual T23, billing,
   C2 policy/award/reservation/catalog producers and facts authoring. It imports
   renderer fixture values instead of editing renderer-owned files. Keep the
   existing missing C4c grant capability an explicit refusing fixture double;
   do not install a production-shaped placeholder or weaken the complete gate.
4. **Focused integration and separate review follow.** Source-bound SQLite
   files, real synthetic renderer processes, native schema/currentness and
   callback/frame canaries, cheap exact-head preflight and non-author review.
   Parent owns private MySQL slot scheduling and exact final file/source census.

Required fixture ownership/acceptance:

- Facts: real MFA authoring and idempotent immutable documents; terms/profile hash
  distinction; actual old-RR table/required-trigger changes before pins; local
  shadow/namespace/schema positive controls; no guessed approval or retention.
- Renderer: two independent processes produce identical bytes; no-overwrite equal
  winner and conflicting winner; wrong/private paths, size/hash, symlink/hardlink,
  completed-publication recovery and separate pre-unlink `nlink=2` refusal. Exact
  original asset/profile hashes and all five frozen families remain unchanged.
- Operation: default/second/read raw PDO transactions at framework level zero;
  callbacks creating/replacing connections; both issuers' callbacks withdrawing
  the other's dependencies before raw closure; afterCommit mutation; no ticket
  on a failed committed bridge; no hidden profile-file read in final admission;
  source changes during I/O; original caps after waits; detached candidate cannot
  become authority; wrong issuer/owner/frame/manifest/shaped hash all refuse.
- All C4b customer cases assert zero origin/artifact/activation/credit effects.
  Authoring may append only complete profile/definition rows. Actual effect-frame
  consume/activation coupling, v3 policy/audit and equal origin/redemption UUID
  insertion are explicit C4c acceptance, not a C4b test-result claim.

No behavior in this contract has been run or proven by writing this file.
Commercial/legal/effective-date, account and real host/storage/restore inputs
remain unanswered. No new question, default, live action or CI dispatch follows
from this contract.
