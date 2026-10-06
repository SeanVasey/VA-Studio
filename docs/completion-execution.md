# Completion execution plan

Checkpoint: October 5, 2026 (America/Chicago), following Sean's instruction to prioritize the finished website and eliminate serial development waits. This plan keeps all required scope in the [40-group register](remaining-development-tasks.csv) and [103-row coverage map](remaining-parity-coverage.csv). The protected preview is a testing aid, not the completion target.

## Baseline audited before this batch

This inventory describes accepted main `1095dd5`, before the active resumable-upload and financial-observation changes below. Their final integrated acceptance is recorded separately.

The code already provides a substantial track-store foundation: private media quarantine/processing, versioned licensing/offers, test quote-to-payment-to-contract-to-delivery, audited administration, MFA/recovery integration, metadata presets/bulk edits, reviewed publication, CMS scheduling and advanced player controls. Rebuilding those features would waste time.

The remaining work is substantive. `TestPaymentExceptionOperations` cannot currently resolve financial/resource effects and reports refund/dispute evidence as uninspected. `ReadOwnedTestOrders` relies on the current session; durable customer claim/recovery and a cross-session library are absent. Resumable media sessions and the collection/kit/service/merchandise/membership product families are absent. Hosting, provider, private source and recovery acceptance are not supplied by code or credentials alone.

T03–T08 remain the six accepted parent groups. The remaining groups contain both accepted children and genuine gaps; counting them does not produce a meaningful completion percentage or reliable launch date.

## Work proceeding together

| Active lane | Accountable author | Concrete batch outcome | Exit evidence |
| --- | --- | --- | --- |
| Media domain, T13 | `/root/workspace_restore` | Private owner/track/role-bound resumable upload sessions and exact-byte completion into the existing quarantine service | Interruption, duplicate/corrupt/out-of-order chunks, revoked actor, concurrent completion and uncertain-commit retention |
| Media HTTP/admin, T11/T13 | `/root/integration_review` | Protected upload endpoints and an admin upload/resume/finalize/cancel journey; existing whole-file upload remains usable | CSRF/current authority/privacy failures, real service integration, client state handling and usable admin controls |
| Financial observations, T19/T21 | `/root/verify_gitlab` | Retained GET-only refund/dispute evidence attached to existing exception history and visible to authorized operators | Exact account/payment binding, incomplete evidence, replay/stale claims, encrypted immutable evidence and unchanged money/rights/resource effects |
| CI throughput, T01/T02 | `/root/github_ci_review` | Refresh measured database weights; assess and implement trustworthy identical-source post-merge deduplication only with fail-closed provenance | Exact complete inventory, source/runtime/policy identities, adversarial provenance cases, independent review and actual hosted results |
| Integration/review | `/root` plus independent reviewers | Compose compatible completed lanes, register tests and publish one frozen candidate | Exact source/tree, focused checks, full final acceptance and expected-head merge |

The first three rows form one product batch where interfaces allow. CI changes can join only after their own independent policy review; an unfinished optimization must not hold up a ready product batch. Authors continue the next bounded work while the frozen candidate runs. Reviewers inspect completed boundaries before the whole batch is finished.

## Dependency order after the active batch

1. **Customer access and media operations in parallel:** implement T23 claim/recovery ownership primitives and the T24 library against those interfaces; finish selected storage/worker/large-file transport and restore children in T13/T14/T25/T37. The current session-only history is reused. Build local/test contracts before activation decisions are settled.
2. **Financial lifecycle:** complete remaining T19 resolution, T20 verified unpaid release, T21 refund/dispute effects and T22 provider interoperability. Preserve original contracts and paid evidence. Use explicit versioned test policies during development; expiry or a provider redirect never substitutes for verified financial state.
3. **Product families and migration preparation:** develop draft/version/manifest/UI children for collections/kits, service/merchandise workflows and membership ledgers alongside their commerce prerequisites. Begin T34 deterministic restartable dry-run tooling with synthetic manifests before private exports arrive. Add actual adapters as product contracts settle. Do not wait for every parent to close before preparing an independent child.
4. **Complete each customer journey with its feature:** T15–T18 contact/consent/support/free delivery, T32 preferences/notifications and T33 responsive/accessibility/player/PWA behavior travel with the owning feature. Avoid separate backend-only and UI-only release cycles where one coherent batch is possible.
5. **Accept the replacement:** reconcile actual historical obligations/data/routes (T09/T35/T36), exercise the exact integrated release and operational recovery (T38), rehearse cutover/rollback (T39), then perform authorized activation and observed reconciliation (T40).

Required product types, memberships, migration and active obligations remain in scope. No private source audit or complete parity is inferred from this ordering.

## Remove avoidable waits

- Keep a stable integration candidate while CI runs. Do not push status-only changes or unrelated work onto that head and restart its tests.
- Use local focused unit/domain/HTTP/browser checks while editing. Use targeted MySQL cases for changed database semantics. Run full acceptance once per coherent ready batch under the current policy.
- Run independent authors and reviewers concurrently, with explicit file/interface ownership. A parent dependency is a completion condition, not a blanket ban on independent preparatory code.
- Record results in the PR body and carry durable status into the next substantive batch. Do not create a second source change solely to report the first run.
- Measure the dominant test costs. Accepted run 37098953844 spent 2,719 seconds in its slowest MySQL test step and about 85 additional seconds in the job. Setup caching alone cannot remove the main delay. Refreshed placement estimates 2,493 seconds for that measured workload; this estimate is not an observed speedup.
- Implement exact-source post-merge proof reuse only after trusted PR/base/tree/runtime/lockfile/workflow evidence and full fallback are verified. Until that implementation passes the existing gates, full main checks remain required. Never turn a skipped, stale or incomplete run into acceptance.

## Outside facts prepared without stopping unrelated code

| Applicable facts | Dependent activation/acceptance | Coding that continues now |
| --- | --- | --- |
| Actual host/operator, private storage and backup/retention arrangement | Deployed media, durable delivery and operational recovery | Local private transport, interfaces, worker/deployment tests and synthetic restore |
| Reviewed production terms, tax, late-payment/exclusive and refund/dispute consequences | Real selling and financial/resource transitions | Versioned policy contracts, marked test policies, retained evidence and adversarial state-machine tests |
| Customer claim/recovery and consent/notification policy | Production customer recovery, linking and messaging | Ownership primitives, account-first interfaces, anti-enumeration/replay tests and test journeys |
| Source exports, historical agreements/orders/memberships and fulfillment obligations | Reconciliation and safe retirement of the old store | Synthetic manifest validation, deterministic identifiers, dry runs, conflict handling and product adapters |
| Membership, service and merchandise terms/providers | Those production purchases and ongoing obligations | Draft/version schemas, ledgers, provider interfaces and synthetic end-to-end flows |

These are feature-specific dependencies, not requests to repeat general development permission. Each lane must finish the concrete implementation and reviewable choice before requesting the missing business/operational fact. Existing approved decisions and source evidence take precedence over generic defaults.

## Reporting standard

Report completed user behavior, implemented-but-unaccepted source, active work and the exact next blocker separately. Attach the tested commit/tree, actual test counts, independent review and merge disposition. A working preview, a source review, a green focused run and a fully accepted product batch are different milestones. No whole-project finish date is promised until the remaining product scope, private obligations and operational inputs support it.
