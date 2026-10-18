DECISION: APPROVE at 7b290b8b8446d7441fbca815f804ca58df75a8bc (tested source 30cca9b6d444755ee106221bcc4b56638e4a783d; 30cca9b6..7b290b8b changes only the packet and CHANGELOG.md)

Independent review of the F3 foundation test repairs (harness/foundation-repairs-20261018). No test was weakened to get green, and no protection was misclassified. Mutation runs in a scratch copy confirm each re-pin. I found no P0, P1 or P2 issues.

## Findings

**P3-1: the 1a positive control pins scope, not a security property.** `MemberOriginalFinishBoundaryTest.php:88-105` would fail if a future change widened `IdentityMetadataAdmission` (`IdentityMetadataAdmission.php:118`) to capture `member-grants`, even though that would be stricter. The packet already says so in its limits. Remedy: none needed now. If that admission is widened later, revisit the control; don't read the failure as a regression.

**P3-2: the member-layer committed `changed_owner` check is now shadowed for `identity_configuration`.** The frame refuses first, at `SealedMemberGrantFacts.php:154`, so `raw()` (`:201-203`) is never reached in that case. The member-layer identity check is still covered by the current-phase case: when I removed `identity_configuration` from `ownerInputs` (`:232`), "current identity_configuration" failed. Remedy: optional. A direct committed-phase `ownerInputs` test would restore defence-in-depth coverage.

**P3-3: the cause of the 1c refusal is inferred, not asserted.** `MemberGrantDefinitions.php:150-152` maps every Throwable to `committed_receipt_refused`. The test shows the hook was live: 0 calls during the receipt, then exactly 1 after `make('config')` (`MemberGrantDefinitionsTest.php:115-118`). It does not assert why the receipt was refused. The hook-free sibling positive and mutation A pin the cause. This limitation predates the batch.

**P3-4: fallout in unchanged test files is not yet excluded.** The batch covers only the p1 sweep (165 changed files). The p2 sweep of about 513 unchanged files was still running at 05:45 UTC (`s1/p2b*.log`, about 10% done). I checked three unchanged files that use container hooks and reach #127 code: `ProductionFeaturePlainConfigurationTest`, `PaidGrantDocumentJourneyTest` and `PaidGrantSourceJourneyTest`. Result: 23 tests, 344 assertions, OK. Remedy: don't treat the Foundation candidate as clean until p2 reports.

**P3-5: one "local-only" file has a production lane.** `MemberActivationReadConfiguration.php:94-97` admits `verified_production` provenance on MySQL in non-staging environments. Its staging verdict is correct, and it is the same gate as `MemberGrantPolicy.php:105-106`, which is already in `LOCAL_ONLY_FILES`. This is a naming nuance only.

## Assessment

1. **The #127 re-pins are sound.**
   - `design.md:42-44` asks for no lazy resolver in terminal proof and for the admission at `IdentityCommittedFrame` birth and current proof. The stricter refusal is intended.
   - No production code registers these hooks. Grepping app/, config/, bootstrap/ and routes/ finds only guards, never registrations. I also probed the booted app (console in testing, the HTTP kernel, console in production): no global callbacks, no db/config/env key in the alias, callback, extender or contextual maps, and no deferred db/config/env service.
   - The member boundary's own refusal is still tested. With its `finish_callbacks` loop removed (`MemberOriginalFinishBoundary.php:56-58`), three tests fail: `test_config_and_global_hooks_…`, `test_earlier_callback_work_…` and `test_contextual_…`.
   - Facts closure: only "committed identity_configuration" accepts `IdentityException`. The other five require exactly `MemberGrantException` (`MemberGrantFactsCallbackClosureTest.php:121-122`). All six assert no write at `:132`, before rollback.
   - Mutation A, the frame restored to 9cfe3a61: 21 tests, 3 failures, exactly the three re-pins (the hook ran 13 times against an expected 0).
   - Mutation C, A plus no boundary loop: 15 tests, 5 failures.
2. **#131 issuer tests.** The purge runs before the act, so a connection opened by the refusal still shows up. Mutation E (my own, different from the packet's): `DB::select` in `MemberOriginalSourceCapture::capture` and `DB::connection('sqlite')->getPdo()` in `beginMemberCapture`. Both tests failed (5 tests, 2 failures).
3. **Tax pin.** `config/production-tax-checkout.php` matches the pinned array exactly: ten keys in the same order, no `env(` call.
4. **Staging census.** None of the seven files contains `admitsTestCommerce`.
   - `MemberActivationReadConfiguration`: rehearsal provenance is admitted only in local/testing (`:94`); production provenance is refused in staging (`:95`). Staging refused.
   - `FreshTaxCheckoutPolicy`: `requireEnabled` runs first (`:28`), then local/testing (`:87`). Staging refused.
   - `PaidTaxPolicy`: local/testing at `:13` and `:40`; `retained` and `source` only check shape. Staging refused.
   - `PaidTaxRuntime`: local/testing at `:201`, checked at construction and in `prove`. Staging refused.
   - `PaidTaxDeliveryPolicy`: local/testing at `:37`. Staging refused.
   - `TaxHttpMount`: local/testing at `:64`, plus a literal `true` flag. Staging refused.
   - `FinancialInspectionPolicy`: local/testing at `:72`. Staging refused.
5. **No regressions; census unchanged.**
   - No skip was added or removed: no skip, requires, group or incomplete change in the test diff, and no diff under scripts/, app/, config/, routes/, database/, bootstrap/, .github/ or the lockfiles.
   - The old method name survives only in historical evidence under `docs/verification/member-originals-20261013/`.
6. **Nothing else found.** No other test asserts an empty connection map. `ProductionFeatureSourceClosureTest` and `InquiryNotificationWorkTest` only index or iterate connections.

## Commands and counts

All runs used the briefed SQLite command, one process at a time, in a scratch copy made with `git archive 7b290b8b` (vendor/composer, vendor/bin and autoload.php copied; the rest symlinked). Class resolution was checked to load from the scratch copy.
- Six touched files whole: 84 tests, 499 assertions, OK (1m34s), matching the packet.
- Mutations A–E as above. Every mutated file was restored and compared byte-for-byte with `cmp`.
- `test-focused-tests.py`: 53 tests, OK. `test-phpunit-shards.py`: 52 tests, OK.
- The worktree stayed clean at HEAD 7b290b8b. No commits, pushes or GitHub writes.

## Not verified

- I didn't run native MySQL; I rely on the packet's touched-method native runs (7 tests, 38 assertions).
- I didn't run the full suite, the p2 sweep or Foundation CI.
- I didn't rerun the packet's staging probe or M1–M3 exactly; I ran my own equivalents.
- The hook probe covered console bootstrap, one HTTP request to `/` (status 500, but it went through the kernel) and console in production. It did not cover queue workers or a real production `.env`.
