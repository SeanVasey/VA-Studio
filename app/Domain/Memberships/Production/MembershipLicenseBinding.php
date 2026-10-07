<?php

namespace App\Domain\Memberships\Production;

/** Immutable nonsecret source value. It is never an authority token or a from-HTTP mint. */
final readonly class MembershipLicenseBinding
{
    public function __construct(
        public string $selectionHash,
        public string $licenseManifestHash,
        public string $assetManifestHash,
        public string $originalTermsHash,
        public int $validUntilUnixMicros,
    ) {
        foreach ([$selectionHash, $licenseManifestHash, $assetManifestHash, $originalTermsHash] as $hash) {
            MembershipValues::hash($hash);
        }
        MembershipException::require($validUntilUnixMicros > 0, 'invalid_deadline');
    }
}
