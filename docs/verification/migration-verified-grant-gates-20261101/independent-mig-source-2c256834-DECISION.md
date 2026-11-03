# Independent MIG-G1a + G1b source review

Decision: **REQUEST CHANGES** — source inspection only; behavioral acceptance remains pending.

Reviewed exact frozen SHA: `2c256834d460f407bcc90239601441feb460edd9`.
Baseline: `2ab0c41e946e30cc402afc8ec107a3670e399abd`.
Reviewer: separate non-author Sol 6.1 reviewer, applying the code-verification skill. Target: complete lane diff, actual normalizer/delta/reconciliation/archive/review, competing predicate, additive declaration migration, affected tests, operator guide and verification packet. Earlier design decisions remain unchanged: approval at eb5b5881 covered the corrected proposal, not this implementation.

## P2-S1 — incomplete historical v2 batches can hide never-inserted evidence and superseded relinks

Confidence: high from source control flow; runtime reproduction not executed.

Locations at reviewed SHA: `app/Domain/Migration/History/LegacyGrantReview.php:142–156` validates physical completeness only for the newest batch; `:201–202` discovers contract candidates from physical originals/declaration rows; `:212` scans only physically archived declaration versions; `:337–339` validates member vocabulary/hash but does not resolve every retained advertised version. `LegacyHistoryArchive::import()` persists a full batch manifest before importing only the segment limit, so an authentic retained older batch can advertise records not yet inserted. This is a supported interrupted/resume state, not SQL corruption or a fabricated snapshot.

Case A (new missing declaration omitted): fully archive v2 A with genuine C1 on eligible line L. V2 B adds valid declaration C2 on L with its original absent and changes at least one earlier-cohort record. Import B with a small segment limit so its immutable batch advertises C2 but no C2 physical original/declaration exists. Fully archive newer v2 C that omits C2 and retains C1. Only C's completeMembers is required. latestVersions contains C2, but physical candidateKeys never discovers it; requiredVersion is never called for C2. The review can proceed with C1 alone. A variation introduces a previously unseen competing sale or ambiguous customer in the incomplete batch: the same physical-candidate pattern can omit those blockers.

Case B (superseded intermediate relink): fully archive declaration C1 on L in A. B advertises C1 moved to L2/order2, but its changed declaration segment is not inserted. Newest complete C returns C1 to L, possibly reusing A's original/declaration bytes. latestVersions selects C's valid version. The historical relink scan sees only stored A/C versions; B's advertised association is unavailable. The implementation can declare no relink despite the approved requirement to retain intermediate movement, including move-back. Latest-version-only physical validation would fix Case A but not this superseded-version Case B.

Consequence: an incomplete retained historical package can erase substantive missing-contract, competitor or relink blockers from eligibility/classification and authorized review. Provenance checks and a complete newest v2 do not establish historical completeness.

Smallest conservative remediation: before eligibility, require every retained same-source v2 batch to have complete resolvable membership (not only the newest batch), fail closed on unresolved manifests, and repeat in authorized writes through the shared derivation. Genuine resume is the recovery path. Do not silently ignore older incomplete batches, synthesize declaration links, permit a disposition to erase missing membership or add a rename escape. After authentic resume, Case A must expose the retained C2 blocker and Case B the historical relink blocker. The author lane suggested this same conservative direction; it is not implemented or approved as passing behavior here.

Required regressions on SQLite and native MySQL, preserving first failing output against this SHA:

1. Older incomplete v2 introduces a brand-new missing-original declaration; newest complete v2 omits it. Inspect and write refuse, existing classification withdraws without review mutation; authentic resume exposes multiple/missing declaration blocker.
2. Older incomplete v2 advertises an unarchived intermediate declaration relink; newer complete snapshot moves back to an already-stored version. Refuse before resume; after resume retain relink blocker, including later copies/omissions.
3. Previously unseen competitor/customer ambiguity in an older interrupted batch, then newest omission: refuse while any membership is unresolved; authentic resume restores the substantive blocker.
4. Unrelated-source incomplete batches do not contaminate a source's admission; all same-source complete batches still support eligible independent review. No artifacts/entitlements/grants are fabricated and provenance remains intact.

The existing `test_an_incomplete_intermediate_export_cannot_hide_a_retained_competitor` begins with a physically retained candidate and therefore covers a different path. The new declaration interruption test only inspects while the interrupted batch remains newest. Neither establishes the two cases above.

## Other reviewed areas and retained conditions

No additional P0–P2 source finding identified in this bounded inspection. The following are source observations, not passing runtime guarantees:

- Stable v1 key namespace is separated from v2 snapshot/transform; original records retain old shape, missing declarations retain actual metadata with null artifact link, genuine zero bytes remain distinct.
- Sidecar has additive exact original_id linkage, uniqueness, native RESTRICT foreign keys, insert guards and update/delete/replace refusal intent. Application decrypts, compares exact identity/order/row metadata and rehashes actual artifact bytes. Native trigger execution/SQL modes/collations and schema guards remain unverified.
- Source-wide any-v1 admission is implemented for complete/incomplete/older batches; unknown transforms refuse. Immutable historical reviews are retained and v2 evidence purpose binds newest snapshot and declaration/original hashes.
- Physically retained ever-associated declarations, including moved-back versions, remain candidates and retain origin declaration/original row/batch/segment actors. G1a collects current clearing versions before filtering and excludes their origin actors; these DB authorization paths have not run.
- Shared zero-value competitor predicate and bounded payment-reference/refund delta changes have affected pure tests, as reported by the author. Mixed-version delta and versioned cohort admission are explicit. V3 remains an operator procedure, not a machine-enforced reconciled-delta gate.

## Evidence status and verdict limits

Specification result: **fail by source inspection** for P2-S1; engineering/runtime result: **partial/unverified**. Only read-only git show/diff and skill reads were executed in this review; no tests, CI, database or PCC worktree operations, source edits, pushes or live actions occurred. The author reports exact-SHA pure units 44 tests/378 assertions, Pint on 13 files and PHP lint pass; these are not independently rerun here and do not prove DB behavior.

Before acceptance: repair P2-S1, retain old-source regression evidence and rerun affected suites, all required SQLite/native migration/auth/provenance cases and meaningful guard mutations from the packet on the final frozen SHA; independently review that exact tested SHA. Existing tests are read as evidence plans only while unexecuted. G1a DB/native review and all five overall activation gates remain open. This decision does not approve runtime behavior, concurrency, production admission, active imports or launch readiness.
