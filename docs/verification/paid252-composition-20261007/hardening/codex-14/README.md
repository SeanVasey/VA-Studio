# Paid252 Codex round 14: a downstream write blocked on a slow client holds its spool slot

Development evidence from the Claude Code harness for PR #56. This is not Foundation or final acceptance. No product code
changed in this round. The finding is recorded with evidence and routed to Sean as a deployment condition.

## Finding (Codex P2, `PaidGrantTransfer.php:35`, reviewed commit `9004245f`)

The transfer deadline is checked before the first byte and before each chunk is handed to the consumer
(`PaidGrantTransfer::within()`). If the consumer's `echo` blocks on FastCGI/SAPI backpressure from a client that stops
reading, PHP never returns to the next check or to the `finally` that closes the stream and its spool lease. Three slow
clients from three accounts can then hold all three global slots past `transfer_max_seconds`. The one-slot-per-buyer
rule (round 11) does not bound this.

## Trace

- The slot is an exclusive `flock` on `slot-<n>.lock`, taken in `PaidGrantPrepareStream::slot()` and held by the open
  descriptor until `PreparedDeliveryStream::close()` runs in `PaidGrantTransfer::writeTo()`'s `finally`.
- A blocked write is a blocking syscall in the SAPI output path. PHP userland cannot interrupt it: `set_time_limit()` /
  `max_execution_time` do not count time spent in stream operations on Linux, and there is no non-blocking or
  timeout-aware API for SAPI output. Asynchronous signals (`pcntl_alarm`) are not available under PHP-FPM and would not
  reliably abort the SAPI write.
- The lease is released when the process dies, because the kernel drops a `flock` when the last descriptor for that open
  file description closes. So the effective bound on a held slot is the wall-clock kill of the PHP worker:
  PHP-FPM `request_terminate_timeout`, or the equivalent for whatever SAPI the production host uses.
- How often PHP blocks at all depends on the front proxy. With nginx `fastcgi_buffering on` (the default), output is
  buffered in memory and then in a temporary file up to `fastcgi_max_temp_file_size` (1 GiB by default), so PHP finishes
  writing and releases the slot while nginx trickles bytes to the client. PHP blocks only when buffering is off, the
  temporary-file cap is reached, or the proxy is one that does not buffer.

## Evidence (`evidence/`)

`flock-blocked-writer.txt` (PHP 8.4.26 CLI, Linux 6.18) comes from `flock-blocked-writer-parent.php`, which starts
`flock-blocked-writer-child.php`. The child takes an exclusive non-blocking `flock`, then blocks in a write to a socket
whose peer never reads.

| Step | Result |
| --- | --- |
| Child takes the lock | `true` |
| Parent tries `LOCK_EX | LOCK_NB` while the child is blocked in the write (kernel wait `poll_schedule_timeout`) | `false`: the slot stays held for as long as the write blocks |
| Parent tries again after `SIGKILL` of the child | `true`: process death releases the slot |

A first version of the demo blocked the child by writing to a `popen()` pipe. After `SIGKILL` the lock was still held,
because the spawned shell had inherited the locked descriptor (PHP does not open files close-on-exec). That is a demo
artefact. The transfer path spawns no process while it holds a slot: the renderer runs during document preparation,
not during the transfer. A future change that spawns a process while holding a slot must not let the child inherit
the descriptor.

## Why no code change in this PR

A code change cannot release the slot at the transfer deadline while a write is blocked. The options that remain all
change the design:

- Release the slot before streaming. That breaks the spool's disk bound, because an unlinked snapshot keeps occupying
  disk until its descriptor closes.
- Raise the slot count (C8, already an owner decision).
- Move delivery off PHP output, for example to the proxy (`X-Accel-Redirect` / `X-Sendfile` from a private path). That
  conflicts with the current design: the snapshot is an exact unlinked descriptor.

The family is default-off and unmounted. The production host is undecided (U-02 in
`docs/architecture/decision-register.md`).

## For Sean (deployment condition, before mount)

- Bound the PHP worker's wall clock: PHP-FPM `request_terminate_timeout` (or the host's equivalent) at
  `transfer_max_seconds` plus a margin. Today that is 7200 s plus the snapshot budget plus a margin.
- Keep proxy response buffering on for the download route.
- Decide whether three slots (C8) are enough, given that slow clients can hold them up to that bound.

With the kill timeout set, a stuck slot is released at that bound rather than never. This is an availability limit:
no bytes leak and no attempt is lost because of it.
