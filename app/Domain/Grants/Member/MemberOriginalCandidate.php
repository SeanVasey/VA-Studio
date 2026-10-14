<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MembershipValues;

/** Non-authority result. No path, file proof, frame, readiness or grant receipt escapes. */
final readonly class MemberOriginalCandidate
{
    public function __construct(public string $originId, public string $redemptionId, public string $definitionId,
        public string $definitionHash, public string $profileHash, public string $renderSourceHash,
        public string $artifactManifestHash, public MemberOriginalArtifactManifest $artifactManifest)
    {
        foreach ([$originId, $redemptionId, $definitionId] as $id) {
            MembershipValues::id($id);
        }
        foreach ([$definitionHash, $profileHash, $renderSourceHash, $artifactManifestHash] as $hash) {
            MembershipValues::hash($hash);
        }
        MemberGrantException::require($originId === $redemptionId && $artifactManifest->originId === $originId
            && $artifactManifest->manifestHash === $artifactManifestHash, 'candidate_value');
    }
}
