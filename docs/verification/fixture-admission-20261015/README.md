# F2a: shared fixture admission and captured finalization cleanup

The implementation captures the external testing/database declaration before application bootstrap, checks configured and actual PDO identity before Laravel's database traits, and checks declared transaction/truncation targets and listed database artisan commands. Owned finalization setup rechecks after its hook, executes one captured target/options recipe, and rechecks that original target before cleanup. Mutable defaults, recipe, environment and another physical PDO do not authorize another cleanup target. Bookkeeping resets even on cleanup refusal.

SQLite admission retains only actual `:memory:`. Local MySQL requires `VA_LOCAL_DISPOSABLE_MYSQL` exactly equal to the dedicated selected schema; the existing CI marker/CI=true pathway remains separate. This marker is an operator declaration, not private-server/host proof. The existing lane-specific DisposableNativeDatabase checks remain unchanged. Controlled product-mode fixtures retain external APP_ENV=testing: required production/staging and optional local enrollment behavior remain unchanged. Native mode-binding probes change application mode after boot; the existing SQLite panel suites supply actual route-boot evidence.

The subsequent compatibility correction also supplies an explicit owned-memory path for the two existing migration-ownership canary files. TestCase creates a fresh internal alias with literal SQLite/:memory:/empty URL and no inherited read/write override, after admitting its current target. It retains the Connection and actual PDO, checks current config/cache equality, and refuses a purged/replaced connection or PDO even when the replacement is another memory PDO. Unregistered aliases still require the captured external selection. Cleanup purges only the owned alias. This preserves SQLite ownership canaries inside the native lane without treating them as native DDL or weakening the primary MySQL declaration.

This is bounded F2a, not an SQL sandbox. Arbitrary raw worker SQL, bootstrap callbacks, later custom truncation hooks/redirects and complete fixture/counterpart normalization remain outside this boundary. All 25 reviewed SQLite-only methods, their native counterparts and full acceptance remain F2 follow-up work. No application/migration/schema/contract/provider/activation behavior or frozen member/manifest is edited.

## Original execution

| Original | Actual source | Tests / assertions | Failures / errors / skips | PHP / recorder |
| --- | --- | --- | --- | --- |
| Initial malformed probe, not regression proof | 8b435917 | 8 / 8 | 8 / 0 / 0 | 1 / 1 |
| Corrected fixture on old admission source | 0d307a57 | 8 / 8 | 8 / 0 / 0 | 1 / 1 |
| First fixed boundary | b502df69 | 8 / 72 | 0 / 0 / 0 | 0 / 0 |
| Expanded hook/cleanup control exposed cached-config omission | 892144b3 | 14 / 132 | 1 / 0 / 0 | 1 / 1 |
| First cache comparison rejected parser-removed empty URL | 3810fd90 | 14 / 0 | 0 / 14 / 0 | 2 / 1 |
| Corrected identity comparison | aeee2f4d | 14 / 135 | 0 / 0 / 0 | 0 / 0 |
| New selection plus existing native-admission unit files | 4dd6bc19 | 26 / 49 | 0 / 0 / 0 | 0 / 0 |
| Initial native identity scope before support repair | 4ff10a05 | 8 / 15 | 0 / 0 / 0 | 0 / 0 |
| Whole existing MFA/route-boot/lifecycle SQLite files | 4ff10a05 | 12 / 181 | 0 / 0 / 0 | 0 / 0 |
| Prior cleanup recipe and admission source | 5b35b787 | 6 / 34 | 4 / 0 / 0 | 1 / 1 |
| Whole current boundary/selection/unit files | 6098bdc2 | 40 / 184 | 0 / 0 / 0 | 0 / 0 |
| Whole existing native lifecycle, failed original child limits | 6098bdc2 | 4 / 8 | 0 / 4 / 0 | 2 / 1 |
| Matching prior-source default-success native control | 4aa1a434 | 1 / 2 | 0 / 1 / 0 | 2 / 1 |
| Current native identity after complete runtime repair | 733c2c9f | 8 / 15 | 0 / 0 / 0 | 0 / 0 |

Each closed process, recorder and foreground tool exit is retained; scopes and repeats are not summed. The 733c tree equals tested6098 exactly. Implementation approved at4ff is unchanged; the narrow subsequent changes declare the shared driver helper, synchronize observed discovery comments and make the old setup-hook probe body well formed. Frozen proof checks56unique declared member/manifest paths across7profiles against actual PR130 merge by bytes; it does not re-pin or certify a new profile.

The genuine old admission cases reach the framework helper/body with child exit0; the parent expects refusal and fails all8. The original malformed probe instead exits255 and is unusable as that regression. An early manually prepared receipt prematurely stated repeat0; actual syntax255/Pint1/failed original contradict it, and the explicit reconciliation supersedes that statement. No accepted pass derives from that receipt. The genuine older cleanup scope fails4of6; two positive controls remain. Current40/184 proves pre-trait refusal, immutable selection, actual PDO checks and captured cleanup recipes through real child PHPUnit boundaries and command spies. The spies execute no destructive SQL.

Native lifecycle remains **failed/unaccepted**. Every current child exceeded its unchanged90-second limit; parent4/8/E4 is not a pass or completed child lifecycle. The exact older default-success dataset also times out at90.468seconds, proving a pre-existing local limit for that dataset only. It does not prove the other three prior-source cases or improve throughput. SQLite12/181 and native identity8/15 do not replace native lifecycle acceptance. A related fixture timing/admission follow-up and exact final Foundation candidate must reconcile this condition without weakening assertions. Full native matrix/concurrency, HTTP/SAPI/browser/host/provider and release acceptance remain untested here.

## Runtime, quality and review

PHP8.4.26/PHPUnit12.5.34, SQLite3.46.1 and actual standalone MySQL8.4.11. New own datadir/socket/loopback33524/schema; original Docker launch125 and copy/create failures from exhausted root storage are retained. Two exact completed stopped task containers were removed only after private metadata/log retention, without volumes; both bind datadirs/images/source/evidence and the unrelated running policy server remain. This restored1.6GBworkspace space. No unrelated work or retained database was deleted.

The standalone executable/101runtime libraries and43support files are copied from the identical pinned MySQL image, with hash/provenance receipts. Initialization0 preceded missing-support warnings; first start1 lacked its packaged file-I/O directory. Corrected source-independent recipe disables file import/export, restores exact messages/ICU support and scopes custom libraries to mysqld. Earlier PHP version-probe extension warnings are preparation records; final version/regex probe0 has empty stderr. Current native8/15 uses the repaired runtime. Final normal SQL shutdown0, socket/PID disappearance and logged shutdown are recorded; datadir retained. No host or performance claim.

Pint8files,52shard-policy safeguards and diff check pass0 with stable source. First discovery attempt rejected its unsupported prefix1; corrected supported command0 lists2431selected cases/233files of9189discovered/677files across8shards.18generated outputs are exact-hash archived and removed; discovery executes no cases. Five new exact native-only SQLite methods and one shared driver helper are declared; workflow changes are census comments only. Foundation/GitLab were not dispatched.

Independent contract guidance and source DECISION at `4ff10a05498079cbcb5b9b65c4c546a0d1d6d158`: **APPROVE BOUNDED SOURCE WITH FINAL EVIDENCE CONDITIONS**, no P0/P1 or required correction. The source writer does not self-review. Final separate review at `e4aeffe70b6ba4ca5ce12a08253697d8bb4c3a08` records **APPROVE BOUNDED DEVELOPMENT SOURCE AND RECORDED FOCUSED EVIDENCE; NATIVE LIFECYCLE REMAINS OPEN**, no P0/P1 or required correction. Byte-original [decision](independent-final-review-e4aeffe7.json) and [report](independent-final-review-e4aeffe7.md) are imported unchanged. Native lifecycle remains failed/open4/8/E4; the old1/2/E1 control supports only one prior timeout. Exact-head documentation carry, cheap preflight, complete inventory/protections and expected-SHA merge remain pending.

No real staff/provider/payment/customer-active import/host/deploy/DNS/cutover action. Free256/B4/frozen metadata/membership P2s and commercial/legal/identity/activation/release conditions remain open.

Sealed originals:14PHP invocations/271members/2573499bytes, SHA256 `7976add384769b1e6b5c8c701ffce9158e7928cc6a68790b59568283964293f6`. Failed raw outputs/runtime originals remain privately hash-bound; labeled derived outcomes retain original counts and failures.

## Preflight failure and census correction

PR #131 was marked ready at `5ef21c28d82a56197469d1222bf119b93aac9c41`. Initial run `38821001027` was cancelled by readiness handling. Replacement run `38821013983` genuinely failed: backend-quality failed in “Verify CI routing and focused-selection safeguards”; scope/frontend passed, documentation skipped, and the aggregate failed. This second run is not a cancellation artifact or a passing check.

The local reproduction at that exact old head failed one of 50 focused-selection tests: inserting the five new native-only methods into the existing census shifted its deliberately pinned first 150 entries. Correction `07a256d2bc6acd576b4e4eae6c3462da6f110859` preserves all 260 original methods in their original order and appends the same five methods. The selected method set, frozen prefix hash `d93da4e7a13a8c6aff8f97f363eb6d93dde884bad53c9ae50eeb0ed32514c71c`, and assertions remain unchanged. Only this policy JSON differs from the approved source; application/PHP/test/workflow files and the original evidence archive remain unchanged. Full manifest equality is not claimed after this policy-input change.

At clean/stable `07a256d2`, exact commands `python3 scripts/ci/test-focused-tests.py`, `python3 scripts/ci/test-phpunit-shards.py`, and `python3 scripts/ci/test-php-test-runtime.py` pass with 50, 52 and 5 tests respectively, no skips/failures/errors, each process exit0. The PHP check uses the activated PHP8.4 toolchain. The original nine-command local reproduction also had four failures among five runtime tests because PHP was absent from PATH; this local setup error is retained and is not identified as the hosted CI cause. Its other seven commands exited0, including the macOS bootstrap check with one explicit skip. Those unchanged safeguards are carried separately, not reported as a passing nine-command original.

[Correction index](prefix-correction-index.json) binds eight byte-original records/logs in [correction evidence](prefix-correction-evidence.zip), SHA256 `09621ff23038d6071824399157ba14111a07b89cbd26b6cd5fd936544b20e5ba`. Two unsuccessful hosted-log downloads remain privately hash-bound because their errors contain temporary signed URLs; no raw URL is published. The diagnosis is a local reproduction of the observed failing step, rather than a claim to possess the unavailable hosted traceback. Native lifecycle remains failed/open; no application/native cases were rerun for this order-only correction. Separate correction review at `07a256d2bc6acd576b4e4eae6c3462da6f110859` records **APPROVE NARROW SKIP-PREFIX CORRECTION AND SOURCE/EVIDENCE CARRY; NATIVE LIFECYCLE REMAINS OPEN**, no P0/P1 or required correction. Byte-original [decision](independent-prefix-review-07a256d2.json) and [report](independent-prefix-review-07a256d2.md) are imported unchanged. Exact documentation-head carry and a fresh cheap preflight remain pending.

## Two reached P1 compatibility findings

Cheap preflight `38822518947` passed at `0db6b8709db5084d0d8113a81184286738afee75`, but the complete final inventory found two current P1 threads and the merge gate refused publication. [Owned SQLite finding](https://github.com/SeanVasey/VA-Studio/pull/131#discussion_r4289240899) and [focused MySQL finding](https://github.com/SeanVasey/VA-Studio/pull/131#discussion_r4289240904) remain open pending final evidence review/publication; the green old head is not sufficient to merge.

Source `305e6522532b7e54b62b10ca7314011fdffc3916` supplies the owned-memory path, unchanged canary bodies/compile-only MySQL grammar, and focused runner/workflow declaration. The runner clears inherited socket/local marker, sets `VA_CI_DISPOSABLE_MYSQL=1` only for its MySQL service and empty for SQLite, and preserves actual CI without inventing CI=true. Five new shared cases prove distinct owned memories, actual artisan cleanup and refusal on config/file-PDO/other-memory-PDO/purged connection while retaining original/file sentinels. One native-only case proves an unregistered memory alias cannot borrow the MySQL selection; its exact census method is appended without shifting the pinned prefix.

| Original PHP invocation | Actual source | Tests / assertions | Failures / errors / skips | PHP / recorder / foreground tool |
| --- | --- | --- | --- | --- |
| Both whole canary files on old source, native outer selection | 0db6b870 | 46 / 0 | 0 / 46 / 0 | 2 / 1 / 1 |
| First whole eight-file SQLite compatibility selection | 9470f1d9 | 103 / 1318 | 0 / 0 / 0 | 0 / 0 / 0 |
| Whole native-outer canaries, shared admission and native identity/refusal | 305e6522 | 74 / 1105 | 0 / 0 / 0 | 0 / 0 / 0 |
| Actual focused environment, disposable marker removed | 305e6522 | 1 / 0 | 0 / 1 / 0 | 2 / 1 / 1 |
| Actual focused environment, marker present | 305e6522 | 1 / 2 | 0 / 0 / 0 | 0 / 0 / 0 |
| Final whole eight-file SQLite compatibility selection | 305e6522 | 103 / 1318 | 0 / 0 / 0 | 0 / 0 / 0 |

Native-outer74 comprises46SQLite canaries/932assertions,19shared admission cases/156, and9actual native identity/refusal cases/17; it is not74native DDL/race cases. The focused proof calls actual `runner_environment()` with CI=true and schema `vaseyaudio_focused`, remapping only the transport to owned loopback33524/socket/blank synthetic password. The missing/present control differs only by the disposable marker. It does not execute hosted Focused feedback. Final SQLite103 finishes in175.597seconds and native-outer74 in145.259seconds; these are observed fixture durations, not performance acceptance. Repeated scopes are not summed.

New focused safeguards52/two intended failures on test-only9738 retain the old runner/workflow omission. At clean305e, focused52/shard52/Pint6 pass0; workflow-cadence14 at9470 is unchanged-policy carry. Actual listing0 at305e reports2432selected/233files of9195discovered/677files;18generated originals are retained by hash and removed. Later `e538c4e8` changes only the two discovery comments to these observed counts. No test case executes in listing and no Foundation/GitLab dispatch occurred.

Same owned standalone MySQL8.4.11 runtime was restarted with unique P1 receipts, regular-expression support/version probe0, and private schema creation0. Normal shutdown0, socket/PID disappearance and datadir retention are recorded. All56frozen member/manifest paths still match actual PR130 merge by bytes. Six new PHP invocations are sealed in112members, SHA256 `5e7c05f42806b4360040c3837c52cacdd277fc6c87109c0a56e32d9274db14a0`; four failed raw outputs remain privately hash-bound. [Index](p1-compatibility-index.json) and [evidence](p1-compatibility-evidence.zip) preserve original counts/source/runner/loader/environment and source-byte bindings. Original271member and eight-member census seals are unchanged.

Separate remediation contract, source9470 approval with conditions, and305e source carry/no further source correction are imported unchanged. Final separate review at `4eb8fa38b0dec78965ea00f81a7d82539a1b9968` records **APPROVE BOUNDED P1 REMEDIATION SOURCE AND SEALED EVIDENCE; BOTH FINDINGS ELIGIBLE FOR CLOSURE; NATIVE LIFECYCLE REMAINS OPEN**, no P0/P1 or required correction. Byte-original [decision](independent-p1-final-review-4eb8fa38.json) and [report](independent-p1-final-review-4eb8fa38.md) are imported unchanged. Startup0 retains a nonfatal component_reference_cache warning; the version/regex probe has empty stderr. Exact documentation-head carry and publication remain pending. Both remote P1 threads still await the evidence-backed replies/resolution; native lifecycle4/8/E4 and olddefault1/2/E1 remain failed/open. No fullF2, wider native/concurrency/host/provider/activation/release acceptance is claimed.


## Current owned-file/remaining-memory P1 and local-focused P2 batch — October 16

Cheap preflight38824840900 passed at27b998fe, and the earlier two P1 threads were each replied to once and resolved. The complete inventory then found current [owned fixture P1](https://github.com/SeanVasey/VA-Studio/pull/131#discussion_r4289377653) and [local marker P2](https://github.com/SeanVasey/VA-Studio/pull/131#discussion_r4289377661); these remain remotely open pending final evidence review/publication. No merge is inferred from the old green run.

Bounded source commits646b53bd/d2cc2416/1f7b6705 supply internal private file allocation, exact config/Connection/PDO/canonical file+parent identity pinning, validated purge before owned removal, the real restore source/release path, remaining four Guards helper entrypoints and exact local MySQL marker preservation without manufacturing CI. No caller filepath is admitted. Independent restored snapshots remain read-only/unregistered destructive targets. Restore assertions and Guards bodies/captured outer cleanup remain; the positive ownership test uses actual migrate because Laravel db:wipe legitimately reconnects the PDO. First new31/E1 remains failed, rather than weakening PDO pinning. Policy936a5c50 declares the shared driver helper; workflow carry changes only its observed discovery comment.

| Byte-original PHP invocation | Source | Tests / assertions | Failures / errors / skips | PHP / recorder |
| --- | --- | --- | --- | --- |
| owned-file-restore-old-original | 27b998fe | 5 / 0 | 0 / 5 / 0 | 2 / 1 |
| owned-memory-guards-old-native-original | 27b998fe | 1 / 0 | 0 / 1 / 0 | 2 / 1 |
| owned-file-admission-and-restore-fixed-original | d2cc2416 | 31 / 717 | 0 / 1 / 0 | 2 / 1 |
| owned-file-admission-and-restore-recipe-corrected-original | 1f7b6705 | 31 / 722 | 0 / 0 / 0 | 0 / 0 |
| focused-local-marker-missing-original | 1f7b6705 | 1 / 0 | 0 / 1 / 0 | 2 / 1 |
| focused-local-marker-wrong-original | 1f7b6705 | 1 / 0 | 0 / 1 / 0 | 2 / 1 |
| focused-local-marker-exact-original | 1f7b6705 | 1 / 2 | 0 / 0 / 0 | 0 / 0 |
| owned-file-current-focused-ci-original | 1f7b6705 | 1 / 2 | 0 / 0 / 0 | 0 / 0 |
| owned-file-sqlite-surrounding-whole-original | 1f7b6705 | 112 / 1288 | 0 / 0 / 0 | 0 / 0 |
| owned-file-guards-four-entrypoints-native-original | 936a5c50 | NULL / NULL | unavailable | -15 / 1 |
| owned-file-guards-four-entrypoints-exact-native-original | 936a5c50 | 4 / 13 | 0 / 0 / 0 | 0 / 0 |
| owned-file-current-native-compatibility-whole-original | 936a5c50 | 86 / 1671 | 0 / 0 / 0 | 0 / 0 |

The SQLite31/722 and disjoint surrounding112/1288 scopes are separate whole-file selections, not a global suite. Surrounding112 includes all28 Guards cases/providers and unchanged SQLite lifecycle cases. Native Guards4/13 comprises one exact representative dataset of each remaining helper entrypoint and is partial; it is not the whole native Guards file or native SQLite-DDL/rollback acceptance. An earlier mistaken filter listed15 and was stopped after709.896seconds: PHP-15/recorder1/empty XML/NULLcounts, no partial pass. The exact4 listing was checked before restarting. Native-outer86/1671 comprises26 shared admission cases/195 assertions,5 SQLite file restore cases/527,46 SQLite migration canaries/932, and9 actual native identity/refusal cases/17. Intrinsic SQLite fixture bodies remain labeled SQLite and are not native DDL/race proof.

Actual focused runner controls preserve CI separately: local CI-unset exact marker1/2 passes, absent/wrong1/E1 remain failed; actual CI-present/local-marker-cleared1/2 passes. Only private synthetic transport is remapped to owned33524/schemafocused. No hosted Focused feedback dispatch is claimed. Old focused-runner safeguard53/1failure and first shard-policy52/1failure remain originals; final focused53/Pint4, declared-shard52/cadence14 each exit0. Discovery0 lists2432selected/233files of9202discovered/677; listing executes no tests,18generated outputs are hashed/retained/removed.

Supplemental [index](owned-file-and-local-index.json) and [evidence](owned-file-and-local-evidence.zip) seal12original invocations in156members/1834854bytes, SHA256 `b165eb65c82920b1bbb57bfa00ea244860b7102e4b1bcd17c7902cdda03395b0`. Failed raw outputs remain privately hash-bound, all original counts/exits retained. Source/runner/physical loader bindings and56frozen member/manifest paths equal PR130 bytes. Same ownMySQL8.4.11/33524 runtime normal SQL shutdown0/socket+PIDgone/loggedshutdown/datadirretained. Its first ten-second shutdown reconciliation exited1 and stopped sealing; a later read-only check confirmed disappearance/current-start shutdown. The failed recorder is retained and was not rerun as a passing original. Original271member, eight-member census and112member P1 seals remain unchanged.

Separate contract/source/policy carries approve bounded source with final native-evidence conditions; imported decisions are byte-original. Final source/sealed-evidence review and exact documentary-head carry, evidence-backed current P1/P2 replies/resolution, fresh cheap preflight/complete current inventory/expected-SHA merge remain pending. Native lifecycle4/8/E4 and olddefault1/2/E1 remain failed/open; all25 counterpart mapping/raw-worker/later-hook/fullF2/provider/host/activation/release and previous frozen P2 conditions remain. No Foundation/GitLab/live/host claim or frozen edit.


Separate final review at `4de9e2b921dee71342179dd63d2d94844b1ecb64` records **APPROVE BOUNDED OWNED-FIXTURE/LOCAL-RUNNER SOURCE AND SEALED EVIDENCE WITH MINOR DOCUMENTATION CONDITION**: no source P0/P1; current P1/P2 fixed and eligible for evidence-backed closure. Byte-original [decision](independent-owned-file-final-review-4de9e2b9.json) and [report](independent-owned-file-final-review-4de9e2b9.md) are imported unchanged. The sole wording condition is corrected here (focused-runner safeguard, not renderer). Exact-head documentation carry, current P1/P2 replies/resolution, fresh cheap preflight/complete inventory/expected-SHA merge remain pending. Native lifecycle and full F2/host/activation/release remain open.
