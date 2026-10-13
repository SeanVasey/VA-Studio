# Independent C2 reservation lifecycle review

## DECISION: APPROVE for development merge, with activation conditions

Reviewer: `/root/membership_award_independent_review`, a separate, non-author
session. This reviewer wrote only this decision file, reviewed source and
retained evidence, and did not author the implementation/tests or execute the
application, tests, native database statements or provider calls.

Reviewed exact candidate **`d8e7944c5137c6649bdc067f22a251d56cbd09b1`** against
review reference **`670f9caa158e436746ed9aed85667c10b9905f35`**. The actual
PR/main base is **`bd230c52a605e05aa89158ab161309091afd17e2`**. Their complete
diff contains only the prior PR #112 merge receipts and queue status in
`CLAUDE-PLAN.md` and `codex-deferred-20261011.md`; those ledger changes were also
reviewed, with no additional product difference. Application source remains
**`defe95802e7d09e7df12c61a98c1ebf1caee99c0`**. Final test source is
**`45d1299406821627f4ba02af186e324a70c6ade0`**; its only two changes from the
application reference are the native temporary-shadow fixture and the bounded
race assertions. The later candidate changes documentation and evidence only.
**No unresolved P0/P1 identified in this batch at its default-off development
boundary.** The carried activation conditions below remain open.

The reviewed composition preserves explicit v1/v2 policy dispatch, real current
catalog/license evidence, original-owner and invoice binding, full authenticated
ledger conservation, same-key/full-intent matching, original honor, capacity for
terminal events, and one owned transaction for each permitted effect. Public
proof methods cannot become standalone writer entries. Release is honor-expiry
cleanup; expiry does not resurrect reserved credit. Consume and actual grants
remain outside this batch.

The review closes the terminal clock/identity ordering findings in the Context
and eligibility issuer, and the one-shot file-wrapper admission finding. The
fixed native file closure opens and admits a native stream before terminal path
inspection. Original wall/monotonic anchors and the outer admission budget are
not renewed. The earlier omitted byte-closure reproductions and corrected source
remain distinguishable in the packet; these are development-source regressions,
not claims about reservation behavior in the earlier merged first-award batch.

## Independently verified evidence

Read-only `git cat-file --batch` comparisons, SHA-256/size checks and independent
Python XML/JSON parsing verified:

- All **320 artifact-manifest members** against both files and committed Git
  blobs, including the explicitly tracked logs; **270 archived copies** against
  their capture-time hashes; both **2,137-file source inventories** against their
  commits. The final inventory also matches the reviewed candidate and workspace.
- All **30 source-binding records**, **25 completed JUnit records**, actual exit
  receipts, full case identities, statuses and assertion totals. Final native
  and style/discovery source bindings agree with the recorded unchanged checks.
- The deduplicated SQLite census: **180 cases / 965 assertions**, **179 passes
  and one declared native-only skip**, across documented mixed sources. The
  integrated selection is **13 / 228**, zero failures/errors and that one skip.
  This is not one final-head 180-case execution; the five native race methods
  were not executed on SQLite and contribute no SQLite passes or skips.
- The deduplicated native census on private **MySQL 8.4.11**: **11 latest passing
  case identities / 336 assertions**, zero latest failures/errors/skips. Its
  contributions are canary **1/21**, different-key race **1/43**, boundary cases
  **3/57**, corrected eligibility **2/12**, and corrected races **4/203**. Passing
  leaves inside stopped invocations do not make those invocations green.
- All **seven native process transcripts**: distinct parent/worker process and
  connection identities, overlapping monotonic intervals, recorded fixed-fence
  and buyer-row waits, idle returned transactions, and exact outcomes. The three
  fresh idempotent retries return the original receipt and preserve matching
  complete-audit and raw retained-row hashes before/after retry; the source also
  asserts equality of the complete values, cardinalities and balances.
- Final **29-file Pint** evidence and source binding. Discovery reports **1,973
  native cases / 187 files** from **8,047 cases / 587 files**, across 24 shards,
  with **2,091 unchanged source hashes**. Discovery is listing/partition evidence,
  not execution of those database cases. The earlier **52/61/32** policy results
  are carried through identical source, not described as fresh executions.

The historical selection command note was archived before a later runtime
append: 269 of the 270 runtime paths still match their archived bytes; the later
note is separately retained as `selection/final-45d12994/commands-history.md`.
All 270 archived copies match their recorded capture hashes. The exploratory
canary/initial ledger source limitations, initial inventory omission, setup
errors, and seven verbatim patch-context whitespace warnings remain disclosed.

## Race interpretation and retained failures

The original same-key assertions failed both with a competing suite and without
another fixture suite: second-worker `operation_deadline` at approximately
66.748 and 63.449 seconds. Those invocations did not reach their final ledger
audits. They remain failures; their post-error retained state is not inferred.
The isolated run rules out competing full-suite load as a sufficient explanation.

The revised assertion follows the preexisting acceptance contract in
[next-writer.md](../membership-award-20261013/next-writer.md): an admitted caller
must return the original identity; bounded loser refusal must be reported
precisely. It requires first success and allows only an identical second receipt
or exact `MembershipException` / `operation_deadline` after at least 60 real
seconds, with closed transactions. No arbitrary error or
`committed_receipt_refused` qualifies. A full authenticated audit precedes an
explicit fresh invocation, whose receipt and retained effects must be unchanged.
There is no product retry or larger deadline.

The final four-case invocation passed **4/203**, exit 0, zero errors/failures/skips,
in **1,130.612 seconds** runner wall time. The same-key loser still refused after
**60.823447676 seconds**; its fresh parent invocation recovered the original
receipt without a new effect. Both duplicate-release races returned matching
concurrent receipts. Expiry versus reserve conserved the allowance. Frozen Carbon
wall time keeps fixture honor valid for recovery; this does not prove that a
physical honor window survives the measured contention. Native monotonic timing
and waits are real. The 60-second cap is an admission bound, not a promise to
interrupt blocking SQL.

## Conditions and merge boundary

Development merge still requires **green cheap preflight at the exact final
merge head**, receipt-only review carry if this decision is committed afterward,
and no newly opened P0/P1. This approval is not activation or release approval.

- The catalog `SHOW CREATE TABLE` comparison includes volatile
  `AUTO_INCREMENT=N`, an open **P2 false conflict** for unrelated inserts. Keep
  its single deferred entry and fix it in the coherent catalog/currentness batch
  before activation, with native unchanged-selection/changed-definition coverage.
- The measured same-key deadline failures and broader R-5 latency/throughput
  condition remain material. The assertion correction does not improve latency.
- Fully current unexpected-trigger inventory and the carried Billing conditions,
  including CP-2 allocation identity and CP-3 database-clock freshness, remain
  open. The documented next C3 batch must preserve legacy retained evidence and
  cover current and committed consumers. Existing contention/queue conditions
  and the C4 driver/provenance condition are not closed here.
- Runtime capability registration and routes remain absent. Sean's commercial,
  legal, account and host inputs remain required. Synthetic fixtures establish no
  real provider, commercial-policy, host, payment or customer-entitlement claim.
- Full matrices, browser/host/provider acceptance and exact-SHA final Foundation
  verification remain unclaimed. No routine Foundation or GitLab dispatch is
  justified by this review. C4 must couple consumption to real activation; this
  batch must not expose a standalone consume writer.

README, source map, commands, next-batch scope and both ledgers preserve these
boundaries and the failed evidence. Their merge receipt must be updated with the
actual PR/head/merge and preflight outcome when those facts exist.
