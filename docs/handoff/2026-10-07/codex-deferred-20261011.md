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
- **Batch: PR #96, draft, independent review REJECT at `2aba5be`.** Handed to Codex: `docs/handoff/2026-10-11/importer-h1/README.md`.
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

## #95 MFA follow-up (head ef59507), next MFA batch
- r4242632400 StaffWriteAuthority.php:230: `consume()` uses a plain `exists()`. A caller already holding an older REPEATABLE READ snapshot could miss a committed consumption marker. Unreachable today: the only caller locks the operator row before any consistent read. Fix: locking read or unique marker key, plus a native MySQL outer-snapshot test. Same as independent review L1 (`docs/verification/domain-mfa-20261010/independent-review-ef59507-DECISION.md`).
## #96 importer pin (head 2aba5be), in #96's own fix
- r4242704519 CatalogDatabaseEvidence.php:361: run `shadowed()` over every name in `$pinned`, not only `TABLES` plus the epoch. It needs a same-session temporary table shadowing a non-evidence table.

Each item has a thread reply on its PR naming this batch. None is P0/P1.
