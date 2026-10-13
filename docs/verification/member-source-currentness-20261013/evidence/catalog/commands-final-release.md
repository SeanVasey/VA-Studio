# Catalog counter development evidence — interim

Frozen candidate `a58d53ef0a8303e59b984e5c5a4a0222f815a66b`, base `0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371`, isolated worktree `/workspace/VA-Studio-member-catalog-currentness`. Exactly four owned product/test files; no shared schema, CI, fixture, member policy, Rows or frozen contract asset edits.

Only pure PHPUnit helper cases have executed. They extend `PHPUnit\Framework\TestCase`, not the Laravel application base. No feature/SQLite/native DB workload has run from this lane yet. Native source and old-source comparison are prepared, not a passing claim.

## Local preparation and commands

Private `vendor/composer`, `vendor/bin` and autoload entry copied into this worktree; dependency package directories point at existing local installed packages. `composer dump-autoload --no-scripts --no-plugins --optimize` exited0; its known historical fixture PSR-4 skip warnings are retained in `autoload.log`, not test skips. It generated11,231 classes. A read-only ReflectionClass call confirmed the helper resolves to this worktree, not another source tree. No package installation/network work was required.

Exact pure runner is retained as `run-pure.py`. It records actual PHP argv with explicit `_composer_autoload_path=getcwd()/vendor/autoload.php`, runner/source hashes, JUnit/log/exit and unchanged before/after sources. Invocations, all with `/workspace/.va-studio-toolchain/activate.sh` sourced and this worktree as cwd:

```bash
python3 /workspace/runtime/membership-catalog-currentness-evidence/run-pure.py pure-initial
python3 /workspace/runtime/membership-catalog-currentness-evidence/run-pure.py pure-quote-boundary
python3 /workspace/runtime/membership-catalog-currentness-evidence/run-pure.py pure-engine-boundary-red
python3 /workspace/runtime/membership-catalog-currentness-evidence/run-pure.py pure-engine-boundary-green
python3 /workspace/runtime/membership-catalog-currentness-evidence/run-pure.py pure-final
python3 /workspace/runtime/membership-catalog-currentness-evidence/run-static.py
```

| Invocation | Tests | Assertions | Failures | Errors | Skips | Exit |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| pure-initial |60|70|0|0|0|0|
| pure-quote-boundary |61|71|0|0|0|0|
| pure-engine-boundary-red |62|72|1|0|0|1|
| pure-engine-boundary-green (name predates outcome) |62|71|0|1|0|2|
| pure-final |63|73|0|0|0|0|

The intended red exposed a draft-helper defect: a complete quote-mode interpretation failing the engine predicate could be discarded while another interpretation chose a different table-body boundary. The fix compares complete lexical boundaries first and requires all complete interpretations to pass the engine/options gate. Exact red source bytes are retained in `pure-engine-boundary-red-source/`.

The first tightened-helper invocation exposed a wrongly positive fixture containing two complete, conflicting outer-body interpretations. That actual error is retained, with exact bytes in `pure-engine-boundary-green-source/`. The same ambiguous string was added as a required refusal, and an unambiguous backslash-escaped string remains a positive. No successful native-format claim is made by this synthetic corpus. Final63/73 passes with source hashes matching the frozen commit.

Initial `vendor/bin/pint --test` over the four files failed on `single_quote` in the new unit file; `pint-initial.log/.exit` retain it. `vendor/bin/pint tests/Unit/MembershipCatalogDefinitionTest.php` fixed only that owned style issue (`pint-fix.*`). The intermediate Pint pass remains in `pint-final.*`; final four-file style and four PHP syntax checks are retained by the exact `run-static.py` runner in `static-final.json` plus logs/exits. All final exits0 and sources unchanged. `git diff --check` and staged `git diff --cached --check` both exited0. No style repair was made to another agent's file.

`quote-ambiguity-probe.log` is a separate read-only ReflectionMethod diagnostic of the earlier helper, showing two admitted structural spans (body_end86/counter101..118 vs body_end44/counter59..76). It is not a later native or final-parser run. Its exact command exists in the tool transcript; no standalone command file was retained.

## Planned native regression

`native-red-plan.json` and `native-red-planned-source/` bind the exact old0d `MembershipCatalogEvidence.php` and the unchanged new native test body. The old comparison will preload that one exact historical class while all other source comes from the candidate; report it as an explicit single-class overlay, not an imaginary old commit containing the new tests. The new helper is unused by old Evidence. Expected old failure is `changed_catalog_source` after real independent committed auto-id insertion, proven raw counter movement, and unchanged selected rows. A setup timeout/error is not an intended red.

The three new native methods cover unrelated draft insertion followed by selected-row refusal, real table-comment mutation in the committed read-only frame, and identical-row temporary shadow after a positive original proof. They currently have syntax/Pint coverage only. Native8.4.11 version and new-schema ownership must be recorded at execution, after the shared heavy slot is explicitly released. Required existing eligibility neighbors will run in the coherent fixed-source selection; no native or SQLite result is invented here.

## First native invocation — setup error retained

At frozen receipt-source `822a848138d5a209ff293e7fc8886680e361a4c0`, `python3 /workspace/runtime/membership-catalog-currentness-evidence/setup-native.py` verified the existing private container, port33413, owned datadir/socket and MySQL8.4.11, asserted the new schema was absent, then created only `vaseyaudio_member_catalog_20261013`. Its full script/hash and `native-setup.json/.log/.exit` are retained.

Exact first test invocation (toolchain activated, own worktree cwd):

```bash
python3 /workspace/runtime/membership-catalog-currentness-evidence/run-native.py --name native-old-source-red --old-source
```

Actual result: **1 test / 4 assertions / 1 error / zero failures or skips, exit2, 83.056seconds**. The independent INSERT encountered MySQL1205 at native test line72 while the original selected catalog transaction remained open. The intended counter mutation/reproof was never reached, so this is **setup error, not intended P2 red and not acceptance**. No root cause is proven from that timeout alone; catalog locking complete-set reads and mutation triggers exist. All1,961 captured source hashes and historical overlay bytes remained unchanged. The exact error/JUnit/commands/source receipts are retained under `native-old-source-red.*`.

A fixture-only post-commit currentness correction is being coordinated; no fixed-source native selection has run yet.

## Native fixture correction, pending execution

Candidate `982f4ea69ed8d2191345cd8c752377da19780a89` changes only the new native test from receipt-source822a8481. Product/helper source remains exacta58d53ef. It captures the original real snapshot, ends the original locking transaction, independently commits the unrelated auto-id insertion, and compares the SAME original snapshot/files/deadline in `IdentityCommittedFrame`. This is the actual post-commit receipt path, not authority-token reuse or an extended lease. A fresh separately admitted current frame owns the selected-row negative. Root approved this narrower fixture; no need to defeat intentional open-frame locks.

Native test syntax and Pint commands, each exit0, are retained under `native-window-syntax.*` and `native-window-pint.*`:

```bash
php -l tests/Feature/ProductionMembershipBilling/MembershipNativeCatalogCurrentnessTest.php
vendor/bin/pint --test tests/Feature/ProductionMembershipBilling/MembershipNativeCatalogCurrentnessTest.php
```

`native-window-source.json` and `native-test-982f4ea6.php` bind the exact replacement test. The initial timeout, its command/source and earlier native test capsule stay retained. The next old-source invocation and fixed-source invocation must load this exact same test body. The negative exception reason is printed before rethrow, so generic setup errors cannot count as intended red.

The final cost-scoped plan is native red1, fixed native5 (new3 plus existing private-byte/deadline and committed-snapshot positives), then whole existing eligibility SQLite16, all serial. No actual native engine transition is claimed; engine distinctions are pure-corpus only. The other existing revision/rights/callback variants are planned whole-file SQLite behavior coverage and remain unexecuted natively in this batch. Root owns a later integrated real-reservation canary. R4 temporarily owns the native slot while this correction undergoes independent methodology review.

## Corrected old-source regression — intended refusal observed

After R4 explicitly released the heavy slot, the frozen candidate was `982f4ea69ed8d2191345cd8c752377da19780a89` with clean worktree. Exact invocation (toolchain activated, own worktree cwd):

```bash
python3 /workspace/runtime/membership-catalog-currentness-evidence/run-native.py --name native-old-source-red-v2 --old-source
```

Actual result: **1 test / 12 assertions / 1 error / zero failures or skips, exit2, 79.314seconds**. This time the independent connection225 committed the real unrelated draft INSERT after the original transaction ended; connection224 retained the original snapshot. Raw `SHOW CREATE TABLE tracks` changed only the table counter2→3, and the selected plan rows stayed equal. Reproof of the same original snapshot inside the committed read-only frame then threw the exact printed reason **`changed_catalog_source`** from the historical Evidence class. The uncaught domain refusal is a PHPUnit error, not an assertion failure. This is the intended old-source P2 regression, distinct from the earlier1205 setup error.

All1,961 captured source hashes stayed unchanged; the exact historical Evidence overlay SHA stayed unchanged. `native-old-source-red-v2.source.json` binds the precise argv, current test/helper source and single-class old-source overlay. The frozen native test body is identical to that being used for fixed-source execution. No claim is made about a concurrent insert completing while the original locking transaction remains open, and the original snapshot deadline was neither renewed nor extended.

## Fixed native selection — completed

Exact invocation (toolchain activated, own worktree cwd):

```bash
python3 /workspace/runtime/membership-catalog-currentness-evidence/run-native.py --name native-fixed-five
```

On private MySQL8.4.11, frozen `982f4ea69ed8d2191345cd8c752377da19780a89` completed **5 tests / 46 assertions / zero errors, failures or skips, exit0, 552.033seconds**. All1,961 source hashes stayed unchanged, and all captured source bytes match the corrected old-source invocation except that the historical single-class overlay is absent. The same exact native test body produced the old refusal and the fixed positive.

JUnit leaves: unrelated insert/original snapshot plus selected-row negative18 assertions; real table COMMENT negative11; identical-row local temporary shadow7; existing private-byte/original-deadline check5; existing committed read-only snapshot positive5. Logs retain raw SHOW strings, distinct native connection IDs, actual counter delta, equality of selected plan rows, and exact negative reasons. The unrelated insert succeeds in the real post-commit receipt window; this does not demonstrate insertion while the original transaction keeps its locking footprint. All five tests exercised native SQL, but no real engine transition was executed.

## Whole existing SQLite eligibility file — completed

Exact invocation (toolchain activated, own worktree cwd), after native selection ended:

```bash
python3 /workspace/runtime/membership-catalog-currentness-evidence/run-sqlite.py
```

The whole `MembershipEligibleLicenseProofTest.php` completed **16 tests / 123 assertions / zero errors, failures or skips, exit0, 73.441seconds** at frozen982f4ea6. All1,961 captured source hashes stayed unchanged. This is the complete existing behavior file, including original identity/revision, rights, private bytes, configuration, clock and callback cases. These SQLite results do not imply native execution of every case; the exact two native neighbors are listed above. The new native-only file was not executed on SQLite and contributes no reported SQLite skips.

The heavy/native slot was explicitly released to root and R4 after the process exited. No database workload remains in this lane. Frozen four-file source is the three-commit chain a58d53ef →822a8481 →982f4ea6; the final two commits modify only the native test. Product/helper/unit final bytes still match the retained63/73 pure run. Final acceptance is reported separately as pure63/73, SQLite16/123, and native5/46; overlapping cross-driver identities are not summed into a misleading unique-case claim. All initial style, pure assertion, pure fixture-error and native setup-error receipts remain visible.

Final evidence integrity reconciliation is implemented by the retained `finalize-receipts.py` script and invoked as:

```bash
python3 /workspace/runtime/membership-catalog-currentness-evidence/finalize-receipts.py
```

It parses every completed JUnit leaf, verifies exact exit/counts, binds the pure/native/SQLite acceptance inputs to frozen982f source, proves red/green captured source inventories identical with only the explicitly loaded historical class differing, and writes `final-verification.json` plus `artifact-digests.json`. Artifact hashes describe local retained bytes at sealing, not a real host/provider verification claim.
