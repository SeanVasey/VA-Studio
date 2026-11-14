<?php

namespace App\Domain\Memberships\Production;

use App\Support\Money\MinorUnits;

/** Immutable nonsecret source value. It is never an authority token or a from-HTTP mint. */
final readonly class MembershipLicenseBinding
{
    public function __construct(
        public string $selectionHash,
        public string $licenseManifestHash,
        public string $assetManifestHash,
        public string $originalTermsHash,
        public int $validUntilUnixMicros,
        public ?int $trackId = null,
        public ?int $offerId = null,
        public ?int $offerRevisionId = null,
        public ?int $licenseVersionId = null,
    ) {
        foreach ([$selectionHash, $licenseManifestHash, $assetManifestHash, $originalTermsHash] as $hash) {
            MembershipValues::hash($hash);
        }
        MembershipException::require($validUntilUnixMicros > 0, 'invalid_deadline');
        $identities = [$trackId, $offerId, $offerRevisionId, $licenseVersionId];
        // Legacy values carry no catalog identities and cannot select an operative redemption rule.
        MembershipException::require($identities === [null, null, null, null]
            || count(array_filter($identities, static fn (?int $id): bool => $id !== null && $id > 0 && $id <= MinorUnits::MAX)) === 4,
            'invalid_catalog_identity');
    }
}
