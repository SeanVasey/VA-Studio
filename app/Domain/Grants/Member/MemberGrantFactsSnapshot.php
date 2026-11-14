<?php

namespace App\Domain\Grants\Member;

/** Detached verified values only, without a live proof, reader or private record payload. */
final readonly class MemberGrantFactsSnapshot
{
    public function __construct(public MemberGrantProfileDocument $profileDocument,
        public MemberGrantDefinitionDocument $definitionDocument, public MemberGrantFactsBinding $factsBinding,
        public MemberGrantProfileBinding $profileBinding, public string $profileSeal, public string $definitionSeal)
    {
        MemberGrantDocumentValues::hash($profileSeal);
        MemberGrantDocumentValues::hash($definitionSeal);
    }
}
