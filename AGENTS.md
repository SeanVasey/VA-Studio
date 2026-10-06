# VASEYAUDIO agent instructions

## Objective and authority

Build Sean Vasey's first-party VASEY.AUDIO music store and complete the BeatStars replacement in bounded, tested changes. Read `README.md`, `docs/architecture/`, the selected work package, and the affected migration/brand ledgers first. Sean's current instructions govern scope and supersede stale source documents. Preserve unrelated changes.

The initial development lead is Astra as requested. These instructions are portable across Codex, Claude, and other coding agents; no model API is needed to run the store.

## Work protocol

1. Choose one dependency-ready work package. State files owned, assumptions, acceptance criteria, and tests before editing.
2. Use a separate branch or worktree for parallel work. Do not edit another agent's owned files without coordination.
3. Keep Laravel domain/application services responsible for business rules. React and Filament invoke them; do not duplicate pricing, license, publication, or entitlement logic in UI components.
4. Verify changed behavior with appropriate tests. Money, authentication, publication, payment replay, exclusive races, rights, and delivery need adversarial cases. SQLite tests do not prove MySQL concurrency.
5. Update the work package and status with exact commands, outcomes, untested conditions, and the next dependency. Never report a scaffold or mock as implemented commerce.
6. Open a focused PR with evidence. Seek independent review for payment, licensing, authorization, migration, and irreversible data changes. A review must assess the actual tested commit.

## Continuous development cadence

Sean authorized agents on 2026-09-12 to choose coherent PR boundaries, push related commits, merge verified work and continue the ordered plan without requesting approval at each task or commit. Batch related functionality and regression coverage into a reviewable feature PR. Use focused checks during implementation and the required CI/review gates for the final candidate; resolve concrete failures without weakening those gates. Merge with an expected head SHA when checks and required review are satisfied, then proceed to the next ready dependency. Ordinary commits do not need release tags. Preserve the release boundaries below and record the next task and actual evidence at meaningful checkpoints.

## CI cost policy — Sean's instruction, October 6, 2026

This policy supersedes the earlier requirement for complete hosted CI on every
merge. Sean explicitly authorized development merges without full CI. Finish
coherent batches using meaningful focused local checks and the cheap PR preflight;
record the exact tested source, selected cases, failures and untested conditions.
Preserve independent review for sensitive domain changes. Do not invent passing
results or describe focused checks as full acceptance.

- Do not launch routine full MySQL/SQLite/browser matrices on pushes, PR updates
  or merges. Foundation CI is manual final verification only. Its dispatch must
  include the exact reviewed 40-character `expected_sha`; a moved ref is rejected.
- Use local affected tests while iterating. Use manual Focused development
  feedback only for a necessary suite/environment unavailable locally; avoid
  duplicate dispatches for the same source/selection. Batch repairs before pushes.
- A focused, reviewed development candidate may merge without Foundation CI.
  Keep genuine audits, secret scanning, security checks and repository protections.
  If an enforced check blocks the merge, report it; do not bypass or change settings.
- Run one complete Foundation CI for the final integrated candidate, or as few
  additional runs as actual failures/new source require. Diagnose from existing
  logs and focused reproduction before another full run. A full pass applies only
  to its recorded commit; unresolved failures remain visible release blockers.
- Main's workflow policy does not automatically update existing PR branches.
  Before another product push, integrate this policy commit and its workflows;
  do not let an older branch restore automatic matrices. Coordinate through the
  CI efficiency PR and `docs/verification/ci-trigger-efficiency.md`.

## Invariants

- Store money in integer minor units with explicit currency. Never trust client totals or payment success redirects.
- Durable verified payment state is required before a grant. Webhook signature validity alone is not proof of payment. Deduplicate events and preserve reconciliation evidence.
- Preserve immutable published licenses, purchased asset revisions, order snapshots, and original historical contracts. Exclusive sales must serialize and must not silently revoke prior grants.
- Keep masters, stems, customer data, secrets, contracts, and exports out of Git and public object paths. Public previews and artwork are distinct asset roles.
- Publication requires complete metadata, cleared rights, valid deliverables, preview/artwork readiness, and approved license offers. Empty catalog is safer than fabricated catalog data.
- Enforce staff permissions on the server. Production administration needs MFA and audited role changes. A hidden navigation item is not authorization.
- Every download requires a valid entitlement and short-lived authorization. Replayed jobs must not duplicate grants or charges.
- Keep unknown consent unknown. Never infer email marketing consent from a purchase or import.
- Preserve source IDs/hashes and import dry-run evidence. Never commit customer exports or production credentials.

## Brand and product

The user explicitly selected the theme currently published on BeatStars. Use the live-theme token mapping in `docs/brand/` rather than silently reverting to Edition 04 or the research attachment. Keep approved typography, exact official identity geometry, and imagery provenance. Do not recreate the logo. No invented product prices or license terms. Development fixtures must be isolated from production.

## Release boundary

Completing the first track-store slice is not complete feature parity. Existing membership, order, license, service, and fulfillment obligations remain cutover gates. Do not change DNS, retire BeatStars, import customers into active entitlements, enable live payment collection, or claim launch readiness without the applicable evidence and authorization. Continue reversible development while those gates remain open.

## Validation

Use the focused commands and preflight checks in `README.md`; reserve `.github/workflows/final-verification.yml` for final verification under the cost policy above. Keep dependency lockfiles committed. Record runtime/tool limitations honestly; do not weaken tests or audit settings to obtain green output.
