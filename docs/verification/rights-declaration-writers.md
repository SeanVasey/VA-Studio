# Transactional rights declaration writers

October 2, 2026. T12 / WP-02 candidate following the verified PR #105 SQL evidence-retention floor. This increment composes current-authority rights services, captured administration review, the participating offer writer and adversarial coverage. Hosted MySQL/native/full acceptance remains required and is currently blocked by GitHub payment authorization. The [current queue](../development-control.md) records ownership and the next publication dependency; parent roles/recovery, scheduling and production acceptance remain open.

## Supported command contract

`SaveRightsDeclaration::create(array $data, User $actor)` accepts exactly `track_id`, `provenance_reference` and `sample_disclosure`. All three are required; text must be valid UTF-8 within MySQL TEXT's 65,535-byte capacity. Canonical positive numeric strings from Filament are accepted as track IDs. Creation forces pending status and null verification identity/time. Client status, verifier, timestamp, primary identity or extra fields are rejected.

`review(RightsDeclaration $record, User $actor)` captures an edit baseline. `updateReviewed(array $review, array $data, User $actor)` compares that baseline with locked current rows before editing or retargeting pending evidence. `VerifyRightsDeclaration::review` captures a verification baseline, and `verifyReviewed` applies it. The existing trusted `handle(RightsDeclaration, User)` signature remains available for current verification; it does not assert that a human reviewed an earlier display.

Rights entry points reject ambient transactions before private queries. They start their own transaction and lock fresh persisted actor → locking catalog authority/required MFA enrollment → numerically ordered affected tracks → current rights declaration. A preliminary association read never holds rights before tracks. Retargeting locks source and target together; association drift is refused rather than discovering and locking another track afterward. Missing/deleted/unsaved actors and withdrawn authority fail closed.

The exact review contains schema version, actor, edit/verify intent, declaration ID, source track ID, pending status, lowercase evidence hash and captured track title/provenance/sample disclosure. Every key and display value is compared with the locked capture. The hash also binds current track identity/title. Verified rows cannot be edited or reverified; corrections append a new pending declaration. Latest pending evidence continues to block new readiness while historical verified rows, original audits and paid snapshots remain unchanged.

Create/edit/verify write and minimized audit share a transaction. Audits contain version, track ID, changed-field names and before/after evidence hashes; free-text evidence is excluded. An unchanged edit produces no write/audit. Failed audit recording rolls back the mutation.

## Administration and participating offer writer

Actual Filament create/edit/verify invoke the services with their outer action transactions disabled. Relationship persistence is disabled so it cannot bypass the command. Edit and verification mount a locked server capture, render escaped exact evidence and bind it to actor/action/record/table context. Submission consumes the current component review before schema validation; cancel, context changes, failed validation and uncertain results require reopening. Authorization failures propagate; unexpected failures retain only the exception class in logs.

The competing-verifier regression originally revealed Filament's silent hidden-action return. The correction checks current authorization and availability before that return: edit gives an actionable fresh-review error; verification notifies and unmounts. Evidence protection remains owned by the services and SQL floor.

`PublishOffer::handle` now locks the fresh actor and locking catalog authority inside its existing transaction before its first offer/track read. This removes the source-identified actor/track inversion between supported rights writes and the publisher's user foreign-key writes. The existing nested API, subsequent track/offer/license/rights order and immutable revision/audit behavior remain. No new offer MFA policy or retry mechanism is introduced.

## Boundaries and verification

Current-value equality does not detect A→B→A history. Consumption applies to the current Livewire component lifecycle, not a durable nonce that invalidates replay of older authentic snapshots. Required MFA checks current enrollment, not a recent challenge proof. Nested offer callers retain inherited snapshot/caller-lock limits. No universal deadlock freedom, privileged SQL protection, fresh physical-media digest, publication apply fence or track scheduling is claimed.

Relevant commands on the composed candidate are:

```bash
php artisan test tests/Feature/RightsDeclarationWriterTest.php tests/Feature/RightsDeclarationWriterActionTest.php tests/Feature/RightsDeclarationWriterConcurrencyTest.php tests/Feature/OfferWriterAuthorityTest.php --compact
FOCUSED_SUITE=operator FOCUSED_ENGINE=sqlite python scripts/ci/focused-tests.py
python scripts/ci/test-focused-tests.py
python scripts/ci/test-database-receipts.py
```

The operator selector includes the writer classes, authority correction and existing rights-floor suites. Only five actually discovered new MySQL methods/eighteen expanded cases are added to the exact SQLite skip policy. They cover both actor-withdrawal orders, both manifest/mutation orders, opposite retargets, pending drift during waits, and the real publisher/rights calls contending on the actor. Independent processes must observe exact `performance_schema` waits and positive assertions on genuine MySQL. SQLite skips and definitions do not prove concurrency.

Source-bound base failures, component feedback and UI repairs are preserved separately. Final local and hosted results belong in the integrating PR with their actual head/tree; prior PR #105 acceptance cannot accept this changed runtime. The original storefront/admin screenshot package retains its PR #104 provenance.
