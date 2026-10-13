# Deferred Codex findings, 2026-10-11

Sean's instruction (2026-10-11, about 03:11 UTC): end the per-push Codex cycle. Rule ("freeze and batch"):

1. A PR merges at its exact current SHA once preflight is green, its independent review approves, and no P0/P1
   Codex finding is open. A pending Codex re-review does not block the merge.
2. Codex P2 findings on a frozen or merged head get one thread reply ("deferred to the lane's follow-up batch")
   and are listed here. Each lane's batch is fixed in one follow-up PR: one push, one Codex pass, one review.
   New P2s from that pass go to the next batch.
3. P0/P1 findings still block: verified, then fixed before merge (or immediately after, if raised after).
4. No single-finding pushes.

Codex reviews every new commit (its summary shows the "New commits" trigger), which is set in the repository
owner's Codex settings, not in this repository.

## #90 importer
- **Batch: PR #96.** The historical `2aba5be` rejection is preserved in `docs/handoff/2026-10-11/importer-h1/README.md`. H1 correction and the related findings are verified at application/test source `a4bc2bbc` on private MySQL 8.4.11 and SQLite; fresh independent source and final-delta reviews approved. Merged at `92f940cc48d2ea2e02dd66503dcc99f20da51747`, exact head `cb69203e3c7947366ee8a75758cd2f0f927bcfed`, after preflight `38316912323` passed.
- discussion_r4240075127: pin every base table covered by engine admission (not only evidence tables + epoch) in lockedStorage(); test: foreign session converts `migrations` to MyISAM + tracks trigger in TransactionBeginning window.
## #91 launch documents (reviewed head dc86ba4)
- **Batch: fixed in PR #94, merged at `6954ffd`.**
- r4240077743 docs/legal/README.md:21 — list both paid-grant scope texts (synthetic_rehearsal vs verified_production; PaidGrantText::document()).
- r4240077740 docs/legal/TERMS.md:47 — describe implemented default-off production identity path (match PRIVACY §2.3).
- r4240077738 docs/legal/TERMS.md:30 — zero-tax rule only at hosted Checkout (CheckoutEvidence); PricingPolicy accepts fixed_test 0–10000 bp and provider_calculated.
- r4240077744 docs/verification/launch-documents-20261010/README.md:160 — resolved-contradictions entry still says prod accounts don't exist; align with CLAUDE.md.
## #92 domain staff MFA (reviewed head 8423f70)
- **Batch: fixed in PR #95, merged at `592241a` (head `ef59507`).**
- r4240117094 StaffWriteAuthority.php:141 — bind the full operation (blocked state, expected version, reference) or consume the attestation atomically on first write.
- r4240117095 ManageRightsScopes.php:71 — after a recorded attestation, refusal output must not say "No changes were made"; say no rights-scope write occurred and the attestation row remains.

## #95 MFA follow-up (head ef59507), October 12 batch
- r4242632400 StaffWriteAuthority.php:230: `consume()` uses a plain `exists()`. A caller already holding an older REPEATABLE READ snapshot could miss a committed consumption marker. The caller locks the operator row, but an outer transaction can already hold an older snapshot; the October 12 native regression reproduces this with the existing register caller. Fix: locking read or unique marker key, plus a native MySQL outer-snapshot test. Same as independent review L1 (`docs/verification/domain-mfa-20261010/independent-review-ef59507-DECISION.md`).
- **Fixed in PR #98, merged at `eb6e3741587c43cb8efc256865b45fff70f56019` from exact head `c2be3d31`; application/test source `abe2956b`.** Native old-source failure 1/8; fixed private MySQL 8.4.11 selection 21/381, zero skips; focused SQLite 83/918 with 11 declared native-only skips. Both full attestation suites are now in native selection; HMAC wording corrected. Final independent review APPROVE carried to the exact receipt-only head; preflight `38318165777` passed; the #95 P2 thread is resolved. Evidence: `docs/verification/domain-mfa-20261010/current-read-20261012/README.md`.
## #96 importer pin (head 2aba5be), in #96's own fix
- r4242704519 CatalogDatabaseEvidence.php:361: run `shadowed()` over every name in `$pinned`, not only `TABLES` plus the epoch. It needs a same-session temporary table shadowing a non-evidence table.
- **Fixed in PR #96, merged at `92f940cc` from reviewed head `cb69203e`; application/test source `a4bc2bbc`.** Every pinned name is checked, including escaped identifiers; the native temporary-shadow case passes. See `docs/verification/mysql-draft-importer-20261010/local-evidence/h1-20261012/README.md` and the fresh independent decision. The separate trigger/routine targets outside the pinned set remain an owner-decision residual.

Each item has a thread reply on its PR naming this batch. None is P0/P1.

## #99 dependency tooling, October 12
- Merged at `0b4a2e76fd2ffc414863a1ba97b9354c9ec4cde8` from exact reviewed head `027c674194c1b8b5c5a20231a6d805523413f855`; preflight `38319085298` passed. No review threads or open P0/P1 at merge.
- #73/#78/#79 closed as superseded; #76 closed for the Node runtime mismatch. #71/#72/#77 remain deferred/unmerged; #74/#75/#80 are the next paired Laravel/Inertia batch. Newer available dependency versions remain a later coherent batch; do not chase them in this frozen graph.

## #108 Laravel/Inertia batch, October 12
- Merged at `3ab71dd354199e86625ac6878564b9c7334fba43` from exact head `c28fb81b0c5fcf6fb0823c51f220ea1e3fac78f6`; independent APPROVE carried to the receipt-only head, preflight `38320089400` passed, no review threads or open P0/P1 at merge.
- #74/#75/#80 closed as superseded. Stripe #71 and frozen renderer #72/#77 remain unmerged. Browser history restoration and completed prior-runtime upgrade remain coverage limits; no demonstrated regression was found.
- C2 begins with its carried A1-4 durable first-attempt record condition and the matching-binding invoice-id regression; current direct predicate coverage already exists. These are development prerequisites, not a claim that an award/reservation writer exists.

## C2 prerequisite batch, October 12
- A1-4 durable first-attempt record and L2-4 matching-binding invoice-ID coverage are implemented at application source `46e91e50`; test-only follow-ups `4b0dd809` and `1a14437e` strengthen hint validation and repair two test fixtures. Native CHECK dictionary normalization is corrected at `dfbd0ba0` after the initial setup failure; no table definition or guard changed in that correction. Final native20/217 and combined SQLite198distinct/1070 with5declared skips are recorded in `docs/verification/membership-c2-20261012/`; separate independent APPROVE at `f1b3c71b`. Receipt/status carry and exact-head preflight remain before merge.
- CP-2/CP-3/P1B-1, R-4/R-5/A1-5, L2-1 and other carried membership activation conditions remain open. New attempts do not supply paid-invoice authority, cover webhook hints, or implement C2 credit writers.

## #109 C2 prerequisite merged, October 12
- Merged at `06684deb1465f0747d67a2e1751e11e9b7317c06` from exact head `f7da37369e821c23650505bedb1a07028142f9c8`; preflight `38323937687` passed. Separate reviewer APPROVE at `f1b3c71b` explicitly carried to final head. The preceding pending entry is historical.
- **Open P2 [r4253341984](https://github.com/SeanVasey/VA-Studio/pull/109#discussion_r4253341984): reserve retrieval positions across both ledgers.** Attempts reject endpoint reuse only within attempts, while observations do not inspect attempts. Low-level insertion can reuse a provider-read interval across the two tables. This is distinct from CP-2. One deferral reply was posted ([r4253354522](https://github.com/SeanVasey/VA-Studio/pull/109#discussion_r4253354522)); do not mark it fixed.
- Next coherent membership batch: enforce cross-ledger uniqueness in both insert guards, test both insertion directions and all endpoint combinations, and prove native concurrent insertion. No single-finding push to the frozen head.
- Independent reviewer `/root/membership_attempt_review` reassessed this P2 at the exact final head and retained **development APPROVE, no P0/P1**. Current reconciliation allocates fresh positions and follows mutually exclusive attempt/observation writes; queue retries allocate new intervals, and attempts supply no paid authority. This addendum supersedes the earlier no-actionable-findings statement for this new P2. All prior scope and activation limits remain.

## C2 immutable policy and endpoint batch, October 13 — merge pending

- PR #109 P2 r4253341984 is implemented in the current coherent batch at final application/test source `df3656b6a74151c5e2f6b89264ad733f70678c0e`; it is not yet recorded as merged/resolved. Both insert directions and all endpoint combinations have native coverage, including separate-process competition and deterministic older-snapshot current-read checks. The same new race fails on unchanged pre-batch application `11a716e7` because both writers retain a conflicting interval.
- The pre-push independent review also found a P2 terminal clock callback after final policy/frame checks. Its new regression fails before correction; the corrected source evaluates the clock first and reproves configuration/frame afterward. Fixed-source SQLite and MySQL cases cover both configuration withdrawal and transaction replacement. The correction stays in this unpublished policy batch; no single-finding push occurred.
- Evidence: `docs/verification/membership-policy-20261013/`. Combined source-bound SQLite263/1316 with10declared skips and MySQL8.4.11 14/194 withzero skips; initial failures retained. Final independent evidence review APPROVE at `62f81a27`, no new P0/P1/P2. Receipt-only carry, cheap preflight and expected-head merge remain pending. Carried CP-2/CP-3/P1B-1, R-4/R-5/A1-5, L2-1 and other activation conditions remain open. Next: real paid-invoice producer and owned first-award operation; no commercial defaults assumed.

## #111 policy preparation and endpoint batch merged, October 13

- Merged at `7f0bfe9a531d082f1716358518333bb5ba68ae08` from exact reviewed head `6a2824b36ad183aad4214c0d8bc39bb481b14c4c`; preflight `38335442946` passed. Separate reviewer APPROVE at `62f81a27` explicitly carried to final receipt-only head. No open PR111 review thread or P0/P1 at merge.
- **Fixed and resolved:** PR #109 P2 r4253341984 cross-ledger endpoint reuse. Both insertion directions/all endpoint combinations, separate-process contention and deterministic older-snapshot reads are verified in private MySQL 8.4.11. The prior-source race fails as intended. The terminal clock finding was also corrected and verified before the single product push.
- Prior pending/open entries remain historical. CP-2/CP-3/P1B-1, R-4/R-5/A1-5, L2-1 and other activation conditions remain open. Next coherent C2 batch: real staff subscription approval, retained provider payment time, captured paid-invoice proof and owned first-award operation. Runtime registration, commercial defaults and live provider activity are not authorized by this merge.

## C2 first-award batch in progress, October 13

- Independent review of application `307699b6` found F1 (P1): the new singleton fence trusted stale MySQL engine/required-trigger dictionary metadata. The engine regression at test head `04b2699a` fails on the unchanged application (1 test / 4 assertions / 1 intended failure); the corrected guard regression at `3d2197c0` also fails (1 / 5 / 1). Application `9302e1c0` pins the actual subjects and checks their current named definitions. Fixed-source/native completion and independent review remain pending; no merge or closure is claimed here yet.
- F2 (P2) from that same unpublished review binds each proof to the actual observation ID/seal, independently of semantic invoice replay. The same-second replacement regression fails on the prior application (1 / 3 / 1); the correction is included in `9302e1c0` and awaits final verification. No single-finding product push occurred.
- Further independent review confirms a blocking P1 in public context construction: standalone rows could write a first award without the complete capability gate or owned commit path. Direct/reentrant regressions fail on the unchanged application (2 tests / 10 assertions / 2 intended failures); resolver-withdrawal and transient add/remove regressions each fail as intended too. Fixed in unpublished `9ec09073` with a private one-use permit and complete registration-baseline admission; SQLite 15/141 passes with zero skips/errors/failures. Separate reviewer approves this exact four-file delta. The original ten-second native award canary still errors at its deadline; an isolated diagnostic measures 11,180 statement preparations and 11,590 executions before refusal. Query batching and the single original 60-second admission budget are committed at `bf498055`; native success/race verification and final review remain pending. The old ten-second failures stay recorded. These correctness findings are not deferred past merge.
- **P2 activation condition: fully current unexpected-trigger inventory.** The MySQL 8.4.11 probe records that both `information_schema.TRIGGERS` and `SHOW TRIGGERS` retain an older REPEATABLE READ snapshot; locking metadata reads are denied/unsupported. Named `SHOW CREATE TRIGGER` reads the current required definition. The new fence verifies each required current guard while holding subject-table metadata locks; additional triggers cannot omit that verified BEFORE INSERT fence. The existing catalog check does not prove a fully current inventory of unexpected triggers across these schema inspectors. Keep that limitation open for a coherent schema-admission follow-up before activation; do not claim exact current namespace inventory. The separate reviewer explicitly classified this residual as P2, outside F1's P1 closure requirement. Probe and decision context are retained in the first-award packet.

- **First-award activation condition: measured latency and throughput.** The isolated original-budget trace after metadata batching spends 13.842 seconds before deadline refusal; identity inspection is 11.097 inclusive seconds across 64 calls. The new single 60-second admission budget is not a hard interruption timeout or a production performance claim. Before mounting/enabling the operation, measure representative award and hint load and resolve repeated inspection/shared-fence throughput limits while preserving current ownership and original proof deadlines.
