# C2 prerequisite: durable first billing retrieval attempts

This batch closes the durable-record part of A1-4 carried from membership lane 2, and adds the L2-4 matching-binding invoice-ID regression. It does not implement the operative 257 award/reservation writer. Memberships remain default-off; no route, provider registration, live provider call or customer entitlement was added.

## Behavior

A first retrieval that times out, is incomplete, or contradicts the approved binding now commits an immutable diagnostic attempt before raising its original bounded reason. The encrypted invoice reference, binding, provider scope, retrieval interval and optional retained hint are recorded without claiming an invoice identity. A later retrieval under the correct binding can still succeed. Attempts never qualify as paid observations or cover webhook hints.

The sixth Billing259 table is added by forward migration `2026_10_12_260000_membership_billing_retrieval_attempts.php`. The five existing table definitions and guards are unchanged. An existing complete five-table database gains the empty attempt table; a fresh database gets it through the shared schema builder and the forward migration is idempotent. Operational rollback refuses to discard retained evidence.

The writer rechecks policy, the sealed subscription binding and schema ownership in its own short transaction. A supplied hint must already exist, have a valid seal, match the invoice/account/mode, and precede the retrieval start. Database guards independently enforce scope, interval kind and reuse constraints, outcome vocabulary and immutability. The seal is an unkeyed canonical SHA-256, not an HMAC. If persistence fails, its exception propagates; no durable-record claim is made for that failure. Policy withdrawal during provider I/O retains no attempt.

Webhook redelivery and the hint sweep carry the internal hint ID to queued retrievals. Legacy serialized jobs lacking that property remain supported. Retry count/backoff, encrypted job invoice references, and already-owned invoice observation behavior are retained.

## Source and evidence

- Regression-test commit `e86f39ea6da61b7de78f150f1cda24704e6565a6` ran against unchanged pre-feature application source from `c28fb81b0c5fcf6fb0823c51f220ea1e3fac78f6`.
- Application and initial complete test source: `46e91e50b02caaa7ce88c3e3e3f521dfa9eccc5b`.
- Test-only strengthening: `4b0dd80995992701564b0794e1851edb1adfc86e` adds three existing-but-invalid hint cases.
- Test-only corrections: `1a14437ecdbded5646e1234a7138d056d036f81c` imports `BillingValues` in the new invoice-ID regression and restores the schema fixture's genuinely contiguous empty tail. Application source is unchanged across these test commits.
- Native metadata correction: `dfbd0ba0bb331d565d6d69021e955950653a5d11` adds the eight new declared reason literals to the existing exact dictionary-comparison allowlist. MySQL 8.4 stores their CHECK literals with `_utf8mb4` introducers/escaping. No table definition, guard or outcome vocabulary changed in this correction.

PHP 8.4.26, PHPUnit 12.5.34; SQLite uses `:memory:`. Native checks use the same private Oracle MySQL **8.4.11** instance retained from the importer work: container `va-studio-private-mysql-20261012`, private datadir `/workspace/runtime/mysql-data`, loopback port 33396, private socket `/workspace/runtime/mysql-run/mysql.sock`. The initial selection uses `vaseyaudio_catalog_import`; the corrected/new selection uses separate disposable schema `vaseyaudio_membership_corrections`. Final disjoint selections additionally use `vaseyaudio_membership_timeout`, allowing independent execution without sharing test tables. No shared or deployed database is involved. Tests use synthetic fixtures only.

| Selection | Source | Recorded result |
| --- | --- | --- |
| Seven first-retrieval failure stages, old application | `e86f39ea` tests / `c28fb81b` application | 7 tests, 14 assertions, 7 intended failures; zero errors/skips, exit 1 |
| Initial same seven cases after implementation | initial feature source, before complete test batch | 7 tests, 98 assertions, zero failures/errors/skips, exit 0 |
| Whole `ProductionMembershipBilling` directory | `46e91e50` | 195 tests, 1048 assertions, **2 test errors**, zero failures, 5 declared native-only skips, exit 2 |
| Three added hint cases plus both corrected cases | `1a14437e` | 5 tests, 25 assertions, zero failures/errors/skips, exit 0 |
| Initial private MySQL selection | `46e91e50` | **16 setup errors, 0 assertions**, zero failures/skips; exit 2, 19:30 elapsed |
| Initial native added/corrected selection | `1a14437e` | Interrupted with SIGTERM, exit 143 after the shared setup defect was identified; no complete summary or passing claim |
| Native schema canary | `dfbd0ba0` | 1 test, 8 assertions, zero failures/errors/skips, exit 0 |
| Native integration selection | `dfbd0ba0` | 5 tests, 61 assertions, zero failures/errors/skips, exit 0 |
| Native timeout selection | `dfbd0ba0` | 7 tests, 98 assertions, zero failures/errors/skips, exit 0 |
| Native other-attempt selection | `dfbd0ba0` | 7 tests, 50 assertions, zero failures/errors/skips, exit 0 |
| SQLite whole attempt and schema files after metadata correction | `dfbd0ba0` | 27 tests, 230 assertions, zero failures/errors/skips, exit 0 |

The final native canary and three disjoint selections total **20 distinct tests / 217 assertions / zero failures, errors or skips**, all on `dfbd0ba0`. Every new attempt case, the forward migration, schema recovery, immutable-row checks, matching-binding invoice-ID regression, hint recovery and the existing independent-process dedup race are included.

The initial native selection failed in migration setup because the exact CHECK comparison did not normalize the new owned reason literals. Its 16 errors ran zero assertions; JUnit test-body time is zero because setup failed, while console elapsed time is 19:30. The secondary native run was stopped once this shared defect was known. These failed/interrupted artifacts remain in the packet. The corrected native selections use fail-fast flags after a single schema canary.

The whole SQLite run's two errors were a missing test import and an outdated empty-tail fixture that left the new sixth table after a deliberately removed fifth-table guard. Both failures and their correction run are retained. Across the original run and corrected/new cases, the final test set has **198 distinct cases: 193 passing and 5 declared skips**, with 1070 assertions from the retained passing/corrected results. The two affected whole files were then re-run after the narrow native metadata correction at `dfbd0ba0` (27/230); substituting their results preserves the same 198-case/1070-assertion total. This is coverage assembled across the recorded source-bound runs, not a claim that the initial whole-directory command passed or that a second whole-directory run occurred.

The five SQLite skips are the native-only independent-process cases in `BillingNativeCrossInvoiceClaimTest`, `BillingNativeIntervalRetrievalRaceTest` (two methods), and `BillingNativeStaleRetrievalRaceTest` (two methods). Their full names remain in the JUnit log; their skip branches name the native requirement in the test source. Skip declarations were not changed.

Pint passed on all 13 changed PHP files at `1a14437e`; the single later schema-comparison file passed again at `dfbd0ba0` (`pint-native-final.txt`, exit 0). Focused-selection policy self-tests passed 50 cases and shard-policy self-tests passed 52. Final real discovery reports **1860 selected native cases in 167 files, of 7819 discovered cases in 563 files**. The new attempt file is explicitly included. The handoff's eight-shard discovery command is only a census: its duration estimates are not observed CI timings; actual hosted jobs remain at 24 shards. Four files use fallback timing weights. A disallowed output-prefix attempt and a later existing-output collision both exited 1; the corrected discovery exited 0. Their logs remain visible.

## Review and limits

A separate reviewer session, `/root/membership_attempt_review`, returned **APPROVE** for development merge at `f1b3c71bea8aff822958d6bf403868a491f5b6f3`, with no actionable findings. The [decision receipt](independent-review-DECISION.md) records the independently checked source, evidence and limits. The reviewer explicitly carried approval to receipt/status-only final head `f7da37369e821c23650505bedb1a07028142f9c8`. Preflight `38323937687` passed and PR #109 merged at `06684deb1465f0747d67a2e1751e11e9b7317c06`. A later P2 about cross-ledger retrieval endpoint reuse was independently reassessed before merge: development APPROVE remains, no P0/P1, with this P2 deferred to the next coherent membership batch. See the decision addendum and the deferred-findings ledger; the earlier no-actionable-findings statement predates this P2.

No full Foundation or GitLab run, real queue worker, real Stripe account, provider I/O, browser, host deployment or customer import was exercised. The whole billing directory was not run on native MySQL. Existing CP-2/CP-3/P1B-1, R-4/R-5/A1-5, L2-1 and other activation conditions are not closed by this batch. A database failure, unrecognized provider exception, or failure before the bounded retrieval outcome does not gain a guaranteed attempt record.

Next is the operative 257 writer, its transaction-bound paid-invoice and policy producers, and the required duplicate-invoice/last-credit native races. The reviewed T23 committed APIs exist on the integrated base. Approved commercial facts remain Sean's inputs; synthetic values must not become plan defaults or evidence of provider/host readiness.
