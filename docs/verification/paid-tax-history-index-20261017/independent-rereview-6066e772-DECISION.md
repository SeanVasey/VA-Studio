DECISION: APPROVE at `6066e772a5ab5f84207737d117250dd56b68b8c1`. No P0, P1 or P2. Four P3s below.

# Independent re-review: T1 delta `e947cdb3..6066e772`

**Reviewer:** an independent Claude subagent. I did not write this change. I made no edits, commits or pushes. HEAD was confirmed before and after, the worktree stayed clean, and `public/build` stayed absent. All mutations ran in scratch copies.

## Assessment

**1. The fix is sound.**
- `amounts()` (`PaidTaxJourney.tsx:55-65`) now checks only structure, fixed values and ranges. No pricing relationship is left anywhere in the component or page.
- What remains is formatting: `usd()` divides by 100 for display (`:122`), and `ready()` counts lines.
- The server still enforces every dropped rule. `PaidTaxGrants.php:165` calls `PaidTaxPolicy::source`, which runs `ProductionTaxPaidLineAdapterV2::assertSelfConsistent` (`:94-104`) before `project()`.
- Nothing is submitted based on the amounts. The authorize body (`:198`) carries only the request key, origin hash, kind and nonce. Tokens and authorization are unchanged.
- The new wording is accurate for both tax types. For inclusive tax the server requires total = subtotal, so the page shows "Subtotal X, tax Y (inclusive), total X". The old "+ tax =" wording did misread inclusive tax.
- **Red/green reproduced.** The new test file against the old component fails 2 of 18: line 62 (the acceptance assertion) and the display text. With the fix, it passes.

**2. The merges did not interact with T1.**
- `git diff e947cdb3 HEAD` shows no change to any T1 code or test file other than the fix's two files.
- PR #146 touched `PaidTaxDownloadJourneyTest` and `PaidTaxSchemaRecoveryTest`. Those are F2c's MySQL-only additions, not T1 files.
- I recomputed both merges with `git merge-tree` in a scratch clone:
  - `40e92c65`: CHANGELOG.md was the only conflict, and its tree differs from the recomputed merge only there;
  - `ef1261dc`: clean, and its tree is identical to the recomputed merge.
- CHANGELOG at HEAD is main (`c488277c`) plus T1's Added and Fixed entries. No entry was lost.

**3. The docs are accurate, apart from P3-A.** I checked the README addenda, the T1 and F2c ledger entries, the F2c split arithmetic (5 + 15 + 4 + row 18) and the F2c deferred list against `independent-review-06a23144-DECISION.md`. They match.

## Findings

- **P3-A. Stale status text.**
  - `docs/verification/paid-tax-history-index-20261017/README.md:247,251` still lists P3-5 as deferred.
  - `docs/handoff/2026-10-17/RESUMPTION.md:37` still says "six P3s deferred".
  - Remedy: mark P3-5 as fixed in `6066e772`, and change "six" to five.
- **P3-B. Some checks have no test.** In `tests/frontend/paid-tax-journey.test.tsx:54-62`, removing any of these checks still passes all tests: the exact-key checks on `preTax` and `tax`, `authority`, `calculator`, `tax.currency`, `automatic_tax.enabled`, and the `preTax.order_subtotal_minor` range. The old suite had the same gaps, so this is not a regression; the new cases also add coverage for `preTax.currency` and `tax_behavior`. Remedy: add one refusal case for each.
- **P3-C. The acceptance assertion is narrow.** Line 62 is a sound boundary test, but it covers only the exclusive line total. Reintroducing any of these still passes: the pre-tax/tax subtotal equality, the order-scope exclusive sum, or the inclusive rule. Remedy: add inclusive, order-scope and subtotal-mismatch acceptance variants.
- **P3-D. Inclusive wording (optional).** At `PaidTaxJourney.tsx:255`, "tax … (inclusive)" is accurate but could read as additive. No render test covers inclusive. Remedy: consider "tax USD Y (included)" and add a render case.

Prior P3-1 to P3-4, P3-6 and P3-7 are untouched by this delta.

## My commands and counts

| Check | Result |
| --- | --- |
| `vitest run` paid-tax-journey + inertia-page-registry (fixed copy) | 21 passed (18 + 3) |
| Same journey file against the old component | 2 failed, 16 passed |
| Full `npx vitest run` | 68 files, 1,172 passed (52.9 s) |
| `npx tsc --noEmit` | exit 0 |
| `npm run build`, then `scan-client-bundle.py` (scratch) | 14 bundle files, no hits |
| Removing each `amounts()` check in turn (16 changes) | 9 caught, 7 survived (P3-B) |
| Reintroducing the arithmetic in part (3 variants) | all 3 survived (P3-C) |

## Not verified

- PHP and MySQL were not rerun; the delta changes no PHP.
- No browser, keyboard, contrast or visual check of the new wording.
- The Codex P1 thread text and its state on GitHub.
- `npm audit`, `composer audit` and any CI.

The scratch copies are under `/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad/t1rr`.
