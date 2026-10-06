# Resumable private media intake

This T13 child adds bounded resumable transport on the existing private local filesystem. It preserves the current upload roles, size ceilings, quarantine and processing boundaries. It does not choose an object-storage provider, expand file limits, bypass scanning, publish media, or complete T13/T14.

## Service contract

`ResumableMediaUploads` exposes `start`, `inspect`, `append`, `complete` and `cancel`. Requests use an opaque UUID; the retained session also has an internal numeric key compatible with existing audit subjects. Responses contain only the UUID, track/role/name, declared byte count and SHA-256, received offset, chunk size, state, expiration, resulting asset ID and cleanup-pending flag. Private paths and part records never leave this projection.

- Start fixes the creator, track, role, filename, size and whole-file digest. Exact pending start retries return the same session without extending its lifetime or allocating another slot. Expired matching sessions remain expired until cancelled.
- Transport lasts at most 24 hours and accepts exact sequential 8 MiB chunks, except the shorter final chunk. Artwork remains limited to 20 MiB; WAV masters and WAV-only stems ZIPs remain limited to 200 MiB. These are development transport bounds, not a new production retention policy.
- At most four sessions with uncleared transport are admitted per actor. Expired sessions and terminal sessions awaiting cleanup continue to occupy a slot. A terminal cleanup failure is recoverable through the same completion/cancellation request.
- Every operation verifies current persisted administrator/MFA authority and creator identity. Mutation order is actor → track → session. Root transactions are required; caller-owned outer transactions are refused so physical cleanup cannot be mistaken for a committed operation.
- Duplicate offsets succeed only when length, server-computed SHA-256 and actual retained bytes agree. Other offsets, altered chunks, missing/corrupt parts and foreign sessions fail. Client filenames never become storage paths.
- Finalization verifies the assembled whole-file digest and calls the existing MIME/size/quarantine intake boundary. It creates one asset and records the completion atomically. A completed replay verifies retained asset identity and returns that same asset; no worker is automatically queued.

## Recovery and file ownership

Each offset, complete assembly and session quarantine source has one deterministic server-owned location. A verified existing file is reused, never overwritten. Incomplete `.pending` copies have no database references and may be recovered under the session fences. Repeated chunk rollback, MIME refusal or completion rollback cannot multiply final part/assembly/quarantine copies for that session.

Any exceptional or unknown final commit outcome retains transport and quarantine bytes. A fresh retry consults durable session state: a successful earlier commit returns its original asset; a rolled-back attempt reuses the same verified source. An unreferenced quarantine source after a failed/cancelled finalization remains for explicit reconciliation; this child does not guess that retained original media is disposable.

Only an acknowledged completed/cancelled root transaction permits transport cleanup. A second transaction reacquires the same fences and current authority, verifies the terminal state, removes only its flat private transport files, and records cleanup. Concurrent retries therefore cannot race cleanup. Cleanup never traverses symlinks or touches quarantine, processed revisions, contracts or unrelated originals.

Migration `000038` retains populated session evidence on rollback. Empty rollback removes the child before its parent tables, while refusing temporary shadows, unknown relationships/indexes/triggers and unexpected columns. An existing unjournaled table is refused rather than silently repurposed after interrupted DDL.

## Verification checkpoint

Focused SQLite execution with PHP 8.4.26 passes 26 cases / 319 assertions, including the existing whole-file commit-recovery suite. Cases cover exact start replay, interruption/resume, duplicate/corrupt offsets, foreign/revoked actors, existing limits, expiration/cancellation, final MIME/digest verification, lost committed-result acknowledgement, rolled-back finalization, bounded retained bytes, symlink refusal and migration preservation/refusal. The unchanged whole-file API retains its original recovery semantics.

The dedicated MySQL suite uses independent PHP/database processes and observes an exact `users` primary-record `WAITING` lock before releasing the first operation. Its four cases cover duplicate chunk admission, duplicate completion, cancellation before completion and completion before cancellation. SQLite skips these four cases explicitly and does not establish concurrency acceptance. Final native MySQL results, exact source and HTTP/admin composition acceptance belong to the integrating PR.

Native MySQL development exposed a real schema incompatibility that SQLite had tolerated: existing audit subjects are numeric. The session now keeps a numeric primary key with a separate unique public UUID. The audit schema and historical events are unchanged. An earlier disposable MySQL setup lacked trigger-creation privileges; that setup error was corrected in the test runner, without weakening application migrations or durability settings.

The HTTP/admin child owns CSRF/session middleware, permission admission, safe error projection and reselect/resume controls. Real persistent hosting, resumable object storage, production retention/orphan operations, representative originals and deployed scanner/worker verification remain separate completion work.
