<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MembershipValues;

/** New member-specific immutable profile value. Technical files may be reused by exact hash only. */
final readonly class MemberGrantProfileBinding
{
    public function __construct(
        public string $id,
        public string $profileHash,
        public string $originalTermsHash,
        public string $implementationHash,
        public string $fontManifestHash,
        public string $provenance,
    ) {
        MembershipValues::id($id);
        foreach ([$profileHash, $originalTermsHash, $implementationHash, $fontManifestHash] as $hash) {
            MembershipValues::hash($hash);
        }
        MembershipValues::provenance($provenance);
    }
}
