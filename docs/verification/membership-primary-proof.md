# Membership terminal primary proof

This isolated correction starts at the explicit-engine child
6271baca17a9e806fcd021dd3abc05d423d4dff4. It preserves the e3bd238 foundation,
its captured-clock correction, and every original receipt. Independent review
actually demonstrated that a QueryExecuted listener could withdraw staff
authority during CreditLedger's final ordered history query, after the earlier
authority proof, and still commit an award. The retained reviewer report is
membership-independent-review/membership-independent-review-e3bd238-blocked.json
(SHA256 18a8502d73c9c9f4cf55b4920d54cbae83267ad225260caa2bfa5593a30326f1).
That is a security blocker in the old source, not an inferred risk.

MembershipEvidence now captures the primary PDO and driver before the first
application authority callback. The terminal proof reuses that exact primary
transaction through internally whitelisted, prepared, strictly typed row/range
reads. MySQL reads retain FOR UPDATE current-read semantics. Full raw user rows
include the credentials and enrolled MFA fields participating in existing
authority checks; account ownership/access version, plan/version/bucket rows,
complete event and audit ranges, audit cursors and the actual command's own
audit identity are compared again. No Eloquent or QueryBuilder read follows
this proof. All callback-producing authority, range and cursor reads run first;
only effect-free policy, sampled-clock and result work follows.

The public plan/ledger API, authority and principal rules, lock order, movement
validation, original immutable evidence, exact replay and semantic no-op
behavior are retained. The engine correction's four InnoDB declarations and
its native altered-default receipt remain a separate child. No other writers,
models, migrations, fixtures, worker bodies or shared CI policy are changed by
this correction.

The new actual regression selection first failed on the old executable source:
26 cases, 52 assertions, 26 failures, zero errors/skips, 5.109 seconds. Twenty-four
genuine late query callbacks withdraw role, verified email, enrolled MFA,
customer credentials or account authority during writes, replay, read, review
and no-op paths. Two further callbacks append event/audit evidence after a
history SELECT has fetched its rows. Complete old PHP bytes and the original
commands, before/after source hashes, logs and JUnit are retained in
membership-primary-proof-evidence/terminal-query-red-source/ and its red records.

The corrected pre-format selection passed 26/104 in 4.185 seconds. After scoped
formatting, the final affected SQLite selection passed 50 cases/254 assertions,
zero errors/failures/skips, 9.057 seconds. It includes the new callbacks plus
captured-clock, lifecycle, exact replay, source collision, expiry, actual own
audit, prior callback authority, ABA and disabled/nested-operation criteria.
The unchanged external reviewer canary was also executed against these bytes
by the author and passed one case/five assertions in 0.499 seconds. Independent
review of the frozen correction remains required; this author execution is not
an approval.

The final narrow native MySQL 8.4.11 selection passed 37 cases/462 assertions,
zero errors/failures/skips, 170.125 seconds. It executes all 26 new regressions,
the captured-clock case, selected plan/lifecycle/replay/authority cases and four
unchanged independent-process native criteria: last-credit contention,
duplicate synthetic invoice-source replay, competing captured revisions and
account withdrawal before a read. Each race requires actual separate primary
processes/connections and the exact relevant InnoDB PRIMARY wait before release;
the original 15/20/40-second worker/barrier/process budgets are unchanged. The
normal durability runner reports flush-at-commit 1, sync_binlog 1, doublewrite
ON and log_bin true. All 18 PHP source hashes before/after these final runs match
the frozen component blobs.

Scoped Pint and syntax passed on the four affected PHP files. Actual source
discovery lists 162 membership cases, retaining all prior identities and adding
26 new callbacks: 13 native-only cases in three methods, 149 expected SQLite
executions. Discovery is not execution of that complete selection. Exact
commands, selectors, sanitized runtime environment, raw JUnit/logs and source
hashes are in membership-primary-proof-evidence/; tested-source-final.json binds
the frozen commit/tree, parent and exact evidence bytes.

No full foundation selection, hosted matrix or browser execution is claimed.
The private local/testing synthetic boundary continues to exclude enrollment,
billing, provider awards and commercial policy. The October 6 manual-only final
verification policy remains unchanged. Independent sensitive review is required
before foundation acceptance; no remote action was performed.
