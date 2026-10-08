# Live payment and production preparation queue

Recorded October 7, 2026 UTC in the published cloud workspace. Sean requested
that the existing agents prepare payment and production actions in development
or queue them ahead. These assignments supplement the remaining personal-store
implementation; `/root` remains the single integration and publication owner.
Sean also explicitly approved any membership test setup. That approval covers
necessary synthetic membership fixtures in this workspace.

## Assigned work

| Owner | Dependency before final implementation | Concrete preparation deliverables | Current state |
| --- | --- | --- | --- |
| `/root/pr25_recovery` | Reviewed Checkout physical-write admission, paid source receipt and automatic tax255 | Stripe account/mode/API/SDK compatibility checks; default-off checkout/tax adapter configuration; webhook signature and duplicate handling checks; authoritative current retrieval and unknown-outcome reconciliation; dry-run and activation packet identifying the exact later credential, payment-test and refund actions | Assigned and queued behind current repair and tax work |
| `/root/recovery_inventory` | Membership257/member258 and Billing259 | Server-owned subscription approval and current invoice proof adapters; renewal/cancellation/dunning/refund/dispute capability inventory; private-server/scanner/storage/worker/scheduler configuration manifests; backup/restore, deploy and rollback preflight and operator packet | Billing259 development planned alongside operative membership; host operations queued |
| `/root/crawler_routes` | Account features253 and suppression254 | Production identity/TLS/sender configuration checks; transactional customer notices and suppression-provider capability/preflight; privacy, queue recovery and retention checks; exact unsupported or missing provider facts | Assigned and queued after current customer feature and suppression work |
| `/root/native_mysql` | Reviewed paid252 consumer and whole-order delivery | Payment/fulfilment interoperability checks; incomplete/unknown payment recovery; whole-order original-file/storage verification; reconciliation, refund and restore acceptance tools and source-bound receipts | Assigned and queued after current paid consumer proof |
| `/root/completion_audit` | Operative free256 and reviewed licensing/content interfaces | Content onboarding and migration dry-run packet: source identity, approved terms/prices/rights, tag/master/stems mapping, immutable originals, customer obligations and retained backups; verified import/restore steps without activating entitlements | Assigned and queued after free256 |
| `/root/pr26_review` | Frozen, independently testable producer/consumer and operations source | Independent review of payment and deployment adapters, manifests, default-off flags, provider facts and exact candidate evidence; enumerate unsupported capabilities and the remaining activation actions | Assigned and queued after current source and membership reviews |
| `harness/production-preparation-1` (Claude Code lane, PR #46) | None. Synthetic, read-only preparation from `main` `3716324a` | Delivered: `vasey:stripe-preflight` (configuration shape, `funds_mode_environment`, SDK 21.3.2 / API `2026-08-26.dahlia` pins, and a confirmed read-only account/capability probe tested only against synthetic fixtures); webhook signature, replay, ordering and unknown-outcome checks; synthetic backup/restore proof, with the MySQL procedure documented and not run; `ops/production/env.production.example`, with a coverage test; `docs/ops/production-activation-packet.md`, prepared and not executed. Architecture findings: (1) there is no live webhook receiver; (2) test-mode payments require `APP_ENV` local or testing, so hosted staging test mode is refused; (3) the production checkout provider and routes are unregistered; (4) there is no operator command for production reconciliation | Independent review: APPROVE WITH CONDITIONS for `01a2590d`. The R-1/R-2 fixes are in `3d615162` and need re-review. Evidence: `docs/verification/production-preparation-1-20261007/`. Nothing was activated |

Each agent must first inspect existing source and operations tools. In particular,
reuse `scripts/ops/private-server-preflight.php`, its existing test harness and
the persistent content-upgrade tooling where applicable. Prepare executable
checks, immutable configuration templates, focused tests and reviewable source;
an assignment or a template does not establish that its implementation is done.

## Handoff and integration

For each handoff, record its branch and frozen commit, changed source hashes,
actual commands/results, original failures and remaining facts. Synthetic and
loopback evidence must stay labelled. Actual provider credentials, merchant/tax
facts, approved prices/terms, host/storage choices and real content remain
explicit required inputs wherever source cannot establish them.

Root composes reviewed changes in dependency order, runs affected local checks,
publishes coherent development PRs and uses inexpensive preflight. The final
integrated candidate still requires one manual exact-SHA Foundation verification
with complete native/SQLite/browser accounting. The current assignments do not
replace that acceptance or close an entire completion group.

## Later activation packet

Prepare a concrete list of commands, verified source and configuration inputs,
expected outcome, recovery/rollback path and evidence for the later operator
actions. Keep real credential configuration, live payment activation, real-money
transactions, paid services, production deployment and DNS/cutover pending
separate authorization. Development, synthetic fixtures and dry runs can proceed
now. Preserve existing budgets, protections, immutable contracts and source
obligations throughout preparation.
