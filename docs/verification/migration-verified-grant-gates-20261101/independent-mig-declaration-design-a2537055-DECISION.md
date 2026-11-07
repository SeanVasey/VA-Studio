# Independent MIG-G1b migration/auth design review

Decision: **REQUEST CHANGES** (design only).

Reviewed exact SHA: `a25370557f57765a1ae0d518af9c23c78acfd5e8` in `/workspace/VA-Studio-mig`.
Baseline: `2ab0c41e946e30cc402afc8ec107a3670e399abd`.
Reviewer: separate non-author Sol 6.1 review agent. Scope: concrete G1b proposal in `docs/verification/migration-verified-grant-gates-20261101/README.md`, especially lines 50–76. Read root AGENTS.md, November 1 handoff and MIG deferred ledger entries. Read relevant exact-commit normalizer/review source for existing semantics. No source edits, tests, CI, database operations or live actions performed. G1a at d714fcd2 remains separately unverified; this review neither approves it nor closes any activation finding.

## P2-D1 — newest v2 does not establish completeness of older v1 history

The compatibility admission at line 74 requires only the newest batch to be complete v2. Line 76 detects v1-only *stored originals*, but cannot discover old missing-original declarations that never became originals. Exact-commit v1 HistoryNormalizer::contracts() records a line exception then continues without retaining declaration identity (lines 474–479). Thus discovery from original/declaration tables is inherently incomplete for v1.

Counterexample: v1 package A declares real C1 plus missing-original C2 on line L; later package B omits C2. Re-normalize/import only B as v2, retaining C1 and its exact original. Newest is complete v2; C1 satisfies all original-to-declaration checks; no stored C2 identity enters latestVersions. The proposed concrete admission can accept L despite the retained historical missing declaration. Refusing mixed-version *delta* is insufficient because the review path is not required to execute such a delta. The broad mandatory re-normalization language at line 20 does not define or enforce coverage over every prior package; the concrete algorithm leaves this unsafe admission available.

Required change before implementation: choose an explicit rule. Either block review whenever the relevant source-system history includes v1 batches whose declaration completeness is unproven, or define immutable, exact source-package completeness evidence for each relevant v1 batch and require validated v2 re-normalization/import coverage before admission. Specify package mapping, hashes, coverage boundaries, immutable provenance, completeness membership and evidence-hash binding. Recovery must preserve C2 and its blocker even when later exports omit it. Unavailable source packages fail closed. Stored originals, a clean newest snapshot, line-level exception absence, or operator assertion alone cannot invent absent history.

## P2-D2 — concrete current-link filtering permits relinks to clear a blocker

Line 20 says relinked declarations refuse, but line 76 resolves moved-line declarations for provenance and then counts only declarations *currently linked* to L. Line 74 labels a link delta blocking without requiring a reconciled-delta authority in review; V3 explicitly remains procedure only. Hence the concrete rules do not implement the stated relink refusal.

Counterexample: complete v2 A contains C1 and missing C2 linked to L. Complete v2 B moves C2 to another valid line and retains genuine C1 on L. Resolution retains C2's authors but excludes C2 from L's count; an independent operator can review L with one current declaration. Excluding the relinker prevents self-review but does not preserve the blocker. An original-linked declaration moved away presents the same ambiguity.

Required change: specify that a historical declaration identity ever associated with L whose current order/line binding changes causes an explicit relink blocker on L (and define handling of the destination). If later authority may resolve a relink, that requires a separate explicit reviewed disposition design; do not assume procedural delta review grants it. Ensure currentness, eligibility, classification reread and write all apply the same rule.

## Design elements acceptable subject to implementation evidence

- Separate immutable sidecar preserves existing originals and original migrations. Unique `(record_key, record_sha256)` supports immutable version reuse; batch membership supplies observation/currentness rather than mutable rows.
- Explicit stable v1 record-key namespace with independently versioned v2 snapshot/transform prevents identity churn. Existing cohorts and original hashes stay byte-for-byte stable.
- Nullable original_id plus the three supplied-original fields, all-null/all-present guards and exact original matching is an appropriate original link. Genuine zero bytes are distinct from absence. Application must compare encrypted identity/order/row data and actual stored artifact bytes; SQL guards alone cannot authenticate ciphertext.
- Native batch/user/original RESTRICT foreign keys and no foreign key to nonunique logical order/line keys are appropriate provided exact decrypted links and snapshot membership are revalidated. Ensure source-system agreement follows from validated identity and batch provenance.
- Five-cohort v2 membership, original-first import, atomic refused segments, no fallback from missing selected intermediate versions, origin-row/batch/all-segment provenance exclusion, retained omission blockers, and immutable prior reviews are appropriate.
- Keeping archived v1 reads/resumes while withdrawing eligibility is conservative. No entitlement creation or activation is authorized by any historical classification.

## Required acceptance matrix after the design is corrected

1. Normalize a real and missing-original declaration on one line: both identities retained; missing artifact metadata is null, no fabricated hash/path/bytes/original; genuine empty original remains distinguishable. Duplicate identities, bad links/filename/hash withhold normalization.
2. Populated v1 upgrade preserves original bytes, hashes, actors, batches, reviews and triggers. Exercise old v1 partial import/resume. Reject foreign/partial sidecar schemas; rollback retains evidence and incompatible consumers refuse.
3. Reproduce D1 with older v1 C1+C2-missing and newest v2 C1-only. Newest-only re-normalization must remain blocked. Cover absent old source package, forged/incomplete coverage, complete authentic old-package re-normalization, later omission and late-arriving older v1 history. Verify completeness-evidence authors cannot review the resulting decision themselves.
4. Complete/incomplete intermediate v2 batch selects a missing declaration version: archive integrity refusal, never older-version fallback. Interrupt after originals and within declarations; rollback/resume yields exact member counts/hashes and retains actual origin actors when immutable versions are reused.
5. C1+C2 where C2 is omitted, relinked to another line/order, changes missing-to-supplied, supplied-to-missing, hash/content, or is corrupt. Assert exact blockers at eligibility, classify/write and existing-classification reread. Relink/omission cannot clear retained history.
6. SQLite and native MySQL SQL adversaries: invalid hex/vocabulary, each partial-null combination, negative bytes, empty ciphertext, nonexistent FK, wrong original key/version/hash/bytes/line; UPDATE/DELETE/REPLACE and duplicate-key update refuse with existing evidence intact. Test foreign keys active and exact schema/trigger verification.
7. Reviewer independence includes selected declarations, linked reused originals, moved/omitted candidates, every consulted origin row/batch/segment actor, newest batch actors, and any new completeness-evidence actors. Repeat within authorized write. Distinct uninvolved reviewer still refuses substantive blockers.
8. v1-v1/v2-v2/mixed-version archive/reconcile/resume/delta matrix; unknown cohort/version/hash refuses. Same source record keys remain stable, raw-row restatement semantics match documented hashing, legacy unique-line review refuses overwrite. No new grants, entitlements, original fabrication, live imports or provider calls.

Use old-source regressions and meaningful guard mutations for behavioral claims; reserve DB/native work until PCC releases its slot. Exact tested implementation SHA must receive independent review. This design review supplies no passing behavior evidence and establishes no P0–P2 closure. No P0/P1 design finding identified in this bounded inspection; two P2 design blockers remain above.
