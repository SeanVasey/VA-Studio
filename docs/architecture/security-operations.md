# Security, administration and operating contract

Status: **Required target controls and evidence**, 2026-09-04. This is not a claim that production controls are deployed.

## Role and authorization model

Recommended initial roles: owner, catalog_editor, publisher, rights_reviewer, finance_operator, support and migration_operator. One person may hold several roles, but high-impact actions still record the actual actor and authority. Role names are not a substitute for action policies.

| Action | Minimum proposed authority | Additional controls |
| --- | --- | --- |
| Draft metadata/upload media | Catalog editor | Product/file scope; quarantine; audit revisions. |
| Publish/unpublish catalog or site release | Publisher | Server readiness, expected revision and preview. |
| Approve/publish a license version | Rights reviewer / designated publisher | Recorded review authority; separation of authorship/approval where policy requires it. |
| Refund/dispute/entitlement restriction | Finance operator | Step-up authentication, reason, versioned policy and immutable history. |
| View customer support evidence | Support | Need-to-know fields; no unrestricted masters/export access. |
| Bulk import private history | Migration operator | Dry-run hash, validated provenance and scoped commit. |
| Change roles/secrets/providers | Owner/operator | MFA, step-up, audit and recovery procedure. |
| Download a purchase | Verified customer owner | Active entitlement, atomic cap and expiring exact-asset URL. |

Require admin MFA before production. Resolve recovery codes, recovery authority, session revocation, idle timeout and emergency access with tests. No default published passwords, account provisioning through a public route, trusted client-side role flags or reusable guest tokens. Customer password reset and email verification use framework safeguards and rate limits.

Apply same-origin cookie sessions, HTTPS, HttpOnly/Secure/SameSite controls, CSRF, input validation, output escaping and a restrictive CSP tested against the actual storefront/admin dependencies. Check direct object references on every order, asset, upload, invoice and grant route.

## File and media trust boundary

Private masters, stems, contracts, customer briefs and unredacted exports never enter public storage or GitHub. Public artwork and tagged preview derivatives are separate roles with explicit promotion.

Upload stages: requested → uploading → quarantined → verified → processing → ready, or rejected/failed. Sniff content; compare length/hash; enforce role-specific limits; scan before promotion; reject archive traversal, absolute paths, symlinks and decompression bombs. Bound ffprobe/FFmpeg CPU, memory, wall time and output count in an isolated worker without network access. Use argument arrays, never interpolate filename text into shell commands.

Record source/output hashes, tool/profile versions and technical results. A failed derivative prevents readiness; an editor checkbox cannot fabricate scan or processing proof. Master replacement is a new revision with an auditable relation.

## Privacy, consent and evidence

Separate account administration, purchase evidence, service communications and marketing consent. Consent includes purpose, granted/withdrawn/unknown status, exact policy version, source and timestamp. An imported email or purchase does not imply marketing opt-in. Free-download consent and its license must clearly distinguish optional marketing from delivery access.

Keep buyer legal identity and historic contracts private with role-controlled access. Define retention, deletion/export, legal/accounting preservation exceptions and processor arrangements before production. Logs carry IDs/correlation/state and safe reason codes; exclude secrets, signed URLs, payment request bodies, full email addresses and customer legal details unless a specific protected record needs them.

Audit writes include actor, action, target/version, time, correlation, authority/reason and minimized before/after summary or hashes. Pricing, rights, publication, refunds, grant/entitlement changes, imports, roles and site releases are audited. Restrict mutation of audit rows and export protected audit evidence through an authorized path.

## CMS and promotions

A site release is a versioned snapshot. Draft editing does not change the live release; preview uses access control and noindex. Publishing atomically changes the active pointer after link/readiness validation. Rollback restores an earlier content release without reversing purchases.

Promotion rules are server-owned and effective-dated: eligibility, allocation, currency, minimum, maximum redemptions, per-customer limits, stacking and product/license exclusions. Redemption reservations/counts require atomic enforcement, especially with concurrent carts. Repricing requires a new quote; a frontend coupon cannot change a payment amount independently.

## Membership and expanded product operations

Membership plan versions freeze purchased benefits. Credits are an append-only ledger; renewal events are deduplicated by invoice/period. Redemption reserves/consumes allowance atomically with the licensed purchase, with explicit expiry/rollover/cancellation and refund policies. A provider subscription status alone never authorizes arbitrary downloads.

Inspect existing memberships before choosing launch scope. Preserve remaining paid periods, accrued credits, grandfathered rights and cancellation pathways. Do not assume payment tokens or subscriptions can migrate between accounts/providers.

Services require brief/upload privacy, deposit/milestone state, scope/revisions and operator handoff. Merchandise requires variant/stock, shipping/tax, provider fulfillment, tracking and returns. These are complete workflows, not generic digital-download SKUs.

## Observability and recovery

| Signal | Evidence and required response |
| --- | --- |
| HTTP errors/latency and failed authorization | Correlation-based logs; alert threshold from baseline; owner can inspect without PII leakage. |
| Queue age, retries and terminal jobs | Dashboard and replay procedure; stop processing poison media rather than exhausting workers. |
| Paid-to-grant and paid-to-fulfilled delay | Reconciliation dashboard; immediate visibility of paid_exception and failed documents. |
| Provider versus internal money totals | Daily job/report with counts/currency and immutable payment references; discrepancies reviewed. |
| Storage integrity/egress and delivery failures | Hash checks, usage budgets, denied/failed issuance metrics and quota alarms. |
| Backups, database health, certificate/domain expiry | Named operator, encrypted backups and observed restore test. |
| Migration reconciliation | Source/target counts, hashes and financial/entitlement totals with unresolved exceptions. |

Set quantitative SLOs, RPO/RTO, alert destinations and budgets after hosting/workload decisions. No fixed cost or uptime claim is established. Cost model must include app/DB/Redis, media/document compute, stored GB, delivered GB, payment/tax/email fees, observability and human operations.

Use separate development/test/staging/production data, secrets and payment environments. Build a reproducible release from lockfiles; run migrations as controlled steps; support rolling code compatibility with jobs. Never run destructive schema rollback against paid history. Additive schema and feature flags normally permit code reversal; irreversible data effects require forward repair.

## Cutover and rollback

The cutover packet must name the exact commit/build, hosting configuration, migration run, accepted parity scope, open exceptions, test results, legal/provider decision references and responsible operator. Production readiness QA, not this blueprint, supplies the evidence for a release decision.

Before cutover: preserve source evidence, validate imports in staging, test redirects including www/apex behavior, rehearse backup restore, verify source and target customer obligations, and document a final source freeze/delta reconciliation. Avoid a period where both systems can sell the same exclusive.

Triggers to stop or revert traffic include incorrect charges, duplicate/excess grants, private asset exposure, missing active-member access, widespread checkout/delivery failure or unreconciled migration totals. Disable new checkout first, preserve inbox processing and reconcile payments already in flight. Do not blindly roll DNS back while both systems accept exclusives.

Before any new paid transaction, code/content rollback may be straightforward. After new payments or subscription changes, rollback is an operational reconciliation: preserve new orders/grants, route existing buyers to their valid delivery, reconcile provider state and resolve exclusive availability before re-enabling a seller path. The exact maximum window and operator steps must be rehearsed with synthetic transactions before production.

## Acceptance evidence

Require tests for unauthorized/admin/customer isolation, published-only reads, media quarantine, immutable licenses/assets, server quotes, provider replay/out-of-order events, MySQL exclusive/coupon/credit concurrency, reproducible contracts, private downloads, CMS rollback, migration totals and actual backup restore. Include keyboard/mobile/reduced-motion/browser checks on the changed flows. Record commands, candidate commit, environment and observed result; “tests planned” is not a passing gate.
