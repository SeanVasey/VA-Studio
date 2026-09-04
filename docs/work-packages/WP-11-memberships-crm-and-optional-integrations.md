# [WP-11] Membership continuity, customer CRM and integration boundaries

Status: **Planned work package**. Inspect the current implementation before starting; initial foundation code may already cover part of this scope. This issue is complete only when the acceptance evidence below exists.

- Suggested issue title: `[WP-11] Membership continuity, customer CRM and integration boundaries`
- Phase: 3; active memberships become a pre-cutover dependency
- Dependencies: WP-06, WP-07, WP-08 and WP-12 source audit. U-10/U-11/U-13 govern live behavior.
- Suggested branch: `work/wp-11-memberships-crm-and-optional-integrations`
- Implementation paths: app/Domain/Memberships, app/Domain/CRM, billing/integration adapters, customer/admin pages and ledger tests.

## Problem

Members and returning customers need durable paid benefits and consent-respecting account/support flows through the replacement.

## First reviewable increment

First PR: versioned plan and credit ledger with one test-mode renewal/redemption path. Separate dependent PRs handle customer preferences/wishlist, support and each verified optional integration.

## Scope

- Versioned membership plans, billing invoices, renewal/cancellation/dunning, benefit eligibility and append-only credit ledger.
- Atomic allowance reserve/consume/reverse/expire with explicit rollover, grandfathering and late-event behavior.
- Customer wishlist/history/preferences/consent and support context; transactional notifications separate from marketing.
- Offers/license upgrades, affiliates, collaborator payouts, email/analytics, e-sign and distribution/publishing handoffs remain separate adapters/issues when verified needed; crypto requires its own recorded decision.

## Acceptance criteria

- [ ] One invoice cannot award duplicate credits; two redemptions cannot overspend a balance.
- [ ] Cancellation/failed renewal and grandfathered benefits follow recorded policy and remain visible to the customer/operator.
- [ ] Active legacy paid periods, balances and benefits are reconciled; no assumption that provider tokens/subscriptions are portable.
- [ ] Unknown imported consent remains unknown, opt-out is honored, and a wishlist/transactional account works without marketing consent.
- [ ] Every verified optional integration has an explicit implemented/integrated/retired-with-authority disposition and child issue; no silent omission.

## Verification

MySQL credit concurrency, duplicate/out-of-order invoice fixtures, renewal/cancellation/dunning paths, benefit authorization, consent withdrawal and source-to-target obligation reconciliation. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Stop new subscription acquisition/redemption if unsafe, preserve ledger and paid benefits, and reconcile provider state. Do not cancel or double-bill existing subscriptions as a rollback shortcut.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-11 beginning with the ledger and verified membership obligations. Read migration source evidence before choosing cutover scope. Keep billing, credits, grants and consent independent, and split optional integrations into bounded issues rather than silently expanding the payment core.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.
