# Independent MIG-G1b corrected design rereview

Decision: **APPROVE — design only**.

Reviewed exact SHA: `eb5b58810e5c2b0502fcf4789fc94ecaefb03d3d` in `/workspace/VA-Studio-mig`.
Baseline: `2ab0c41e946e30cc402afc8ec107a3670e399abd`.
Reviewer: same independent non-author Sol 6.1 reviewer. This additive decision supersedes the two design blockers in `independent-mig-declaration-design-a2537055-DECISION.md` only for this corrected proposal. Inspected the exact committed packet, including corrections at lines 79–95. No source edits, tests, CI, DB or live operations performed.

## Disposition of previous design findings

**P2-D1: adequately corrected in design.** Lines 74 and 83 now require source-wide v2 retained batch history, not merely a complete newest v2 export. Any same-source v1 batch, complete or incomplete, refuses eligibility; unknown transform versions refuse. Inspection/classification and authorized writes both enforce this. Existing mixed histories remain blocked even if all known originals map into v2. No fabricated history, originals backfill or unreviewed package-completeness evidence can lift the blocker. Lines 85–87 explicitly prohibit treating a source-system rename, old-batch deletion or equivalent regenerated records as recovery. This conservative design avoids claiming knowledge of older missing declarations that v1 discarded. Any future completeness recovery remains separate reviewed work.

**P2-D2: adequately corrected in design.** Lines 93–95 expressly supersede the earlier current-link-only count. Candidate discovery includes every retained declaration version ever associated with the line; moved-away current versions remain resolved and consulted. Historical multiple line/order associations remain an unresolved blocker even after relink back, copy or omission. Inspection/classification and authorized write apply this independently of delta dispositions or V3 procedure. No implicit relink authority remains.

No new P0–P2 design defect identified in this bounded rereview. This disposition closes the two proposal-level issues only; neither behavioral finding nor activation gate is closed by documentation.

## Implementation acceptance remains required

The original decision's eight-part acceptance matrix remains applicable, with D1 recovery tests now asserting permanent fail-closed mixed-history admission because no recovery mechanism exists in this scope. Specifically preserve:

- Complete/incomplete/older/late-arriving v1 batches and unknown transforms block both inspection and write; newest v2 and original mappings cannot lift the refusal. Existing review classification withdraws without mutation. Stable source identity cannot be renamed as a recovery strategy.
- Every retained declaration association is checked, including intermediate relink then move-back, newest copy/omission, changed order with the same line key, and missing selected versions in incomplete batches. Prove blocker retention without relying on delta review. Provenance includes all consulted rows/originals and origin batch/segment actors before filtering, and is repeated within write.
- Populated v1 upgrade and resume preserve bytes, hashes, actors, immutable reviews and schema guards; five-cohort v2 membership is exact and interrupted declaration imports fail closed/resume correctly.
- SQLite and native MySQL prove additive schema ownership, foreign keys, all-null/all-present supplied-original metadata, genuine zero-byte originals, exact original_id matching, ciphertext/artifact verification and UPDATE/DELETE/REPLACE/duplicate-update rejection. No invented artifact metadata or original rows.
- Stable v1 record-key namespace, versioned v2 normalization/transform/evidence hashing, archive/reconcile/resume/delta admission, unknown version/cohort refusal, immutable review uniqueness and absence of grants/entitlements all require exact implementation evidence and meaningful guard mutations.

Acceptance must review the exact tested implementation SHA independently. No DB slot was used or authorized by this rereview. PCC retains its heavy slot. G1a at `d714fcd2` is outside this design approval and remains unapproved pending its separate queued DB/native and other acceptance evidence. No concurrency or launch readiness claim is made. All production activation and live-action boundaries remain intact.
