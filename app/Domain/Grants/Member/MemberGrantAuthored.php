<?php

namespace App\Domain\Grants\Member;

/** Non-authority identity returned only after the owned authoring commit proof. */
final readonly class MemberGrantAuthored
{
    public function __construct(public string $profileId, public string $profileHash, public string $definitionId,
        public string $definitionHash, public string $provenance)
    {
        MemberGrantDocumentValues::id($profileId);
        MemberGrantDocumentValues::id($definitionId);
        MemberGrantDocumentValues::hash($profileHash);
        MemberGrantDocumentValues::hash($definitionHash);
        MemberGrantDocumentValues::provenance($provenance);
    }
}
