<?php

namespace App\Domain\Memberships\Production;

/** Immutable nonsecret source value. It is never an authority token or a from-HTTP mint. */
final readonly class MembershipPolicyBinding
{
    public ?MembershipAwardTerms $awardTerms;

    public array $supportedOperations;

    public ?MembershipRedemptionTerms $redemptionTerms;

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
        ?MembershipAwardTerms $awardTerms = null,
        array $supportedOperations = [],
        ?MembershipRedemptionTerms $redemptionTerms = null,
        public ?MembershipConsumptionTerms $consumptionTerms = null,
    ) {
        if ($consumptionTerms !== null) {
            MembershipException::require($awardTerms !== null && $redemptionTerms !== null, 'unsupported_policy');
            $awardTerms = MembershipAwardTerms::from(MembershipConsumptionTerms::detached($awardTerms->facts));
            $redemptionTerms = MembershipRedemptionTerms::from(MembershipConsumptionTerms::detached($redemptionTerms->facts));
            $supportedOperations = MembershipConsumptionTerms::detached($supportedOperations);
        }
        $this->awardTerms = $awardTerms;
        $this->redemptionTerms = $redemptionTerms;
        $this->supportedOperations = $supportedOperations;
        MembershipValues::id($planVersionId);
        foreach ([$policyHash, $originalTermsHash, $rolloverPolicyHash, $lateInvoicePolicyHash, $cancellationPolicyHash,
            $reservationHonorPolicyHash, $grandfatherPolicyHash, $retentionPolicyHash, $periodPolicyHash, $eligibleLicensePolicyHash] as $hash) {
            if ($hash !== null) {
                MembershipValues::hash($hash);
            }
        }
        MembershipException::require($consumptionTerms !== null
            ? $supportedOperations === ['paid_period_award', 'reserve', 'release', 'expire', 'consume']
                && $retentionPolicyHash === $consumptionTerms->retentionHash()
                && $rolloverPolicyHash === null && $cancellationPolicyHash === null && $grandfatherPolicyHash === null
                && $eligibleLicensePolicyHash === $redemptionTerms->eligibilityHash()
                && $reservationHonorPolicyHash === $redemptionTerms->honorHash()
            : ($redemptionTerms === null
            ? $supportedOperations === [] || ($supportedOperations === ['paid_period_award'] && $awardTerms !== null)
            : $supportedOperations === ['paid_period_award', 'reserve', 'release', 'expire'] && $awardTerms !== null
                && $eligibleLicensePolicyHash === $redemptionTerms->eligibilityHash()
                && $reservationHonorPolicyHash === $redemptionTerms->honorHash()), 'unsupported_policy');
        MembershipValues::provenance($provenance);
        MembershipException::require($creditAllowance >= 1 && $creditAllowance <= MembershipPolicy::MAX_CREDITS
            && $billingPriceMinor >= 0 && preg_match('/\A[A-Z]{3}\z/D', $billingCurrency) === 1, 'invalid_policy_value');
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, $this->supportedOperations, true);
    }
}
