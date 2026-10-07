# VASEY.AUDIO architecture index

Status: **Provisional implementation blueprint**, created 2026-09-04.

Sean authorized beginning a complete first-party replacement of www.VASEY.AUDIO, using the current BeatStars theme and a repository named VASEYAUDIO, developed incrementally. That authorizes the reversible foundation being built now. It does not establish that payment accounts, final legal terms, production hosting, historic-data exports, or domain cutover have been configured or approved.

Read in this order:

1. [Decision register](decision-register.md): authority, current assumptions, and narrowly scoped blockers.
2. [Blueprint](blueprint.md): modules, trust boundaries, and target behavior.
3. [Data contracts](data-contracts.md): durable records and transaction invariants.
4. [Interfaces and event contracts](interfaces.md): proposed commands, HTTP boundaries, and workers.
5. [Security and operations](security-operations.md): authorization, audit, recovery, and launch evidence.
6. [Phased roadmap](roadmap.md): delivery order and the definition of complete replacement.
7. [Work-package index](../work-packages/README.md): GitHub-ready bodies and individual agent prompts.

Supporting directories elsewhere in this repository contain the source-research/parity ledger, migration evidence, brand evidence, and actual implementation verification. Their source facts outrank assumptions in this blueprint. Do not treat target contracts below as already implemented APIs or claim a checklist item is complete from this document alone.

## Authority and evidence

Order: Sean's current instructions; recorded owner decisions; repository instructions and decision register; this blueprint; structured contracts and source ledgers; current brand sources; official documentation; historical research. Conflicts stay visible.

- **Verified**: inspected source or reproducible result with provenance.
- **Decision**: actual explicit owner direction, with its scope.
- **Recommendation**: proposed implementation choice.
- **Inference**: reasoned interpretation not directly observed.
- **Unresolved**: missing evidence or a choice that remains open.

Inspected for this initial draft: current task, supplied replacement specification, architecture and licensing skills and their references, current scaffold listing, official framework/transaction/payment references, and the brand agent's active-theme inspection. No pre-existing repository index, decision register, blueprint, or master prompt was present when drafting began. This pack establishes new working documents; it does not invent historic approvals. The attached reports are research inputs, not proof of Sean's account settings, catalog, or contractual obligations.

## Completion standard

A complete replacement means every source capability and every active customer obligation is accounted for as implemented and verified, integrated and verified, or explicitly retired by Sean with a compliant continuity plan. A beautiful catalog and a working payment test are intermediate milestones. Unknown exports, subscriptions, licenses, grants, balances, or redirects block the relevant cutover scope.

Follow the current [ordered development record](../development-order.md) for accepted increments and the next dependency. PRs #39–#42 merged economic policies, public/owned quote disclosure and pricing evidence under [D-08](D-08-quote-pricing-evidence.md). Current promotion allocation/usage holds are defined in [D-09](D-09-promotion-usage.md); shared exclusive inventory follows their acceptance. Provider-independent development continues while external choices are resolved.

[D-10](D-10-shared-inventory.md) records recovery of merged PR #43 and the shared-inventory foundation, followed by exclusive offer/quote integration before WP-07.

[D-11](D-11-exclusive-offer-preparation.md) defines inactive scope-bound exclusive revisions as the first integration step after merged PR #44; activation and quote/pricing integration remain separate acceptance gates.

[D-12](D-12-exclusive-selection.md) connects test exclusive activation to versioned selection/disclosure/pricing and atomic inventory, preserving the WP-07/WP-08 handoff.

[D-13](D-13-order-preparation.md) records merged PR #52: explicit test order review/assent, encrypted immutable order evidence and atomic pending attempt binding.

[D-14](D-14-hosted-test-checkout.md) records merged PR #62: hosted test-session/reconciliation with durable encrypted requests and retained pending resources, accepted after final CI and two independent Codex reviews.

[D-15](D-15-test-payment-processing.md) records merged PR #63: durable receipt processing and authoritative test-payment verification, accepted after full CI and two independent Codex reviews.

[D-16](D-16-test-payment-finalization.md) records merged PR #64 local/testing finalization: immutable paid/exception effects, complete historical verification, unique grants with frozen render input, pending exact-asset entitlements/outbox and read-only customer status.

[D-17](D-17-test-contract-issuance.md) records private test-contract issuance accepted in merged PR #65: pinned offline profile, immutable originals, recoverable rendering work and contract status.

[D-18](D-18-test-fulfillment-activation.md) records the complete-order proof accepted in merged PR #67: fresh private checks of every original and exact purchased asset before one immutable activation, preserving pending entitlements/outbox and licensed permissions.

[D-19](D-19-test-owner-delivery.md) records the internal access foundation accepted in merged PR #69: explicit test policy, versioned controls, hash-only short-lived authorization, atomic technical limits and bounded private stream snapshots. Main `1a6ecebd`, including status PR #70, passed [CI 36280010722](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36280010722). The dated candidate language in those ADRs remains historical; the [ordered record](../development-order.md) retains their final acceptance evidence.

[D-20](D-20-test-owner-delivery-http.md) records owner HTTP/UI accepted in merged PR #71: database-only item/bounded history projection, session/CSRF/privacy checks, explicit authorization and native POST attachments from exact private descriptors. Downloads remain test-only; guest recovery, the complete purchase library and production fulfillment remain open.

[D-21](D-21-site-content-releases.md) records the first WP-09 CMS slice accepted in merged PR #72: immutable private home/studio/navigation/footer/SEO drafts, protected preview and atomic publication/rollback, including the original site baseline. [The ordered record](../development-order.md#accepted-downloads-and-cms--september-28-2026) retains both accepted sources, full CI and independent review; post-merge main `ede2065` passed CI.

[D-22](D-22-promotion-administration.md) records test promotion administration accepted in merged PR #73: immutable disabled creation, separately versioned availability, aggregate usage and campaign-locked enforcement without changing retained order evidence. The [ordered record](../development-order.md#accepted-test-promotion-administration--september-29) retains its final tested source, all ten CI gates, independent review and post-merge verification.

[D-23](D-23-editorial-content.md) extends the same immutable release/publication contract to bounded about/contact/blog/video content with active-only routes and private pinned previews. Stored v1 snapshots and hashes remain unchanged; new copies use v2. Contact is an encoded mail link and videos are typed provider watch links. It was accepted in merged PR #74; editable assets, inbound contact delivery and consent-aware embeds remain open.

[D-24](D-24-scheduled-site-publication.md) schedules one saved release for a whole UTC minute. A minute runner publishes it through the same lock, revision and audit path, and staff publication supersedes a pending schedule. A 60-minute grace window and retained fail-closed outcomes cover missed, unauthorized, stale or corrupt schedules. The integrating PR records final acceptance; editable asset references are next.

[D-25](D-25-editable-site-images.md) makes the home hero, studio and share images editable. This first part adds a private library: uploads with provenance, scanning, metadata-free re-encoding into fixed JPEG and WebP sizes, a manifest hash per image and retained failures. The second part adds image slots to site releases (schema version 3), serves an image publicly at a content-hashed URL once a release using it has been live, and renders the images on the storefront.

[T12-PUBLICATION-EVIDENCE-01](T12-PUBLICATION-EVIDENCE-01.md) describes the internal read-only track publication manifest foundation: fresh staff authority, current ready evidence and a minimized immutable content identity. Its [source-bound verification record](../verification/track-publication-manifest-foundation.md) retains focused feedback and pending acceptance. This partial scheduling prerequisite supplies no retained approval, apply fence or track timing policy; D-24's site-release choices do not transfer to tracks.

[D-26](D-26-production-track-preparation.md) records the current production
preparation consumer: encrypted immutable selections and future-order commitments,
explicitly unknown tax/total, and composed source/consumer proof. This increment
does not grant commerce or delivery authority.
