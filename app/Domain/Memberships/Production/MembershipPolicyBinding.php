<?php

namespace App\Domain\Memberships\Production;

/** Immutable nonsecret source value. It is never an authority token or a from-HTTP mint. */
final readonly class MembershipPolicyBinding
{
    public function __construct(
        public string $planVersionId,
        public string $policyHash,
        public string $originalTermsHash,
        public string $rolloverPolicyHash,
        public string $lateInvoicePolicyHash,
        public string $cancellationPolicyHash,
        public string $reservationHonorPolicyHash,
        public string $grandfatherPolicyHash,
        public string $retentionPolicyHash,
        public string $provenance,
    ) {
        MembershipValues::id($planVersionId);
        foreach ([$policyHash, $originalTermsHash, $rolloverPolicyHash, $lateInvoicePolicyHash, $cancellationPolicyHash,
            $reservationHonorPolicyHash, $grandfatherPolicyHash, $retentionPolicyHash] as $hash) {
            MembershipValues::hash($hash);
        }
        MembershipValues::provenance($provenance);
    }
}
