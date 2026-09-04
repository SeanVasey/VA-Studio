# VASEY.AUDIO migration and cutover runbook

Status: **planned; no live cutover authorized or performed**. Prepared 2026-09-04. The current instruction authorizes repository creation and phased development. These gates concern a later concrete production migration and do not prevent reversible implementation work.

## 1. Roles, scope and source authority

The launch operator, incident decision maker, finance/rights reviewers and support owner must be named in the launch record. Sean is the product owner; do not fabricate other role assignments. Pin the exact repository commit, schema version, source batch IDs, transformation version and parity-matrix revision proposed for launch.

Use `source_ledger.csv`, `migration_field_map.csv`, `authenticated_studio_audit_checklist.csv`, `known_unknowns_and_validation.csv` and the repository decision register as the working control records. Keep their IDs stable. Every changed source class must have a mapped destination or an explicit preservation/disposition decision.

Prefer seller-owned originals, official exports, authorized read-only screens, owner-controlled payment/fulfillment records and public routes, in that order. No undocumented private endpoints, token extraction, guessed account settings or destructive source operations. Retain confidential source data only in approved private storage, encrypted and access controlled, with hashes and opaque pointers in the project evidence.

## 2. Acquire a bounded immutable snapshot

1. Inventory products by type and status; active license versions and assignments; originals and delivery roles; customers; orders/refunds; executed contracts; grants/download evidence; consent; promotions/offers; memberships; services; physical orders; public routes; and external rights obligations.
2. Record the source system, source record ID, acquired-at timestamp, source as-of watermark, acquisition method, operator, content hash and retention classification for every acquired class. A file download time is not automatically its source-system as-of timestamp.
3. Hash raw exports and original media before parsing. Preserve their bytes unchanged. Checkpoint large acquisitions so retry does not silently create a second competing snapshot.
4. Acquire the complete DNS zone, TTLs, certificate state, apex/www behavior and third-party mail/integration records through an authorized read-only provider export. Do not change the zone during inventory.
5. Record actual provider ownership for memberships, refunds, payouts and fulfillment. Inventory any module proposed for deferral enough to prove it does not strand active obligations.

Stop that batch on unexplained source changes, missing active contract text, ambiguous file role, unclear rights, unreadable media, unsupported money/currency data or incomplete source intervals. Keep unresolved records isolated while unrelated verified records continue through staging.

## 3. Normalize and validate in staging

Each record follows:

`acquired → hashed → quarantined → parsed → normalized → validated → dry_run_ready → approved → imported → reconciled`

Side states: `exception`, `rejected`, `superseded`, `rolled_back`. Preserve state transitions and reasons.

Validation includes schema/referential checks, source ID uniqueness, explicit media roles, codec/duration, archive traversal/bomb safety, SHA-256, visibility, rights readiness, approved license text/variables, integer minor units and ISO currency, contributor splits, consent provenance, and legacy-route authority. Retain raw values alongside normalized values.

Historic commerce must be classified:

| Classification | Permitted behavior |
| --- | --- |
| `archive_only` | Preserve the source transaction/contract privately for history; do not mint a new usable download entitlement |
| `verified_grant` | Evidence supports the historic grant; preserve its original conditions and immutable references |
| `active_entitlement_candidate` | Paid evidence, exact buyer linkage, applicable original contract, exact asset revision and approved policy reconcile; activate only through the reviewed import |

Missing original contracts are exceptions, not a prompt to regenerate history. Unknown consent remains unknown. An expired download URL is not proof a license has expired. Customer identity collisions require manual resolution rather than automatic merging.

## 4. Produce the concrete dry run

A batch manifest records batch ID, input manifest hash, transform version, target environment, `dry-run`/`commit` mode, approval reference, expected writes, checkpoint and rollback method. A dry run makes no production mutations.

Report per-class creates, updates, skips, conflicts and errors; per-field differences and lossy transforms; visibility, price, currency, rights, license, grant, consent and identity conflicts; unresolved references; destructive effects; estimated storage/job cost; and each exception's owner and proposed disposition.

Demonstrate importer rerun/resume with fixtures covering duplicate IDs, corrupt media, unsafe archives, ambiguous buyers, missing file roles, disabled offers, draft visibility, unknown consent, exact contract hashes and redirects. Preserve actual commands, commit, fixture identities, environment, timestamps and results. Do not claim tests passed merely because scripts exist.

## 5. Reconcile the staged result

For every row and aggregate cohort, record expected, actual, delta, explanation, evidence link, owner and disposition. Aggregate equality cannot hide wrong line-item allocations.

| Cohort | Required reconciliation |
| --- | --- |
| Catalog | Counts by type/status; source/target ID; title; timestamp; public/draft/sold state |
| Assets | Role; revision; byte size; SHA-256; codec/duration; archive member manifest |
| Rights/licenses | Source and version hashes; active assignments; contributor approvals; exact file grants |
| Money | Payment/order/refund totals in minor units by currency; allocated discounts/tax; no unexplained differences |
| Buyer/history | Identity and ambiguous-match report; original contract linkage; grant classification; reliable delivery history |
| Consent | Purpose/status/source/time/policy and suppressions; unknown remains unknown |
| Memberships | Subscription provider; paid period; credit grants/debits/expiry; no duplicate billing |
| Services/merch | Open scope; deposits/balance; milestones; pending fulfillment/returns and provider references |
| URLs | Controlled host; target; status; one-hop redirect; canonical and sitemap coverage |

No production import can be called reconciled with unexplained differences. For production commit, use the approved exact manifest and exception policy, bounded batches, checkpoints, audit events and restartable idempotency. Never silently treat partial completion as success.

## 6. Prove the complete purchase and recovery path

Before a live-domain switch, record evidence for:

- Browse/filter/share on desktop and phone; persistent preview playback; correct accessible license comparison.
- Admin upload/edit/publish; preview/master separation; exact asset versions and source hashes.
- Auth/role protection; buyer isolation; signed links; public cache behavior; no private secrets or originals in client output.
- Quote and checkout; actual provider-confirmed captured/paid state; duplicate, late, async and out-of-order webhook handling; no fulfillment from client redirects.
- Buyer-specific original contract; accepted license version; durable grant; exact asset entitlement; signed download; retryable receipt delivery.
- Concurrent exclusive purchase attempts and overlapping lease/exclusive boundary; expiration and late-payment recovery; already-issued grants preserved.
- Coupon/bulk rounding and applicable full-replacement features; membership last-credit race and renewal/cancel; service/merch continuity when active.
- Policy-approved refund/dispute behavior, including partial refunds and entitlements, without rewriting executed agreements.
- Database/object backup restore, provider event replay, dead-letter recovery, alerting and a rehearsed rollback.

Real-money canary purchases and refunds require the applicable owner authorization for that concrete transaction. Prepare the tested sandbox result and exact proposed amount/merchant first. Do not initiate live transactions to prove a demo works.

## 7. Establish one checkout and exclusivity authority

Before any canary or dual-platform interval, choose one authoritative checkout/exclusive-inventory channel or a demonstrably reliable serialization mechanism. Cached availability is not synchronization. If another seller/collaborator copy can still sell the same exclusive elsewhere, inventory is not controlled and exclusive launch remains blocked.

Record the migration source watermark and every later change. Immediately before cutover, use an authorized freeze or capture/reconcile a complete delta covering orders, refunds, customers, consent, licenses, visibility, offer changes and exclusive state. Reconcile until there is no unaccounted interval. Do not simply re-run the initial catalog export while orders continue changing elsewhere.

## 8. Concrete launch approval record

The final launch packet contains the exact commit/deployment, approved parity disposition, source manifests and reconciliations, legal/merchant/tax decisions, successful tests and canary evidence, monitoring dashboards, backup/restore proof, domain plan, named incident owner, rollback thresholds and customer-support path. The live launch authorization applies to this packet, not to an earlier generic intention to replace BeatStars.

Required gates:

| Gate | Pass condition |
| --- | --- |
| Scope | All full-replacement requirements accepted or explicitly dispositioned without breaking active commitments |
| Source | No critical unmapped class or unexplained reconciliation difference |
| Commerce | Actual merchant/provider configured; paid confirmation, contract, grant, delivery and recovery proven |
| Rights | Active and historic agreements preserved; applicable legal decisions recorded |
| Reliability | Restore, retries, monitoring and rollback rehearsed |
| Domain | Verified zone/control; certificates; apex/www; redirects; mail/integrations preserved |
| Authority | One checkout/exclusive source of truth and reconciled final delta |
| Approval | Named owner approval for the precise production operation |

Keep BeatStars available while these gates are prepared. Avoid cancelling the source subscription or removing source originals as part of a first switch.

## 9. Domain switch and observation

1. At the approved window, verify current source/target status and acquire the final delta; pause if any gate changed.
2. Apply only the approved domain records and routing settings. Use the rehearsed TTL/certificate/apex/www plan. Preserve MX, SPF, DKIM, DMARC and unrelated records.
3. Purge only relevant cache entries. Verify canonical host, SSL, legacy redirects, robots/sitemap and provider callback endpoints.
4. Run the approved synthetic/canary checks from independent clients. Observe playback failures, checkout errors, payment-to-grant lag, fulfillment lag, signed-download failures and support incidents against thresholds defined before launch.
5. Record new target order/payment IDs and source events throughout the window. Reconcile post-switch transactions before declaring success.

## 10. Rollback and forward recovery

Rollback triggers include unexplained duplicate exclusive grants, exposed private assets, failed paid-order fulfillment above the approved threshold, critical money/hash drift, unavailable contract evidence, broken critical routing or an unrecoverable processing backlog. Numerical thresholds and observation duration must be selected and rehearsed in the launch packet; none are invented here.

The named incident owner decides rollback. Preserve logs and affected IDs. Disable new target checkout before switching authority, while continuing to honor and process already-accepted payments, receipts and legitimate downloads. Do not delete target orders or reset a database over paid transactions.

Export post-cutover writes and provider events; classify pending, paid, refunded and fulfilled records; preserve locks on sold exclusive inventory. Before reopening any source checkout, prove it cannot resell those exclusives. If the source cannot represent target sales safely, hold affected products unavailable and choose forward recovery instead of blind DNS reversal.

Restore the rehearsed source routing only when its checkout/availability state is safe. Reverse approved DNS records, preserve mail records, purge relevant caches and verify SSL/apex/www. Continue a secure target receipt/download path for already-paid buyers even if the main storefront reverts. Draft customer communications for owner review; this runbook does not authorize sending messages.

Document the incident, data deltas, financial/rights disposition, corrective action and criteria for another launch. A restored DNS record alone is not a completed rollback.
