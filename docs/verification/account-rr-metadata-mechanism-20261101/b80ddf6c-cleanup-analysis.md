# b80ddf6c first STOP: read-only diagnosis

Source b80ddf6c2c528f205be0533920b3db45ad7d4eda remains frozen. No source edits,
DB/container/test commands, or replay were performed for this diagnosis.
Receipt rrns-20261102T001923Z-0e38ab157e94 remains INCOMPLETE/STOP; all earlier
STOPs and retained scratch schemas remain unchanged. Root reports outer exit2,
2.528s; external final audit verified zero owned clients/trx/MDL/data and lease
returnable. Final close is not proof of the earlier rollback observation.

## Actual events (zero-based partial.jsonl indices)

- 575: plain arm release observation transactions=[], MDL=[], data=[], at
  9033709642501ns. This observer I_S.INNODB_TRX read can populate the shared
  transaction cache while other EARLY readers, including share, remain active.
- 583: share.tables exact HY000/3550 shared-lock error classified UNSUPPORTED.
  This is a capability observation only; no absence/currentness qualification.
- 595: share counter recorded unsupported=true.
- 597–604: share old anchor count1, PDO transactiontrue, REPEATABLE-READ,
  release/recreate savepoint succeeded; witness preserved.
- 606: share ROLLBACK succeeded, PDO transactionfalse at9033781253913ns.
- 607: immediate release observation at9033782698021ns: only transaction row
  {trx_mysql_thread_id:41}; MDL=[], data=[]. Thread1222 is the share connection41.
- 608/609: strict release oracle STOP. No later arm candidate executed.

The failing observation was ~1.444ms after the recorded rollback result and
~73.056ms after the prior plain-arm I_S transaction observation. It was not an
observed pending MDL/data lock or foreign wait. The STOP resource snapshot had
no share MDL/data and no inflight share statement; other EARLY cells retained
expected own resources. The post-STOP anchor count2/transactionfalse is expected
after explicit rollback and cannot serve as the pre-rollback witness.

App-shaped reserved TABLE admission reproduced; four named warning provenance
controls succeeded. Plain baseline completed (seven catalog/peer observations).
share.tables alone was observed unsupported with exact matching Error3550.
share_union/update/update_union/SHOW qualification remains UNEXECUTED.

## Source-backed causal explanation and limits

Exact public MySQL tag mysql-8.4.11:
https://github.com/mysql/mysql-server/blob/mysql-8.4.11/storage/innobase/trx/trx0i_s.cc
lines681–699: can_cache_be_updated requires more than100ms since last cache
read; the stated purpose is consistent versions across I_S joins. Lines820–825
skip refresh if ineligible. Lines893–901 update last_read whenever read ends.
https://github.com/mysql/mysql-server/blob/mysql-8.4.11/storage/innobase/handler/i_s.cc
lines821 and852 call possible refresh then end_read for this metadata path.
Downloaded source outside Git, trx0i_s.cc SHA256
3006dba44b36a3c963755afd11a7133bcb8dabd2a1428665eebadfd0f0ccfc66.

The ~73ms spacing is consistent with an intentionally cached prior share
transaction row surviving its rollback. This is strong source-backed diagnostic
inference, not direct capture of the internal cache generation. Final external
zero audit establishes cleanup after clients closed; it does not retroactively
turn the original single-sample rollback check into a pass.

## Proposed smallest bounded observation repair (NOT implemented)

Retain exact all-zero transactions+MDL+data release requirement for the same owned
connection/thread. Record every sample and elapsed monotonic time. If the first
sample is nonzero, allow only finite observation resampling, with at least150ms
between I_S.INNODB_TRX reads (no intervening reads of that cache by this probe),
within an absolute1second release-observation deadline and existing outer budget.
Do not reissue rollback, candidate, witness, or any mutating SQL. Query failures,
unknown thread identity, unavailable instrumentation, or nonzero final sample
must STOP/inconclusive, never pass. Preserve initial nonzero rows rather than
rename them a release success. Require an explicit final all-zero observation
while the arm client remains open; then permit the next independent arm.

The sleep interval is bounded observation timing, not a SQL/isolation or guard
change. Global cache readers outside this probe could indefinitely postpone
refresh; deadline expiry must remain STOP. This does not claim transient lock
freedom. A fake-clock pure regression should cover initial stale row→zero,
persistent row, MDL/data persistence, read exceptions, and absolute deadline;
no source change or execution is authorized by this proposal itself.

Production namespace P1, runtime strategy, full/terminal admission, original110
cost and15 memo budget, ledger restart, domain controls remain unresolved/unrun.
