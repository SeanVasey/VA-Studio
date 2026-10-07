<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipValues;

/** Required original member value, not authority. This child supplies no approved facts. */
final readonly class MemberGrantFactsBinding
{
    public const VERSION = 1;

    public const FAMILY = 'production-member-origin-v1';

    public const PURPOSE = 'production-member-license-grant-v1';

    public function __construct(
        public string $definitionId,
        public string $definitionHash,
        public string $profileId,
        public string $profileHash,
        public string $originalTermsHash,
        public string $policyHash,
        public string $licenseManifestHash,
        public string $assetManifestHash,
        public string $retentionPolicyHash,
        public string $provenance,
    ) {
        MembershipValues::id($definitionId);
        MembershipValues::id($profileId);
        foreach ([$definitionHash, $profileHash, $originalTermsHash, $policyHash, $licenseManifestHash, $assetManifestHash, $retentionPolicyHash] as $hash) {
            MembershipValues::hash($hash);
        }
        MembershipValues::provenance($provenance);
        MemberGrantException::require(MemberGrantIntent::FAMILY === self::FAMILY && MemberGrantIntent::PURPOSE === self::PURPOSE, 'family');
    }
}
