# Stems recording current binding verification

This bounded WP-03 repair starts from `2716dbf9590ae61d221a93272cea075bbf3ee9bf`. It corrects the recording writer's binding lookup and identical replay after a competing confirmation commits. It does not claim to refresh all media, preview, or processing evidence in an existing transaction snapshot.

## Observed failure

[Focused MySQL run 37092030217](https://github.com/SeanVasey/VA-Studio/actions/runs/37092030217), at that source commit, reached the independently observed exact track-row lock wait. The losing recording worker then attempted another insert and reported MySQL `SQLSTATE[23000]`, error `1062`, for `stems_recordings_stems_asset_id_unique`. The recording test failed; the separate metadata race passed.

The test's earlier shared-administrator barrier cycle had already been corrected by using distinct committed administrators. Each recording worker still read media before acquiring the track lock. In MySQL Repeatable Read, the later ordinary binding lookup therefore used a snapshot predating the winning insert. Merely making that lookup current would still leave identical replay exposed: `RecordingAssociation::verified()` previously queried the binding again through the old snapshot.

## Change and boundary

`BindStemsToRecording` retains its actor authorization fence and track lock, and inspects the binding only after that track fence. The new writer-only `RecordingAssociation::inspectCurrentBinding()` requires a transaction, queries the binding once with `FOR UPDATE`, and validates that exact object through the same private retained-evidence and availability checks used by the existing public readers.

The result separates absence from invalid existing evidence. An invalid row remains present with `verified=false`, so the writer rejects it without attempting replacement. The transaction guard and locking query sit outside the validation catch; query and lock errors propagate. No duplicate-key exception is converted into successful confirmation.

Existing `retained()` and `verified()` consumers keep their ordinary-read behavior and validation rules. The writer's matching-reference rule, conflict message, immutable evidence, audit attribution, private-byte checks and caller-owned transaction semantics remain in place. No transaction is restarted, no isolation level is lowered, and no asset lock is introduced ahead of the track lock.

This is a current **binding** read. Related media and latest-preview reads retain their existing semantics. Broader stale media/preview behavior in a pre-existing caller snapshot requires separate evidence and is not declared resolved by these races.

## Regression coverage and evidence gate

The existing MySQL recording race method now has four datasets: conflicting and identical confirmations, each with a root transaction or an already-snapshotted caller transaction. Every case retains the exact track/primary-key/record wait proof, distinct processes and connections, bounded barriers, and one immutable winning attestation and audit. Identical replay must return the same binding to both workers. Caller-owned cases prove the service leaves the outer transaction open and the loser's ordinary snapshot still cannot see the winner even though the current binding inspection handled it correctly.

Two ordinary regression cases preserve malformed initial evidence as a conflict, and inject a synthetic binding-query exception to prove it propagates without a row or audit. Immutability triggers stay enabled. The latter is controlled fault injection, not an engine-outage claim. Existing sequential idempotency, authorization, media integrity, publication and audit-rollback tests remain.

The four datasets keep the already-listed method and SQLite skip reason. CI derives their exact identities from the test inventory; no skip exemption is added. PHP and Composer are unavailable in the editing environment, so local source checks and independent review do not establish runtime acceptance. The integrating PR must record green focused MySQL/SQLite evidence and subsequent required CI on the final source SHA. Runtime results belong in that PR rather than a status-only source commit.
