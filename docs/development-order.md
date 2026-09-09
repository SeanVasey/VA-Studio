# Ordered development status

Reconciled 2026-09-09 against `main` at `f083c5681ad403ade445d95c78f131db784a4521`, the repository implementation, issues #1–14, and the original [roadmap](architecture/roadmap.md). Sean explicitly requested returning to the pre-Stripe order and retaining every unfinished requirement.

## Current increment and next handoff

[PR #27](https://github.com/VASEYDEV/VASEYAUDIO/pull/27) is merged: WP-05 server catalog pagination and saved-selection reconciliation are on main. The current bounded WP-02 increment adds authorized, audited metadata commands, stale-form protection and permanent published URL reservations. See [catalog administration](catalog-administration.md) for behavior, migration and verification boundaries.

Next, reconcile the remaining WP-01 clean-boot/operator diagnostics and WP-02 real-browser save/error acceptance. Livewire component tests exercise server actions; they are not browser evidence. Keep broader RBAC, production MFA/recovery and independent review visible. Then continue the remaining Phase 1 media and rights dependencies before dedicated WP-05 track detail/device acceptance and further Phase 2 commerce.

## Coverage and remaining work

“Increment merged” is not completion of a whole work package. The matrix below retains all 14 packages; their detailed acceptance criteria and source obligations remain authoritative.

| Original order | Implemented evidence | Remaining work and dependency |
| --- | --- | --- |
| WP-01 — foundation | PR #15 scaffold/lockfiles/CI; PR #24 tailored PR template; later PR CI exercises MySQL/SQLite, frontend build and dependency audits | Reconcile final clean-install/boot/operator setup and diagnostics acceptance. Keep runtime and provider limitations explicit. |
| WP-02 — catalog/admin | Protected Filament resources and readiness; current increment adds audited metadata commands, revision conflicts, database URL reservations and direct-action tests | Verify this increment in CI and independent review; real-browser save/error acceptance and production MFA/recovery evidence remain open. Address foundation acceptance before more Phase 2 work. |
| WP-03 — media | PR #21 private WAV/artwork intake, quarantine, immutable derivatives, measured previews and retries | Stems/ZIP safety, resumable uploads/object-store adapter, real scanner acceptance, isolated workers, recovery and actual catalog/tag/playback evidence. Production configuration does not block unrelated reversible code. |
| WP-04 — licensing/offers | PR #22 exact review evidence, successor versions, immutable commercial snapshots and deliverable identity | Typed rights/caps/variables and consistency fixtures, actual reviewed terms, historical continuity; buyer-specific rendering integrates with WP-08. No invented legal policy. |
| WP-05 — storefront/player | Foundation catalog/player; PR #25 metadata; merged PR #27 pagination/reconciliation | Dedicated track detail, device navigation/playback/seek, responsive accessibility acceptance and production performance. Search pages may contain fewer than 12 eligible records; no hidden-record total is exposed. |
| WP-06 — quotes/reservations | PR #23 provisional immutable selection reviews | Payable quotes, actual tax policy, promotions, shared exclusive inventory and reservation lifecycle; follows media/rights and storefront readiness. |
| WP-07 — checkout/finalization | PR #26 verified immutable Stripe test receipts | Test orders/assent and hosted sessions, payment validation, inbox processing/reconciliation, idempotent finalization and exception handling after WP-06. Connected Stripe accounts do not replace application implementation. |
| WP-08 — contracts/delivery | Architecture and work package | Deterministic buyer contracts, exact asset entitlements, secure re-downloads, retries and historical preservation after payment evidence. |
| WP-09 — seller workflows | Catalog administration and initial public sharing cover portions only | CMS, release scheduling, sharing administration, promotions and order/support operations after their domain dependencies. |
| WP-10 — other products | Parity requirements retained | Collections, kits, services, merchandise and fulfillment after media/delivery/seller foundations; bring forward any audited active obligations. |
| WP-11 — memberships/CRM | Parity and consent rules retained | Membership continuity, renewal/failure/cancellation, customer library/CRM and integrations; bring forward audited active obligations. |
| WP-12 — source/migration | Three-attachment research, 103-item parity baseline, field/route maps and audit checklist | Authenticated source audit is unperformed. Acquire inventory/obligations privately, then restartable import dry-runs, SEO redirects and reconciliation. Begin source truth early, complete migration after target workflows exist. |
| WP-13 — validation | CI and domain regression tests accumulate throughout development | Exact release candidate security/accessibility/device/performance review, production scanner/worker/provider evidence, restore and recovery drills. |
| WP-14 — cutover | Runbook and boundaries | Source obligations resolved, staging/rehearsal evidence, rollback and postlaunch reconciliation before authorized domain cutover. |

## Sequence control

1. Preserve merged WP-05 pagination/reconciliation evidence from PR #27; finish and verify the current WP-02 metadata/URL PR.
2. Reconcile remaining WP-01 operator diagnostics/boot and WP-02 browser acceptance. Continue the early WP-12 source audit when authenticated source access is available; record unknowns without pretending they are absent obligations.
3. Work through remaining Phase 1 media and rights dependencies (WP-03, WP-04), then complete WP-05 track detail/player acceptance. Split large packages into explicit increments and retain external production gates separately.
4. Resume Phase 2 in order: WP-06 payable quote/reservation work → WP-07 orders/hosted checkout/finalization → WP-08 contracts/entitlements/delivery. Reuse the merged provisional quote and Stripe receipt foundations.
5. Complete WP-09 → WP-10/WP-11, finish WP-12 migration reconciliation, then WP-13 release validation and WP-14 cutover. Audited customer obligations may pull the required WP-10/WP-11 slice earlier, as the original roadmap requires.

Before each PR: inspect current main, this record and the selected issue; name its dependency and remaining criteria; record tests at the actual head; update the next handoff here and in the issue. Do not close broad work packages from a partial increment, revive stale “next task” paragraphs, or use provider setup as a reason to skip earlier work.

The [source/parity records](migration/README.md) remain the omission checklist. Their `planned` target statuses are a baseline, not a fresh implementation audit; mark a requirement accepted only with its specific implementation and acceptance evidence. No authenticated audit, completed import, production transaction or live cutover is implied by this reconciliation.
