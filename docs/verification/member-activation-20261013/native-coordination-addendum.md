# Native activation and release coordination

The original API contract retains its exact bytes. This addendum refines native
MySQL scheduling after the preserved early two-activation and consume-wins
failures at `72dbd1b45d547d2be608cd77e7f3fe15528dead5`. Those runs reached real
contention but did not return the required successful writer receipt. They are
not passing races or verified complete post-failure graph audits.

The separate reviewer accepted the design and corrected source. At `3543b71f`,
focused native early activation 1/78, terminal orders 2/129 and rollback/recovery
5/970 pass with observed process/verifier 0 and unchanged source gates. Final
whole packet approval remains pending. Everything remains default-off and unmounted.

Activation and ordinary release coordinate on one schema/redemption-derived
MySQL user lock on their captured native primary PDO. This is scheduling state,
not customer identity, source authority, a mutation permit or a replacement for
any current/committed graph proof. Reserve, expire, public historical reads and
SQLite retain their existing scheduling. Different redemptions have different
keys; their shared owner-row contention remains a separate availability limit.

Activation claims its genuine process-local confirmation before acquiring the
lock and before opening its first source frame. Waiting is bounded by at most
60 seconds and the original confirmation Budget. Each subsequent source frame
retains its own original bound. Ordinary release starts its single original
60-second deadline before coordination and passes that same deadline through
construction, current proof, physical commit and committed receipt; waiting
never restarts it. Every renderer, source, honor and native physical observation
minimum remains unchanged and can only shorten admission.
The acquisition also captures its own original 60-second admission deadline
before its native route/ownership statements and checks it after GET_LOCK.
The native integral timeout is at most 60 seconds; a late acquisition is refused
and cleaned up under the captured session, even if the workflow has time left.

The acquisition rejects a pre-existing same-session lock. Cleanup releases at
most one proved acquisition on the same captured native session. It never calls
RELEASE_ALL_LOCKS, retries recursive decrements, adopts a replacement handle or
commits/rolls back another transaction. Continued ownership by the same session
after the single release refuses; an immediately admitted waiting session is
allowed. Unknown or failed cleanup is a refusal, never a successful receipt or
a rollback claim.
An existing committed-receipt refusal, a completed writer and proven retained
replay keep their committed refusal classification when cleanup fails. Both
the primary exception and cleanup exception remain attached internally, and
native diagnostics traverse both branches within their existing metadata caps.
One adjacent pre-existing ordinary-operation limitation remains: an afterCommit
delegate may throw after physical commit before `commit()` returns and before
the operation records its committed flag. That unknown outcome retains its
existing generic refusal mapping. The next related correction requires a genuine
owned physical-commit witness and actual throwing ordinary callback regression;
an idle PDO is not commit proof. Member-activation postcommit recovery tests do
not execute that ordinary branch.

The sole scheduling unlock explicitly follows the complete current and separate
committed proofs, single finish and post-finish admission. It precedes additional
cached, callback-free route/idle/finish/deadline admission. On the private
activation readback and independently confirmed retained replay paths, the
original retained-observation assertion remains last. Public readActivated has
no scheduling unlock. Ordinary release adds final Context admission after its
unlock, retaining configuration, original/authority deadlines and retained
invoice freshness. No business row SQL, producer callback, permission refresh
or new physical observation is introduced after the final proof.

Native race evidence must identify actual pending/granted USER LEVEL LOCK rows,
exact key and independent requester/blocker threads, plus the first activation's
real parent owner-row wait or the terminal writer's genuine owned committing
hold. Strict successful-writer, terminal loser, exact winner A/R/D, atomic graph,
conservation, bytes, recovery and idle checks remain. The native ownership
checks exercise pre-existing/reentrant locks, one-use release and preservation
of a foreign native transaction. Original failed row-wait cases remain separate
receipts; changing the scheduling mechanism does not relabel their outcomes.

Host lock support, representative throughput/latency, unrelated redemption or
reserve/expire contention and physical/host/provider conditions remain untested
unless explicitly recorded elsewhere. No production activation is authorized.
