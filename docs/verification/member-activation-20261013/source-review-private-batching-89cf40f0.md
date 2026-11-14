# C4c private batching implementation source review — 2026-10-14

Reviewer: `/root/activation_deadline_independent_review`, separate non-author reviewer. This is bounded ordinary application correctness review under AGENTS and the Code Verification instructions, against the accepted private batching design addendum. No implementation/tests/configuration were edited and no application/DB tests, native workloads, provider actions or hosted checks were run. Only read-only source inspection and this new runtime note were performed.

## Exact target and source conclusion

Inspected exact product commit `89cf40f0129822df82f44777811c709c704c6c62`, its immediate eight-file delta (258 insertions/five deletions), relevant unchanged callers, and `docs/verification/member-activation-20261013/private-batching-addendum.md`. **The implementation conforms to the accepted private batching design. No new product P0/P1 was established and no further product correction is required by this bounded source review.** Runtime acceptance remains pending the lead's focused results and eventual native positive/committed/readback evidence.

The separate race schedule correction is `cec922a4c4ccc87dabfd9bc5bc64f8dbad07df90`, carried in the inspected source. During this review the lead committed test-only `a47e0d69ca593efd0ed945aebff7b4007bebe5f5`. Its sole delta fixes the pure public-readiness-refusal test's synthetic manifest; product bytes are unchanged from 89cf40f0. This correction was inspected separately and is not silently assigned to the original 89 test bytes. The tree was clean at the observed heads.

The addendum accurately records the C4c-only provisional boundary and the diagnostic's physical-cap attribution. The original API-contract bytes are retained. Public/C4b readiness, ordinary producer paths, original min-only 60s/300s/honor/physical constraints, complete post-delegate/committed checks and default-off/unmounted C4c remain separate obligations. No full C4c DECISION, release, legal, host, credential, provider, payment, Foundation CI or GitLab claim is supplied here.

## Permit tuple, single consumption and retirement

The sole actual artifact permit factory is inside private `MemberOriginalSourceCapture::inspectActivation` after registering the actual active inspection and admitting the actual authorization claim. It is reached only with materialization; `artifactPlanSources` requires the exact Source issuer, a `MemberActivationCompletionPermit`, actual proof/claim, no prior materialization, and uncommitted/unretired state, with original configuration/Budget/deadline admission.

The stored tuple at SourceCapture:383–384 binds exact Sources, Artifacts, inspection, materialization, intent, facts proof, source values, principal, actor, Rows, captured PDO and original Budget. The exact inspection itself was constructed through the source-owned complete service/owner/configuration/finish/deadline tuple, so those captured service identities are transitively fixed rather than inferred from DTO contents.

`consumeArtifactPlanPermit:589–599` reads the private registration and unsets it **before any validation**. Its `is_array` guard short-circuits a shaped permit before uninitialized Rows/issuer fields are used. A registered permit must match the complete exact tuple, same concrete Artifacts instance, same actual current active inspection, and same private completion source identities. A replay, changed tuple, foreign issuer/frame or retirement cannot recover the consumed registration.

The factory `finally` at SourceCapture:387–388 removes any remaining permit if admission refuses before consumption. After every source-frame outcome the normal outer `finally` removes active source registration, retires the inspection, and invokes `Artifacts::retireInspection`. The latter clears the exact stored plan token and inspection association; workflow retirement removes the materialization entirely. Opaque permits/tokens refuse clone/serialization and contain no transferable registration.

## Stored plan token and public readiness separation

`MemberOriginalArtifacts::admitActivationPlan:109–120` first consumes that permit, then checks actual inspection intent/facts/principal/actor/Rows identity, actual source-values identity, original materialization Budget and exact source hash/document equality. It obtains the existing materialization's validated manifest/observation, creates a distinct `MemberActivationArtifactPlanToken`, and stores that exact token in the materialization registration. It does not populate the public `ready` WeakMap.

`assertActivationPlan:130–133` closes the usual exact Sources/inspection/source/Budget/materialization association, rechecks original configuration/physical bounds through `entry`, then requires both the distinct PlanToken class and `entry.plan_token === suppliedReceipt` plus exact manifest-object identity. A caller-created token with identical manifest contents is insufficient. `MemberActivationInspection::admit` uses that stored-token assertion whenever its materialization is present, including mandatory current/committed/postfinish admission. Pure bounded manifest recomputation and captured registry/deadline checks introduce no producer refresh or filesystem read.

Public `admitReady`, `readyInspection` and `proveReadyCurrent` retain their full current proof and original tuple checks. Public readiness requires an actual registered `MemberOriginalReadyToken`; it rejects the separately typed PlanToken. Adding an interface implementation to the new value carrier therefore does not promote it into public readiness or a writer permit.

## Mandatory write/commit boundaries remain complete

`insertActivation:277` still calls the actual grant issuer's `proveReadyCurrent`, which requires its exact private registered grant receipt and calls unchanged `proveGrantPlan -> proveCurrent`. This full authorization/facts/reservation callback phase, all raw closures, PlanToken admission, frame/configuration and original caps follows **all** planned record/encryption/UUID/clock/history/audit work and precedes the exact planned-to-inserting transition/native INSERTs. No public generic defer flag, reused effect permit, caller expected-row setter, new clock or shared schema cache was added.

The actual fresh inserted graph/scope old-plus-plan comparisons, complete history/ledger audit, one-way transition and original terminal/honor/authority/freshness checks are unchanged in `MembershipReservationContext::proveInsertedActivation`. After it returns, Inspection now performs callback-free config/Budget/PlanToken/finish/Rows admission rather than the redundant full source proof.

The fixed SourceCapture path proceeds directly from that private insertion return to its owned normal commit. The unchanged `MemberActivationObserver` runs ordinary committing delegates first, then the full context source proof, and marks `proved` only after that closure. That is the mandatory pre-physical-commit closure for inserted-history authentication callbacks as well as the delegates. The distinct committed proof still authenticates the persisted graph and sources, finishes once, then applies reservation/authorization postfinish and final captured admission. Results remain local until that closure and the existing byte-bearing historical read complete. No cap renewal is introduced after physical commit.

## New test source and the fixture-only correction

The shaped-permit unit case meaningfully requires refusal before uninitialized dependencies can do work. The separately typed public-readiness-refusal case exercises public admission with a PlanToken. At **89cf40f0**, its manifest used origin ID `synthetic` and an empty artifacts list; `MemberOriginalArtifactManifest` rejects those values during setup, before the intended public-readiness call. That is a concrete test fixture defect, not evidence of readiness rejection.

The inspected **a47e0d69** correction supplies a valid canonical UUIDv4 and one bounded artifact with the exact role/hash/bytes/storage fields. This reaches the intended `foreign_readiness` gate without changing product behavior or substituting a successful issuer. Its execution and the original fixture error, if run, must be attributed to their actual sources/receipts; no result is invented here.

The new RetentionTest installs a real forwarding crypto observation hook after actual fixture creation. It forwards decryption and actual encryption, arms only for the actual origin-record envelope, proves an owned active transaction and zero origins, then really changes catalog status before the origin insert. It requires reaching the hook, source refusal, complete unchanged effects/rollback and restored catalog status. This is appropriate preinsert-withdrawal coverage, with the pending positive candidate needed to distinguish an unrelated later refusal. It does not itself exercise withdrawal during the new inserted-history authentication boundary; that separate scenario remains an evidence gap if full acceptance claims that boundary was directly executed.

The private-registry single-use/token identity conclusions above are source-established. The added pure cases do not themselves execute a genuine permit replay or a forged replacement token against a real materialization. Do not describe them as comprehensive runtime registration/cleanup proof.

## Race schedule correction

CEC922a4 prepares the scheduled winner first, then the loser, and reduces the winner held-marker await from 120 to 90 seconds. In release-wins order the genuine activation ticket/start barrier now begins after release readiness, eliminating the previous possible 100-second release startup plus 120-second held wait against activation's 120-second start cap. The remaining 90-second held wait fits that start barrier at source level. In consume-wins order the already readied winner starts after the loser's at-most-100-second startup, within its 120-second start bound.

This closes prior **C4C-TEST-P2 at source level**. Actual native receipt execution remains outstanding. The 20-second delegate hold/18-second row-wait observation still spends original winner source/observation headroom and requires actual successful winner receipts within unchanged product limits. Neither the harness correction nor its source review proves that headroom or changes product caps. All four earlier new portable/native authored cases remain separately attributable to their completed results when available.

## Source inventory and remaining acceptance

Exact 89cf40f0 Git-blob hashes, calculated with Python standard library:

| File | Bytes | SHA-256 |
| --- | ---: | --- |
| MemberActivationArtifactPermit.php | 949 | `be6ccf778bac7956dfbdaf23cbc56b07528ea05e57ec23be28d4b5681b6fe2d4` |
| MemberActivationArtifactPlanToken.php | 1124 | `54b65168f90fd85a27ae89df14e722b14678dedf1bfcc37d039bfed8da710c3f` |
| MemberActivationInspection.php | 21612 | `83f6912590b13bec1a557a2bfd30135927cf55d88a87ef13989f4e4a3daefe7e` |
| MemberOriginalArtifacts.php | 12233 | `11958d350a03a1e9693cfa5331ea486197b52b170f15c9e333037e7b9abda016` |
| MemberOriginalSourceCapture.php | 35095 | `5336e88dcbc7ef8febc892989031e335c01973f1e181dcd29b18f034945c2a8d` |
| private-batching-addendum.md | 3981 | `61cab5b8eafb680b38a868e789ababa05cbd15f2d4bc56961fe9e98027992262` |
| MemberGrantActivationRetentionTest.php | 10650 | `75487936923e40eaa2aeddcb31b191778cb4a2b5083affabedaa6fdbc1ae96eb` |
| MemberActivationOwnershipTest.php (89 fixture) | 7680 | `e1ea3678342be50633f1279daec5e32233979dc10050e8b38b9a79c78fd910da` |
| MemberGrantNativeActivationReleaseRaceTest.php | 16691 | `314c28ee1336e901a1068291095fe12da8358d45db3862c6ed5954c2c28cefc7` |

PHP names are in `app/Domain/Grants/Member` except tests in their previously named paths; the addendum is in `docs/verification/member-activation-20261013`. These hashes bind this source review, not a full input inventory or test execution. The a47 unit fixture bytes intentionally differ.

Full application acceptance remains **partial/pending**. Preserve the two original uninstrumented native failures and labeled diagnostic, original observation clocks, C4b reconciliation boundary and any next-phase committed/readback refusal. A measured subtraction from diagnostic time does not establish new unmodified native success. The lead owns focused tests; final complete-source/evidence DECISION remains a later review against actual frozen results.

Read-only commands used: `git rev-parse/status/log/show/diff`, `cat`, `nl`, `rg`, and Python hashing of Git blobs. Only this runtime note was written.
