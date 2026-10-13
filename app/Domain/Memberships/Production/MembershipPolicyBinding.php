<?php

namespace App\Domain\Memberships\Production;

/** Immutable nonsecret source value. It is never an authority token or a from-HTTP mint. */
final readonly class MembershipPolicyBinding
{
    public function __construct(
        public string $planVersionId,
        public string $policyHash,
        public string $originalTermsHash,
        public ?string $rolloverPolicyHash,
        public string $lateInvoicePolicyHash,
        public ?string $cancellationPolicyHash,
        public ?string $reservationHonorPolicyHash,
        public ?string $grandfatherPolicyHash,
        public ?string $retentionPolicyHash,
        public string $provenance,
        public int $creditAllowance,
        public int $billingPriceMinor,
        public string $billingCurrency,
        public string $periodPolicyHash,
        public ?string $eligibleLicensePolicyHash,
        public ?MembershipAwardTerms $awardTerms = null,
        public array $supportedOperations = [],
    ) {
        MembershipValues::id($planVersionId);
        foreach ([$policyHash, $originalTermsHash, $rolloverPolicyHash, $lateInvoicePolicyHash, $cancellationPolicyHash,
            $reservationHonorPolicyHash, $grandfatherPolicyHash, $retentionPolicyHash, $periodPolicyHash, $eligibleLicensePolicyHash] as $hash) {
            if ($hash !== null) {
                MembershipValues::hash($hash);
            }
        }
        MembershipException::require($supportedOperations === [] || ($supportedOperations === ['paid_period_award'] && $awardTerms !== null), 'unsupported_policy');
        MembershipValues::provenance($provenance);
        MembershipException::require($creditAllowance >= 1 && $creditAllowance <= MembershipPolicy::MAX_CREDITS
            && $billingPriceMinor >= 0 && preg_match('/\A[A-Z]{3}\z/D', $billingCurrency) === 1, 'invalid_policy_value');
    }
}
