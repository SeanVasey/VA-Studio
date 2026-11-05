# PR #191 independent security review

- Repository: SeanVasey/VA-Studio
- Head reviewed: `a58fa2dbc884bb23de081a252e8347c6d3f14f4b` (detached worktree `/home/user/wt/pr191`, clean, unmodified)
- Base: `048244a6baf893a5d5f5581e466f353af24faaf7`
- Reviewer: Claude (independent security review; the Codex security review did not run because of quota)
- Date: 2026-11-04

## Decision

**APPROVE WITH CONDITIONS.** I found no P0, P1 or P2 issue. The conditions below do not block a development merge under the October 6 cost policy, because membership HTTP mounting stays literal-false. They do have to be met before mounting, or recorded as untested items in the final integrated verification.

1. Before any mount, or in the final integrated verification, record native MySQL runs at the exact SHA for two methods: `test_native_page_statement_count_stays_bounded_when_total_history_grows` and `test_native_committed_index_frame_refuses_temporary_table_creation_before_raw_proof`. The PR's own record lists only 5 native tests: the recovered-owner test plus the 4 owner-lock tests. These two appear only as "next selections" in `main-composition-20261103.md:75-76`.
2. The `FORCE INDEX` change also alters SQL for the identity request, completion and notification flows (see P3-2). Before the customer identity path is enabled anywhere, record a native run of the identity selections that exercise `CompleteIdentity`, `IdentityRequests` and `WorkIdentityNotice`.
3. Correct the lock-scope claim in the `MembershipCustomerIndex` docblock (P3-1), so a later reviewer doesn't rely on a property the code doesn't have.

Source attribution: `git diff 5b001577..a58fa2db -- app routes tests config bootstrap` is empty. The product and test bytes at head are therefore the bytes recorded as tested at `5b001577`. Every commit after that changed only documentation, CI comments or the census.

## Scope reviewed

I read every product and test hunk in `git diff 048244a6..a58fa2db`:
- `IdentityRows.php`
- `MembershipCustomerIndex.php`
- `MembershipController.php`
- `routes/production-memberships.php`
- the census
- the race worker
- the ops lease test
- the membership index, native index and HTTP/mount test diffs

I also read the surrounding code those hunks depend on:
- `MembershipPrivacy`
- `MembershipHttpMount`
- `config/production-memberships.php`
- `routes/web.php`
- `MembershipValues::id`
- the `IdentitySchema` index definition
- every `rows('production_identity_verifications', 'origin_id = ?', ...)` caller

## Assessment by question

### 1. Authorization and IDOR

- **Anchor ownership.** `anchor()` (`MembershipCustomerIndex.php:423-436`) resolves the anchor only with `WHERE account_id = ? AND id = ?`. Both values are bound parameters, and `account_id` is the authenticated principal's account. The anchor row then goes through the same `period()` checks as every window row (`:245-249`, `:266-274`): account, user, current identity origin, retained buyer binding and full ledger audit. The keyset window (`:409-420`) keeps `account_id = ?`. Only the anchor's own `period_start` and `id` feed the boundary, and the caller supplies neither. Rows owned by someone else can't be reached through the anchor.
- **Existence oracle.** A foreign id and an id that doesn't exist both take the same path: one primary-key lookup that returns 0 rows, then `MembershipException('foreign_period')`. A malformed or non-v4 id gives `invalid_value`. `MembershipPrivacy::refusal` maps every `MembershipException` except `identity_refused` to the one identical `404 MEMBERSHIP_NOT_FOUND` body (`MembershipPrivacy.php:107-116`). Status codes and bodies are therefore identical. The only timing difference is whether the primary-key probe hits a row, which is microseconds. That sits behind a frame of schema proofs and locks that takes hundreds of milliseconds and varies widely, and the ids are random UUIDv4. I don't consider it a practical oracle. The tests cover this. `test_anchor_requires_owned_complete_history_even_when_it_is_not_displayed` checks a foreign anchor, an unknown anchor and a malformed anchor. `MembershipHttpTest` (new line 167) checks a foreign continuation and gets the not-found response.
- **Disclosure.** The anchor period is audited but never projected. `projection()` uses only `$periods`, and `indexBody` still enforces its exact key allowlist.

### 2. Locking (`FORCE INDEX`)

- **Injection.** `IdentityRows.php:140-143`: the hint is the literal string `` FORCE INDEX (`piv_sequence_unique`) ``. It is added only when four things hold: the driver is `mysql`, `committedFrame === null` (which is exactly the condition that adds `FOR UPDATE`), the table is the literal `production_identity_verifications`, and `$where` is exactly `'origin_id = ?'`. The table name still goes through the allowlisted `table()`. No user input reaches the hint, the table or the predicate, and the bindings are unchanged. There is no injection path.
- **Scope.** The hint applies only to locking origin reads on MySQL. SQLite reads, committed-frame reads and every other predicate (`challenge_id = ?`, `id = ?`) keep their original SQL. If the index were missing or renamed, MySQL would raise an error and the read would fail closed. The admission check proves the index's exact columns: `IdentitySchema.php:33` defines it as `(origin_id, sequence)`, and `IdentityMigrationOwnership::inspect` and `SchemaProofFrame` re-prove it.
- **Lock scope.** An equality search on the unique index's prefix takes next-key locks on the owner origin's secondary-index records and record locks on their clustered rows. It takes a gap-only lock on the first record past the range (InnoDB `ROW_SEL_EXACT` uses `LOCK_GAP` for a record that doesn't match). The rows of the next origin are not locked, so the PR's claim about rows holds. The gap before the owner's first record is still locked; see P3-1.
- **LIMIT.** With `LIMIT 129` and `ORDER BY id`, MySQL now locks all of the origin's verification rows before the filesort and LIMIT. All of those rows belong to the same owner, and the count is bounded by the existing 128-row check.
- **Ordering and deadlocks.** Locks are now taken in `(origin_id, sequence)` order instead of PRIMARY order. Same-origin lock cycles still require two transactions on the same owner, and those are serialized by the owner `users` row lock and the T23 identity-row lock that every writer takes first. I found no new deadlock cycle across owners. A deadlock would roll back and fail closed in any case.

### 3. Proof integrity and TOCTOU

- **Same snapshot.** The anchor, the window, the sentinel and every audited history are consistent reads in one REPEATABLE READ snapshot. That snapshot is created after the owner lock (`lockOwnerFirst`) and the T23 lock, so no same-owner writer can commit between the anchor read and the window read.
- **Re-reads.** `raw()` (`:390-394`) re-resolves the anchor and the window and refuses with `changed_period_anchor` or `changed_period_window` if either differs. It does this at the end of the transaction and again in the read-only committed frame after commit, which sees a newer snapshot.
- **Pre-bytes.** The proof re-runs the whole collect with the captured `before` value. Its baseline now also includes the anchor's strings, the raw window rows (sentinel included) and the full history of every audited scope (anchor and sentinel included), at `:228-233`. That is strictly stronger than before.
- **Tests.** The tests exercise these paths:
  - a valid ledger change to a hidden anchor withholds the page (`changed_before_bytes`)
  - an anchor re-sealed with a tamper refuses
  - a window row committed inside the commit window refuses (`changed_period_window`)
  - newer insertions above the cursor leave continuations unchanged
  - timestamp ties use a strict id boundary
- **Ordering.** `period_start DESC, id DESC` with the boundary `period_start < ? OR (period_start = ? AND id < ?)` is a correct strict keyset. The 101-period traversal test shows no rows missing or duplicated.

### 4. Route and mount

- `config/production-memberships.php` still has `'http_mounted' => false`, unchanged. `routes/web.php:97-98` requires the route file only when `MembershipHttpMount::admitted()` returns true. That needs a literal `true` and an environment of `local` or `testing`.
- The new route (`routes/production-memberships.php:16-17`) is inside the same file and group. It has a lowercase-UUID `where`, the same `throttle:10,1,production-memberships-reads` prefix (a budget shared with the index), `block(20, 5)` and the domain `MembershipCustomerLimiter` `reads` admission.
- `MembershipPrivacy::matches` covers `production-memberships/*`, so the request is checked against the mount at runtime before any session starts, and query strings and bodies are refused. `MembershipController::index` re-checks the mount before the domain call and again after pre-bytes. `MembershipHttpMountTest` asserts that the `before` route is absent when unmounted, that it returns not-found with no session cookie and no queries, and that query strings and bodies on a continuation are refused.

### 5. Other AGENTS.md invariants

- Nothing touches money, payments, grants, entitlements, publication, consent or staff permissions. The `LIMIT` change from `MembershipPolicy::MAX_PERIODS` (100) to 10 only changes page size. `MAX_PERIODS` is now referenced only by its own definition.
- `tests/Support/membership-award-race-worker.php`: the new `VA_MEMBERSHIP_AWARD_REAL_WALL_CLOCK` switch only skips the fixed test clock and adds an `elapsed_seconds` output field. It is a test-only script, and nothing in `app/` references it.
- `tests/ops/test_staging_operation_lease.py`: **test-only, confirmed.** The diff changes only the fake child script inside the test, which now writes an empty file, sleeps and then writes the PID, and the wait predicate, which now requires a complete PID line. No `scripts/ops` or deploy file changed.
- CI files: the two workflow edits change comments only. The SQLite census appends three native-only methods. All 12 `test_native_*` methods skip on non-MySQL in `setUp`, and 12 census entries match them.
- `docs/verification/.../MemberIndexLatencyProbeTest.php` and `HistoricalProofCounter.php` are manual probes outside `tests/`. They are not autoloaded by `composer.json` or `phpunit.xml`, and they aren't web-served. The docs and JSON files contain counts, timings and SHAs only. I found no secrets, emails or customer data.

## Findings

No P0, P1 or P2.

### P3-1: the docblock overstates lock isolation; the gap before the owner's first verification record is still locked

- **Where:** `app/Domain/Customers/ProductionIdentity/IdentityRows.php:138-144` (behaviour) and `app/Domain/Memberships/Production/MembershipCustomerIndex.php:33-37` (claim: "never holds, any lock another customer's transaction needs ... no row or gap of another owner's ranges").
- **Path:** MySQL 8.4 uses REPEATABLE READ by default, and the native tests set it explicitly. Under REPEATABLE READ, the forced `origin_id = ?` `FOR UPDATE` read takes a next-key lock on customer B's first record `(B, 1)` in `piv_sequence_unique`. That lock covers the gap `(A_last, (B,1))`, where A is the origin immediately before B in the index. Customer A's identity recovery or completion inserts `(A, n+1)` into exactly that gap, so its insert-intention lock waits until B's transaction commits. B's membership index transaction can stay open for a long time: the recorded local samples reach 20 to 40 seconds under the 60-second budget. A's recovery can therefore hit `innodb_lock_wait_timeout` (50 seconds by default) and be refused.
- **Exposure:** It fails closed (a wait, then a refusal) and is not a data exposure. It needs mounting, and the customer identity path is default-off. It is not introduced by this PR: the same locking read already ran in `ProductionCustomerAccess::lock`. The PR greatly reduces cross-owner locking compared with a PRIMARY scan. A B who is rate-limited to 10 index calls per minute could hold the gap for much of each minute and delay recovery for one adjacent customer.
- **Fix:** Correct the docblock and the PR claim to say "no foreign row locks; the gap before the owner's first verification record is locked". Add a native test in which a foreign recovery or completion runs while an index is open. If cross-owner waits are unacceptable, lock the owner's origin row and read the verifications without locking, or read them by exact primary keys, so no gap lock is taken.

### P3-2: the hint changes SQL for five call sites, not one read, and its trigger is an exact string match

- **Where:** `IdentityRows.php:140-142`. The callers are `ProductionCustomerAccess.php:162` and `:244`, `CompleteIdentity.php:66`, `IdentityRequests.php:78` and `WorkIdentityNotice.php:165`.
- **Path:** The PR describes the change as one locking read, but every locking `origin_id = ?` verification read now uses the forced plan. That includes the identity request, completion and notification flows. No native evidence for those flows is recorded at this SHA. If a future caller writes the predicate slightly differently, for example `'origin_id=?'`, the hint is silently skipped and the optimizer may choose a PRIMARY scan again, which brings back cross-owner row locks. That failure is quiet: nothing errors.
- **Fix:** Replace the string match with a dedicated method, for example `IdentityRows::verificationsByOrigin(int $originId, int $limit)`, that always uses the hint. Add a test that asserts the native `EXPLAIN` key is `piv_sequence_unique`. Record native identity-flow runs (condition 2).

### P3-3: no recorded native evidence for two new native regressions

- **Where:** `tests/Feature/ProductionMembershipBilling/MembershipNativeCustomerIndexTest.php`: `test_native_page_statement_count_stays_bounded_when_total_history_grows` and `test_native_committed_index_frame_refuses_temporary_table_creation_before_raw_proof`. The evidence record is `final-verification-20261103.md` and `final-verification-results-20261103.json` (`targeted_native.tests: 5`).
- **Path:** Both tests skip on SQLite, and the census records that. The verification record lists native runs only for the recovered-owner test and the 4 owner tests. Per-page work being independent of total history (the DoS bound) and the committed frame being read-only are therefore asserted by tests nobody has run.
- **Fix:** Run both methods natively, one method per process, at the exact SHA, and record the results (condition 1). Until then, list them as untested.

## What I ran

All runs used direct PHPUnit on SQLite (`:memory:`), with `public/build` absent and the exact environment the task gave.
- `tests/Feature/ProductionMembershipBilling/MembershipCustomerIndexTest.php` filtered to these 7 tests: anchor ownership, ties and pre-bytes, hidden-anchor change, newer insertions, commit-window row, ten-page bound and sentinel. Result: **OK, 7 tests, 137 assertions** (1:38).
- `tests/Feature/ProductionMembershipHttp/MembershipHttpMountTest.php` and `tests/Feature/ProductionMembershipHttp/MembershipHttpTest.php`. Result: **OK, 42 tests, 1785 assertions** (1:51).

## What I did not check

- I ran no native MySQL test. As instructed, I started no server. All lock-scope reasoning (next-key and gap behaviour, plan choice, deadlock cycles) is static analysis of InnoDB semantics, not observation.
- I did not run the 101-period traversal test, the recovered-binding SQLite test, `MembershipCustomerIndexAvailabilityTest`, or the full SQLite and MySQL suites.
- I did not run any identity-flow test (`CompleteIdentity`, `IdentityRequests`, `WorkIdentityNotice`) under the new hint. Natively they can't run here.
- I did not independently verify the physical-pool evidence, JUnit files or observer records. I read their published summaries only.
- I did not verify that `IdentityMigrationOwnership::inspect` compares index columns exactly. I relied on the PR's packet description plus the `IdentitySchema` definition.
- I did not do a timing measurement of foreign versus non-existent anchors. My conclusion that they are equivalent rests on code-path analysis.
- I did not review CI workflow behaviour beyond the two comment-only hunks, and I did not review the docs and handoff text for accuracy beyond the evidence attribution.
