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
        public int $creditAllowance,
        public int $billingPriceMinor,
        public string $billingCurrency,
        public string $periodPolicyHash,
        public string $eligibleLicensePolicyHash,
    ) {
        MembershipValues::id($planVersionId);
        foreach ([$policyHash, $originalTermsHash, $rolloverPolicyHash, $lateInvoicePolicyHash, $cancellationPolicyHash,
            $reservationHonorPolicyHash, $grandfatherPolicyHash, $retentionPolicyHash, $periodPolicyHash, $eligibleLicensePolicyHash] as $hash) {
            MembershipValues::hash($hash);
        }
        MembershipValues::provenance($provenance);
        MembershipException::require($creditAllowance >= 1 && $creditAllowance <= MembershipPolicy::MAX_CREDITS
            && $billingPriceMinor >= 0 && preg_match('/\A[A-Z]{3}\z/D', $billingCurrency) === 1, 'invalid_policy_value');
    }
}
