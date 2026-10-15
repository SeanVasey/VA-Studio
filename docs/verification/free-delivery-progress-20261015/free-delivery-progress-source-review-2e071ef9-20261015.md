# D1 repaired source review

DECISION: **APPROVE SOURCE WITH FINAL EVIDENCE CONDITIONS**

Reviewed immutable commit: `2e071ef9b4783beb99336968a8b73cd76f7c9cf5`. Open P0: **0**. Open P1: **0**. Required source corrections: **none**.

The two findings from the original `f1d5fb1f` source review are repaired. Account admission scans canonical existing slot locks, including live slots above the current allocation range after a configuration shrink, and refuses an unknown held holder. Clock observations must be native nonnegative integers before deadline or progress comparisons. Both changes preserve the original start, returning acceptance accounting and cleanup behavior.

The genuine old-source control at `80c174caa551ff18d619181e38415a80c8597901` retains all three `f1d5fb1f` application source files byte-for-byte. Its six selected cases produced **6 tests, 7 assertions, 3 failures, 1 error, 0 skips**, PHP exit **2**, recorder exit **1**, observed tool exit **1**. The failures expose high-slot admission and numeric-string/float clock coercion; the null clock produced the original TypeError. These nonzero originals remain retained.

At the reviewed commit, the whole three-file component selection passes **27 tests, 85 assertions, zero failures/errors/skips**. The seven whole affected SQLite files pass **34 tests, 313 assertions, zero failures/errors/skips**. Both original commands use PHP 8.4.26 / PHPUnit 12.5.34; the recorded SQLite runtime is 3.46.1. PHP and recorder exits are **0** for both; observed foreground tool completions are **21441 / 0** and **76963 / 0**. I independently matched commands, XML case/assertion totals, logs, process exits, canonical bundle identities, relevant committed source hashes, 2,460 input count and 13 physical loader hashes. Source, loader and runner stability receipts remain intact.

The new controls exercise high-slot same-account and unknown-holder refusal, a different-account positive case, failed-fill cleanup, strict clock typing, clock reversal, fixed zero-base startup without renewal and partial policy tuple refusal. Actual two-PHP-process tests establish holder publication and pending reservation during fill, continued account hold after preparation, ordinary close and explicit child process exit with owned residual reclamation. These are concrete overlap/lifecycle controls; they do not claim simultaneous-start contention, forced-kill coverage or host filesystem acceptance.

The affected domain controls preserve the global allocation bound using three distinct real fixture accounts. The account gate refuses the same fixture owner across roles and origins before copying while another fixture account succeeds. Residual storage controls use explicitly synthetic holder marks before actual domain redemption. Timing updates preserve healthy transfer beyond authorization TTL through 50-second returning chunks; below-rate delivery still refuses after the first MiB. The separately approved MAIN `ProductionCustomerAccess` test reference is the sole dependency pin update. Frozen production-free-v1 resource/profile bytes are unchanged. Existing held allocations retain their leases during a configuration change; this review does not claim immediate contraction of those allocations.

The specification and engineering-quality verdicts pass for this bounded source scope. Final evidence approval remains **partial**: the selected native MySQL domain case was pending when this decision was recorded, and the final sealed packet and publication gates still need their own reconciliation. No further source correction is required from this review.

Remaining conditions:

- Reconcile the native domain case and actual private-server lifecycle, retain all original failures, then seal and independently review the final packet.
- Bind any documentation-only publication head explicitly to the tested source; require cheap exact-head preflight, complete review/protection inventory and expected-head merge.
- Keep HTTP mount, first-byte reproof, blocked callback/send timeouts, source adapter, fonts, identity host evidence and commercial activation open.

I performed read-only Git/source and standard-library hash/XML receipt inspection, with no application, database, network, CI or duplicate test execution. An initial mechanical assertion treated pretty-printed JSON bytes as the runner's canonical JSON digest; inspection of `canonical()` corrected that reviewer assumption and all corrected checks pass. No source or original evidence changed. The structured companion records exact commands and original hashes.
