# C2 prerequisite: immutable policy facts and billing interval ownership

This batch supplies immutable policy authoring and an actual retained-policy proof producer. It also addresses PR #109's deferred cross-ledger retrieval-endpoint finding [r4253341984](https://github.com/SeanVasey/VA-Studio/pull/109#discussion_r4253341984). It does not implement or enable the operative award/reserve/consume/release/expire writer. Commercial terms remain Sean's inputs; all exercised documents are explicitly synthetic fixtures.

## Policy preparation

The separate `authoring_enabled` switch defaults to false. Authoring requires a current verified staff member, the `administer-catalog` gate, enrolled MFA, and the caller's captured outer transaction. Staff and configuration are rechecked after callbacks. A strict versioned document supplies allowance, price/currency, original terms, expiry and late-payment semantics. Unknown or unsupported fields are refused; there are no store defaults. Identical documents return the existing immutable version without replacing its original author.

The retained record includes encrypted original terms and authoring evidence. Its purpose-bound HMAC covers every retained column using the application key; previous application keys can verify historical records. This is internal record integrity, not external provider evidence. The authoring result is provisional until the caller commits and proves its own commit boundary. No route, panel action or committed-success API is installed.

`SealedMembershipPolicyFacts` reads and verifies the actual retained record, requires the configured approved hash and provenance, and binds each proof to its issuing authority, exact `MembershipRows`/PDO, seal, configuration and original deadline. Revalidation cannot renew that deadline. Unsupported lifecycle/license hashes remain null, and the binding declares only the `paid_period_award` scope. The existing complete operative capability gate remains intact; this producer is not registered as an activation shortcut.

## Retrieval endpoints and upgrade

New guards on observations and diagnostic attempts refuse every start/end endpoint combination across the two ledgers. On MySQL, both paths lock their shared position rows in ascending order and use a current locking read of the opposite ledger. The trigger does not issue a locking read against its own subject table, which MySQL rejects with error 1442. Existing same-ledger guards remain unchanged; this batch does not close the separate carried CP-2 finding.

Migration `2026_10_13_261000_membership_billing_interval_ownership.php` recognizes the exact previous six-table graph and adds the two guards without rewriting retained rows. It inspects the full graph, serializes the retained-data check and DDL with billing-table write locks on MySQL (an immediate transaction on SQLite), and refuses historical endpoint collisions or foreign definitions. An interruption after either guard is recoverable; runtime admission requires both. Parent and namespace checks surround the native locked section; the locked inspection reads only tables admitted by that explicit lock set. Rollback refuses to discard evidence.

This upgrade boundary is the complete six-table graph from PR #109. Earlier stopped installations must first complete that predecessor; no arbitrary older installation interoperability is claimed. Inspect and resolve retained conflicts through a reviewed recovery process before retrying; do not delete or reseal evidence to force migration success.

## Verification boundary

The [command record](commands.md), [source map](source-map.json), console logs and JUnit files bind the evidence to actual sources. Initial application source is `6cd0f4ab`; `fc8b74c7` corrects inspection under native table locks and test environment cleanup; `45ec5631` removes the MySQL trigger's invalid locking read of its own subject table. Final application/test source is `df3656b6a74151c5e2f6b89264ad733f70678c0e`: it evaluates the callback-capable clock before the terminal configuration/frame checks, leaving only a monotonic deadline check afterward. That last correction has its own failing-before regression and focused follow-up, rather than relabelling earlier results.

| Selection | Source | Actual result |
| --- | --- | --- |
| Initial SQLite fresh-schema canary | `6cd0f4ab` | 1 test / 8 assertions, zero failures/errors/skips |
| Initial native fresh-schema canary | `6cd0f4ab` | 1 test / 0 assertions / **1 error**: parent inspection referenced an unlocked table |
| Initial SQLite policy selection | `96078e09` | 3 tests / 25 assertions / **1 error**: test environment was not restored before teardown |
| Corrected whole SQLite policy file | `fc8b74c7` | 14 tests / 73 assertions, zero failures/errors/skips |
| Corrected native fresh-schema canary | `fc8b74c7` | 1 test / 8 assertions, zero failures/errors/skips |
| Whole SQLite interval file | `90f363d0` | 4 tests / 38 assertions, zero failures/errors/skips |
| Native symmetric endpoint combinations | `45ec5631` | 1 test / 27 assertions, zero failures/errors/skips |
| SQLite endpoint regression on previous application | Base `11a716e7`, test-only `d43d2dd8` | 1 test / 1 assertion / **1 intended failure**, zero errors/skips |
| Whole affected SQLite directories | Checkout `061c9284`, application `45ec5631` | 261 tests / 1311 assertions / **9 declared native-only skips**, zero failures/errors |
| Native policy selection | Checkout `061c9284`, application `45ec5631` | 5 tests / 37 assertions, zero failures/errors/skips |
| Native migration/race integration | Checkout `061c9284`, application `45ec5631` | 6 tests / 74 assertions, zero failures/errors/skips |
| Native two-process endpoint race on previous application | Base `11a716e7`, test-only `d43d2dd8` | 1 test / 5 assertions / **1 intended failure**: both writers retained conflicting rows; zero errors/skips |
| Terminal clock regression before correction | Application `45ec5631`, test `c54a3d20` | 1 test / 1 assertion / **1 intended failure**, zero errors/skips |
| Whole final policy files plus declared native snapshot case on SQLite | `df3656b6` | 16 tests / 78 assertions / **1 declared native-only skip**, zero failures/errors, exit 0 |
| Final native real-writer and terminal-clock selection | `df3656b6` | 2 tests / 23 assertions, zero failures/errors/skips, exit 0 |
| Deterministic native prior-snapshot case | `df3656b6` | 1 test / 51 assertions, zero failures/errors/skips, exit 0 |

The whole affected SQLite directories were run before the last clock correction. Replacing the 14 earlier policy results with the final policy results and adding the clock/snapshot cases gives **263 distinct cases, 253 passing and 10 declared native-only skips, 1316 assertions across the recorded runs**. This is combined source-bound coverage, not a claim that a second whole-directory command ran. The changed proof has been rechecked with both complete policy files. JUnit retains the exact skipped identities; skipped native cases are not counted as passes.

The native policy, endpoint-combination and integration runs provide 12 distinct cases / 138 assertions on application `45ec5631`. Replacing the real-writer result with its final-source repeat and adding the clock and deterministic snapshot cases gives **14 distinct native cases / 194 assertions / zero failures, errors or skips across the recorded sources**. The earlier fresh-schema canary is additional historical evidence, excluded from this aggregate. The final snapshot case explicitly proves an ordinary read still sees the older snapshot before the conflicting insert receives SQLSTATE 45000, across both directions and all four endpoint combinations. The separate two-process race proves exactly one retained winner; the previous application admits both and fails the same regression. The native integration also retains the previously green duplicate-invoice and unrelated-invoice cases.

Pint passed all **20 changed PHP files**; focused-selection checks passed **50**, and partition-policy checks passed **52**. Initial Pint failure, two initial partition-policy errors, and the subsequent missing-worker policy failure remain visible beside their corrections. Actual discovery proves **1881 selected native cases in 172 files, of 7840 discovered cases in 568 files**. Nine files lack timing entries and use historical fallback weights; no performance claim follows from those weights.

The separate reviewer completed source review at `df3656b6` with no new P0/P1/P2 finding. Its final evidence decision and exact-head preflight are still pending; no approval or merge is represented by this working packet. The next dependency is recorded in [next-writer.md](next-writer.md).

Native work uses a new private Oracle MySQL 8.4.11 instance: container `va-studio-private-mysql-policy-20261013`, datadir `/workspace/runtime/mysql-policy-data`, loopback port 33413 and socket `/workspace/runtime/mysql-policy-run/mysql.sock`. Policy and interval selections use separate disposable schemas. The predecessor container was absent in this execution environment; it was not silently reused. This is local synthetic evidence, never host or provider verification.

No real Stripe call, payment, customer import, mounted authoring workflow, browser journey, full Foundation matrix or GitLab dispatch is included. Carried CP-2/CP-3/P1B-1, R-4/R-5/A1-5, L2-1 and other activation conditions remain open. The next C2 batch needs transaction-bound paid-invoice facts with the provider's actual payment timestamp, an owned committed operation, semantic invoice idempotence and canonical credit-ledger writes. Award/last-credit native races and C4's shared activation/consumption transaction remain subsequent work.
