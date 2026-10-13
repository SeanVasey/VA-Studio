<?php

namespace App\Domain\Grants\Member;

/** Public committed identity only. No private path, token or download credential. */
final readonly class MemberGrantActivationResult
{
    public string $status;

    public function __construct(public string $originId, public string $redemptionId, public string $definitionId,
        public string $consumeEventId, public string $activationId, public string $artifactManifestHash, public string $activationHash)
    {
        foreach ([$originId, $redemptionId, $definitionId, $consumeEventId, $activationId] as $id) {
            MemberGrantDocumentValues::id($id);
        }
        MemberGrantDocumentValues::hash($artifactManifestHash);
        MemberGrantDocumentValues::hash($activationHash);
        MemberGrantException::require($originId === $redemptionId, 'invalid_activation');
        $this->status = 'activated';
    }
}
