# C4b member originals — independent early design review

Reviewer: `/root/membership_award_independent_review`, separate from the design and implementation authors. This is a design assessment, not a DECISION approving unwritten application code or a release.

## Scope and source

Reviewed `/workspace/runtime/member-originals-c4b-design-20261013.md` and its JSON inventory against the actual 258 tables/interfaces, current C3 freshness and reservation composition, and C4a's captured member policy. The initial design SHA-256 was `a4d4e799ef0571d380e00bd29459e82bdc3b0a7ba62e2f767e5db04156962847`.

Current source was read in `/workspace/VA-Studio-member-source-currentness`, initially at `ca0fe30b6614d64a8b9050a39d6944256f170d69` and then `0095e46aefa85463268027d89c829a4886537ad6`. The intervening difference is two workflow files; the inspected application files are unchanged. The design inventory separately identifies C3 application `0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371`, packet `526e6f5b12068ca3d9571dfcb47dc25194e24598`, capture head `7e01ccb9cd770eff897986d0db176421043c9d24`, and C4a policy `15a65e0761382a9ad50c4b279605a644a4eeb285`. These are source references, not assertions that planning commits were merges. C3's actual merge is `7b467c3824e2da81174a5445b6ee639cf0a59d93`.

No application/database tests, DB statements, native service operations, provider calls, hosted checks, source edits, or production actions were performed. Read-only source/Git/hash checks independently matched all 55 inventory entries against their three stated Git references (165 comparisons), all five frozen manifests and 38 referenced member entries against current bytes and declared hashes, and the captured C4a policy against current integration. The initial design MD hash matched its JSON. These checks establish source identity only.

## Assessment

The bounded capture → transaction-free materialization → fresh final readiness design is viable without a new work schema for this unmounted synchronous batch. It fits 258's immutable final manifest: preparation need not create an origin, and C4c can insert the complete origin/artifacts and coupled consume/activation in one later owned transaction. Staff profile/definition authoring is a separate effect from customer preparation.

Seven contracts below must be explicit before splitting implementation ownership. They were sent to the root and design author. They are design obligations, not claims of newly tested application defects. In particular, 258 currentness belongs to C4b's newly operative facts; it is not a request to reopen C4a's frozen policy/catalog scope.

### 1. The public result cannot outlive readiness authority

The proposed standalone `MemberOriginals::prepare()` completes and closes its final frame, while the plan correctly forbids readiness-token reuse after that frame. Define its result as a non-authority candidate descriptor with no private path, live proof, or promise of a grant. The current artifact interface at `app/Domain/Grants/Member/MemberOriginalArtifactAuthority.php:13` explicitly needs replacement; it currently receives live Rows in `prepare()`.

A future C4c operation must obtain a private issuer-owned materialization through an owned workflow and call `admitReady` inside the same final transaction that writes its effects. A descriptor supplied by a caller, a closed-frame ready token, or a token from another issuer cannot supply readiness. Returning the C4b descriptor is not a consumption API.

Current/committing proof uses the live transaction token. Committed verification must instead be a separate owned committed-read closure over retained dependencies and the original physical/workflow caps. Do not call `proveReadyCurrent` with closed Rows. Observe any promised physical result again only after that committed read closes, without extending the original allowance or retrying a committed effect.

### 2. The transaction-free barrier must include native transactions

`app/Domain/Contracts/ContractIo.php:12` only checks Laravel `transactionLevel()`. Direct `PDO::beginTransaction()` can leave that level zero. A new member-owned barrier must check both framework and native transaction state on all resolved connections and reject unexpected primary/configuration changes. Recheck at each actual child/file/observation boundary after callback-capable setup; do not edit the frozen helper.

Acceptance must include direct PDO transactions on the primary and a second resolved connection, including a setup callback that opens one before materialization. The child/storage/physical observation must not run in those cases. Existing `MembershipReservationOperations.php:61` and `:73` demonstrate the paired checks for an owned entry.

`ContractRenderProfile::validate/hash()` indirectly reads `profile-assets.json`, and `verifyVersionRuntime()` opens implementation/font files. Existing technical verification APIs therefore belong outside the new live readiness frame. Their names do not make them pure metadata checks. Final admission compares captured verified metadata/observations without accidentally invoking these filesystem helpers.

### 3. Operative 258 facts need actual current table and required-guard checks

`MemberGrantSchema.php:75` reads table metadata through `information_schema`; its `SHOW CREATE TABLE` check at `:85` only rejects a temporary table. Required guard bodies at `:115` also come from the dictionary snapshot. Calling `assertOwned()` alone does not fulfill the new reader's exact-currentness promise under an earlier REPEATABLE READ snapshot.

The fixed allowlist reader/writer must pin required subjects and dependencies before comparing current complete table structure and current named required trigger definitions. Preserve engine, columns, collations, keys, checks, foreign keys, guard subject/event/timing/body and shadow refusal. Canonicalization must not hide a structural change or change literal/check semantics. Qualified captured names and prepared predicates remain necessary.

Require native old-snapshot regression evidence for an altered table and a removed/replaced required guard, including honest failing old-source receipts and fixed-source checks. Preserve the separately documented limitation on current inventory of unknown additional triggers; an old `information_schema` listing cannot prove that inventory current. No installed 258 definition change or new migration is inherently required to strengthen inspection.

### 4. Capture needs an owned combined committed-source bridge

`MembershipReservations.php` exposes `lock()`/`proveCurrent()` and privately retains its issuing Context. The proposed value-only export is useful, but cannot itself invoke that Context's original post-callback committed proof. `MembershipReservationContext::proveCommitted()` at `:180` exists and calls `frame->finish()` at its end.

Specify a narrowly typed issuer/operation-owned bridge bound to the original inspection and capture observer. It must perform member and reservation callback-capable work first, then a final raw closure over both dependency sets before a single committed-frame finish. Checking member facts and then invoking the entire existing reservation proof is insufficient if later reservation callbacks can change those member facts. Preserve the standalone reservation wrapper; expose no Context getter, mutation permit, arbitrary callback, or reusable raw producer registry.

Mint the detached source ticket only after this combined committed receipt and cleanup succeeds. It contains copied canonical values, not a live Rows/PDO or reservation/invoice/license proof. The original C3 window remains bounded through capture's committed proof and is then discarded. Final inspection obtains new producer proofs and a new C3 window against the same semantic source; it never refreshes the old token.

### 5. Freeze the canonical artifact manifest computation

`MemberOriginalArtifactManifest.php:10` accepts a shaped `manifestHash`; its constructor validates value shapes, not the relation between that hash and the entries. The new issuer must compute the manifest itself from a fixed versioned canonical document containing the deterministic origin and ordered role/hash/size/storage-policy values. Bind the resulting value to the exact source/profile and issuer-owned observation. The proposed single `license_pdf` gives one explicit ordinal for this version.

A caller-computed hash, shape-valid DTO, changed order, wrong source/profile/storage policy, or different origin must not become readiness. Keep the complete terms-bound member profile hash distinct from the reusable technical renderer descriptor hash, as the design already proposes. Otherwise 258's unique profile hash can collapse different terms onto one retained row.

### 6. State the source-withdrawal guarantee at the boundaries actually observed

The acceptance phrase “no child/storage call ... after source/cap withdrawal” must not imply continuous database observation during detached rendering. Initial committed source admission, current captured configuration and the original workflow/honor caps permit bounded materialization. If a source changes after that observation or during I/O, fresh final admission refuses and the candidate may remain an unreferenced private file with no origin/artifact/credit/activation effect.

No new proof may extend the original workflow, physical-observation or retained honor bound. Fresh paid observation identity may differ; the semantic invoice, original owner, policy, period/redemption/reserve, catalog/license/assets/terms and selected member facts must still equal the copied render source. Keep C3 database freshness separate from business honor and physical observation age. A final deadline check after waits/callbacks is required; a duration bound does not promise to interrupt a blocked syscall or query.

### 7. Distinguish the two-link crash from adoptable orphan recovery

After `link(temp, final)` and before `unlink(temp)`, both names refer to an inode with `nlink=2`. A process crash there leaves a final candidate that strict hardlink checks must reject. A later invocation does not own the old exclusive temporary name, so the current-call-only cleanup rule cannot silently remove it.

Explicitly classify this state as fail-closed and requiring separately authorized exact repair/cleanup, unless a separately reviewed publication primitive avoids it. Do not describe every post-publication crash as automatically recoverable. Tests must distinguish this interval from a crash after temporary-name removal: the latter can be recaptured, deterministically rerendered and adopted only after exact trusted byte equality and fresh observation. Neither case permits overwrite, deletion of unrelated entries, a random replacement origin, or a self-authored sidecar as authority.

## Preserved boundaries and later acceptance

The immutable 258 layout supports the proposed no-placeholder approach. The separately enabled authoring path may create the first complete profiles/definitions without already-approved customer hashes, but must preserve staff/MFA admission and complete callback/commit closure. It must not weaken C4a's full customer capability/frame gate or convert configuration values into facts.

Membership v2's null retention and award/reserve/release/expire audit remain unchanged. C4c still needs an explicit successor consumption policy and complete retained origin/artifact/activation audit. No prospective activation hash, generic consume entry, route, binding, grant, historical-original replacement, or approval hash is justified by C4b preparation.

The new member renderer needs its own verified profile and independent-process exact-byte tests. Frozen resources remain unchanged; technical fonts/packages may be reused through their actual API without relabeling another family's legal purpose. Candidate date labels must not invent an activation date. Legal/effective-date, commercial retention, host storage/restore and production readiness remain unanswered inputs. The bounded physical observation contract does not prove continuous filesystem integrity, atomic file/DB commit, or host verification.

Implementation acceptance still requires the design's focused source/facts/frame/callback, real synthetic PDF, no-overwrite process race, orphan/crash, deadline/honor and private MySQL currentness cases, with exact source/results and declared skips. No such C4b tests have run in this review. A fresh non-author exact-candidate implementation review and cheap preflight remain later gates.

## Revision follow-up

Reread the released revision after the author incorporated the contracts above:

- Design MD SHA-256: `3e80f8c6577eeb2e2e4aed392312940c1edc6bb09e622d156cf79c1f3e4d75f7`.
- Accompanying JSON SHA-256: `666242f4a7f07293bffa9e58653ff7b2992b41d403040750d36305e143d91f01`.
- The expanded inventory's 59 entries match their three stated Git references (177 comparisons); its two vendor declaration excerpts match the identified dependency bytes. The JSON's revised design hash matches the MD. No behavioral test was run.

All seven issues are now explicit design contracts, including the separate committed validator, callback-first combined closure, pure expected 258 plan/current sixteen required guards, every resolved native write/read handle, server-computed versioned manifest, bounded source-withdrawal claim, and two-link crash refusal. The facts/renderer/composed-operation lanes have distinct ownership, and the shared API contracts must land before parallel source implementation. The reservation committed phase split belongs to one operation owner.

No remaining design-level contradiction identified requires a new schema or a new commercial/legal default. Proceed with the documented contract-first implementation after the actual C4a prerequisite is integrated. This resolves design wording only: every currentness, frame, byte, deterministic recovery and callback claim remains subject to actual implementation, recorded tests and separate final exact-candidate review. No C4b application DECISION is issued.

## Bounded API consistency follow-up

Accepted `/workspace/runtime/member-originals-c4b-api-contract-20261013.md` at SHA-256 `076c275b1a615d7bda34785c6c716a1f0ec1353a4070aca3ef676f8d95d71eaa` as the shared implementation contract. No remaining API contradiction identified in the requested review scope.

- `originId = authenticated redemptionId` preserves the actual UUIDv4-only `MembershipValues::id()` constraint. The inspected 258 per-table primary keys, unique redemption relation and artifact/activation joins impose no inequality between these two IDs. They remain distinct logical objects. Actual effect insertion/coupling remains C4c acceptance.
- Artifact/activation row UUIDs and their record envelopes stay outside the deterministic PDF and canonical manifest. The manifest remains server-computed from the fixed origin and ordered verified content; later owned effect insertion can allocate row IDs without changing it.
- Corrected source projection retains the historical T23 `verification_observation_id`/hash inside exact buyer bindings and the semantic invoice. Only fresh per-call C3 retrieval observation IDs/seals/leases are excluded; a blanket exclusion of all observation IDs would have contradicted existing source semantics.
- The registered source claim carries the exact original budget and bound materializer. Facts/reservation callback phases precede the combined raw closure, and the operation-owned committed proof finishes once. Existing reservation wrappers stay complete; no closed-frame token reuse is authorized.
- An MD-only shared baseline followed by exclusive complete-type ownership is consistent with the design. Operation owns its interface/double changes after file handoff; no placeholder PHP success or scaffold approval is implied.

This follow-up used bounded source reads and the API file's hash only. No DB/application test or inventory corpus was rerun, and no application source was edited. This is API/design acceptance, not an implementation DECISION.
