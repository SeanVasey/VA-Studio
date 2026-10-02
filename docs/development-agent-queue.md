# Development agents and launch queue

October 2, 2026 (America/Chicago). This is the current assignment record for Sean's instruction to organize agents, complete configuration and finish his single-owner store so he can onboard content and sell. It supersedes historical active-agent tables in [development control](development-control.md), not their accepted evidence. Completion criteria remain in the [40-group register](remaining-development-tasks.csv); this queue does not accept a parent task or narrow parity.

## Integration and evidence boundary

The authoritative integration branch is `codex/gitlab-catalog-writer-locks-20261002`, with [draft GitLab MR !1](https://gitlab.com/vaseydev/va-studio/-/merge_requests/1). The preserved branch `codex/rights-writers-20261002-08690e2` at `8fdc341` remains unchanged. The initial GitLab candidate is `495d3b1386880ad7cd98c79cbc95c6985eba5e2e`; its local equivalent tree is `6ddc03b10c72b57b9751c6ff42c468089febe3b1` at local commit `be5fb2fd2cf989f8fe51d2a77ef78a92fae9235c`. Current edits compose a subsequent candidate; it is not frozen or accepted by those earlier results. Runtime corrections need their own final source-bound evidence, independent review and full GitLab acceptance before expected-head merge.

The initial catalog correction passed 206 local MySQL cases, including 24 concurrency cases, with 3,286 assertions. The initial SQLite run executed 182 cases / 1,708 assertions and skipped those 24 exact MySQL-only cases. Initial subset CI cannot accept the composed candidate. Full four-MySQL/two-SQLite partitions, frontend/native/browser checks, audits and provider-native proof collection are being ported; configuration is not successful execution.

One lead integrates. Current bounded corrections share disjoint assigned files in this existing candidate; contributors must not change another owner's files. Subsequent parallel runtime packages use separate branches/worktrees under `AGENTS.md`. Shared service contracts and test registration are coordinated before edits. Only the lead publishes, freezes and merges the composed source; the reviewer reads the actual frozen head. Accepted evidence is retained separately from subsequent corrections.

## Current agents and bounded exits

Seven slots include the integration lead. Completed contributors remain available for a new bounded assignment; their names below are actual identities, not promises of unattended future execution.

| Slot / identity | Current ownership | State and exit |
| --- | --- | --- |
| Lead — `/root` | Integration, shared test registration, durable status, publication and exact-head acceptance | Active; compose owned changes, freeze, obtain review, execute applicable full gates, merge only verified expected head |
| CI — `gitlab_workflow` | `.gitlab-ci.yml`, GitLab setup/evidence helpers and dedicated safeguards | Active; port complete acceptance with native GitLab provenance, exact census and fail-closed collector tests; retain the predefined native disposable-runner guard |
| Domain — `exclusive_lock_fix` | `PrepareExclusiveOffer`, `ActivateExclusiveOffer`, `ManageRightsScope`, exclusive/scope authority and concurrency tests/helpers | Exclusive correction and four scope races passed initially. Six nested repeatable-read authority cases then reproduced stale authority failures; the minimal locking Gate correction is under a fresh 31-case MySQL check. Earlier green results do not accept that subsequent change |
| Domain — `license_lock_fix` | License writer commands/tests/helpers plus `PublishOffer`, `PublicationReadiness` and `VerifiedLicense` | Initial MySQL run passed 69 cases and failed one distinct-actor license publication case because readiness read old status. The correction passed 115 SQLite cases; the fresh 71-case MySQL check remains pending. Final composed review/acceptance is still required |
| Operations — `mac_setup` | `scripts/dev/bootstrap-macos.sh`, its tests and `docs/development-macos.md` | Preparation completed; 13 safeguard tests passed. Actual Mac installation has not occurred: no connected Mac execution surface |
| Source/plan — `launch_gap_review` | This file and current prepend sections of development control/remaining plan | Source review completed; current queue and production/obligation gaps documented without changing CSV completion criteria |
| Review — `independent_review` | Read-only composed-source and participating-writer inventory | Initial review completed; identified further source-traced participants; final review waits for frozen corrected source |

The lead owns UI integration and regression coordination until a current contributor is reassigned. Do not fabricate separate running UI/test agents. Reassign a completed slot when a dependency-ready package has named files, API/state contract, tests and exit criteria; do not create 34 simultaneous implementation branches.

### Source-reviewed participant backlog

The exclusive/scope owner is correcting `ManageRightsScope::link/block`, with initial real races and a subsequently reproduced nested repeatable-read authority regression as recorded above. The license owner is correcting the separately executed distinct-actor old-status readiness failure. These executed cases are separate from the broader backlog: independent source review identified resource-to-actor ordering in `QueueMediaProcessing`, `BindStemsToRecording`, media completion in `MediaProcessor`, `IngestMediaUpload`, and `ReserveQuoteInventory`. The broader leads are source-traced compatibility risks, not executed reproductions or an executed universal deadlock claim. Some are pre-existing interactions with earlier actor-first publication services. Review each participant's actual caller/transaction/background-authority semantics, reproduce the relevant failure where feasible, then add bounded compatible corrections and both-order races. Do not treat a static ordering observation as proof of production exploitation, or impose staff authorization on a background job merely to make lock order uniform. The exclusive/scope and license owners retain their explicit files; the lead coordinates any further media/inventory assignment. This queue assigns no overlapping runtime edits.

## All 40 completion groups

Lane abbreviations: **L** integration lead, **C** CI, **D** domain/backend, **U** UI/design, **O** operations/source/policy, **Q** regression/security, **R** independent review. These are durable roles; current agent identities may rotate. L remains accountable for integration of every row. Sean supplies actual business decisions/access; a named role does not imply access to a provider or a qualified legal opinion.

| Group | Delivery lanes | Canonical dependency | Current remaining scope |
| --- | --- | --- | --- |
| T01 | C, Q, R | BASE | Full GitLab acceptance/provenance port; accepted earlier focused receipts retained; reuse disabled |
| T02 | C, Q | BASE | Further measured profiling retaining exact scenarios/census |
| T03 | D, Q | BASE | Accepted whole-identifier guards; preserve evidence |
| T04 | D, Q, R | BASE | Accepted byte-exact hash/UUID and ownership-safe migration safeguards |
| T05 | U, D, Q | BASE | Accepted waiting-image diagnostics |
| T06 | D, Q | BASE | Accepted stems pre-scan inspection |
| T07 | D, Q | BASE | Accepted source-size bounds |
| T08 | D, Q | BASE | Accepted stems deadline/recovery edges |
| T09 | O, Q | BASE | Private source/obligation acquisition and parity reconciliation |
| T10 | O, L; Sean | BASE | Concrete feature-specific policy decisions and production account configuration |
| T11 | U, O, Q | BASE | Remaining journey/state contracts and exact asset provenance |
| T12 | D, U, Q, R | T10 | Current rights/writer candidate; reviewed manifest apply; track scheduling; remaining roles/MFA/recovery/bulk licensing |
| T13 | D, O, Q, R | T03 T04 T06 T07 T08 T10 | Private object storage/resumable intake, quotas/orphans and retained revision protection |
| T14 | O, D, Q | T05 T06 T07 T08 T10 T13 | Deployed worker/scanner/isolation and real-media acceptance |
| T15 | D, U, O, Q | T10 T11 | Production inquiry delivery/notice/retention and abuse/error paths |
| T16 | U, D, O, Q | T10 T11 | Remaining consent/provider policy and related content acceptance |
| T17 | U, D, Q, R | T10 T11 | Sharing/support, buyer context and scanned private attachments |
| T18 | D, U, Q, R | T10 T11 | Approved free license/asset identity and purpose-specific consent |
| T19 | D, U, Q, R | T10 T12 | Audited payment-exception transitions beyond accepted read-only inspection |
| T20 | D, Q, R | T10 T19 | Verified unpaid release and late-payment/resource races |
| T21 | D, U, Q, R | T10 T19 T20 | Partial/full refunds/disputes and inventory/access consequences |
| T22 | D, O, U, Q, R | T10 T12 T13 T19 T20 T21 | Production-capable commerce/provider interoperability and required commercial-policy child workflows |
| T23 | D, U, Q, R | T10 T11 T12 | Guest/account claim and recovery, anti-enumeration and takeover protection |
| T24 | D, U, Q, R | T11 T13 T23 | Cross-order library/originals, large download/resume and ownership |
| T25 | O, D, Q, R | T10 T13 | Production archival/retention/historical compatibility beyond synthetic restore |
| T26 | D, U, Q, R | T10 T11 T15 T16 T17 T18 T22 T24 | Collections/albums with frozen composition/licensing/fulfillment |
| T27 | D, U, Q, R | T10 T11 T13 T15 T16 T17 T18 T22 T24 | Sound kits/presets and safe exact purchased archives |
| T28 | D, U, O, Q, R | T10 T11 T15 T17 T22 T23 | Services/deposits/milestones/revisions/private delivery |
| T29 | D, U, O, Q, R | T10 T11 T17 T22 T23 | Merchandise/provider stock/shipping/fulfillment/returns |
| T30 | D, U, Q, R | T09 T10 T11 T22 T24 | Membership plan versions and atomic append-only credits |
| T31 | D, O, Q, R | T09 T10 T30 | Renewal/cancellation/rollover and legacy member continuity |
| T32 | D, U, O, Q, R | T09 T10 T11 T17 T23 T24 | CRM/preferences/consent/outbox/support/data disposition and truthful reports |
| T33 | U, D, O, Q | T11 T12 T14 T15 T16 T17 T18 T24 T26 T27 T28 T29 T30 T31 T32 | Complete player/PWA, responsive/accessibility/native-device/performance acceptance |
| T34 | D, O, Q, R | T09 T13 T25 | Restartable source-mapped dry-run/import/conflict tooling |
| T35 | O, D, Q, R; Sean | T09 T22 T24 T25 T26 T27 T28 T29 T30 T31 T32 T34 | Historical catalog/orders/contracts/grants/credits/consent/fulfillment reconciliation |
| T36 | U, D, O, Q | T09 T16 T26 T27 T28 T29 T34 | Verified source routes, redirects/canonicals/metadata/sitemaps |
| T37 | O, D, Q, R | T10 T12 T13 T14 T25 | Connected hosting/secrets/health/queue/payment alerts/backups/recovery/operator |
| T38 | L, C, U, O, Q, R | T01–T37 | Frozen release; complete applicable parity/security/money/rights/device/performance evidence |
| T39 | L, O, D, Q, R | T35 T36 T37 T38 | Concrete rehearsed cutover/rollback and in-flight webhook/sales-authority plan |
| T40 | L, O, Q; Sean/operator | T39 | Authorized domain/payment activation and observed post-launch reconciliation |

T03–T08 are the six accepted groups. All other groups retain open parent criteria, even where accepted children exist. Neither 34 open groups nor PR numbering supplies an effort estimate. No launch date or percentage is asserted.

## Dependency-ready stages and first sale

1. **Finish current candidate:** compose compatible participating writers; prove relevant authority/rollback/races; port full GitLab acceptance; review the actual frozen source. Keep the preserved branch intact.
2. **Catalog publication:** reviewed current-evidence manifest compare/apply, then track scheduling with its own timing decision. Preserve accepted metadata presets, bulk edits, private review, manual guards and whole-site scheduling.
3. **Parallel readiness:** O gathers source/obligation and provider evidence for T09/T10/T13/T14/T37; U prepares T11/T33 customer/owner state contracts; Q develops adversarial acceptance for the selected ready increment. Synthetic work can continue without private exports.
4. **Selling and access:** T19 → T20 → T21 → T22, alongside T23 → T24 and T25 after their storage/security contracts are ready. Actual provider test-account purchase/failure/refund/reconciliation and immutable payment-to-contract-to-download evidence are required.
5. **Retained product families and continuity:** rotate D/U/Q into T26–T32; O/D into T34–T36; complete T33/T37 cross-site/operations acceptance. Actual source obligations can advance the necessary membership/service/merch slice.
6. **Release:** T38 frozen acceptance → T39 rehearsed cutover → T40 authorized launch and observed reconciliation.

A production-capable first-track-sale journey is an engineering milestone, not automatic permission to replace BeatStars. The [103-row coverage map](remaining-parity-coverage.csv) retains 92 launch-required and 11 optional/postlaunch baseline requirements. Deferring an active membership, service, merch or historic purchase obligation requires verified absence or an expressly approved continuity plan; silent deferral cannot reduce the replacement scope. No independent seller onboarding, tenant stores, marketplace commissions or Stripe Connect platform are planned.

## Concrete readiness gaps

The existing Filament track/media/rights/license/offer/preset/release resources already support substantial private authoring. Uploads become publishable only after configured scanner/tag/worker processing, verified deliverables, cleared rights and approved offers. [Media operations](media-processing.md) specifies the actual quarantine, limits, tool and isolation contracts.

Selling still requires code, not merely live credentials: `routes/web.php` returns HTTP 503 at `/checkout`; `Orders/OrderPolicy`, `Checkout/CheckoutPolicy` and `Delivery/TestAccessPolicy` retain explicit local/testing/test-payment/private-local boundaries. Customer claim/recovery, cross-order library, production commerce, refund/dispute resolution and durable storage remain incomplete. Do not turn synthetic policies into production policies or enable live payments by bypassing their guards.

Prepare a reviewable configuration/decision packet before requesting missing inputs:

| Needed input/evidence | Canonical references | Delivery responsibility |
| --- | --- | --- |
| Host/region/topology, deployment, MySQL, queue/cache, private storage, email and monitoring accounts | Decision U-02/U-03; source unknown U-021; T13/T14/T37 | O prepares deployable secret-free configuration; Sean/operator selects and authorizes accounts; credentials installed outside Git |
| Real scanner signatures/limits/isolation, ffmpeg tools, approved preview tag, master/stem provenance and alignment | Source U-003/U-006; T09/T14 | O/D/Q prove representative approved assets on production-like Linux worker |
| Seller entity, exact licenses/rights/exclusive policy, currency/tax/capture/refund/dispute/late-payment/terms/privacy | Decision U-04/U-05/U-08/U-13; source U-004–009/U-023; T10/T19–T22 | L/O prepare concrete options from actual records; Sean and applicable qualified reviewers approve policy |
| Stripe application configuration, deployed signed webhook, real test-account purchase/failure/refund/reconciliation | Existing payment account observation; T22 | D/O complete application interoperability. Existing Vasey Multimedia test/live account availability does not prove credentials, deployed webhook or charge readiness |
| Account-first versus guest claim, recovery identity, admin/helper roles/MFA/recovery operator | Decision U-07; source U-022; T12/T23 | D/U/Q implement and prove approved security journey |
| Private source inventory, originals/hashes, historic contracts/orders/assets, active credits/subscriptions/services/returns | Decision U-09/U-10/U-12; source U-002–017/U-024; T09/T25/T35 | O conducts authorized acquisition; D/Q reconcile private evidence without placing customer records in Git |
| Transactional email, consent/suppression, retention/export/deletion and support/takedown policy | Decision U-13; source U-016/U-023; T15/T32 | O/D prepare provider integration and truthful retry/delivery states; purchases never imply marketing consent |
| Backup retention, isolated restore, RPO/RTO, incident owner/deployment operator | Source U-027; T25/T37/T39 | O/Q demonstrate measured restore and operational ownership |
| DNS/certificates/routes, source coexistence and exclusive-sales authority | Source U-018–020; T36/T39 | O prepares concrete rehearsal; one sales authority preserves in-flight payments and new paid records |
| Physical iOS/Android playback/PWA/downloads, accessibility and performance | Source U-026; T33 | U/Q prepare exact-candidate cases; physical-device tester remains to be assigned |
| Approved official identity master and content/copy provenance | Brand README/manifests; T11/T33 | U/O retain exact existing geometry; no tracing or invented logo replacement |

The [unknown ledger](migration/known_unknowns_and_validation.csv) records 27 unresolved acquisition/validation facts and the [private audit checklist](migration/authenticated_studio_audit_checklist.csv) records unperformed audit areas. Missing evidence is not proof that Sean lacks the corresponding accounts, files or rights.

[Mac setup](development-macos.md) and its bootstrap are prepared and safeguard-tested; installation on the actual Mac remains unverified because no Mac execution connection is available. Linux processing with `prlimit` remains a separate runtime requirement. Installing Mac development dependencies does not satisfy deployed scanner, production storage or launch evidence.
