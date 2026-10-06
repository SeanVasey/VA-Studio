# [WP-13] Security, reliability and exact-candidate release validation

Status: **Planned work package**. Inspect the current implementation before starting; initial foundation code may already cover part of this scope. This issue is complete only when the acceptance evidence below exists.

- Suggested issue title: `[WP-13] Security, reliability and exact-candidate release validation`
- Phase: 4; security controls start earlier
- Dependencies: WP-01 through WP-12 for their affected release scope. U-02/03/04/05/07/13 must be resolved for production.
- Suggested branch: `work/wp-13-security-reliability-and-release-validation`
- Implementation paths: tests, deployment/worker configuration, policies, observability, docs/operations and release evidence.

## Problem

The store handles money, rights and private masters; release confidence must come from reproducible evidence and recovery drills.

## First reviewable increment

Threat/control review plus actual admin MFA and customer isolation tests, followed by staging commerce/restore and operational drills.

## Scope

- RBAC, MFA/recovery/step-up, sessions/CSRF, request limits, media isolation, protected logs and retention settings.
- Observability for payment exceptions, reconciliation, queue delay, contract/delivery failures and storage/backup health.
- Exact-candidate integration/concurrency/browser/accessibility/performance checks on production-like MySQL/workers/storage.
- Encrypted backup restore, failed-job replay, provider reconciliation and rollback rehearsal with named operator/runbook.
- Record real hosting/provider costs and budgets from chosen services and observed workload; do not invent estimates.

## Acceptance criteria

- [ ] No unresolved critical money/rights/privacy defect in the scoped candidate; all remaining findings have explicit disposition.
- [ ] Admin MFA/recovery and customer ownership protections are exercised, not merely configurable.
- [ ] Replay/race tests prove one valid exclusive sale, one grant effect and bounded entitlement/credit issuance.
- [ ] A backup is restored into an isolated environment and verified against hashes/counts; RPO/RTO targets and observed outcomes are recorded.
- [ ] Release evidence identifies exact commit/build, environment, commands/results, source reconciliation and unresolved blockers.

## Verification

The October 6 [private alpha](../private-alpha.md) increment adds an isolated loopback installation for real admin practice: fresh temporary environment/cache/storage/database, random per-run credentials, synthetic private drafts, disabled external effects and bounded process cleanup. Required CI executes its native PHP/SQLite bootstrap and HTTP cases with `PRIVATE_ALPHA_REQUIRE_PHP=1`; missing prerequisites fail that check. This is a bounded setup/control child, not production MFA/recovery, deployment, backup-restore or provider acceptance. Actual counts, independent exact-source review and final integration disposition belong to the integrating PR.

Run only meaningful required gates and tests resolving concrete risk. Include independent review of commerce/security changes and inspect actual staging flows. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Apply the rehearsed code/config reversal; preserve paid evidence and in-flight provider processing. If data semantics changed, use the documented forward-repair path.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-13 against a fixed candidate and the production-readiness skill. Verify actual controls and recovery, report test environment/commit/results, and distinguish unresolved launch gates from passing checks. Do not certify launch from a plan or local SQLite-only success.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.
