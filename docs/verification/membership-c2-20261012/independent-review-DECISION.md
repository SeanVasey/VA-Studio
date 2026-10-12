# Independent review decision

Reviewer session: `/root/membership_attempt_review`, separate from the implementing lead. This receipt transcribes the reviewer's returned decision. The reviewer made no edits and ran no tests; the findings below reflect independent source and retained-evidence inspection.

**DECISION: APPROVE for development merge of `f1b3c71bea8aff822958d6bf403868a491f5b6f3`.**

No actionable findings remain. This closes A1-4's durable-attempt condition and L2-4's matching-binding invoice-ID regression for this batch.

The attempts remain diagnostic: they neither claim invoices nor cover hints or authorize paid effects. The forward migration preserves existing tables and retained rows; retries, encrypted job payloads and older serialized jobs remain compatible. The final MySQL correction normalizes only the eight declared reason literals.

The reviewer independently inspected the source, subsequent changes and retained evidence:

- Native MySQL: **20 distinct tests, 217 assertions**, no failures, errors or skips.
- SQLite evidence combined across documented runs: **198 distinct cases, 193 passing, 5 declared skips, 1070 assertions**.
- All **36 evidence digests** and **14 source-map entries** match.
- Application/test source is unchanged between tested `dfbd0ba0` and reviewed `f1b3c71b`; workflow changes only update census comments.

Original failures and the interrupted run are accurately retained, with their corrections demonstrated.

This approval covers the prerequisite batch only. Billing remains default-off and unregistered. Existing activation conditions remain open; no operative award/reserve/consume/activation writer, full Foundation acceptance, real queue/provider interoperability or deployment approval is established. Exact-head green preflight remains required before merge.

## Final-head carry and later P2 addendum

The same separate reviewer explicitly carried development APPROVE to `f7da37369e821c23650505bedb1a07028142f9c8` after inspecting its four-file receipt/status-only delta from `f1b3c71b`. No application, test, workflow or recorded evidence changed in that delta.

After automated review raised P2 r4253341984, the reviewer reassessed the frozen head and returned:

> Development APPROVE remains for `f7da37369e821c23650505bedb1a07028142f9c8`, with this P2 explicitly deferred. No P0/P1 identified.

The finding is valid and distinct from CP-2: endpoint reuse is checked within each table, so low-level insertion can associate one interval with both an attempt and an observation. Current reconciliation allocates fresh positions and uses mutually exclusive write paths; queue retries allocate new intervals and serialized jobs carry none. Attempts supply no paid authority, and no operative credit writer exists.

This addendum supersedes the earlier no-actionable-findings statement for this new P2. Sean’s freeze-and-batch rule supports one deferral reply and next-batch ledger entry. That batch should cover reuse in both directions, endpoint combinations and native concurrent insertion. Prior scope limits remain.

Exact-head preflight `38323937687` subsequently passed. PR #109 merged at `06684deb1465f0747d67a2e1751e11e9b7317c06`.
