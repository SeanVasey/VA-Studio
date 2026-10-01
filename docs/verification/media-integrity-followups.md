# Media integrity evidence and follow-ups

Status: T03 and T05–T08 accepted in [PR #88](https://github.com/VASEYDEV/VASEYAUDIO/pull/88) on October 1, 2026 (UTC). T04's hash correction is accepted; its UUID child keeps T04 open until integrating MySQL acceptance. The actual tested head is `5e44354785c44e46a93dd97705ef050d62fc6ab5`, tree `4161be856fad978394368a1708bcec8f9085b7d3`, merged as `cc591daa001f774626e574885cbc7b4904f6b2d2`. [Foundation 36817029405](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36817029405) passed all complete gates. This evidence does not authorize a production migration or cutover.

## Executed evidence

The isolated baseline matched all 764 tracked blobs of main `8be3bd7271595f2c21a3c5017b3b19ceb821a842`, tree `c389602f8acb43287a8d8125b482c4c0dd2b3dba`. Real PHP 8.4.26 / PHPUnit 12.5.34 used in-memory SQLite, strict warning/stop flags and physically copied dependencies matching all 158 locked Composer packages. Standard local test configuration supplied the application key. No placeholder sources were created.

| Focused group | Tests | Assertions | Errors / failures / skips |
| --- | ---: | ---: | ---: |
| CommerceGuardBytes + three changed commerce migration suites + HashByteGuardMigration | 51 | 570 | 0 / 0 / 0 |
| SiteImageHttp | 10 | 279 | 0 / 0 / 0 |
| MalwareScanner + StemsArchive + MediaWorkflowBudget | 132 | 805 | 0 / 0 / 0 |
| Total | 193 | 1,654 | 0 / 0 / 0 |

JUnit group times were 63.869s, 4.757s and 45.589s. Separate processes used separate databases and isolated private-storage roots. The initial missing local application-key error was corrected through test setup, without changing product source. Discovery found 125 test classes/files and 1,903 expanded cases; discovery is not full execution.

Sixteen focused-tested files were overlaid; application/test bytes were unchanged. The media guide received an EOF whitespace correction during integration. Local author snapshots: T03 `29e4a17b`, T04 `9f89069b`, T05 `b866ad8a`, T06–T08 `6891d679`. They identify authored snapshots, not remote ancestry or acceptance. No all-files formatter pass is claimed; the optional touched-file Pint check still reports existing/style differences.

T03 preserves intended SQL while replacing only whole whitelisted identifiers. T04 adds stored-byte hash guards without changing existing length-only/hexadecimal, nullable-draft or ready-image policies. T05 displays a waiting hint without claiming dispatch failure or requeueing on refresh; existing specific failure/interruption diagnoses retain precedence and explicit retry dispatches again. T06 inspects ZIP metadata before scanner expansion. T07 accounts for independently allowed source, expansion, artwork and tag sizes. T08 refuses operation starts at the deadline or with less than one whole second and checks returned work against the archive deadline.

## Migration 000029 runbook boundary

`2026_10_01_000029_byte_exact_hash_guards.php` supplements 25 hash fields across 12 tables with 13 triggers. It never normalizes or rewrites retained evidence. Applying it to a populated database requires a verified restorable backup, recorded original trigger definitions and a restored-copy rehearsal on the intended engine/version.

Quiesce all affected writers **before preflight and through installation/verification**: HTTP/admin writes, payment/delivery workers, queues, scheduled jobs and out-of-band database writers. Maintenance UI alone is insufficient; the migration does not obtain a global writer lock. Its complete field preflight precedes the first DDL statement. Invalid retained bytes abort with the table/column named; preserve and investigate evidence rather than trimming, lowercasing, truncating or replacing it.

MySQL DDL commits individually, so interruption can leave supplemental guards partly installed while the migration ledger remains incomplete. Keep writers paused, inspect actual definitions, then retry: matching existing guards remain and only missing ones are added. A same-named different definition aborts without replacement. Verify all 13 supplemental guards and every original lifecycle/relational/immutable guard before resuming writers.

Rollback removes only the supplemental guards and preserves original guards/rows, but removes the new protection. Quiesce writers and rehearse rollback/reapply; do not use broad multi-migration rollback or disable existing guards as a workaround. Updating the historical T03 helpers does not rebuild already-installed triggers: their intended emitted SQL remains unchanged, while T04 supplies the additive deployed hash correction.

## Open child: T04-UUID-01 — SQLite UUID bytes

Open integrity follow-up, owned by the domain/migration lead. SQLite UUID text predicates can admit a valid 36-character UUID followed by NUL and extra bytes because text operations may stop at NUL. Migration 000029 corrects hash bytes; it does not change UUID predicates. UUID integrity is not resolved by this batch.

Inventory UUID guards and retained-row exceptions, reproduce direct insert/update bypasses, and add exact text/byte validation with valid/invalid tests on SQLite and MySQL. Applied databases need an additive, preflighted correction with the same writer-isolation, resumability, rollback and retained-evidence discipline. Never truncate or silently repair identifiers. Keep the child attached to T04 and unresolved before production migration/release acceptance.

## Remaining acceptance

The earlier local environment had no MySQL server or qpdf; its focused SQLite evidence is historical component proof. Accepted [Foundation 36817029405](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36817029405) now supplies 1,903 passing MySQL cases / 22,594 assertions with zero skips, and 1,809 passing SQLite cases / 18,647 assertions plus 94 intended MySQL-only skips. All 125 files and expanded identities reconcile exactly once per engine. MySQL verified trigger DDL/retry/definition matching, column conversion, retained-data preflight, stored-byte rejection and the complete commerce/concurrency suite. Both native site-image journeys passed, including the staff presentation of queue state. T03's whole-identifier regressions, T05's ten queue cases, T06's 47 stems cases, T07's 81 scanner and 26 media-processing cases, and T08's deadline/budget/claim/recovery cases are accepted on both relevant engines. These selections overlap; do not add their counts as distinct coverage. Real deployed workers, representative approved media, private transport/storage, production migration and broader work-package acceptance remain separate gates.

## MySQL raw input and stored-byte verification

The initial PR #88 MySQL run exposed an incorrect newline-input rejection expectation. A [pinned disposable diagnostic](mysql-byte-guard-diagnosis.md) verified that MySQL converts excess newline/CRLF/space bytes before triggers and retains an exact valid-width value. NUL tails and ordinary overflow are rejected. The corrected regression retains each input case and asserts exact persisted bytes plus rollback/history preservation where MySQL normalizes; raw predicates and malformed retained-byte rejection remain required. Neither migration nor historical evidence was changed. The diagnostic is separate from the final required acceptance run.
