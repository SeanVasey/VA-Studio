# C4b implementation refinements

These refinements coordinate implementation of the separately reviewed [API contract](api-contract.md); they are not application approval or behavioral evidence.

## Owned assembly

`MemberOriginals` receives four concrete dependencies: `SealedMemberGrantFacts`, `MembershipReservations`, `MemberOriginalRendererProcess`, and `MemberOriginalFiles`. Construction performs no admission, I/O or container registration. Its `artifactAuthority(): MemberOriginalArtifacts` returns the exact composed service for explicit fixture/container binding. It is not a replacement setter.

A fixed internal source-capture assembly creates the source issuer and artifacts composer and binds that exact composer once through private registration. Foreign consumer claims, first-caller adoption and rebinding refuse. Actual preparation still performs the complete configured capability and source/frame gates; construction supplies no readiness or write authority.

`MemberOriginalCandidate` has the seven scalar fields from the contract (`originId`, `redemptionId`, `definitionId`, `definitionHash`, `profileHash`, `renderSourceHash`, `artifactManifestHash`) plus `artifactManifest: MemberOriginalArtifactManifest`. It exposes no path, token, Rows or grant receipt. The existing manifest type is a value; its constructor alone does not authenticate its hash.

The operation and integration-fixture owners coordinate these signatures. Exact implementation/source tests and separate review remain required.

## Fresh intent bridge

The concrete reservation issuer adds `memberIntent(MembershipMemberCapture $capture, MembershipRows $rows): MemberGrantIntent`. It accepts only its registered original live capture/frame and returns a cached intent holding that Context's actual invoice/license proofs and its issuer-issued reservation proof. It refuses after the current-to-committed transition or retirement. It exposes no Context or mutation permit.

`memberSourceValues` remains a copied-values export. The operation uses `memberIntent` only in the fresh final inspection; artifact readiness binds those exact proofs to that owned capture. A publicly shaped `MemberGrantIntent` does not establish the combined bridge. Wrong, retired and foreign capture refusal remain focused implementation acceptance, not a passing claim here.

## Captured billing assertion

The operation lane additionally owns a narrow `MembershipBillingEvidence` refinement: expose the existing PDO/snapshot assertion as `assertCapturedSnapshot(PDO, array)`, preserve existing wrappers/signatures and query/freshness semantics, and use the already captured native primary in the member bridge's final raw phase. Calling the committed wrapper would obtain its reader through framework checks after the sibling raw phase.

This helper checks a captured value; it is not a public source issuer or grant authority. The combined operation must also preserve supported concrete framework dependencies through the required committed-frame finish, whose existing implementation performs framework getters. Callback-free closure is still an implementation and regression-test obligation; no shared identity frame change or completed proof is asserted here.

The early reviewer further identified that exact `DatabaseManager::getDefaultConnection()` accesses `$app['config']`, which fires applicable container `beforeResolving` callbacks before returning even a cached repository. Therefore exact class checks alone do not satisfy this boundary. The new operation must explicitly admit the raw facade/container routing and relevant callback/contextual/deferred-resolution state, or obtain a separately coordinated proof design. This requirement applies before and after the required native committed-frame finish; C4B-E4 records the obligation, without claiming an unwritten completed flow was verified.

## Renderer observation hook

Early review C4B-E6 requires the renderer to own the actual standard `Process` construction and launch. The proposed correction removes transport substitution. An optional no-argument `beforeStart` observation hook may run before final profile/budget/native admission; it receives no process, command, environment or input and its return value supplies no output. The renderer rebuilds its trusted launch state after the hook. The integration fixture's former factory-shaped callbacks must be adapted with that implementation. Actual old/fixed process evidence and independent source re-review remain required before closure.
