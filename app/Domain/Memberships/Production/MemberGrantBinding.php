<?php

namespace App\Domain\Memberships\Production;

/** Immutable nonsecret source value. It is never an authority token or a from-HTTP mint. */
final readonly class MemberGrantBinding
{
    public function __construct(
        public string $originId,
        public string $redemptionId,
        public string $sourceInvoiceHash,
        public string $definitionHash,
        public string $profileHash,
        public string $licenseManifestHash,
        public string $assetManifestHash,
        public string $originalArtifactHash,
        public string $activationHash,
        public array $originalBuyerBinding,
    ) {
        MembershipValues::id($originId);
        MembershipValues::id($redemptionId);
        foreach ([$sourceInvoiceHash, $definitionHash, $profileHash, $licenseManifestHash, $assetManifestHash,
            $originalArtifactHash, $activationHash] as $hash) {
            MembershipValues::hash($hash);
        }
        MembershipValues::buyer($originalBuyerBinding);
    }
}
