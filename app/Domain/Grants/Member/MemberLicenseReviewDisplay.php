<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MembershipValues;
use App\Support\CanonicalJson;

/** Exact detached display. This value proves neither delivery nor an authenticated request. */
final readonly class MemberLicenseReviewDisplay
{
    private function __construct(private string $canonical) {}

    public static function from(array $value): self
    {
        MemberGrantDocumentValues::bounded($value);
        MemberGrantDocumentValues::keys($value, ['schema_version', 'family', 'purpose', 'provenance', 'action', 'record_claim',
            'buyer_binding', 'render_source_hash', 'definition', 'profile_hash', 'redemption', 'plan', 'authorization_policy',
            'original_terms', 'consumption_reference', 'consumption_text']);
        MemberGrantDocumentValues::family($value);
        MemberGrantException::require($value['schema_version'] === 'member-license-review-display-v1'
            && $value['action'] === 'activate_member_license' && $value['record_claim'] === 'owner_requested_activation', 'invalid_authorization');
        MembershipValues::buyer($value['buyer_binding']);
        MemberGrantException::require($value['buyer_binding']['provenance'] === $value['provenance'], 'invalid_authorization');
        foreach (['render_source_hash', 'profile_hash'] as $key) {
            MemberGrantDocumentValues::hash($value[$key]);
        }
        MemberGrantDocumentValues::keys($value['definition'], ['id', 'hash']);
        MemberGrantDocumentValues::id($value['definition']['id']);
        MemberGrantDocumentValues::hash($value['definition']['hash']);
        MemberGrantDocumentValues::keys($value['redemption'], ['id', 'seal', 'credit_amount', 'honor_deadline']);
        MemberGrantDocumentValues::id($value['redemption']['id']);
        MemberGrantDocumentValues::hash($value['redemption']['seal']);
        MemberGrantDocumentValues::integer($value['redemption']['credit_amount'], 1, 1000000);
        MemberGrantException::require(is_string($value['redemption']['honor_deadline']), 'invalid_authorization');
        MembershipValues::utc($value['redemption']['honor_deadline']);
        MemberGrantDocumentValues::keys($value['plan'], ['id', 'policy_hash', 'consumption_terms_hash']);
        MemberGrantDocumentValues::id($value['plan']['id']);
        MemberGrantDocumentValues::hash($value['plan']['policy_hash']);
        MemberGrantDocumentValues::hash($value['plan']['consumption_terms_hash']);
        $policy = $value['authorization_policy'];
        MemberGrantDocumentValues::keys($policy, ['schema_version', 'mode', 'action', 'record_claim', 'display_reference', 'display_text', 'required_sources']);
        MemberGrantException::require($policy['schema_version'] === 'member-license-authorization-policy-v1'
            && $policy['mode'] === 'explicit_owner_confirmation_v1' && $policy['action'] === $value['action']
            && $policy['record_claim'] === $value['record_claim'] && $policy['required_sources'] === 'selected_license_and_consumption_terms', 'invalid_authorization');
        MemberGrantDocumentValues::text($policy['display_reference'], 160);
        MemberGrantDocumentValues::text($policy['display_text'], 65536, true);
        MemberGrantDocumentValues::originalTerms($value['original_terms']);
        MemberGrantDocumentValues::text($value['consumption_reference'], 160);
        MemberGrantDocumentValues::text($value['consumption_text'], 65536, true);

        // A canonical scalar string retains no nested PHP reference cells.
        return new self(CanonicalJson::encode($value));
    }

    public function value(): array
    {
        return json_decode($this->canonical, true, 32, JSON_THROW_ON_ERROR);
    }

    public function hash(): string
    {
        return hash('sha256', $this->canonical);
    }
}
