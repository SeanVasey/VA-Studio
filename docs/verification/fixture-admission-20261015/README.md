# F2a: shared fixture admission and captured finalization cleanup

The implementation captures the external testing/database declaration before application bootstrap, checks configured and actual PDO identity before Laravel's database traits, and checks declared transaction/truncation targets and listed database artisan commands. Owned finalization setup rechecks after its hook, executes one captured target/options recipe, and rechecks that original target before cleanup. Mutable defaults, recipe, environment and another physical PDO do not authorize another cleanup target. Bookkeeping resets even on cleanup refusal.

SQLite admission retains only actual `:memory:`. Local MySQL requires `VA_LOCAL_DISPOSABLE_MYSQL` exactly equal to the dedicated selected schema; the existing CI marker/CI=true pathway remains separate. This marker is an operator declaration, not private-server/host proof. The existing lane-specific DisposableNativeDatabase checks remain unchanged. Controlled product-mode fixtures retain external APP_ENV=testing: required production/staging and optional local enrollment behavior remain unchanged. Native mode-binding probes change application mode after boot; the existing SQLite panel suites supply actual route-boot evidence.

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

Independent contract guidance and source DECISION at `4ff10a05498079cbcb5b9b65c4c546a0d1d6d158`: **APPROVE BOUNDED SOURCE WITH FINAL EVIDENCE CONDITIONS**, no P0/P1 or required correction. The source writer does not self-review. Final sealed evidence/current-source review, exact-head documentation carry, cheap preflight, complete inventory/protections and expected-SHA merge remain pending.

No real staff/provider/payment/customer-active import/host/deploy/DNS/cutover action. Free256/B4/frozen metadata/membership P2s and commercial/legal/identity/activation/release conditions remain open.

Sealed originals:14PHP invocations/271members/2573499bytes, SHA256 `7976add384769b1e6b5c8c701ffce9158e7928cc6a68790b59568283964293f6`. Failed raw outputs/runtime originals remain privately hash-bound; labeled derived outcomes retain original counts and failures.
