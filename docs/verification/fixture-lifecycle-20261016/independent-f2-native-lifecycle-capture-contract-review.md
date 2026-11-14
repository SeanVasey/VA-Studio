# F2 native lifecycle evidence-capture contract

**DECISION: ACCEPT READ-ONLY DESCRIPTOR CAPTURE FOR THESE THREE ARTIFACTS WITH OWNERSHIP, PROVENANCE AND COMPLETENESS CONDITIONS.** Design advice only; no execution/calibration/native acceptance. No shared edits, tests/database/network/CI execution or delegation. Implementation belongs after actual PR131 merge; MySQL300/SQLite90 remains a candidate subject to the earlier budget contract and completed calibration.

The reviewed parent/child baseline is `c4a0bbe33ec815e4e90d4d6251b2fdff9e33f787`. During this review the parent advanced to `c96da8e29999838ca14477e7dc8f236d6f00ad4a`; its narrow timeout/marker propagation delta preserves these artifact writers, assertions and finally cleanup. This capture advice does not grant implementation or calibration acceptance to that moving next-batch source.

On POSIX/Linux, PHPUnit opens JUnit with `wb` before tests and flushes XML through the same stream. EventLogger and the fixture append events/receipts with FILE_APPEND/LOCK_EX. A read-only descriptor acquired before parent unlink retains that original inode and can recover final bytes after writers finish while the directory still disappears normally. Polling alone does not guarantee acquisition or completeness.

Required conditions:

- Start the observer before launching the parent. Bind the canonical effective storage root/baseline inventory and correlate each new UUID directory with the owned parent/child ancestry, exact argv/log destinations and dataset. New name plus matching UID alone is insufficient ownership. Admit only junit.xml/events.log/receipt.jsonl; do not read arbitrary new directories or secret environment values.
- Use pinned directory descriptors and no-follow/read-only/close-on-exec opens. Verify file/directory type, owner, device/inode and pathname identity at acquisition. Never precreate files, consume child stdout pipes, lock/rewrite artifacts, or prevent source directory cleanup. Monitor replacement: an obsolete inode descriptor cannot silently represent the final pathname. Bind the actual PHPUnit/writer/source versions that support append/same-stream behavior.
- Copy from offset0 only after the parent and all known child writers have terminated, not at first EOF/first receipt or an apparently stable size. On timeout, establish the owned child's terminal/cleanup state first. Record final descriptor size/mtime/link count/hash and actual directory disappearance. Bound observer time, directory/file sizes and FD count; close all descriptors in finally. Missed files, inode replacement, oversize or observer errors remain evidence failures, separately from parent PHP results.
- Successful parent datasets require complete sets: unchanged ordered two methods, exactly two post-cleanup receipts, expected alias/view/state fields, exact deliberate body error and unchanged event-warning criteria. Empty/missing/malformed artifacts remain partial/missing with NULL usable counts and original errors. Never count child cases as additional parent acceptance or turn interrupted prefixes into complete results.

Three descriptors alone do **not** independently record successful child stdout/stderr or OS exit status. The parent holds those internally and asserts exit0/2. A passing parent supports explicitly derived, parent-asserted child exit evidence; it is not a raw child-exit capture. Preserve parent stdout/stderr/result and expected-error JUnit separately. Do not compete for pipe bytes, which would alter Symfony Process observations. If raw child output/exit receipts are required for a later claim, arrange a separately reviewed noninterfering capture before claiming them; this plan alone is insufficient for that expanded scope.

Provenance: exact parent/child commands, source/test/loader/observer/runtime hashes, admitted driver/schema and captured local/CI declaration, actual process IDs/dataset mapping, acquisition/final inode metadata, observer timestamps, original copy hashes and parent results. Keep raw failure/object dumps privately hash-bound and distinguish derived summaries. Preserve native4/E4 and prior1/E1 failures. No hostile-filesystem race sandbox, host performance, full F2/counterpart/native matrix/provider/release acceptance is established.

Immutable read bindings:

- `/tmp/f2-native-lifecycle-capture-plan-20261016.md`: `f8a67ff53ec8f0374a2909ce8d96fd72bb695886154308daae57c7b2c4208189`.
- `/tmp/f2-native-lifecycle-budget-contract-review-20261015.md`: `c317e7d708ad1d961e70d419bef7cfa1ecbf48acaf55227f2fc4d990e023dc42`.
- `c4a0bbe3:tests/Feature/FinalizationDatabaseLifecycleTest.php`: `83fcde0b2f3ccbbe7b81978ca362281cdb79a69e7ef1347873c4484ec45cc522`.
- `c4a0bbe3:tests/Support/FinalizationLifecycleFixture.php`: `7ef29a58fae5d0ce0a81b5c381d9261824b773432bd6051de93b120d91f852f6`.
- `c96da8e2:tests/Feature/FinalizationDatabaseLifecycleTest.php`: `d571195075cd5dff116a04057bc094f65ad171e046d4b50e1d80709d28473fd7`.
- `vendor/phpunit/phpunit/src/Logging/JUnit/JunitXmlLogger.php`: `d9fa2022c384027fe8a7ef13ac06b3597cad245e343584a8dacc99438a20f0aa`.
- `vendor/phpunit/phpunit/src/Logging/EventLogger.php`: `5df801a21e318f1ac6b163fad003d05470cb7dac76b2492ac627e8b6083ba6d8`.
- `vendor/phpunit/phpunit/src/TextUI/Output/Printer/DefaultPrinter.php`: `de2f8f15210bee77c45028863d41c627b656a51071baac502c962bb666e54402`.

Preparation note: a final write-time check detected that the parent source had advanced after the first read; no report was created by that failed check. Baseline/observed immutable SHAs above keep this advice separate from execution approval.
