# Development agents and launch queue

October 2, 2026 (America/Chicago). Sean authorized completion of his single-seller store, configuration, verified integration and eventual private-server migration. This is the current assignment record; older active-agent tables describe historical checkpoints. The [task register](remaining-development-tasks.csv) retains acceptance criteria and dependencies. The [content onboarding packet](content-onboarding-readiness.md) connects that work to Sean's actual files and the path to selling.

## Current integration boundary

The frozen writer/configuration candidate is native GitLab `cd0cf7`, tree `fc61aabc`, equivalent to local `d953b93711f4093f5c033e3047972bff983888a5`, on `codex/gitlab-catalog-writer-locks-20261002`. [MR !1](https://gitlab.com/vaseydev/va-studio/-/merge_requests/1) has full acceptance [pipeline 2908229729](https://gitlab.com/vaseydev/va-studio/-/pipelines/2908229729) pending at this checkpoint. The new batch has not merged. Current results and any corrected successor belong in the MR, rather than a status-only commit that restarts its tests. The preserved branch `codex/rights-writers-20261002-08690e2` at `8fdc341` remains intact.

The candidate contains participating rights/catalog/license/exclusive/media/customer writer fences, native GitLab acceptance, Mac bootstrap and private-server preparation, plus bounded fixture/environment corrections. [The correction record](verification/gitlab-acceptance-corrections.md) and [commerce attribution record](verification/commerce-audit-attribution.md) preserve actual focused results and predecessor failures. Local MySQL, SQLite, browser and independent source review are evidence for their stated scopes; they do not replace full native acceptance or separate post-merge verification.

Publication compare/apply and payment-exception work below are **new work on isolated branches based on this candidate**. They are not included in the frozen candidate, accepted by its tests or merged merely because implementation is underway. The lead alone composes, publishes and merges with an expected head SHA after actual gates and review. No assignment changes production payment activation or cutover prerequisites.

## Assigned lanes and current execution

The team has seven concurrent slots including the lead. Eight named identities currently cover seven durable lanes: the independent reviewer is idle after its initial audit/lock-plan review while the publication browser sub-agent uses the available execution slot. A queued task has an accountable lane but no claim of a separate running agent. Reassign a slot after its current increment has a retained handoff; do not create overlapping implementations or 34 speculative branches.

| Lane / current identity | Active assignment and owned boundary | Exit and queued successor |
| --- | --- | --- |
| Integration — `/root` | Shared integration, cross-lane contracts/test registration, exact source/tree identity, MR and durable status; two publication Filament UI files and editor feature tests | Review actual composed source, finish full acceptance, expected-head merge and main verification; select next dependency-ready batch |
| CI acceptance — `/root/ci_acceptance` | Observe native jobs and investigate concrete failures; implement measured fixture teardown optimization on an isolated branch without changing the frozen candidate | Retain failures and narrow repair evidence; verify database census/provenance and same-source measurements for T01/T02 |
| Publication — `/root/publication_development`; sub-agent `/root/publication_browser` | T12 reviewed publication-manifest compare/apply domain contract and adversarial tests in its isolated worktree; browser sub-agent owns assigned regression helper/spec; lead owns the specified Filament/editor files | Independent review and source-bound acceptance; then track scheduling with an explicit timing contract, followed by remaining authoring/experience children |
| Commerce — `/root/commerce_operations` | T19 append-only retained-exception disposition and leased reconciliation in its isolated worktree | Audited, authorized, replay-safe operational outcomes; preserve paid-exception terminal evidence and no automatic grant/re-finalization; then T20 unpaid release and T21 refund/dispute contracts |
| Hosting — `/root/hosting_configuration` | Private-server configuration and reproducible development/hosting preparation in its isolated worktree; candidate preflight has 16 safeguard passes | Reviewable configuration with observed local checks and explicit untested host boundaries; actual T13/T14/T25/T37 deployment/restore after host facts are available |
| Launch/source — `/root/launch_plan` | This queue, task-register ownership and content onboarding packet; reconcile code, source obligations and feature-specific input needs | All 40 IDs have one accountable lane with exact dependencies; then private source/route/migration reconciliation once authorized evidence is available |
| Independent review — `/root/independent_release_review` | Initial audit/lock-plan review complete; currently idle, then reassigned after a handoff to review actual implemented source | Findings tied to exact source and retested corrections; independent acceptance input for each sensitive batch and T38 |

UI and tests travel with their feature lane; the publication browser sub-agent and lead's Filament/editor ownership are explicit exceptions to a single implementer. A reviewer does not accept their own implementation. The lead coordinates shared files before another lane edits them. Parent dependencies are acceptance prerequisites: a bounded preparatory child can proceed earlier, but cannot close its parent or enable dependent production behavior.

The CI lane measured 20.863 seconds of redundant teardown in nine real MySQL cases, 37.54% of that 55.578-second sample, and is testing a bounded correction. This is a measured local sample, not a prediction of full CI savings or permission to remove cases. The hosting lane's 16 passing preflight safeguards likewise do not prove that Sean's actual server is configured.

## All 40 completion groups

Dependencies below reproduce the CSV exactly. **I** = integration, **C** = CI acceptance, **P** = publication, **M** = commerce, **H** = hosting, **L** = launch/source. Independent review supports sensitive work across lanes. Sean supplies actual policy/content/account facts; assignment does not imply those facts or qualified legal review. Queued rows retain accepted children in the register even when no new implementation is active today.

| Group | Accountable lane | Canonical dependencies | Current work / next retained scope |
| --- | --- | --- | --- |
| T01 | C | BASE | Active acceptance: full native gates and source-bound receipts; reuse disabled |
| T02 | C | BASE | Active investigation: profile actual native bottlenecks without removing cases |
| T03 | H | BASE | Accepted; preserve whole-identifier evidence |
| T04 | H | BASE | Accepted; preserve exact hashes/UUID and migration recovery |
| T05 | H | BASE | Accepted; preserve waiting-image diagnostics |
| T06 | H | BASE | Accepted; preserve stems pre-scan inspection |
| T07 | H | BASE | Accepted; preserve source-size limits |
| T08 | H | BASE | Accepted; preserve deadline/recovery edges |
| T09 | L | BASE | Active preparation; private source acquisition/obligation audit still pending |
| T10 | I | BASE | Active feature-specific decision preparation; real choices/evidence from Sean remain |
| T11 | P | BASE | Queued: remaining journey/state contracts and exact asset provenance |
| T12 | P | T10 | Active child: reviewed manifest compare/apply; then scheduling and remaining roles/MFA/recovery/bulk licensing |
| T13 | H | T03 T04 T06 T07 T08 T10 | Active configuration preparation; adapter/resumable intake, quotas/orphans and retention implementation queued |
| T14 | H | T05 T06 T07 T08 T10 T13 | Queued: actual deployed scanner/worker isolation and representative media proof |
| T15 | P | T10 T11 | Queued: production inquiry delivery/notice/retention and abuse/error paths |
| T16 | P | T10 T11 | Queued: remaining consent/provider and related-content acceptance |
| T17 | P | T10 T11 | Queued: order-aware support and scanned private attachments |
| T18 | P | T10 T11 | Queued: approved free-license/asset identity and purpose-specific consent |
| T19 | M | T10 T12 | Active child: append-only operational disposition and leased reconciliation; broader resolution still open |
| T20 | M | T10 T19 | Queued after T19: verified unpaid release and late-payment/resource races |
| T21 | M | T10 T19 T20 | Queued after T20: refunds/disputes and explicit inventory/access effects |
| T22 | M | T10 T12 T13 T19 T20 T21 | Queued: production policies/provider interoperability and commercial child workflows |
| T23 | M | T10 T11 T12 | Queued: approved claim/recovery, anti-enumeration and takeover protection |
| T24 | M | T11 T13 T23 | Queued: cross-order library/originals and large download/resume ownership |
| T25 | H | T10 T13 | Queued: production archival/retention and hash-verified historical restore |
| T26 | M | T10 T11 T15 T16 T17 T18 T22 T24 | Queued: collections/albums with frozen composition, license and fulfillment |
| T27 | M | T10 T11 T13 T15 T16 T17 T18 T22 T24 | Queued: kits/presets and safe exact purchased archives |
| T28 | M | T10 T11 T15 T17 T22 T23 | Queued: service briefs/deposits/milestones/revisions/private delivery |
| T29 | M | T10 T11 T17 T22 T23 | Queued: merch stock/shipping/provider fulfillment and returns |
| T30 | M | T09 T10 T11 T22 T24 | Queued: membership plan versions and atomic append-only credits |
| T31 | M | T09 T10 T30 | Queued: renewal/cancellation/rollover and active-member continuity |
| T32 | M | T09 T10 T11 T17 T23 T24 | Queued: CRM/preferences/consent/outbox/support/data disposition and reports |
| T33 | P | T11 T12 T14 T15 T16 T17 T18 T24 T26 T27 T28 T29 T30 T31 T32 | Queued: remaining player/PWA and responsive/accessibility/physical-device acceptance |
| T34 | L | T09 T13 T25 | Queued: restartable source-mapped dry-run/import/conflict tooling |
| T35 | L | T09 T22 T24 T25 T26 T27 T28 T29 T30 T31 T32 T34 | Queued: historical orders/contracts/grants/credits/consent/fulfillment reconciliation |
| T36 | L | T09 T16 T26 T27 T28 T29 T34 | Queued: verified source routes, redirects/canonicals/social metadata/sitemaps |
| T37 | H | T10 T12 T13 T14 T25 | Active configuration preparation; actual hosting/alerts/backups/restore still pending |
| T38 | I | T01 T02 T03 T04 T05 T06 T07 T08 T09 T10 T11 T12 T13 T14 T15 T16 T17 T18 T19 T20 T21 T22 T23 T24 T25 T26 T27 T28 T29 T30 T31 T32 T33 T34 T35 T36 T37 | Queued: exact integrated release, parity/security/money/rights/device/performance acceptance |
| T39 | I | T35 T36 T37 T38 | Queued: rehearsed cutover/rollback, in-flight webhooks and one sales authority |
| T40 | I | T39 | Queued release: authorized activation and observed post-launch reconciliation |

T03–T08 are the six accepted parent groups. The other 34 retain open parent criteria, including substantial accepted children. These are differently sized deliverables, not 34 new features or a completion percentage. The 103-row [coverage map](remaining-parity-coverage.csv) still covers 92 required baseline capabilities and 11 optional/postlaunch capabilities; no scope is removed by this queue.

## Path to content and selling

1. **Integrate the current foundation:** resolve actual native failures, retain full census/provenance and independent review, merge the verified head and verify main. New feature work can continue on isolated branches while that run completes.
2. **Prepare content now:** assemble originals, exact metadata, rights references, real license/price decisions and site copy in protected storage. Accepted draft/preset/bulk-edit/private-preview workflows can support an isolated, persistent authoring installation before public hosting. Real processing still needs configured workers, signatures and the approved tag; fixtures do not establish those conditions.
3. **Finish publication and commerce in parallel:** P completes manifest compare/apply, then scheduling and remaining security/authoring children. M completes T19 → T20 → T21 → T22; customer claim/recovery T23 → T24 and retained originals T25 follow their own identity/storage prerequisites. H prepares and proves T13/T14/T37. L supplies applicable T09/T10 evidence while unrelated implementation continues.
4. **Prove a real catalog purchase in a provider test account:** reviewed seller policies and actual content must survive upload → publication → immutable checkout → verified payment → original contract → authorized download, including failure/refund/reconciliation and exact-byte checks. This engineering milestone precedes live collection; credentials alone do not implement production checkout.
5. **Finish replacement scope and obligations:** complete retained product families T26–T32, experience T33, restartable migration T34, historical reconciliation T35 and route continuity T36. Source findings can advance active membership/service/merch obligations; a first-track flow does not silently defer them.
6. **Release:** T38 exact integrated acceptance → T39 rehearsed cutover/rollback → T40 authorized activation and observed reconciliation. Preserve one sales authority for exclusive inventory and rollback that retains new orders and existing customer rights.

The smallest implementation path toward a first sale is publication/security + durable media + commerce exceptions/unpaid/refunds/production provider integration + customer recovery/delivery. Full replacement additionally needs all applicable source/product/experience/operational gates. No launch date is inferred from task or PR counts.

## Concrete outside evidence

The [onboarding packet](content-onboarding-readiness.md) lists inputs tied to current validation and release dependencies. Prepare a representative content pack and approved preview tag first; relevant license/price/merchant decisions before production commerce; actual private-server facts before deployment; official source records before historical reconciliation. Missing evidence does not mean Sean lacks corresponding assets, accounts or rights.

The [private audit checklist](migration/authenticated_studio_audit_checklist.csv) still has 14 unperformed areas, and the [unknown ledger](migration/known_unknowns_and_validation.csv) retains 27 unresolved acquisition/validation facts. No private audit, customer import or obligation reconciliation is claimed. The [Mac bootstrap](development-macos.md) is prepared and safeguard-tested; installation on Sean's Mac is unverified. Private-server defaults are prepared; no actual server, production storage, real mail, signed deployed webhook or restore has been accepted. Physical-device testing and final deployment execution still need real access and an identified operator.
