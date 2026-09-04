# [WP-14] Rehearsed domain cutover and post-launch reconciliation

Status: **Planned work package**. Inspect the current implementation before starting; initial foundation code may already cover part of this scope. This issue is complete only when the acceptance evidence below exists.

- Suggested issue title: `[WP-14] Rehearsed domain cutover and post-launch reconciliation`
- Phase: 4
- Dependencies: WP-12 and WP-13 accepted evidence; all applicable production decisions resolved.
- Suggested branch: `work/wp-14-cutover-and-postlaunch-reconciliation`
- Implementation paths: docs/operations cutover/rollback, deployment configuration, redirect rules and reconciliation tooling.

## Problem

The live domain must move without duplicate exclusive sales, lost customer rights, interrupted paid benefits or unrecoverable deployment mistakes.

## First reviewable increment

Produce and rehearse the exact cutover packet before requesting any missing final external/domain approval.

## Scope

- Named candidate/build, source freeze/delta import, parity scope, unresolved exceptions, DNS/certificate/redirect plan and rollback owner.
- Controlled traffic/checkouts transition with one authoritative sales path and preserved webhook processing for in-flight payments.
- Real-time checkout/delivery/member checks after cutover and provider/order/entitlement reconciliation.
- Customer/support continuity and retained access to historic evidence; source retirement only after obligation verification.

## Acceptance criteria

- [ ] Cutover packet is concrete and reviewable, with actual rehearsal results and authorized operator responsibilities.
- [ ] No point permits both systems to grant competing exclusives without a proven cross-system prevention mechanism.
- [ ] New and existing buyers can reach valid order/contract/download access; active member obligations remain fulfilled.
- [ ] Errors trigger checkout disable/reconciliation according to the runbook; post-payment rollback preserves new paid records and reconciles inventory.
- [ ] DNS and deployment writes are verified only when executed with applicable authorization; no completion claim from prepared instructions.

## Verification

Synthetic rehearsal of source freeze/import, traffic switch, webhook in flight, failed checkout/delivery and rollback with post-switch orders. Execute post-cutover smoke/reconciliation only if cutover actually occurs. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Disable new checkouts first; retain event receipt and fulfill/reconcile confirmed payments. Traffic reversal requires explicit treatment of new orders/grants/member state and source exclusive availability.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Execute WP-14 only after a fixed candidate and actual release evidence exist. Prepare and rehearse the complete packet first, preserve one sales authority and all paid obligations, then perform only authorized external changes. Report observed URLs/builds and reconciliation results; never imply DNS switched when it did not.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.
