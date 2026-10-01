# Single-seller experience implementation candidate

This October 1, 2026 candidate continues the [remaining development plan](../remaining-development-plan.md) after the first CI/media increment in PR #88. It preserves the single-seller scope: Sean administers his content; listeners and buyers use the public store. It is under integrated verification, not a production launch or completion of the parent work packages.

## Implemented children

| Parent | Implemented behavior | Evidence and remaining boundary |
| --- | --- | --- |
| T04 | Additive UUID byte guards preserve previous format/nullability rules and retained records. | [UUID evidence](uuid-integrity-followup.md). Real MySQL upgrade/guard acceptance remains required. |
| T12 | Privileged actions and MFA enrollment checks consult persisted authority; revoked or stale models cannot retain access. | [Operator evidence](operator-authority.md). Granular helper roles, production recovery and physical authenticator acceptance remain open. |
| T15 | Bounded private contact submissions retain encrypted content and approved notice identity, deduplicate requests across session loss, and reach an audited operator inbox with read/archive transitions. | [Backend](contact-inquiry-backend.md), [UI](contact-inquiry-ui.md), [operator contract](../contact-inquiries.md). Intake defaults off until notice/retention/operator setup. Real notifications and wider order support are separate. |
| T16 | Explicitly loading an approved video creates its restricted provider frame; removing it withdraws that page's consent. Private previews have no external playback action. | [Video evidence](editorial-video-consent.md). Related-track associations, real provider/device playback and owner-approved production content remain open. |
| T17 | A script-free, session-free public player serves the exact current eligible tagged preview and links to the store. | [Embed contract](public-track-embed.md). Buyer messaging, private support attachments and order-aware support remain open. |
| T24 | Current browser-session test-order history is paginated and ownership checked on the server; explicit selection opens the existing status/delivery flow. | [History contract](../test-order-history.md). Account claims/recovery, cross-session library and large production delivery remain open. |
| T25 | A synthetic isolated restore recovers original database evidence and exact private contract/media bytes, including damaged/missing-source scenarios. | [Restore evidence](synthetic-private-restore.md). This is SQLite rehearsal, not production MySQL backup, key rotation or operational recovery acceptance. |
| T33 | One persistent player supplies a reorderable queue, explicit next/previous, optional auto-next/repeat, section looping, playback speed and supported OS media controls. | [Player contract](../player-queue-and-section-loop.md). Separate pitch shifting, installable experience and physical-device/background acceptance remain open. |

These children were developed in isolated snapshots and reviewed separately. Local snapshot commits identify source evidence, not remote ancestry. The integrating PR must record its actual remote parent/head/tree and executed CI outcomes before acceptance.

## Integrated evidence so far

- Frontend: 326 tests across 20 files, TypeScript checking, production build and client-bundle scan passed on the combined player/history/video/contact frontend.
- PHP integration: 141 selected operator, inquiry, UUID, order-history, restore and editorial tests passed with 4,267 assertions. After merging the embed route/error handling and three actual editorial-projection cases, the 107 inquiry/embed cases passed with 3,741 assertions. These selections overlap; they are not 248 distinct tests or a full backend run.
- Independent inquiry review reproduced all 89 original backend cases / 2,713 assertions and the three projection cases / 69 assertions. A later MySQL PAD SPACE review tightened new inquiry-state comparisons before cloud acceptance; its targeted evidence is in the backend record.
- Focused CI now includes bounded `operator` and `seller` selections and the new feature regressions. All 20 selector safeguards passed and an independent reviewer checked every path, enum and count. [Focused feedback](focused-ci.md) remains informative; Foundation CI is the merge gate.
- Browser specs use the existing isolated wrapper. Frontend transport fixtures do not prove persistence. Native execution, screenshot inspection and the real inquiry/operator journey remain required against the final candidate. Local case listing is not a browser pass.

PHP evidence uses PHP 8.4.26 and SQLite with exact-lock dependencies. No local MySQL server or usable native browser binaries were available. Full MySQL/SQLite, Chromium/WebKit, audits, build and independent source acceptance remain required. Any source correction needs the appropriate fresh checks; earlier green evidence does not certify later source.

## Prerequisite and launch gates

PR #88's initial cloud run found three hash-migration test failures on MySQL and a mobile row-menu helper synchronization defect. Neither can be waived because local SQLite passed. A bounded disposable MySQL diagnostic investigates parameters, column coercion, actual trigger input and retained bytes; no production evidence is rewritten. The menu helper waits for a consistent public DOM state before deciding whether to open it.

[Source reconciliation](single-seller-implementation-audit.md) preserves the 103-row baseline and all unaudited private areas. The authenticated source inventory, actual customer obligations, approved commercial/privacy policies, storage/hosting, provider test-account interoperability and physical-device checks remain real dependencies. Existing commerce stays test-only. No live charges, active entitlement imports, DNS changes or source retirement are included.

Continue dependency-ready engineering and focused reviews while these gates are resolved. Count a parent deliverable complete only when its full task-register acceptance exists, not when one child is implemented.
