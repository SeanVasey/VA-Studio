# Independent review: REP fixture repair eb5ee434

- Commit under review: `eb5ee43488f161af8b2b2157f07a81556419054a` (parent `a0c6cc83`)
- Files: `tests/Feature/Reporting/ReportingIndexesTest.php` (+15/-1, only `populated()` and three `use` lines), `docs/verification/rep-indexes-20261103/README.md` (+10)
- Worktree: `/home/user/wt/rep` at `527150b4` (eb5ee434 plus the unrelated identity fix). It was not edited; `git status` is clean after the review.
- Reviewer date: 2026-11-04

## Decision: APPROVE WITH CONDITIONS

The diagnosis is correct and proven by experiment. The ID change is an honest repair that keeps the product rule intact. The clock advance does not hide a defect, because same-second observation works. The conditional `allocate` branch never runs today, but it removes a check that a0 enforced, so it should be replaced by an assertion (P2-1) before this counts as a clean fixture repair. Nothing here is P0 or P1.

Condition for approval: fix P2-1 (replace the fallback with the assertion below, or delete it), then re-run the two affected methods on the same source. P3s are optional.

## Answers to the questions

### 1. Diagnosis

- The rule is exactly as stated. `app/Domain/Commerce/Payments/PaymentFinancialEvidence.php:179-182`: `preg_match('/\A'.$prefix.'_[A-Za-z0-9]{1,120}\z/D', $id)`. For refunds it is called at `:147` with prefix `re`. `re_REPORTING_INDEX` has a second underscore, so `invalid()` (`:184-187`) throws `PaymentVerificationException('financial_attention')`.
- `app/Domain/Commerce/RefundDispute/RefundDisputeLedger.php:155-161` catches that as `['attention', null]`. With `$financial === null`, `$accepted` is false (`:84-89`). `refund_state` is stored as null (`:99`), and an `observation_attention` effect with reason `provider_evidence` is written (`:119-122`).
- It is the only cause, shown by experiment on SQLite with scratch copies (the two `populated()` methods only):
  - v3: old ID with the new clock line: both FAIL with `null is identical to 'full'`. The clock does not rescue the old ID.
  - v5: old ID, outcome printed: `outcome=attention refundState=NULL effects={"observation_attention":{"reason":"provider_evidence",...}}`.
  - v2: new ID without the clock line: both PASS (109 assertions).
  - So the ID change is both necessary and sufficient. This is a fixture defect, not a product defect: real Stripe refund IDs are `re_` plus alphanumerics, and the rule was not relaxed.

### 2. Is the repair honest?

- **Clock advance (`ReportingIndexesTest.php:130`).** It isn't needed and it doesn't mask anything.
  - v2 (no clock move) passes. The probe shows `confirmed_at=2026-10-01T12:00:00Z now=2026-10-01T12:00:00Z`, and the automatic allocation is still written.
  - The ledger has no comparison against `confirmed_at`. The only time guard is `$now->lessThan($observedAt)` at `RefundDisputeLedger.php:77`, and it passes when the two are equal.
  - `tests/Feature/Reporting/CustomerSegmentsEdgeTest.php:39,116-117` already observes a full refund in the same frozen second as confirmation.
  - The advance is forward (12:00:00 to 12:00:01, because no fixture under `DeliveryFixtures::ready` moves the clock). It stays inside the reported day `2026-10-01`, and both the before and after snapshots are taken after it. Harmless; see P3-1.
- **Conditional allocate branch (`:133-141`).** It never runs, and it can't run while the preceding assertion holds.
  - The probe in v1 (committed source plus prints) shows `alloc_rows=[{"source":"automatic_full_refund","amount_minor":122956,"actor_id":null,"sequence":1}]` in both methods. The `FALLBACK BRANCH RAN` marker never printed.
  - Code path: an accepted observation with `refund_state === 'full'` runs `RefundDisputeEffects::afterObservation`. That method allocates every unallocated succeeded refund automatically (`app/Domain/Commerce/RefundDispute/RefundDisputeEffects.php:48-55`) inside the same transaction as the observation.
  - If that allocation throws, `safe()` (`RefundDisputeLedger.php:195-203`) turns the error into `RefundDisputeException('unavailable')`, so `observe()` never returns `full` without an allocation row.
  - It doesn't weaken anything today, but it would hide a future regression; see P2-1.
- **Original assertions.** All are still enforced, and none was removed or relaxed:
  - `refundState === 'full'` (`:132`)
  - `count > 0` for `quotes`, `orders`, `order_finalizations`, `refund_line_allocations` (`:142-144`)
  - `reporting()`: available, 1 order, `net_minor === 0`, `refund_unallocated === 0` (`:149-159`)
  - `retained()`/`R::originals()`/reporting equality across up and down, the immutable-update refusals, and the DDL-free re-run
  - The only loss is the implicit one described in P2-1.

### 3. Containment

- `populated()` is private and has exactly two callers: `ReportingIndexesTest.php:180` and `:201`.
- The four other methods in that file and the two in `ReportingProductionIndexesTest.php` don't call it. That second file isn't in the diff (`git diff a0c6cc83 eb5ee434 --stat`: two files only).
- The three new `use` imports don't conflict with any existing name; the file compiles and runs.
- The change is limited to the fixture helper. The cherry-picked `527150b4` changes only `CatalogSocketBinding` and its test, and doesn't touch reporting.

## Findings

### P2-1: the dead fallback allocation hides a regression in automatic full-refund allocation that a0 would have caught

- **Where:** `tests/Feature/Reporting/ReportingIndexesTest.php:133-141`; the README claim is at `docs/verification/rep-indexes-20261103/README.md:74` ("never ... weakened count/net checks").
- **Failure path:**
  - At a0, if `RefundDisputeEffects::afterObservation` stopped writing the `automatic_full_refund` allocation, the `refund_line_allocations` assertion at `:142-144` would fail.
  - At eb5ee434, the fallback would quietly write a `staff` allocation through `RefundDisputeActions::allocate`. Then `count > 0`, `net_minor === 0` and `refund_unallocated === 0` would all still pass, and the regression would go unseen.
  - So this branch weakens an implicit check, even though it is unreachable today. The README's statement that no check was weakened is therefore inaccurate.
  - `RefundDisputeLifecycleTest` covers `automatic_full_refund` directly, which limits the impact. Hence P2, not P1.
- **Fix:** delete the conditional and the three imports it needs (`RefundDisputeObject`, `RefundDisputeActions`, `Str`). Assert the automatic allocation instead:
  ```php
  $this->assertSame(['automatic_full_refund'], DB::table('refund_line_allocations')->where('order_id', $fixture['order']->id)
      ->distinct()->pluck('source')->all(), 'full refund is allocated automatically');
  $this->assertSame((int) $fixture['payment']->amount_minor,
      (int) DB::table('refund_line_allocations')->where('order_id', $fixture['order']->id)->sum('amount_minor'));
  ```
  Scratch variant v4 (`/home/user/notes/rep-review/v4-no-fallback/ReportingIndexesTest.php`) does exactly this and passes on SQLite: 2 tests, 113 assertions. Correct README:74 to match.

### P3-1: the clock advance is unnecessary and its rationale is loosely worded

- **Where:** `ReportingIndexesTest.php:129-130`; `README.md:72`.
- **Failure path:**
  - No functional failure. A reader may infer that same-second observations are rejected, which would be a product ordering bug. They are not: v2 passes, and `CustomerSegmentsEdgeTest` relies on same-second observation.
  - The README's "matching the original reporting test's later-observation pattern" picks one precedent (`RefundNettingReportTest` jumps days) and leaves out the contrary one.
- **Fix:** either drop line 130 (v2 proves it isn't needed), or keep it and state plainly in the comment and the README that it models ordering only and is not required. The README already concedes the second half: "did not establish equal timestamps alone as the rejection cause". Add the v2 result as evidence.

### P3-2: run-together tokens in the packet text

- **Where:** `README.md:70`: "completed8/301assertions with2failures,0errors/skips/warnings".
- **Failure path:** a reader or grep misreads the receipt counts.
- **Fix:** "completed 8 tests / 301 assertions with 2 failures, 0 errors/skips/warnings".

### P3-3 (observation): the packet's provenance line is now stale

- **Where:** `README.md:74`: "no PHP/SQL/runtime reproduction or pass by the author".
- **Failure path:** that is true of the author at commit time. A later reader may not see the harness's SQLite (8/408) and native MySQL 8.4.11 (8/293) runs at `527150b4`.
- **Fix:** none needed in eb5ee434 (the packet is immutable author evidence). Record the harness runs, with their exact source `527150b4`, in the next packet.

## Evidence (this review)

Command form: the caller's SQLite command, with `--filter 'test_populated_upgrade_preserves_evidence_triggers_and_reporting_results|test_partial_installation_resumes_only_missing_indexes_without_changing_retained_evidence'` pointed at the scratch file. Run from `/home/user/wt/rep` (PHP 8.4.27, PHPUnit 12.5.34, repo `phpunit.xml`).

| Variant (`/home/user/notes/rep-review/`) | Change from committed source | Result |
|---|---|---|
| v1-instrumented | stderr probes only | OK, 2 tests / 109 assertions; fallback marker never printed; allocation source `automatic_full_refund`, 122956 minor, sequence 1; order has 1 line |
| v2-no-clock | probes, clock line removed | OK, 2 / 109; `now == confirmed_at` |
| v3-old-id-with-clock | probes, `re_REPORTING_INDEX` restored | FAIL 2/2, `null is identical to 'full'` at the line-132 assertion |
| v4-no-fallback | fallback replaced by strict automatic-allocation assertions | OK, 2 / 113 |
| v5-old-id-outcome | old ID, outcome printed (populated-upgrade method only) | outcome `attention`, effect reason `provider_evidence` |

## Not checked

- The six other methods were not run, per the instruction. Their non-involvement comes from reading the code (no call to `populated()`, no diff) plus the caller's reported runs.
- No native MySQL run. The fallback being dead on MySQL is inferred from engine-agnostic domain code, not observed.
- eb5ee434 by itself was not run. On this IPv6-less host, migrations need `527150b4`, so every run here is at `527150b4` with scratch test files.
- I did not independently re-verify the caller's full-file counts (SQLite 8/408, MySQL 8/293).
- No lint, Pint or static analysis on the changed test file.
- The EXPLAIN v1/v2 operator described at README:76 is out of scope and was not reviewed.
- Whether real Stripe refund IDs can ever contain a second underscore was not checked against Stripe documentation. The product rule was taken as authoritative.
- The fallback's correctness in a multi-line or discounted order (line totals vs paid amount) was not exercised, because it never runs.
