# Production track policy final primary proof

This is a narrow forward repair of the authored source-policy preparation service,
following the independently reviewed `18365c56` policy implementation and `a631ebf3`
explicit InnoDB repair. Those receipts continue to describe their original source.
This child does not activate commerce or verify merchant, tax, legal, account,
storage, delivery or worker facts.

## Demonstrated defect

The old final resource and actor checks used Laravel Query Builder. Its
`QueryExecuted` listeners execute after fetching the rows. The retained two-case
native negative probe showed a listener on the last actor read withdrawing the
actor's administrator role through primary PDO after that read. Source preparation
returned revision 1 and committed one draft, version and audit with the role
withdrawn. The second negative case, a listener on the earlier final authority
query, already rolled back correctly. The distinction identifies the affected
boundary without declaring the earlier focused evidence a pass for this repair.

## Correction

The service captures the standalone transaction's primary PDO and driver before
callbacks. It still performs fresh staff authorization and MFA, and all existing
model, audit and Laravel query callbacks. After those callbacks finish, every final
ordered source, version, acknowledgment, audit and actor read uses that captured
PDO directly. MySQL reads retain `FOR UPDATE`; SQLite uses the same bound SQL
without the unsupported clause. No model retrieval or Laravel Query Builder read
follows the final proof before return and commit. Fixed table and predicate text
remain internal; caller values use prepared-statement bindings.

The final proof compares the entire expected owned graph and actor row. It applies
to prepared saves, creations, source updates, exact no-ops, prepared source
acknowledgments and acknowledgment persistence. Returned models are populated from
already proved raw attributes rather than retrieving them again.

## Focused acceptance evidence

The exact commands, tested source hashes, case identities, outcomes and native
record waits accompany this child in `production-track-policy-final-proof-*`
receipts. The SQLite selection covers both new probes, encrypted creation, exact
no-op, independent and historical acknowledgment, and three late audit callback
rollback cases. The native selection adds both existing pre-fence snapshot no-op
commit orders. The original red canaries and their source hashes are retained.

The corrected SQLite selection passed all 9 cases / 49 assertions, with no skips.
The corrected genuine MySQL 8.4.11 selection passed all 11 cases / 185 assertions,
with no skips and two observed exact record waits. The seven common existing
domain cases and the two new probes use the same source on both engines. Source
hashes remained unchanged across each execution.

The corrected first canary proves the forbidden final `QueryExecuted` surface is
absent and that an authorized creation commits. The second proves an actual role
withdrawal in the last permitted authority callback causes complete rollback.
The native snapshot races verify current locking proof reads after an actor
retrieval callback has opened an older consistent snapshot and the draft fence
has actually waited on the competing writer.

Scoped formatting, lint, diff validation and workflow-cadence guards are run for
this child. Workflow files and shared controls are unchanged. Root separately owns
the inherited GitLab manual-dispatch policy reconciliation before publication.
No earlier complete policy selection is rerun or relabeled; hosted CI, provider
operations, production activation and final integrated acceptance remain deferred.
