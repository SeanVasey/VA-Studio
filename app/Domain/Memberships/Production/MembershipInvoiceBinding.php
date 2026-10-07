<?php

namespace App\Domain\Memberships\Production;

/** Immutable nonsecret source value. It is never an authority token or a from-HTTP mint. */
final readonly class MembershipInvoiceBinding
{
    public function __construct(
        public string $sourceInvoiceHash,
        public string $sourceGraphHash,
        public string $providerScopeHash,
        public int $amountMinor,
        public string $currency,
        public string $periodStart,
        public string $periodEnd,
        public string $planPolicyHash,
        public string $originalTermsHash,
        public array $originalBuyerBinding,
        public string $provenance,
    ) {
        foreach ([$sourceInvoiceHash, $sourceGraphHash, $providerScopeHash, $planPolicyHash, $originalTermsHash] as $hash) {
            MembershipValues::hash($hash);
        }
        MembershipValues::utc($periodStart);
        MembershipValues::utc($periodEnd);
        MembershipValues::buyer($originalBuyerBinding);
        MembershipValues::provenance($provenance);
        MembershipException::require($amountMinor >= 0 && preg_match('/\A[A-Z]{3}\z/D', $currency) === 1
            && $periodStart < $periodEnd && $originalBuyerBinding['provenance'] === $provenance, 'invalid_invoice_value');
    }
}
