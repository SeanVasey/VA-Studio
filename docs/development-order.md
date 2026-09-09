# Ordered development status

Reconciled 2026-09-09 against `main` at `3224e0a32a1b94a05ea726010ac1f449be4540e0`, the repository implementation, issues #1–14, and the original [roadmap](architecture/roadmap.md). Sean explicitly requested returning to the pre-Stripe order and retaining every unfinished requirement.

## Current increment and next handoff

Resume the previously recorded next increment in [WP-05 / #5](https://github.com/VASEYDEV/VASEYAUDIO/issues/5): **server catalog pagination and cart/selection reconciliation**. Public sharing was merged in [PR #25](https://github.com/VASEYDEV/VASEYAUDIO/pull/25). The bounded Stripe receipt prerequisite in [PR #26](https://github.com/VASEYDEV/VASEYAUDIO/pull/26) stays in place; its earlier “payable orders next” handoff is superseded by this order.

After this increment, close the earlier foundation/admin acceptance gaps before advancing commerce: published slug continuity and ordinary metadata audit coverage need a focused WP-02 implementation/verification pass. `Track` currently permits published slug edits, and the Filament metadata save path needs evidence that it records edits. Existing publish/unpublish auditing does not prove that criterion. Keep the dedicated WP-05 track detail and real device playback/navigation acceptance queued behind this foundation closure and the required media/rights work.

## Coverage and remaining work

“Increment merged” is not completion of a whole work package. The matrix below retains all 14 packages; their detailed acceptance criteria and source obligations remain authoritative.

| Original order | Implemented evidence | Remaining work and dependency |
| --- | --- | --- |
| WP-01 — foundation | PR #15 scaffold/lockfiles/CI; PR #24 tailored PR template; later PR CI exercises MySQL/SQLite, frontend build and dependency audits | Reconcile final clean-install/boot/operator setup and diagnostics acceptance. Keep runtime and provider limitations explicit. |
| WP-02 — catalog/admin | Protected Filament resources, persistent metadata, central readiness, publish/unpublish commands, private catalog protection and synthetic seed restrictions | Published slug continuity, metadata audit coverage, direct-action denials and browser save/error acceptance; production MFA/recovery evidence remains a release gate. Address these before more Phase 2 work. |
| WP-03 — media | PR #21 private WAV/artwork intake, quarantine, immutable derivatives, measured previews and retries | Stems/ZIP safety, resumable uploads/object-store adapter, real scanner acceptance, isolated workers, recovery and actual catalog/tag/playback evidence. Production configuration does not block unrelated reversible code. |
| WP-04 — licensing/offers | PR #22 exact review evidence, successor versions, immutable commercial snapshots and deliverable identity | Typed rights/caps/variables and consistency fixtures, actual reviewed terms, historical continuity; buyer-specific rendering integrates with WP-08. No invented legal policy. |
| WP-05 — storefront/player | Foundation catalog/player; PR #25 metadata; current pagination/reconciliation increment | Dedicated track detail, device navigation/playback/seek, responsive accessibility acceptance and production performance. Search pages may contain fewer than 12 eligible records; no hidden-record total is exposed. |
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

1. Finish and verify the current bounded WP-05 pagination PR.
2. Reconcile WP-01 acceptance and close the identified WP-02 admin/publication gaps in a separate PR. Continue the early WP-12 source audit when authenticated source access is available; record unknowns without pretending they are absent obligations.
3. Work through remaining Phase 1 media and rights dependencies (WP-03, WP-04), then complete WP-05 track detail/player acceptance. Split large packages into explicit increments and retain external production gates separately.
4. Resume Phase 2 in order: WP-06 payable quote/reservation work → WP-07 orders/hosted checkout/finalization → WP-08 contracts/entitlements/delivery. Reuse the merged provisional quote and Stripe receipt foundations.
5. Complete WP-09 → WP-10/WP-11, finish WP-12 migration reconciliation, then WP-13 release validation and WP-14 cutover. Audited customer obligations may pull the required WP-10/WP-11 slice earlier, as the original roadmap requires.

Before each PR: inspect current main, this record and the selected issue; name its dependency and remaining criteria; record tests at the actual head; update the next handoff here and in the issue. Do not close broad work packages from a partial increment, revive stale “next task” paragraphs, or use provider setup as a reason to skip earlier work.

The [source/parity records](migration/README.md) remain the omission checklist. Their `planned` target statuses are a baseline, not a fresh implementation audit; mark a requirement accepted only with its specific implementation and acceptance evidence. No authenticated audit, completed import, production transaction or live cutover is implied by this reconciliation.
