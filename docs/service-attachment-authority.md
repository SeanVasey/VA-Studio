# Service project attachment authority

The service adapter is a separate child of reviewed service recovery38105a6.
It registers under the server's fixed service_project source kind and implements
SupportAttachments' AttachmentMutationAuthority. Its source token is privately
minted through a current locked read, never accepted from a browser body.

`lock(sourceId,expectedVersion,purpose,trustedActor,rows)` returns the opaque proof.
`proveCurrent(proof,rows)` is the terminal current actor/account/source byte fence.
`authorizeMutation(proof,expectedVersion,purpose,rows)` admits intake/process with
the same list proof or same already scoped mutation proof, requires exact version
and graph-derived open state, then proves current bytes. It does not create a
second token. A download-scoped token cannot authorize intake.

The caller captures AttachmentRows before framework callbacks and owns one outer
Laravel transaction. Lock order is current User,CustomerAccount for a buyer,
project,then events; staff use current administer-catalog and MFA policy. Qualified
primary reads reject temporary shadows. Gate/MFA framework queries precede the
terminal raw authority/source fence, so their stale returned models cannot
survive a role/account withdrawal. The MFA provider is evaluated only in that
callback-capable policy phase; the raw authority phase does not invoke it again.
Service and customer preparation flags are rechecked after the terminal graph
read, so the last staff query or provider cannot withdraw admission and still
return a source proof. The adapter reuses the service domain's sole
projection/transition verifier, including encrypted original brief/service
snapshot and every quote/decision/milestone event. No adapter row mutation occurs.

Intake requires exact expected version. Withdrawal,cancellation request or
cancellation closes new intake/process. Authorized retained list/download/delete
remain available, while every operation requires fresh current owner/operator
proof. Actual attachment entitlement/file/scan checks belong to SupportAttachments.
This adapter does not confer paid service completion or delivered product access.

Binding fields include support-source-v1,family test_service_project_v1,kind
service_project,opaque project UUID,internal project/account IDs,explicit test
origin,immutable parent/brief/service hashes and selected service-version ID,
accepted source event version,complete raw graph hash,strict intake_open boolean,
and fixed policy version/hash. originBinding removes only event version,graph
hash and intake_open; it preserves original owner/source commitments for replay.
actorBinding is a stable hash of server audience/user/account IDs. Credentials,
owner capability and private project text never enter the durable binding.

Tokens cannot be serialized,JSON encoded or cloned. They close on framework
commit/rollback,outer-transaction loss or PDO replacement. A private savepoint
marker also detects direct PDO commit/reopen bypassing framework events. A probe
releases and recreates that marker without rolling back consumer writes. Mint one
source token per consumer operation/outer transaction and reuse it for mutation
admission/replay: on SQLite,releasing an earlier nested marker can invalidate a
later separately minted token. Markers disappear with the outer transaction.

The bridge translates failures into closed generic attachment errors. Preparation
is still default-off and synthetic local/testing under the reviewed service and
customer policy. Operative production identity/terms/source admission requires a
separate reviewed successor; this child does not enable it.
