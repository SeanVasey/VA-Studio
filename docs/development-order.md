# Ordered development status

Reconciled 2026-09-17 against `main` at `4c08c0caf9239230cb1b6e5caec3e6b59301a0fe`, the repository implementation, issue #6's verified handoff, and the original [roadmap](architecture/roadmap.md). Sean explicitly requested returning to the pre-Stripe order and retaining every unfinished requirement.

## Current increment and next handoff

PRs #27–#33 and #39–#43 are merged. Recovery verified that PR #43 completed on September 14, with no open PR or uncommitted changes in registered worktrees. Its final source tree matches recovered main. The last batch passed 598 MySQL tests, 581 SQLite tests with 17 intentional MySQL-only skips, 55 frontend tests, 8 browser cases, build/audits and documented independent review. [PR #43](https://github.com/VASEYDEV/VASEYAUDIO/pull/43) and issue #6 retain the exact evidence. Earlier text calling it a pending candidate was stale; no missing promotion commit was found.

Current development is **WP-06: shared rights inventory foundation** under [D-10](architecture/D-10-shared-inventory.md). The [inventory contract](shared-rights-inventory.md) adds audited scope/revision linkage, owner-verified quote-bound test reservations, atomic multi-scope locks, administrative holds, irreversible unstarted expiry and pending-attempt retention. The integrating PR records actual candidate CI and review. Current non-exclusive offer/quote snapshots remain unchanged; these internal test exercises do not enable exclusive sales.

Next is **WP-06 versioned exclusive offer/quote integration and inventory-aware selection/pricing**, retaining historical non-exclusive readers and requiring explicit scope evidence at publication. Then WP-07 buyer identity/assent/orders/hosted checkout binds both promotion and inventory attempts before any provider request and supplies verified terminal effects; WP-08 contracts/entitlements follows. Production U-04/U-05/U-07/U-08 and remaining media/storage/scanner/worker/physical-device gates remain open. Issues #3–#6 retain their broader acceptance criteria.

Sean authorized continuous development on 2026-09-12: agents choose coherent PR boundaries, push related commits, merge verified candidates and proceed to the next dependency without a separate approval after each task. Keep CI and independent review proportional to the affected surface, merge with the expected head SHA, and record completed/next work at meaningful checkpoints. This does not authorize production cutover or bypass release gates.

## Coverage and remaining work

“Increment merged” is not completion of a whole work package. The matrix below retains all 14 packages; their detailed acceptance criteria and source obligations remain authoritative.

| Original order | Implemented evidence | Remaining work and dependency |
| --- | --- | --- |
| WP-01 — foundation | PR #15 scaffold/lockfiles/CI; PR #24 tailored PR template; later PR CI exercises MySQL/SQLite, frontend build and dependency audits | Merged PR #29 supplies clean-run installation and Chromium/WebKit workflow evidence; retain independent review and the tracked abrupt-reload QA finding. Production hosting/operations remain separate. |
| WP-02 — catalog/admin | Protected Filament resources and readiness; merged PR #28 adds audited metadata, revision conflicts, URL reservations and direct-action tests; PR #29 adds browser verification and visible publication blockers | Exact-head outcomes are in the relevant PRs. Retain independent review, granular roles, production MFA/recovery and complete upload/publication/device acceptance. |
| WP-03 — media | PR #21 private WAV/artwork intake, quarantine, immutable derivatives, measured previews and retries | Merged PR #30 adds bounded WAV-stems ZIP safety; merged PR #31 adds explicit immutable recording association and offer evidence. Retain resumable uploads/object-store adapter, real scanner acceptance, isolated workers, recovery and actual catalog/tag/stems alignment/playback evidence. Production configuration does not block unrelated reversible code. |
| WP-04 — licensing/offers | PR #22 exact review evidence, successor versions, immutable commercial snapshots and deliverable identity; merged #32 typed usage, #33 scope and #39 ownership/economic policies | Retain actual reviewed terms and historical continuity; buyer-specific rendering integrates with WP-08. V1–v3 evidence remains intact. No invented legal policy. |
| WP-05 — storefront/player | Foundation catalog/player; PR #25 metadata; merged PR #27 pagination/reconciliation; merged PR #40 detail/disclosure/player increment | Physical-device playback, broader accessibility and production performance; PR #40 records its actual candidate acceptance. Search pages may contain fewer than 12 eligible records; no hidden-record total is exposed. |
| WP-06 — quotes/reservations | PR #23 provisional immutable selection reviews; merged #41 full disclosure and #42 pricing/test-tax evidence; merged #43 promotion/usage holds; current shared-inventory foundation | Candidate CI/review; then exclusive offer/quote versioning and inventory integration. Actual production policies, complete terminal coupon redemption and purchase finalization remain dependent work. |
| WP-07 — checkout/finalization | PR #26 verified immutable Stripe test receipts | Test orders/assent and hosted sessions, promotion attempt/order binding and terminal redemption/release, payment validation, inbox processing/reconciliation, idempotent finalization and exception handling after WP-06. Connected Stripe accounts do not replace application implementation. |
| WP-08 — contracts/delivery | Architecture and work package | Deterministic buyer contracts, exact asset entitlements, secure re-downloads, retries and historical preservation after payment evidence. |
| WP-09 — seller workflows | Catalog administration and initial public sharing cover portions only | CMS, release scheduling, sharing administration, promotions and order/support operations after their domain dependencies. |
| WP-10 — other products | Parity requirements retained | Collections, kits, services, merchandise and fulfillment after media/delivery/seller foundations; bring forward any audited active obligations. |
| WP-11 — memberships/CRM | Parity and consent rules retained | Membership continuity, renewal/failure/cancellation, customer library/CRM and integrations; bring forward audited active obligations. |
| WP-12 — source/migration | Three-attachment research, 103-item parity baseline, field/route maps and audit checklist | Authenticated source audit is unperformed. Acquire inventory/obligations privately, then restartable import dry-runs, SEO redirects and reconciliation. Begin source truth early, complete migration after target workflows exist. |
| WP-13 — validation | CI and domain regression tests accumulate throughout development | Exact release candidate security/accessibility/device/performance review, production scanner/worker/provider evidence, restore and recovery drills. |
| WP-14 — cutover | Runbook and boundaries | Source obligations resolved, staging/rehearsal evidence, rollback and postlaunch reconciliation before authorized domain cutover. |

## Sequence control

1. Preserve merged WP-05 pagination/reconciliation and WP-02 metadata/URL evidence from PRs #27/#28.
2. Preserve merged PR #29 operator diagnostics/boot and WP-02 browser evidence and its remaining review/device gates. Continue the early WP-12 source audit when authenticated source access is available; record unknowns without pretending they are absent obligations.
3. Work through remaining Phase 1 media and rights dependencies (WP-03, WP-04), then complete WP-05 track detail/player acceptance. Split large packages into explicit increments and retain external production gates separately.
4. Resume Phase 2 in order: WP-06 payable quote/reservation work → WP-07 orders/hosted checkout/finalization → WP-08 contracts/entitlements/delivery. Reuse the merged provisional quote and Stripe receipt foundations.
5. Complete WP-09 → WP-10/WP-11, finish WP-12 migration reconciliation, then WP-13 release validation and WP-14 cutover. Audited customer obligations may pull the required WP-10/WP-11 slice earlier, as the original roadmap requires.

Before each PR: inspect current main, this record and the selected issue; name its dependency and remaining criteria; record tests at the actual head; update the next handoff here and in the issue. Do not close broad work packages from a partial increment, revive stale “next task” paragraphs, or use provider setup as a reason to skip earlier work.

The [source/parity records](migration/README.md) remain the omission checklist. Their `planned` target statuses are a baseline, not a fresh implementation audit; mark a requirement accepted only with its specific implementation and acceptance evidence. No authenticated audit, completed import, production transaction or live cutover is implied by this reconciliation.
