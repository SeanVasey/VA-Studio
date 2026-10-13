# C4b implementation refinements

These refinements coordinate implementation of the separately reviewed [API contract](api-contract.md); they are not application approval or behavioral evidence.

## Owned assembly

`MemberOriginals` receives four concrete dependencies: `SealedMemberGrantFacts`, `MembershipReservations`, `MemberOriginalRendererProcess`, and `MemberOriginalFiles`. Construction performs no admission, I/O or container registration. Its `artifactAuthority(): MemberOriginalArtifacts` returns the exact composed service for explicit fixture/container binding. It is not a replacement setter.

A fixed internal source-capture assembly creates the source issuer and artifacts composer and binds that exact composer once through private registration. Foreign consumer claims, first-caller adoption and rebinding refuse. Actual preparation still performs the complete configured capability and source/frame gates; construction supplies no readiness or write authority.

`MemberOriginalCandidate` has the seven scalar fields from the contract (`originId`, `redemptionId`, `definitionId`, `definitionHash`, `profileHash`, `renderSourceHash`, `artifactManifestHash`) plus `artifactManifest: MemberOriginalArtifactManifest`. It exposes no path, token, Rows or grant receipt. The existing manifest type is a value; its constructor alone does not authenticate its hash.

The operation and integration-fixture owners coordinate these signatures. Exact implementation/source tests and separate review remain required.
