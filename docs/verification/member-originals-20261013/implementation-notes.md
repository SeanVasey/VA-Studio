# C4b implementation refinements

These refinements describe the implementation of the separately reviewed [API contract](api-contract.md). Behavioral evidence and outstanding native verification are recorded in the [packet](README.md); this design note is not an independent approval.

## Owned assembly

`MemberOriginals` receives four concrete dependencies: `SealedMemberGrantFacts`, `MembershipReservations`, `MemberOriginalRendererProcess`, and `MemberOriginalFiles`. Construction performs no admission, I/O or container registration. Its `artifactAuthority(): MemberOriginalArtifacts` returns the exact composed service for explicit fixture/container binding. It is not a replacement setter.

A fixed internal source-capture assembly creates the source issuer and artifacts composer and binds that exact composer once through private registration. Foreign consumer claims, first-caller adoption and rebinding refuse. Actual preparation still performs the complete configured capability and source/frame gates; construction supplies no readiness or write authority.

`MemberOriginalCandidate` has the seven scalar fields from the contract (`originId`, `redemptionId`, `definitionId`, `definitionHash`, `profileHash`, `renderSourceHash`, `artifactManifestHash`) plus `artifactManifest: MemberOriginalArtifactManifest`. It exposes no path, token, Rows or grant receipt. The existing manifest type is a value; its constructor alone does not authenticate its hash.

The operation and integration-fixture owners coordinated these signatures. The composed SQLite tests use these concrete services; a returned candidate is not an authority token.

## Fresh intent bridge

The concrete reservation issuer adds `memberIntent(MembershipMemberCapture $capture, MembershipRows $rows): MemberGrantIntent`. It accepts only its registered original live capture/frame and returns a cached intent holding that Context's actual invoice/license proofs and its issuer-issued reservation proof. It refuses after the current-to-committed transition or retirement. It exposes no Context or mutation permit.

`memberSourceValues` remains a copied-values export. The operation uses `memberIntent` only in the fresh final inspection; artifact readiness binds those exact proofs to that owned capture. A publicly shaped `MemberGrantIntent` does not establish the combined bridge. The packet records issuer ownership and capture-retirement checks separately from integrated native verification.

## Captured billing assertion

The operation lane additionally owns a narrow `MembershipBillingEvidence` refinement: expose the existing PDO/snapshot assertion as `assertCapturedSnapshot(PDO, array)`, preserve existing wrappers/signatures and query/freshness semantics, and use the already captured native primary in the member bridge's final raw phase. Calling the committed wrapper would obtain its reader through framework checks after the sibling raw phase.

This helper checks a captured value; it is not a public source issuer or grant authority. The combined operation preserves supported concrete framework dependencies through the required committed-frame finish, whose existing implementation performs framework getters. `MemberOriginalFinishBoundary` checks captured raw routing and registration maps before and after that finish; no shared identity frame change was made.

The early reviewer identified that exact `DatabaseManager::getDefaultConnection()` accesses `$app['config']`, which fires applicable container `beforeResolving` callbacks before returning even a cached repository. Therefore exact class checks alone do not satisfy this boundary. The implemented boundary admits raw facade/container routing and callback/contextual/deferred-resolution state before and after the committed-frame finish. C4B-E4's actual SQLite integration case installs a config hook during the committed callback phase and verifies refusal before the finish can execute that hook. This is a bounded result, not complete native acceptance.

## Renderer observation hook

Early review C4B-E6 requires the renderer to own the actual standard `Process` construction and launch. The correction removes transport substitution. An optional no-argument `beforeStart` observation hook may run before final profile/budget/native admission; it receives no process, command, environment or input and its return value supplies no output. The renderer rebuilds its trusted launch state after the hook. Integration fixtures use this observation hook; the packet retains the actual old/fixed renderer evidence and separate source review.

## Private initialization proof deferral

The first actual native preparation exhausted its original 60-second frame budget after repeatedly running complete proofs during private construction. The `9399dd99` correction removes the combined proof from the private `MemberOriginalInspection` constructor and binds an optional exact member-capture identity into the consumed reservation constructor permit. Only that registered member path defers the constructor's final full proof; it still establishes the original honor/authority deadlines and calls admission immediately. Ordinary inspection and every writer retain their full constructor proof; a shaped non-null capture cannot select this path.

Constructed values remain inside the owned inspection. Both readiness APIs still require the complete combined proof, as does the committing observer; the separate committed callback/raw/finish sequence and post-finish checks remain unchanged. No proof is cached across callbacks and no deadline is extended. New real facts-decryption withdrawal tests cover initial capture and final readiness, including refusal before the final committing delegate. Actual native timing acceptance belongs to the verification packet, not this source rationale.
