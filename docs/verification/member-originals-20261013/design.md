# C4b member original preparation — proposed implementation, 2026-10-13

Read-only design for the development lead. The inspected application baseline is
`0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371`; the inspected C3 packet head was
`526e6f5b12068ca3d9571dfcb47dc25194e24598`. R4's proposed frame admission was
read at `15a65e0761382a9ad50c4b279605a644a4eeb285` in its separate worktree.
The accompanying JSON binds inspected bytes to those references. This is not an
implementation, test result, independent decision, or host/provider claim.
Only these two runtime design files were written. Integrate actual C3 and C4a
merges before implementation/publication; do not use their planning heads as a
claim that the prerequisite merge occurred.

Revision: the separate early design review supplied six required contracts:
closed preparation frames cannot export ready authority; member I/O needs raw
native transaction admission; 258 needs opened/current structure and known-guard
proof; source capture needs an issuer-owned committed-proof bridge; manifest
hashes need canonical recomputation; and detached rendering cannot continuously
observe database withdrawals. It also clarified transaction-free technical
profile verification and the unrecoverable two-hardlink crash state below.
This revision does not itself constitute a reviewer approval.

## Decision: bounded pre-materialization; no forward schema for this batch

Implement an internal, synchronous, member-only preparation operation. It captures
authenticated immutable source values in a short owned frame, renders/stores and
observes private bytes outside transactions, then checks fresh sources in a new
owned read frame. The standalone entry returns a **non-authority candidate** after
that frame closes. Its materialization object may serve as input to C4c, but is
not ready authority. C4c must call `admitReady` inside its actual effect frame and
reprove the fresh issuer-bound inputs there. C4b writes only fully formed
profile/definition rows through a separate staff authoring entry. It writes no
origin, artifact, activation, or credit event during preparation.

This fits immutable 258: an origin's final `artifact_count` and
`artifact_manifest_hash` are known before C4c inserts that origin and its artifact
rows, followed by consume and activation in the same final transaction. No pending
origin, placeholder manifest, row update, paid/free work row, or consume-only API
is necessary. A filesystem candidate is not a retained original or an entitlement.

A new work schema becomes justified when durable job ownership, cross-request
attempt accounting/backoff, queued autonomous recovery, or separately retained
draft/review workflow is required. None is needed to implement this unmounted
synchronous batch. Do not imply that per-call bounds provide a durable global
attempt limit. No automatic orphan deletion is part of C4b.

## Existing constraints that the implementation must preserve

- `MembershipPolicyDocument` v2 supports award/reserve/release/expire only, its
  retention binding is null, and `MembershipLedgerAudit` rejects consume and all
  non-null grant columns. Leave this unchanged in C4b. C4c needs explicit successor
  policy semantics and a complete original/artifact/activation audit.
- `MemberGrantBinding` already includes `activationHash`. C4b must not return a
  `MemberGrantReceipt` with a fabricated or prospective activation. Leave the
  `MemberGrantAuthority` implementation for C4c. Keep the existing complete
  capability gates, all defaults off, and no runtime registrations/routes.
  Synthetic integration fixtures may continue supplying an explicitly refusing
  grant test double; that is not the missing operative capability.
- `MembershipRows::one()` only admits 257 tables. Add a narrow member record
  reader using the captured Rows/PDO, fixed 258 table allowlist, exact native
  schema/currentness checks and prepared predicates. Do not expand it into a
  general caller-supplied table/SQL writer. Merely calling
  `MemberGrantSchema::assertOwned()` is insufficient: its MySQL columns,
  constraints and known trigger bodies are substantially read through
  `information_schema` and can reflect the earlier RR snapshot. Add the opened,
  current verification described below before treating 258 as current.
- The selected license's original-terms identity is the canonical
  `membership-license-original-terms-v1` object containing authored source,
  structured terms, renderer version and fixture hash. It is not the membership
  plan's text hash. Preserve both identities distinctly in member documents.
- The new member renderer, storage and physical-original observation must remain
  outside transactions. `ContractIo::outsideTransactions()` alone is insufficient:
  it checks Laravel's transaction level and does not detect an independently
  started raw PDO transaction. Add the member-owned native barrier below without
  changing frozen `ContractIo`. Do not call paid/free adapters from a live Rows
  frame. Existing catalog producer checks are a separate
  inherited implementation; this proposal does not claim they already perform
  all media I/O outside transactions or silently change their contract.

## Facts and profile implementation

Add strict member profile/definition documents, member record encoders/verifiers,
staff authoring, and `SealedMemberGrantFacts`. Use purpose/table-domain-separated
authenticated ciphertext/seals and exact column-to-payload verification; a
well-formed hash, unsigned array or configuration value cannot mint a proof.
The private issuer map binds a concrete facts token to the exact issuer, Rows/PDO,
customer principal/actor, profile/definition rows and original non-extendable cap.

The profile document contains the member family/purpose, released member render
descriptor, its implementation/font identities, exact original-terms identity and
provenance. Its `profile_hash` covers that complete member document. Keep this
distinct from the reusable technical renderer descriptor's hash: otherwise the
258 unique profile hash would incorrectly collapse different license terms onto
one terms-bound profile row. The definition document binds that profile, exact
retained plan version/policy hash, selected license/assets and full manifests,
original member terms and an explicit member retention document/hash. V2's null
retention field supplies no consumption/retention authority; member document
retention is a separately authored fact until C4c's successor policy joins it.

Follow `MembershipPolicyVersions`' current staff/MFA and reproof pattern for the
authoring entry. Persist author identity/binding, exact submitted document and
timestamp in the immutable payload, with idempotency by complete document hash.
Give authoring an explicit, default-off `authoringFor(rows)` gate analogous to
257's authoring gate: native/environment/provenance and current staff/MFA are
required, but already approved definition hashes or customer grant capabilities
are not prerequisites for creating the first authored definition. This separate
gate can only append profiles/definitions; it cannot admit customer preparation,
readiness or grant effects. Keep `currentFor(rows)`'s complete capability and
approved-hash gate intact for actual preparation. Coordinate this narrow policy
addition after C4a merges rather than editing its owned branch in parallel.
Configured approved hashes remain an external approval gate over these retained
bytes; do not label authoring itself an independently approved review. C4b does
not invent a second staff approval or a production approval receipt. If a future
product workflow requires separate durable author/reviewer sessions, design its
append-only review records explicitly rather than overloading immutable 258.

Add a new `resources/contracts/production-member-v1/profile-assets.json`, own
member text/input/PDF/process implementation and child entry script. Its trusted
registry has explicit released hashes and retained-version lookup. Reuse exact
test-v2 technical font/package bytes through their existing verification API;
do not relabel paid/free/test legal purpose or edit/re-pin any of the five frozen
manifests or their members. The existing deterministic PDF implementations show
how to freeze page/document dates and IDs, but the new renderer needs its own
byte-equality tests before claiming determinism. Use no invented logo or terms.

For this first profile, prepare one `license_pdf` artifact, not sixteen large
artifacts merely because 258 permits them. Suggested technical limits follow the
existing base profile: 1 MiB child input, 16 MiB PDF, 100 pages, 128 MiB memory and
60-second process bound. These are proposed technical caps, not measured host
capability or commercial policy. Bind them in the new reviewed profile.

## Current member schema and transaction-free I/O contracts

The facts/record owner supplies a small `MemberGrantCurrentSchema` verifier. On
the captured native primary, open **all five** known durable 258 table names in
stable order before consuming any member record, retaining transaction metadata
locks. Then compare current, fully qualified `SHOW CREATE TABLE` results against
the pure installed schema plan: exact table identity, non-temporary InnoDB,
ordered columns/types/nullability/defaults/collations, all keys, FKs/actions,
CHECK expressions/enforcement and remaining options. Do not capture an arbitrary
live definition and call equality to it ownership. All 16 required named guards
also receive current, fully qualified `SHOW CREATE TRIGGER` verification after
the pins, including exact event/table/timing/body and the activation consume
coupling guard. Do not obtain the authoritative expected/current structures from
the earlier `information_schema` snapshot. Repeat the relevant current checks at
terminal proof boundaries; committed READ ONLY comparison uses current reads and
cannot silently acquire a new mutation permit.

Expose a narrowly typed pure expected-plan method from the installed schema code,
or extract the same definitions into a member schema catalog with byte-identical
DDL; do not duplicate a gradually diverging handwritten schema. The method accepts
validated captured driver/schema/prefix and performs no facade/container lookup
or DDL. Only reviewed native printing differences may be normalized. 258 has no
auto-increment columns, so no counter projection is needed. Preserve its existing
namespace, local temporary shadow, prefix and SQLite checks. Current required
guard and full table proof does not settle the separately recorded **unexpected
trigger inventory** condition; do not claim the RR inventory is current.

The operation owner supplies `MemberOriginalIoFrame::captureIdle()` and
`assertStillIdle()` (proposed names), used by every new member physical adapter as
well as orchestration. Before the first child/file action, capture the exact
container, database manager, configuration repository and raw resolved-connection
map after dependency-resolution callbacks finish. Admit only supported captured
framework connection classes and native PDO classes; do not invoke overridable
`transactionLevel()` or late connection/container getters in the terminal check.
Read the connection's raw transaction counter and raw write/read PDO slots. For
each resolved connection, require counter zero and `PDO::inTransaction() ===
false` on every already-instantiated handle, including a separate read handle.
Resolve any permitted lazy dependency only in the earlier callback phase, then
recapture and recheck; the final barrier never resolves a PDO closure. Unsupported
slot/class/state shapes refuse rather than assuming an unresolved value is idle.

Recheck the complete raw map/configuration/handle identities after every
callback-capable preparation and immediately before each child launch, storage
write or full file observation. A new/replaced connection or transaction opened
by a callback refuses the step; a fresh invocation may capture a new legitimate
baseline. The operation owns none of a pre-existing transaction: refusal must
not commit, roll back or reset it. ContractIo may remain an additional check but
cannot substitute for this member barrier. Test native transactions opened with
PDO on both the default and a second resolved connection while Laravel reports
zero, separate read handles where supported, and callback connection/transaction
replacement. This barrier covers the application's resolved connection handles,
not arbitrary inaccessible PDO objects or an unverified host process boundary.

Technical profile verification is also filesystem work:
`ContractRenderProfile::validate()`/`hash()` reach registry metadata that reads
`profile-assets.json`, and `verifyVersionRuntime()` reads implementation/font
files. Call these only during the transaction-free phase. Member facts inside a
frame compare pure retained documents/released identities and the issuer-owned
bounded observation; neither `admitReady` nor terminal reproof may reach those
helpers indirectly. Do not edit frozen code to make the boundary appear to pass.

## Concrete API and lifetime changes

Names below are proposals, not existing methods. Explicitly replace the currently
unimplemented artifact `prepare(intent, facts, principal, actor, liveRows)` seam:

| Owned entry/seam | Input and result | Authority/lifetime |
| --- | --- | --- |
| `MemberOriginals::prepare(definitionId, redemptionId, principal, actor)` | Server locators only; returns `MemberOriginalCandidate` after fixed capture/materialize/final-inspection sequence closes | Candidate descriptor/materialization input only; never a `MemberOriginalArtifactReceipt` or `MemberGrantReceipt`, no caller closure/manifest/path or credit write |
| `MemberOriginalSourceCapture::capture(...)` | Private `MemberOriginalSource` ticket containing copied canonical render/source values | Issued only after the capture transaction's committed source/owner receipt passes; contains no live Rows/PDO or reusable invoice/license/reservation proof |
| `MemberOriginalArtifactAuthority::prepare(MemberOriginalSource)` | Private `MemberOriginalMaterialization` object | Raw/native transaction-free render/store/observation; issuer map, source digest, profile, bounded physical observation and original workflow deadline; no ready/write authority |
| `MemberOriginalArtifactAuthority::admitReady(materialization, freshIntent, freshFacts, principal, actor, rows)` | `MemberOriginalArtifactReceipt` | C4c's actual effect frame only: exact issuer/source/owner match, freshly issued proofs and original observation/workflow remainder; no rendering, filesystem adapter or provider call |
| `proveReadyCurrent(receipt, freshIntent, freshFacts, principal, actor, rows)` | No value | Same concrete token/issuer/Rows/PDO/actor/principal and original final-frame caps; raw captured dependency checks after callbacks; never renews readiness |

All tickets/proofs are non-cloneable, non-serializable and non-HTTP; private paths
and ciphertext do not appear in debug/projections. A private constructor alone
is insufficient: the issuer's object registry is authoritative, not a binding
DTO. No ready token survives its Rows transaction or crosses to a retry frame.
Standalone preparation may run the same pure source/manifest checks in its final
read frame to check composability, but exports only the candidate. Returning
ready authority after that frame commits would be an API defect. If C4c is not
entered immediately in the same process with the issuing materializer, the
candidate supplies locators/digests only: recover and physically observe it anew
through a fresh issuer-owned invocation. A serialized descriptor never resumes
or renews the old materialization/ready object's clock or registry membership.

Capture uses the established lock order: T23 owner → billing fence and invoice →
plan/policy → catalog → period/redemption/full ledger → member facts. Reuse the
real reservation inspection, invoice and catalog producers. A narrowly named,
read-only member-source export from `MembershipReservationContext` (reachable
through its concrete reservation issuer after `proveCurrent`) can expose the
authenticated retained period/redemption/reserve event and catalog selection
values. It must not expose a mutation permit, generic Context constructor or raw
producer token registry. `CurrentEligibleLicenses::snapshot()` is a reference for
an in-frame value export only; a scalar export is not a committed receipt.

The source-capture bridge is a narrow concrete-issuer seam, provisionally
`MembershipReservations::beginMemberCapture` / `finishMemberCapture`. The new
owned member capture operation supplies a private, one-use operation permit and
exact observer/Rows/principal/actor identities. `beginMemberCapture` creates the
real inspection context and records it in the reservation issuer's private map,
along with the original deadline, copied provisional source and an opaque capture
token. It returns that token and bounded values, never a Context object or a
mutation permit. A public token constructor, source hash or expired reservation
proof cannot replace this private registration. The member operation has no
caller callback and no choice of mutation operation.

The operation's observer asks the issuer to reprove the same registered context
at the committing boundary, runs normal commit/afterCommit delegates, and must
complete its original proof before `finishMemberCapture` is eligible. The finish
path opens `IdentityCommittedFrame` on the original context's reader and original
deadline, then invokes the original context's committed source proof, not a newly
created context or `proveCurrent()` on closed Rows. The issuer consumes the
capture registration on success or failure. Only after committed proof and owned
cleanup succeed may the member source owner publish the detached ticket.

**Required composition seam before coding:** existing
`MembershipReservationContext::proveCommitted()` finishes the frame itself. The
operation lane must add a narrowly typed member committed closure that combines
member-facts checks with that original context's committed checks **before one
frame finish**. Preserve the existing standalone wrapper. Run all callback-capable
member/reservation checks first, then raw member/reservation dependency checks and
original deadlines with no intervening callbacks, and finish once. An internal
split of proof-versus-finish may serve this owned path, but must not become an
arbitrary public callback/context getter or renew a producer's deadline. No
member proof may be appended after the frame already finished. This contract
needs coordinated operation/facts signatures, not a new transaction-proof DTO.
Any callback/proof/cleanup exception yields no detached ticket; cleanup touches
only the still-owned frame.

Capture the exact owner/original identity, invoice semantic identity, policy,
period/redemption/reserve seals, selection/license/assets/terms, member definition,
profile and storage policy. Build render input solely from those verified bytes.
The detached source ticket is sealed into the preparation issuer's registry only
after normal commit callbacks and the original committed read proof succeed;
retain copied scalars, not a live Rows object that keeps its SQLite pin alive.

Freeze the artifact-manifest hash contract before the renderer and operation
lanes separate. Proposed v1 canonical input is exactly
`{schema_version: "member-original-artifact-manifest-v1", family, purpose,
origin_id, originals}`, where `originals` is the ordered, duplicate-role-free list
of `{role, sha256, bytes, storage_policy_hash}` values in the new profile's fixed
role order. `manifest_hash = SHA256(CanonicalJson::encode(input))`; the member
issuer computes it from verified output and recomputes it during fresh admission
and retained-record verification. Validate exact keys/types/bounds before hashing.
`MemberOriginalArtifactManifest` currently checks only shape, so its externally
constructed `manifestHash` is not trusted. Binding/hash equality without issuer
membership and recomputation cannot admit readiness. C4c must reuse precisely
this versioned contract for origin/artifact/activation joins.

Use a fixed namespace plus redemption ID to derive the prospective origin UUID,
and a domain-separated canonical render-source digest for its candidate path.
No current time, worker ID or random claim appears in deterministic render input.
Use the retained reservation timestamp as a clearly labelled source-record date,
not an asserted grant/activation date. A candidate document must not claim rights
were activated. Final legal wording/effective-date semantics remain unanswered
inputs; if they require an activation instant in the PDF, revisit this contract
before activation instead of inventing one or changing already issued bytes.

## Budget, physical observation and final readiness

Start one monotonic workflow cap before source capture; a proposed 300-second
maximum is the existing profile's claim ceiling, not a blocking-call guarantee.
Each child/I/O step is capped by both its own technical bound and remaining
workflow time. This original workflow cap can only shorten. The capture's paid
proof retains its C3 original database freshness window and capture operation cap
until the committed capture proof; it is retired when that frame ends.

Rendering does not extend that invoice/license proof. Final inspection creates
new proofs from current producers inside a new owned operation, with a new C3
database freshness window and at most the existing 60-second operation admission
cap, further shortened to the original workflow/materialization remainder and
retained honor deadline. These are new observations, never refreshed old tokens.
The current semantic source must equal the copied render source; a new fresh
observation alone cannot authorize changed invoice/selection/terms/owner content.
Those final-inspection proofs expire when standalone preparation closes its read
frame. C4c's actual effect frame must acquire its own new source/facts proofs and
invoke `admitReady` there, still under the original workflow/materialization
remainder if continuing the same invocation. A later invocation performs a new
capture/observation/recovery sequence; it cannot extend an old ticket's lifetime.

Observe actual private PDF bytes immediately before the final frame, outside all
transactions. Start its observation clock before the first file read and record
end time only after full size/hash/path/descriptor/mode checks. A proposed
60-second maximum age is independently shortened by the original workflow cap;
lock waits consume it. Preserve start/end and issuer ownership in the private
materialization token. No arbitrary caller timestamp supplies observation age.
Final database admission compares source values, configuration and that bounded
observation receipt; it performs no new physical-original file I/O. Readiness
reproof at the current/committing boundaries uses the same original live-frame
token and caps. Before commit the owned operation separately freezes a bounded
committed-evidence value/private registration containing retained scalar
dependencies, manifest identity and the **original** observation/workflow caps.
After normal commit callbacks, a distinct owned committed-read validator uses
`IdentityCommittedFrame` and that registration to check current retained rows,
owner, configuration and freshness. It never calls `proveReadyCurrent` with closed
Rows, never reactivates the readiness token and grants no write authority. Its
combined member/reservation raw closure finishes one committed frame. After that
frame closes, any promised physical result is observed again outside transactions
before reporting success; uncertainty never triggers an automatic write/retry of
a committed operation.

Detached rendering has no continuously live database source authority. Before
each step, recheck the captured configuration, member I/O barrier and original
workflow/honor bounds; rechecking these does not observe a database-only change.
If source ownership, invoice status, selected bytes or terms change during I/O,
the next fresh source inspection refuses readiness and leaves only an orphan,
with no origin/artifact/credit/activation effect. Do not promise that every such
concurrent withdrawal prevents the already-admitted child from doing any work.

This is a bounded physical observation contract, as explicitly described in D-18,
not an atomic filesystem/database transaction or continuous file-existence
guarantee. Out-of-band deletion/mutation after the last physical observation can
be detected by a subsequent observation/delivery check, not retroactively
prevented by a database receipt. Do not claim a test proves otherwise. Holding a
file descriptor or advisory lock does not turn a mutable host into immutable
storage. If activation requires a stronger guarantee, the host/storage contract
must provide and verify it before mounting; a new work table alone cannot.

## Deterministic candidate ownership and recovery

Proposed private path: `contracts/production-member-v1/{provenance}/{originUUID}/`
`{renderSourceHash}/original.pdf`. Every segment is derived by the operation,
not supplied by HTTP or an alleged artifact manifest. Validate the private local
root and every directory/entry; reject public/served overlap, symlinks, hardlinks,
incorrect modes/types and unexpected descriptor/path identity. Keep candidate
metadata private and authenticate any retained record later.

Render trusted expected bytes first, under the exact verified new member profile
and source. Write to an exclusively created random temporary file; verify/flush
and seal it, then use the reviewed no-replacement hard-link publication pattern
to install the final candidate name. The implementation must handle the brief
two-link state and failures deliberately; no "existing target" error is treated
as success. Remove only the current call's exclusive temporary entry after
checking its original device/inode. Never remove, overwrite or repair the final
target, even on mismatch. Reopen and fully verify the final target before issuing
the physical-observation token.

A process crash **after `link(temp, final)` but before `unlink(temp)`** leaves two
names for the same inode. The next invocation must refuse its `nlink=2` final
target even if bytes match: it does not own the previous process's temporary
entry and cannot silently remove it. Classify this as requiring a separately
authorized, reviewed exact cleanup/repair procedure. No automatic cleanup,
success projection, random-path fallback or relaxed hardlink check is part of
C4b. Normal orphan recovery below applies only after publication completed and
the final inode has the expected single link.

If another worker won or a crash left that final path, compare its exact bytes
with the freshly trusted renderer output as well as hash/size/profile/source.
An orphan path, caller hash or self-described sidecar is never authority. Equal
bytes allow a new local observation token; different/truncated/nonprivate bytes
refuse. Cross-process fixtures must prove equal input actually yields equal bytes
before this recovery path is accepted. A refusal does not select a random new
origin ID or overwrite the conflicting candidate.

| Interruption/state | Recovery behavior |
| --- | --- |
| Before candidate publication | A fresh authorized invocation recaptures source and renders; the failed call cleans only its own identifiable temporary entry |
| Link created, previous process crashed before removing its temporary link | Refuse `nlink=2`; no automatic adoption/deletion or alternate path; exact cleanup/repair is separate authorization/work |
| Publication completed with one link, no materialization token/DB origin | Recapture and deterministically rerender outside transactions, then compare exact existing bytes; no opaque token is resumed |
| Source/configuration/honor changed | Refuse final readiness; retain unreferenced candidate, no origin/credit/activation effect |
| Two identical preparers | Same deterministic target; loser must verify the winner's exact bytes; both preparation results remain non-authoritative until separate fresh final admission |
| C4c rolls back after inserting origin/artifacts or consume | All DB changes roll back; candidate stays private and retry requires fresh source/readiness |
| C4c has committed an original | Historical original identity and bytes remain authoritative; loss requires exact-byte restore, not a new rendering claim or replacement original |

Per-call byte/process/time caps and one deterministic target prevent a normal
same-source retry from generating unlimited final candidates. Staff-approved
source/profile revisions may leave distinct unreferenced paths. Record that
storage-growth limitation. C4b provides bounded read-only orphan inventory, if
needed, rather than deletion; any future cleanup needs retained-origin checks,
ownership, retention rules and a separate reviewed deletion contract.

## Focused acceptance matrix and PR boundary

Implement as one reviewable C4b batch with new member facts/record/source classes,
profile resources, deterministic renderer/process/files and explicit artifact
interface changes. Coordinate narrow reservation source-export edits with its
owner; root owns CI census/ledger updates. No migration, generic consume writer,
routes, service-provider bindings, real terms/prices or approval hashes are added.

Settle and commit the shared **API contracts first**, before parallel source
implementation: source ticket/issuer bridge, pure member documents and schema
plan, canonical manifest input, candidate/materialization versus live ready proof,
I/O barrier, original deadline fields, and the combined committed validator.
Suggested coordinated ownership for the single composed batch:

| Lane | Exclusive implementation responsibility |
| --- | --- |
| Facts/authoring | Strict profile/definition documents and records, pure profile lookup, staff gate/authoring, facts issuer, current 258 table/known-guard verifier and narrowly shared expected-plan extraction |
| Renderer/materialization | New member-only profile/manifest, render input/text/PDF/child, private no-overwrite files, physical observation and deterministic candidate recovery; use agreed barrier/token/manifest interfaces |
| Composed operation | Source capture and its private reservation-issuer bridge, raw/native all-connection barrier, artifact interface/lifetime changes, standalone candidate return, combined callback/raw/committed proof phases and integration tests |
| Integration lead | Shared contract sequencing and conflict coordination, real merge ancestry, CI census/ledgers, source-bound packet, separate final review and publication gates |

Do not assign the reservation Context's committed phase split to multiple lanes.
Facts supplies a typed proof contract; operation owns its exact ordering with the
reservation proof and the single finish. Renderer may not make a convenience
call into a profile filesystem helper from the final database admission path.

Required focused checks, with exact source/commands/results recorded at execution:

1. Real staff/MFA-authored immutable facts, idempotent replay, exact columns/seals,
   wrong owner/plan/license/profile/terms/retention/provenance and altered payloads;
   host hash gates without retained facts still refuse. Historical profiles read
   by retained registry; current render refuses a changed runtime/font/package.
2. Real synthetic PDF output, exact text and input/profile hashes, multipage/date/
   metadata determinism in independent processes, size/glyph/input/process bounds,
   default-off/unmounted behavior and all frozen-manifest/member hashes unchanged.
3. Transaction-free materialization includes direct native PDO transactions at
   Laravel level zero on the default, second resolved and separate read handles;
   original transactions survive refusal untouched. No child/storage call before
   committed capture admission or after an observed configuration/cap refusal.
   Source capture commits before I/O; final frame begins after I/O. A database-only
   source withdrawal during detached work refuses at fresh source admission and
   leaves only an orphan. Wrong issuer, shaped DTO/hash, clone, serialization,
   frame/connection/principal/actor swap and commit/reopen cannot admit readiness.
   A standalone preparation result cannot be used as ready authority in another
   frame. Trace technical profile helpers too: no hidden manifest/font file read
   inside the new member readiness or committed database closure.
4. Missing/truncated/changed/symlinked/hardlinked/public-path candidates refuse at
   actual physical observation. Real process no-overwrite race, equal winner
   adoption, mismatched winner refusal and completed-publication crash recovery
   all compare trusted expected bytes. A separate pre-unlink crash must refuse
   its two-link target and leave the prior temporary link untouched. Confirm
   unrelated files are untouched.
5. Original workflow/observation/final-operation caps only shorten, lock waits and
   callback work consume them, a late hint/current unpaid source or expired honor
   refuses final readiness, and a new retry obtains entirely new producer proofs.
   C3 freshness and retained business honor remain distinct. Test post-observation
   external file loss as the documented bounded-observation limit, not a claimed
   atomicity guarantee.
6. Whole affected SQLite files plus focused private MySQL source/currentness and
   frame/callback cases: establish an old RR snapshot, change a required member
   guard or full table definition before its metadata pin, and prove current
   SHOW-based refusal with unchanged positive controls and temporary-shadow
   negatives. Include a source withdrawal in normal afterCommit and in member/
   reservation committed callbacks; no detached source ticket escapes a failed
   combined proof. Verify exact canonical manifest recomputation for wrong order,
   role, origin, storage policy, hash/size and arbitrary shaped hash inputs.
   Record declared skips and untouched conditions. All C4b
   cases assert zero origin/artifact/consume/activation effects. Later C4c owns
   consume/activation rollback/races and full post-consumption ledger acceptance.

Use focused local checks, cheap exact-head preflight and a separate non-author
review. No Foundation or GitLab dispatch follows from this design. Commercial,
legal, account, host/storage/restore, latency and current-trigger inputs remain
open; no new question or production default is introduced here.
