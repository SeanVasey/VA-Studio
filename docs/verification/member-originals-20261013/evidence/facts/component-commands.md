# Facts lane local receipts — in progress

Worktree `/workspace/VA-Studio-member-original-facts`, synthetic environment only. Toolchain `source /workspace/.va-studio-toolchain/activate.sh`; PHP 8.4.26. No host/provider/customer claims. No native tests run by this lane yet.

Pure documents/records command (three distinct runs):

```sh
php -r 'require "vendor/autoload.php"; exit((new PHPUnit\TextUI\Application)->run(["phpunit", "--colors=never", "--log-junit", "/workspace/runtime/member-original-facts-evidence/documents-records-<RUN>.xml", "tests/Unit/ProductionMemberOriginals/MemberGrantDocumentsTest.php", "tests/Unit/ProductionMemberOriginals/MemberGrantRecordsTest.php"]));'
```

- `first`: 28 tests /64 assertions, 1 failure, no errors/skips, exit1. Changed released renderer descriptor correctly refused, but propagated `ContractIssuanceException`; test required `MemberGrantException`. Narrow exception boundary now maps it. Other outcomes were not relabelled.
- `fixed`: same 28/64, zero failures/errors/skips, exit0. 0.279s.
- `final`: same 28/64, zero failures/errors/skips, exit0 after scoped Pint, 0.278s. Bound to nine-file source commit `dfc78527` and renderer dependency `2333b9da` cherry-picked as `3d518cc7`. Records tests boot plain application config/encryption only; no migration/DB body.
- Initial intended lint command mistakenly targeted missing `MemberGrantDocuments.php`, with stderr redirected and `|| true`. That command is not passing lint evidence. Actual ten drafted PHP files later individually linted by `php -l` with nonzero exit propagated, recorded in `draft-lint.txt`.

`documents-pint.txt` shows scoped formatting edits; `draft-pint.txt` covers remaining owned ten draft files. Schema child evidence is separate under `schema/` and includes first syntax-error history, unchanged installer byte comparison, pure checks and source commits.

The `sqlite-schema-authoring-canary.sh` command is the granted first DB-backed selection: whole six-case schema file plus one real MFA authoring positive canary. Source-bound before execution. E4 terminal finish callback admission remains pending, so this positive check is not complete callback closure evidence. No native tests or other feature expansion belongs to this grant.

First granted SQLite selection completed at base7721d762 + exact `canary-source/` draft snapshot: 7tests/17assertions, 1error, zero failures/skips, exit2,18.765s. All six schema methods passed; the authoring canary returned `committed_receipt_refused`. Two diagnostic reruns were retained separately: wrong-catch instrumentation 1/0/error1 (no diagnostic generated); corrected committed-catch instrumentation 1/0/error1 showed `staff_refused` at committed Gate call. Original Gate argument requested `lockForUpdate=true`, whose existing gate refuses Laravel level0; the native read-only frame intentionally uses Laravel level0. Correction selects existing nonlocking Gate branch (`false`) only in committed staff lookup; current locking MFA and exact native row comparisons are unchanged. Class/stack/reason-only diagnostic contains no ciphertext or source row values.

Affected positive canary after correction: 1test/9assertions, zero failures/errors/skips, exit0,3.839s. Full source of its one changed application file is `authoring-canary-fixed-source.php`; other executable inputs match canary-source manifest. Exact command is the same explicit-autoload PHPUnit invocation with `--filter test_real_enrolled_staff_authors_and_deduplicates_without_customer_gate_or_grant_writes` and only `MemberGrantDefinitionsTest.php`, logs prefixed `authoring-canary-fixed`.

Schema source commits6b76eea8/3561eee8; granted selection did not execute either native-specific case. E4 complete component3cc2a0d3 cherry-picked7721d762 was integrated before this first canary, but standalone negative-hook test remained pending at this receipt. No native/host/provider proof belongs to this run.

Whole authoring/facts first invocation (`sqlite-authoring-facts-first.sh`): 9tests/39assertions, error1, no failures/skips, exit2,29.489s (failfast stopped remaining three facts cases). JUnit component breakdown: Definitions whole8/34 zeroall25.233279s; Facts first1/5 errored at teardown because SQLite pin remained referenced. The issuer WeakMap value mistakenly held its own proof key strongly. Correction removes that self-reference; only the separately registered live closure retains the original proof. Its retirement/consumption releases that reference. Added explicit WeakReference Rows-release regression; complete fixed Facts4/17 zeroall exit0,16.070s. This is a product lifetime correction, not teardown suppression.

After frozen DB source completed, aligned the new member document authored_source bound to the released renderer's 65536-byte limit (the historical catalog validator remains unchanged). Same-API pure case at65537 failed on prior member source1/1/failure1; corrected whole documents/records30/66 zeroall exit0,0.259s also proves65536 bytes retained exactly. Scoped final Pint12files passes and diff check passes.

Final reviewable own implementation commit `bd3661ec02e3004fef3ae3d8a990e44ef5c6ce08`; clean worktree. Depends on own6b76eea8/3561eee8 and dfc78527, renderer cd6aefe9 plus real FinishBoundary3cc2a0d3. The source-cap change does not touch DB phase logic; prior authoring/facts bodies used tiny synthetic strings and are carried by exact branch/code comparison. No native/verified-host claim; native old2/fixed4 schema selection prepared, not yet authorized/executed at this note.
