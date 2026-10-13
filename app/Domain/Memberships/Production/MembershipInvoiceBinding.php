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
        public ?string $paidAt = null,
        public ?string $planVersionId = null,
        public ?string $invoiceId = null,
        public ?string $evidenceDeadline = null,
    ) {
        foreach ([$sourceInvoiceHash, $sourceGraphHash, $providerScopeHash, $planPolicyHash, $originalTermsHash] as $hash) {
            MembershipValues::hash($hash);
        }
        MembershipValues::utc($periodStart);
        MembershipValues::utc($periodEnd);
        MembershipValues::buyer($originalBuyerBinding);
        MembershipValues::provenance($provenance);
        foreach ([$paidAt, $evidenceDeadline] as $date) {
            if ($date !== null) {
                MembershipValues::utc($date);
            }
        }
        foreach ([$planVersionId, $invoiceId] as $id) {
            if ($id !== null) {
                MembershipValues::id($id);
            }
        }
        MembershipException::require($amountMinor >= 0 && preg_match('/\A[A-Z]{3}\z/D', $currency) === 1
            && $periodStart < $periodEnd && $originalBuyerBinding['provenance'] === $provenance, 'invalid_invoice_value');
    }
}
