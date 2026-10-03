# Development agents and launch queue

## Current GitHub handoff — October 2, 2026

Sean directed development to continue in [SeanVasey/VA-Studio](https://github.com/SeanVasey/VA-Studio). The active candidate is `codex/github-handoff-20261002`. It composes the shared writer foundation, browser/runtime repairs, publication compare/apply, payment-exception operations and launch preparation. [The handoff record](verification/github-handoff-20261002.md) lists immutable source identities and the acceptance boundary.

Current bounded assignments: `/root` owns integration and the PR; `/root/github_ci_review` owns repository/runtime CI portability; `/root/integration_review` independently reviews composition; `/root/verify_gitlab` audits source preservation. These replace active-agent claims in the historical GitLab checkpoint below. The seven durable lanes and all 40 parent criteria remain assigned; no parent closes from source transfer alone. Fresh GitHub acceptance and independent review must precede expected-head merge. Record run results on the GitHub PR without status-only source changes that restart CI.

## Preserved GitLab checkpoint

October 2, 2026 (America/Chicago). Sean authorized completion of his single-seller store, configuration, verified integration and eventual private-server migration. This is the current assignment record; older active-agent tables describe historical checkpoints. The [task register](remaining-development-tasks.csv) retains acceptance criteria and dependencies. The [content onboarding packet](content-onboarding-readiness.md) connects that work to Sean's actual files and the path to selling.

## Current integration boundary

The frozen writer/configuration candidate is native GitLab `cd0cf7`, tree `fc61aabc`, equivalent to local `d953b93711f4093f5c033e3047972bff983888a5`, on `codex/gitlab-catalog-writer-locks-20261002`. [MR !1](https://gitlab.com/vaseydev/va-studio/-/merge_requests/1) has a terminal, unsuccessful full acceptance [pipeline 2908229729](https://gitlab.com/vaseydev/va-studio/-/pipelines/2908229729). All four MySQL jobs ended with `ci_quota_exceeded`; they did not produce complete acceptance. SQLite shard 1, provenance, frontend, backend quality and the genuine-scanner related-browser job passed. SQLite shard 2 reported zero test failures/errors across 1,514 reported cases, 143 skips and 16,200 assertions, then its receipt collector rejected the exact skip-identity policy comparison. The CI lane traced the two extra skips to physical short-write tests requiring the missing native PHP PCNTL extension: 283 observed global skips versus the unchanged 281-case policy. The corrected installation/runtime preflight passed focused red/green reproduction: disabling `pcntl_signal` reproduces receipt rejection; enabling PCNTL passes all three selected cases with 18 assertions and receipt validation. The 23 native collector, 12 setup and 24 shared receipt safeguards pass; no skip allowance is added. Independent review of the exact corrected head is complete, and the combined repair is published as native `b5c0a40700777e0fdb608697dc4ddad10b3fbdb2`; full native acceptance remains pending capacity. Both operator browser jobs hit the same standalone image-fixture bootstrap defect; their remaining results were 47 desktop passes and 46 WebKit passes plus one intentional WebKit skip.

The new batch has not merged. Capacity must be restored and source corrections verified before a new full native acceptance run; repeatedly retrying the exhausted hosted quota cannot supply that evidence. Current results and any corrected successor belong in the MR, rather than a status-only commit that restarts its tests. The preserved branch `codex/rights-writers-20261002-08690e2` at `8fdc341` remains intact.

The candidate contains participating rights/catalog/license/exclusive/media/customer writer fences, native GitLab acceptance, Mac bootstrap and private-server preparation, plus bounded fixture/environment corrections. [The correction record](verification/gitlab-acceptance-corrections.md) and [commerce attribution record](verification/commerce-audit-attribution.md) preserve actual focused results and predecessor failures. Local MySQL, SQLite, browser and independent source review are evidence for their stated scopes; they do not replace full native acceptance or separate post-merge verification.

Publication compare/apply and payment-exception work below are **implemented and independently reviewed on isolated branches based on this candidate**, with source-bound local checks. They are not included in the frozen candidate, accepted by its tests or merged. The lead alone composes, publishes and merges with an expected head SHA after actual gates and review. No assignment changes production payment activation or cutover prerequisites.

| Preserved increment | Published GitLab identity | Actual evidence and remaining boundary |
| --- | --- | --- |
| Launch/configuration preparation | `codex/launch-readiness-configuration-20261002`, [a6947b08a3408d2ca36d3d96769dd106b2a81451](https://gitlab.com/vaseydev/va-studio/-/commit/a6947b08a3408d2ca36d3d96769dd106b2a81451), tree `bea52b577cea00a0ac1747ba08808be9b356e16e` | Seven lanes, all 40 owners, onboarding packet and private-server preflight; 16/16 safeguards independently rerun. This later documentation checkpoint updates terminal results; no actual host deployment or Mac installation is claimed |
| Reviewed publication compare/apply | `codex/reviewed-publication-apply-20261002`, [d58250a2c28a75a9b1a2e5be74f87947f0c930f2](https://gitlab.com/vaseydev/va-studio/-/commit/d58250a2c28a75a9b1a2e5be74f87947f0c930f2), tree `5bb7a043ab2b6fcc2b7d0390464c09cc2988563b` | Domain SQLite 67/834 and MySQL 13/899 passed; editor SQLite 33/433 includes overlapping guards; default WebKit 1/1 passed. Full native and the new genuine-scanner browser journey remain pending; track scheduling is separate |
| Payment-exception operations | `codex/payment-exception-operations-20261002`, [82552f05a47eedea624d99694fec7365d005aef3](https://gitlab.com/vaseydev/va-studio/-/commit/82552f05a47eedea624d99694fec7365d005aef3), tree `1462c4696c6ab028fe284cb3eccf9c9f74dbebc6` | Append-only acknowledgment/history and leased provider-status inspection; SQLite 79 executed plus 3 exact MySQL-only skips, MySQL 28/431 and WebKit 1/1 passed. Full native/Chromium acceptance and broader financial resolution remain pending |
| Browser/runtime and measured teardown repair | `codex/browser-image-fixture-bootstrap-20261002`, [b5c0a40700777e0fdb608697dc4ddad10b3fbdb2](https://gitlab.com/vaseydev/va-studio/-/commit/b5c0a40700777e0fdb608697dc4ddad10b3fbdb2), tree `9871757607f340f7a2d1a2a80ef21be80f83a60d` | Reproduced bootstrap defect, corrected exact image generation and local WebKit upload journey 1/1 passed. Combined browser/teardown/PCNTL repair matches local `9df417c1a33cd777609ef7eacd5e4a2e237a207e` across all 37 root entries and modes. Focused correction checks and independent exact-head review pass; native acceptance remains pending restored capacity |

Each published tree was compared against its corresponding local tree. These are reviewable, durable source checkpoints, not completed parent tasks or transferred acceptance receipts.

## Assigned lanes and current execution

The team has seven concurrent slots including the lead and seven durable lanes. Named implementation/review identities record accountability; they do not imply those agents are all running. The bounded publication, commerce, hosting, browser and independent-review assignments below have retained handoffs. Investigation and reviewed repairs for both identified CI defects are complete; the CI lane is queued for restored capacity. The lead retains integration ownership, and the launch lane has refreshed this terminal checkpoint. Reassign a free slot to the next dependency-ready child after its contract and file ownership are fixed; do not create overlapping implementations or 34 speculative branches.

| Lane / accountable identity | Current state and owned boundary | Exit and queued successor |
| --- | --- | --- |
| Integration — `/root` | Active: shared integration, cross-lane contracts/test registration, exact source/tree identity, MR and durable status. Publication Filament/editor increment complete and published with its feature | Resolve capacity and reviewed source corrections, finish full acceptance, expected-head merge and main verification; select next dependency-ready batch |
| CI acceptance — `/root/ci_acceptance`; review sub-agent `/root/ci_acceptance/teardown_review` | Both identified defects repaired, focused-verified, independently reviewed and published with measured teardown optimization at `b5c0a407`; queued for restored capacity | Preserve exact policy and census/provenance; resume full acceptance of corrected integrated source only when capacity is available |
| Publication — `/root/publication_development`; sub-agent `/root/publication_browser` | Bounded T12 compare/apply domain, UI and browser increment complete, reviewed and published; full native/genuine-scanner journey acceptance queued | Accept exact integrated source; then define track scheduling's explicit timing contract and remaining authoring/security children |
| Commerce — `/root/commerce_operations` | Bounded T19 operational history and leased provider-status inspection complete, reviewed and published; full acceptance queued | Broader T19 financial-resolution work remains open; preserve terminal paid-exception evidence and no automatic grant/re-finalization, then T20 unpaid release and T21 refund/dispute contracts |
| Hosting — `/root/hosting_configuration` | Bounded configuration/preflight complete, reviewed and published; 16 safeguards passed. Actual host work queued pending host facts/access | Implement/verify remaining T13/T14/T25/T37 storage, worker, deployment and restore criteria on the selected host |
| Launch/source — `/root/launch_plan`; checkpoint `/root/launch_checkpoint` | All 40 owners/dependencies and onboarding packet published; current docs refreshed for retained increments and terminal CI result | Private source/route/migration reconciliation queued for actual records; prepare feature-specific decisions without inventing policy/content facts |
| Independent review — `/root/independent_release_review` | Final source reviews complete for publication, commerce, hosting and composed browser/teardown/PCNTL repairs; available for new corrected source | Review actual subsequent corrections and integrated candidate; independent acceptance input for each sensitive batch and T38 |

UI and tests travel with their feature lane; the publication browser sub-agent and lead's Filament/editor ownership are explicit exceptions to a single implementer. A reviewer does not accept their own implementation. The lead coordinates shared files before another lane edits them. Parent dependencies are acceptance prerequisites: a bounded preparatory child can proceed earlier, but cannot close its parent or enable dependent production behavior.

In a nine-case MySQL sample, the CI lane reduced measured teardown from 20.874 seconds to 2.686 seconds; total observed runtime changed from 55.578 to 48.627 seconds. Every setup still runs full migrations. Four lifecycle guards passed on each database, and independent review is complete. This is a measured local sample, not a prediction of full CI savings or permission to remove cases. The hosting lane's 16 passing preflight safeguards likewise do not prove that Sean's actual server is configured.

## All 40 completion groups

Dependencies below reproduce the CSV exactly. **I** = integration, **C** = CI acceptance, **P** = publication, **M** = commerce, **H** = hosting, **L** = launch/source. Independent review supports sensitive work across lanes. Sean supplies actual policy/content/account facts; assignment does not imply those facts or qualified legal review. Queued rows retain accepted children in the register even when no new implementation is active today.

| Group | Accountable lane | Canonical dependencies | Current work / next retained scope |
| --- | --- | --- | --- |
| T01 | C | BASE | Browser/PCNTL corrections focused-verified, reviewed and published; new native acceptance blocked by quota; skip policy unchanged and reuse disabled |
| T02 | C | BASE | Bounded teardown optimization measured, reviewed and published; native integrated acceptance pending |
| T03 | H | BASE | Accepted; preserve whole-identifier evidence |
| T04 | H | BASE | Accepted; preserve exact hashes/UUID and migration recovery |
| T05 | H | BASE | Accepted; preserve waiting-image diagnostics |
| T06 | H | BASE | Accepted; preserve stems pre-scan inspection |
| T07 | H | BASE | Accepted; preserve source-size limits |
| T08 | H | BASE | Accepted; preserve deadline/recovery edges |
| T09 | L | BASE | Preparation packet published; private source acquisition/obligation audit still pending |
| T10 | I | BASE | Feature-specific input inventory published; real choices/evidence from Sean remain |
| T11 | P | BASE | Queued: remaining journey/state contracts and exact asset provenance |
| T12 | P | T10 | Compare/apply child implemented/reviewed/published, acceptance pending; scheduling and remaining roles/MFA/recovery/bulk licensing queued |
| T13 | H | T03 T04 T06 T07 T08 T10 | Configuration preparation published; adapter/resumable intake, quotas/orphans and retention implementation queued |
| T14 | H | T05 T06 T07 T08 T10 T13 | Queued: actual deployed scanner/worker isolation and representative media proof |
| T15 | P | T10 T11 | Queued: production inquiry delivery/notice/retention and abuse/error paths |
| T16 | P | T10 T11 | Queued: remaining consent/provider and related-content acceptance |
| T17 | P | T10 T11 | Queued: order-aware support and scanned private attachments |
| T18 | P | T10 T11 | Queued: approved free-license/asset identity and purpose-specific consent |
| T19 | M | T10 T12 | Operational history/provider-inspection child implemented/reviewed/published, acceptance pending; broader financial resolution still open |
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
| T37 | H | T10 T12 T13 T14 T25 | Configuration/preflight published; actual hosting/alerts/backups/restore still pending |
| T38 | I | T01 T02 T03 T04 T05 T06 T07 T08 T09 T10 T11 T12 T13 T14 T15 T16 T17 T18 T19 T20 T21 T22 T23 T24 T25 T26 T27 T28 T29 T30 T31 T32 T33 T34 T35 T36 T37 | Queued: exact integrated release, parity/security/money/rights/device/performance acceptance |
| T39 | I | T35 T36 T37 T38 | Queued: rehearsed cutover/rollback, in-flight webhooks and one sales authority |
| T40 | I | T39 | Queued release: authorized activation and observed post-launch reconciliation |

T03–T08 are the six accepted parent groups. The other 34 retain open parent criteria, including substantial accepted children. These are differently sized deliverables, not 34 new features or a completion percentage. The 103-row [coverage map](remaining-parity-coverage.csv) still covers 92 required baseline capabilities and 11 optional/postlaunch capabilities; no scope is removed by this queue.

## Path to content and selling

1. **Integrate the current foundation:** resolve actual source/receipt failures and hosted capacity, retain full census/provenance and independent review, run full acceptance on the corrected candidate, merge the verified head and verify main. New feature work can continue on isolated branches while those blockers are resolved.
2. **Prepare content now:** assemble originals, exact metadata, rights references, real license/price decisions and site copy in protected storage. Accepted draft/preset/bulk-edit/private-preview workflows can support an isolated, persistent authoring installation before public hosting. Real processing still needs configured workers, signatures and the approved tag; fixtures do not establish those conditions.
3. **Finish publication and commerce in parallel:** accept the implemented compare/apply and payment-operations children, then P advances scheduling and remaining security/authoring children. M completes remaining T19 → T20 → T21 → T22; customer claim/recovery T23 → T24 and retained originals T25 follow their own identity/storage prerequisites. H prepares and proves T13/T14/T37. L supplies applicable T09/T10 evidence while unrelated implementation continues.
4. **Prove a real catalog purchase in a provider test account:** reviewed seller policies and actual content must survive upload → publication → immutable checkout → verified payment → original contract → authorized download, including failure/refund/reconciliation and exact-byte checks. This engineering milestone precedes live collection; credentials alone do not implement production checkout.
5. **Finish replacement scope and obligations:** complete retained product families T26–T32, experience T33, restartable migration T34, historical reconciliation T35 and route continuity T36. Source findings can advance active membership/service/merch obligations; a first-track flow does not silently defer them.
6. **Release:** T38 exact integrated acceptance → T39 rehearsed cutover/rollback → T40 authorized activation and observed reconciliation. Preserve one sales authority for exclusive inventory and rollback that retains new orders and existing customer rights.

The smallest implementation path toward a first sale is publication/security + durable media + commerce exceptions/unpaid/refunds/production provider integration + customer recovery/delivery. Full replacement additionally needs all applicable source/product/experience/operational gates. No launch date is inferred from task or PR counts.

## Concrete outside evidence

The [onboarding packet](content-onboarding-readiness.md) lists inputs tied to current validation and release dependencies. The exact outside inputs are:

- Restored GitLab CI capacity through the account's compute allocation or an identified, authorized runner with actual execution access. The four quota-terminated jobs establish this immediate acceptance blocker; no billing change or private-runner installation is claimed.
- A representative protected content pack with original WAV, artwork, applicable stems, metadata and rights references, plus the actual approved preview-tag WAV and retained hash.
- Actual license/price/deliverable terms, separate reviewer account/contact and merchant policies needed by the next commerce behaviors; test fixtures supply none of these decisions.
- The intended private host's OS/architecture/resources, storage/network arrangement and authorized access route, plus identified deployment/backup/restore operators. A server preference alone cannot configure services.
- Official catalog/customer/contract/obligation exports and source-route records for private audit and historical reconciliation; secrets and customer exports stay out of Git.

Existing authorization covers preparation and reversible implementation. These are missing operational facts, material and access, not a request to repeat broad development approval. Missing evidence does not mean Sean lacks corresponding assets, accounts or rights.

The [private audit checklist](migration/authenticated_studio_audit_checklist.csv) still has 14 unperformed areas, and the [unknown ledger](migration/known_unknowns_and_validation.csv) retains 27 unresolved acquisition/validation facts. No private audit, customer import or obligation reconciliation is claimed. The [Mac bootstrap](development-macos.md) is prepared and safeguard-tested; installation on Sean's Mac is unverified. Private-server defaults are prepared; no actual server, production storage, real mail, signed deployed webhook or restore has been accepted. Physical-device testing and final deployment execution still need real access and an identified operator.

