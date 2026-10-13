<?php

namespace App\Domain\Memberships\Production;

use App\Support\CanonicalJson;

/** Strict award-policy document. Unsupported benefit/lifecycle operations have no implied rules. */
final readonly class MembershipPolicyDocument
{
    public const VERSION = 'membership-award-policy-v1';

    private function __construct(public array $facts, public MembershipAwardTerms $awardTerms) {}

    public static function from(array $facts): self
    {
        self::keys($facts, ['schema_version', 'scope', 'provenance', 'plan_name', 'terms_reference', 'terms_text',
            'credit_allowance', 'billing_price_minor', 'billing_currency', 'period_source', 'award_terms']);
        MembershipException::require($facts['schema_version'] === self::VERSION && $facts['scope'] === 'paid_period_award'
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
        CanonicalJson::encode($facts);

        return new self($facts, $terms);
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
            cancellationPolicyHash: null, reservationHonorPolicyHash: null, grandfatherPolicyHash: null, retentionPolicyHash: null,
            provenance: $this->facts['provenance'], creditAllowance: $this->facts['credit_allowance'],
            billingPriceMinor: $this->facts['billing_price_minor'], billingCurrency: $this->facts['billing_currency'],
            periodPolicyHash: hash('sha256', CanonicalJson::encode(['source' => $this->facts['period_source'], 'expiry' => $this->awardTerms->facts['expiry']])),
            eligibleLicensePolicyHash: null, awardTerms: $this->awardTerms, supportedOperations: ['paid_period_award'],
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
