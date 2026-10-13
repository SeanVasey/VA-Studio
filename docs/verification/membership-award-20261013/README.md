# C2 retained invoice and first-period award

Development candidate on `harness/membership-award-20261013`, based on PR #111 merge `7f0bfe9a531d082f1716358518333bb5ba68ae08`. This packet is in progress: no PR, final approval, preflight or merge is claimed yet.

## Implemented scope

- Current MFA staff approve an immutable subscription binding to a retained policy and the original T23 buyer. Price and currency come from that policy. Identical retries preserve the original approval author; legacy v1 bindings remain readable but cannot authorize awards.
- Billing retains the matched InvoicePayment's validated `status_transitions.paid_at`. Retrieval time is not payment time. New v2 observations and hints authenticate their retained columns inside the encrypted payload, including database positions and observation freshness deadlines.
- A captured-primary invoice producer audits approved buyer/plan/provider facts, the bounded observation chain, the latest settled evidence and all invoice hints. A singleton row serializes every hint insert with an award, including hints arriving before an invoice identity exists.
- Migration 262 retains the historical six-table billing graph and adds the immutable singleton and hint guard. It admits only exact owned interruption states.
- `MembershipAwards::award()` creates one paid period and its first credit event in an owned transaction. Replays return the original result; changed semantic invoice facts refuse. The first event has sequence one, zero prior seal and zero starting balances. Expiry derives from the service-period end; explicit late-payment terms evaluate provider-paid time.
- Ordinary Laravel commit callbacks remain enabled. A separate committed read-only frame audits retained effects and original/current owner evidence before success is returned. Refusal after commit does not mean rollback or authorize an automatic retry.

## Boundary

The operation is unmounted and default-off. The complete four-capability gate remains required. Tests install explicitly unusable license/grant doubles solely to exercise the first award; application boot does not register them. Reservation, license eligibility, consumption, member-original activation and HTTP entry points remain subsequent work.

MySQL current required-guard verification is distinct from complete current trigger inventory. The native probe shows that both the ordinary dictionary and `SHOW TRIGGERS` can retain an older REPEATABLE READ snapshot. Named `SHOW CREATE TRIGGER` reads current definitions. The fixed fence pins its subjects and verifies every required current guard; exact current rejection of unexpected additional triggers remains a P2 activation condition in `codex-deferred-20261011.md`. No complete current namespace claim is made.

All provider objects, identities, terms, prices and credentials in these tests are synthetic. Sean's commercial inputs remain pending. There is no live provider I/O, host verification, customer entitlement import, deployment, Foundation dispatch or GitLab dispatch in this batch. The full integrated candidate still requires final exact-SHA Foundation verification under AGENTS.md.

## Verification in progress

Private database: MySQL **8.4.11**, container `va-studio-private-mysql-policy-20261013`, loopback port `33413`, socket `/workspace/runtime/mysql-policy-run/mysql.sock`, private datadir `/workspace/runtime/mysql-policy-data`, test schema `vaseyaudio_membership_award`. Runtime: PHP 8.4.26 / PHPUnit 12.5.34.

The independent review of `307699b6` requests changes: F1 requires current native fence/trigger metadata admission; F2 requires per-proof observation identity. Application `9302e1c0` corrects both. The native F1 engine regression fails on unchanged `307699b6` application source as intended (test commit `04b2699a`: one test, four assertions, one failure, zero errors/skips, exit 1). Both corrected native metadata cases pass (two tests, nine assertions); the same selection's positive award case errors at the original operation deadline, so the complete selection is **not green** (three tests, fourteen assertions, one error, exit 2). Investigation is ongoing.

SQLite at `9302e1c0`: the integrated approval/fence/invoice/award selection passes 35 tests / 231 assertions, zero failures/errors/skips. The whole Billing directory at `a1b91e13` (same application source) passes 255 tests / 1,354 assertions with 12 declared skips, zero failures/errors, exit 0. These results predate the additional entry-point correction below.

Further independent review confirmed a blocking P1 in direct `MembershipAwardContext` construction: a standalone reader could bypass the complete capability gate and owned commit path. The new direct/reentrant regressions fail on the unchanged application (two tests, ten assertions, two intended failures, exit 1). Related resolver withdrawal and transient add/remove regressions also fail before their corrections (1/8 and 1/9, one intended failure each). Commit `9ec09073` adds a private single-use operation permit and captures a complete capability registration baseline before resolution. Direct registrations and nonshared factories remain supported; this unmounted owned operation refuses capability aliases. Its six ownership cases plus the nine existing award cases pass on SQLite (15/141, zero skips/errors/failures). The separate reviewer approves this exact four-file delta, with final native verification still pending.

An isolated diagnostic at unchanged `a1b91e13` reproduces the native operation deadline: 15.430 seconds, no durable award effects. Its award-window counters record 11,180 native statement preparations (6.439 server seconds) and 11,590 executions (7.504 server seconds). The diagnostic retains its real PHPUnit error/exit 2. Metadata query batching is in progress; the deadline and ownership checks remain required. No final approval, successful native award or merge is claimed.

Initial integrated SQLite evidence at `307699b6`: 16 tests, 120 assertions, one failure, zero errors/skips, exit 1. The failed after-commit fixture omitted the account guard's required access-version increment. Its test-only correction at `04b2699a` passes one test / ten assertions, exit 0; no application behavior was relaxed. The MySQL migration canary at `307699b6` passes one test / four assertions, exit 0. Original logs will be retained alongside final results.
