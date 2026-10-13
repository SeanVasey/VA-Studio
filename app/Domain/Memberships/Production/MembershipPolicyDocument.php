<?php

namespace App\Domain\Memberships\Production;

use App\Support\CanonicalJson;

/** Strict versioned policy documents. V1 remains award-only; unsupported operations have no implied rules. */
final readonly class MembershipPolicyDocument
{
    public const VERSION = 'membership-award-policy-v1';

    public const CREDIT_VERSION = 'membership-credit-policy-v2';

    private function __construct(public array $facts, public MembershipAwardTerms $awardTerms,
        public ?MembershipRedemptionTerms $redemptionTerms) {}

    public static function from(array $facts): self
    {
        $credit = ($facts['schema_version'] ?? null) === self::CREDIT_VERSION;
        self::keys($facts, [...['schema_version', 'scope', 'provenance', 'plan_name', 'terms_reference', 'terms_text',
            'credit_allowance', 'billing_price_minor', 'billing_currency', 'period_source', 'award_terms'], ...($credit ? ['redemption_terms'] : [])]);
        MembershipException::require(($credit ? $facts['scope'] === 'paid_period_credit_lifecycle'
            : $facts['schema_version'] === self::VERSION && $facts['scope'] === 'paid_period_award')
            && $facts['period_source'] === 'verified_invoice_line_service_period', 'unsupported_policy');
        MembershipException::require(is_string($facts['provenance']), 'invalid_policy_value');
        MembershipValues::provenance($facts['provenance']);
        foreach (['plan_name' => 160, 'terms_reference' => 160, 'terms_text' => 65536] as $key => $limit) {
            MembershipException::require(is_string($facts[$key]) && trim($facts[$key]) !== '' && strlen($facts[$key]) <= $limit
                && mb_check_encoding($facts[$key], 'UTF-8') && ! str_contains($facts[$key], "\0"), 'invalid_policy_value');
        }
        MembershipException::require(is_int($facts['credit_allowance']) && $facts['credit_allowance'] >= 1
            && $facts['credit_allowance'] <= MembershipPolicy::MAX_CREDITS
            && is_int($facts['billing_price_minor']) && $facts['billing_price_minor'] >= 1 && $facts['billing_price_minor'] <= 999999999
            && is_string($facts['billing_currency']) && preg_match('/\A[A-Z]{3}\z/D', $facts['billing_currency']) === 1
            && is_array($facts['award_terms']), 'invalid_policy_value');
        $terms = MembershipAwardTerms::from($facts['award_terms']);
        if ($credit) {
            MembershipException::require(is_array($facts['redemption_terms']), 'invalid_policy_value');
        }
        $redemption = $credit ? MembershipRedemptionTerms::from($facts['redemption_terms']) : null;
        $canonical = CanonicalJson::encode($facts);
        // Leave space for the retained author/record envelope under its existing 131072-byte cap.
        MembershipException::require(! $credit || strlen($canonical) <= 122880, 'invalid_policy_value');

        return new self($facts, $terms, $redemption);
    }

    public function hash(): string
    {
        return hash('sha256', CanonicalJson::encode($this->facts));
    }

    public function termsHash(): string
    {
        return hash('sha256', $this->facts['terms_text']);
    }

    public function binding(string $id): MembershipPolicyBinding
    {
        return new MembershipPolicyBinding(
            planVersionId: $id, policyHash: $this->hash(), originalTermsHash: $this->termsHash(),
            rolloverPolicyHash: null, lateInvoicePolicyHash: hash('sha256', CanonicalJson::encode($this->awardTerms->facts['late_payment'])),
            cancellationPolicyHash: null, reservationHonorPolicyHash: $this->redemptionTerms?->honorHash(), grandfatherPolicyHash: null, retentionPolicyHash: null,
            provenance: $this->facts['provenance'], creditAllowance: $this->facts['credit_allowance'],
            billingPriceMinor: $this->facts['billing_price_minor'], billingCurrency: $this->facts['billing_currency'],
            periodPolicyHash: hash('sha256', CanonicalJson::encode(['source' => $this->facts['period_source'], 'expiry' => $this->awardTerms->facts['expiry']])),
            eligibleLicensePolicyHash: $this->redemptionTerms?->eligibilityHash(), awardTerms: $this->awardTerms,
            supportedOperations: $this->redemptionTerms === null ? ['paid_period_award'] : ['paid_period_award', 'reserve', 'release', 'expire'],
            redemptionTerms: $this->redemptionTerms,
        );
    }

    public static function keys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        MembershipException::require($keys === $expected, 'invalid_policy_value');
    }
}
