# Customer-account admission v2 prerequisite

Base main `a7bc63cc5c5785353f13e882f49d0ab9dcff55b3`, excluding unaccepted member
paging/history trial. Approved design and byte-exact independent decision are in
`design/`; all P0/P1 conditions are acceptance requirements.

Own new closed account parent classifier and trigger upgrade coordinator,
IdentityMigrationOwnership and ConsentMigrationAdmission consumers,
SchemaProofFrame exact version pin, one new late migration, focused tests and
this packet. Historical migrations and fixtures remain byte-identical. No
identity/consent/account/audit/contract data rewrite; no mount change.

Implementation must admit only whole stable V1/V2 at runtime; migration-only
exact ordered overlap supports crash resume. Add complete new guard before
removing old, reverse before down; same PDO advisory lock, no active transaction,
bounded metadata lock, reinspect each DDL. SQLite verifies exact V1 no-op.

Serial test slot belongs to MIG. Initially source/static checks only; DB/native
and heavy checks require coordinator release. Scratch A/B is causal evidence,
never actual-domain acceptance. Native tests and independent actual-SHA review
remain required before push/PR/merge. Original member trial remains failed.
