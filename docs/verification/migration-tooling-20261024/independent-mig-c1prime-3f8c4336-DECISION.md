# MIG C1′ narrow check at 3f8c4336 (HEAD 9807ac60)

- Worktree: `/home/user/wt/mig`
- Tested source: `3f8c4336192312d35837f1d68b053eabb017f906`
- HEAD `9807ac60` changes docs only.
- Prior record: `scratchpad/mig-rereview-41681c84.md`.
- I made no commits, pushes or comments. At the end, `git status --short` is empty and `public/build` is absent.

## Verdict: APPROVE WITH CONDITIONS

C1′ as specified is closed. That is the case where the newest export omits a competing record and its creator then reviews. There is no P0 or P1.

One residual variant of the same class remains: R-2, a P2 that can be deferred. It must be closed before anything consumes `verified_grant`, which means before historical entitlement activation (that needs Sean's authorization). Nothing consumes it today.

## Scope of change: verified

`git diff --stat 41681c84 3f8c4336 -- app database config scripts tests routes bootstrap resources` touches only two files:

- `app/Domain/Migration/History/LegacyGrantReview.php`
- `tests/Feature/Migration/LegacyHistoryReviewConditionsTest.php`

Everything else in the diff is the count comments in `.github/workflows/final-verification.yml` and `.gitlab-ci.yml`, plus docs and evidence. The migration, the other services, the commands and the census are unchanged.

## Results

### Probe D, both blocker kinds: closed

My own probe set up a baseline containing a competing exclusive sale for track-03 and a customer sharing buyer one's address. Operator B then imported an omitting export with a newer watermark (0 rows inserted).

| Line | `inspect` | B reviewing | Classification |
| --- | --- | --- | --- |
| 1002 | `duplicate_exclusive_sale` | `reviewer_imported_evidence`, write refused `not_eligible` | `archive_only` |
| 1001 | `ambiguous_customer_identity` | `reviewer_imported_evidence`, write refused `not_eligible` | `archive_only` |

### The new tests fail on the old code

I swapped in the `41681c84` version of `LegacyGrantReview.php` and ran `--filter c1_prime`: 2 tests, 2 failures. The file was then restored.

### RR4 equivalence claim: holds

`batch()` inserts the batch row inside the first segment's transaction. That same transaction always appends the `legacy_history.import_segment` audit event with `actor_id` = the creator, even when it processes 0 records.

So the batch creator is always a recorded segment actor. Excluding "batch creator" separately is redundant, and keeping it as defence in depth is fine.

### R-2 (P2, deferrable): a reviewer-authored version that clears a blocker survives once someone else imports a newer export

The new rule reads the latest archived version of competing records. It does **not** exclude the actor who inserted that latest version.

**Probes V2 and V2b (SQLite):**

1. A imports the baseline.
2. B imports batch 2 (10-01). In it the competing sale is restated as refunded, and the sharing customer's address is changed. B inserts those new versions.
3. While B's batch is the newest, B is excluded. That part works.
4. C then imports batch 3 (10-05). It is either an export that omits both records (V2), or an identical copy of B's content (V2b). In both cases C inserts 0 relied-on rows.
5. B reviews line 1002 and line 1001: `blockers=[]`, the write is `recorded`, and classification is `verified_grant`.

This is the same single-person subversion as R-1, but it needs a later import by someone else that does not overwrite B's version. That happens if BeatStars later purges the record, or if C imports a file B prepared. A genuine later export that still contains the unrefunded sale would restore the blocker.

Note that a forged *refund* is classified as the automatic, non-blocking delta category `refund`. Requiring a reconciled delta would not catch it either.

**Fix (cheap):** for every competing record consulted, add the `actor_id` of the rows holding its latest version (and their batch and segment actors) to the reviewer exclusion set. Competing records are the other exclusive lines and their orders, customers sharing the address, and other contracts. Alternatively, exclude anyone who inserted any version of those records.

### V3 (change rather than omit)

B restates the competing sale onto another track. B is refused. An uninvolved C can then review and gets `recorded`.

This is the intended two-person outcome. However, C gets no signal from the review path. The track change is a blocking delta item, but the review does not depend on the delta.

The author argued that reading latest versions makes a reconciled-delta requirement redundant. That holds for omissions, not for changes.

I rate this P3: either document in the guide that a reviewer must check the newest export's delta, or require a reconciled delta when a blocker clears because of a changed record.

## Commands and results

| Run | Result |
| --- | --- |
| SQLite, the 8 MIG files on HEAD | 70 tests, 823 assertions, 2 race tests skipped (native-only); matches the packet. `LegacyHistoryReviewConditionsTest` is 7 tests, 199 assertions |
| New `c1_prime` tests against the `41681c84` `LegacyGrantReview.php` | 2 tests, 2 failures (file restored) |
| Native selection proof | 2526 selected tests in 244 files (of 9665 in 708); matches both workflow comments. Generated files deleted |
| `test-phpunit-shards.py` | OK |
| Probes D, V2, V2b, V3 | Results as described above |

- Probe file: `scratchpad/mig-review/ZzC1PrimeProbeTest.php`. It was in the worktree only while running.
- No native MySQL run. The change adds only plain `SELECT`s (`distinct()->pluck`, an ordered batch scan) and no locking or DDL, so I see no MySQL-specific risk.
