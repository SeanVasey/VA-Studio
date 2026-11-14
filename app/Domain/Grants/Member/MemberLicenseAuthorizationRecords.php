<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MembershipPolicyBinding;
use App\Domain\Memberships\Production\MembershipValues;
use App\Support\CanonicalJson;

/** Pure retained semantics. Actual owner proof, clocks, UUIDs and ticket issuance belong to the issuer. */
final class MemberLicenseAuthorizationRecords
{
    public static function display(MemberOriginalSourceValues $source, MembershipPolicyBinding $policy): array
    {
        $s = $source->document();
        $definition = $s['definition']['document'];
        $terms = $policy->consumptionTerms;
        MemberGrantException::require($policy->supports('consume') && $terms !== null
            && $policy->planVersionId === $s['invoice']['plan_version_id']
            && $policy->policyHash === $s['invoice']['policy_hash']
            && $policy->originalTermsHash === $s['invoice']['original_terms_hash']
            && $policy->provenance === $s['provenance']
            && $policy->billingPriceMinor === $s['invoice']['amount_minor']
            && $policy->billingCurrency === $s['invoice']['currency']
            && $policy->retentionPolicyHash === $terms->retentionHash()
            && $policy->retentionPolicyHash === CanonicalJson::hash($definition['retention_policy']), 'invalid_authorization');
        $t = $terms->value();

        return MemberLicenseReviewDisplay::from(['schema_version' => 'member-license-review-display-v1',
            'family' => $s['family'], 'purpose' => $s['purpose'], 'provenance' => $s['provenance'],
            'action' => 'activate_member_license', 'record_claim' => 'owner_requested_activation',
            'buyer_binding' => $s['buyer_binding'], 'render_source_hash' => $source->hash(),
            'definition' => ['id' => $s['definition']['id'], 'hash' => CanonicalJson::hash($definition)],
            'profile_hash' => CanonicalJson::hash($s['profile']['document']),
            'redemption' => array_intersect_key($s['redemption'], array_flip(['id', 'seal', 'credit_amount', 'honor_deadline'])),
            'plan' => ['id' => $policy->planVersionId, 'policy_hash' => $policy->policyHash, 'consumption_terms_hash' => $terms->hash()],
            'authorization_policy' => $t['authorization_policy'], 'original_terms' => $s['profile']['document']['original_terms'],
            'consumption_reference' => $t['reference'], 'consumption_text' => $t['text']])->value();
    }

    public static function create(MemberOriginalSourceValues $source, MembershipPolicyBinding $policy, array $actorBinding,
        string $authorizationId, string $confirmedAt): array
    {
        $display = self::display($source, $policy);
        $s = $source->document();
        MembershipValues::id($authorizationId);
        MembershipValues::utc($confirmedAt);
        MembershipValues::buyer($actorBinding);
        foreach (['schema_version', 'identity_policy_version', 'identity_policy_hash', 'account_id', 'user_id',
            'origin_id', 'account_public_id', 'provenance'] as $key) {
            MemberGrantException::require($actorBinding[$key] === $s['buyer_binding'][$key], 'invalid_authorization');
        }
        MemberGrantException::require($confirmedAt >= $s['redemption']['created_at']
            && $confirmedAt < $s['redemption']['honor_deadline'], 'invalid_authorization');
        $a = ['schema_version' => 'member-license-authorization-record-v1', 'family' => $s['family'], 'purpose' => $s['purpose'],
            'provenance' => $s['provenance'], 'authorization_id' => $authorizationId,
            'action' => 'activate_member_license', 'record_claim' => 'owner_requested_activation', 'affirmed' => true,
            'display_hash' => CanonicalJson::hash($display), 'policy_hash' => $policy->policyHash,
            'consumption_terms_hash' => $policy->consumptionTerms->hash(),
            'authorization_policy_hash' => $policy->consumptionTerms->authorizationHash(),
            'definition_id' => $s['definition']['id'], 'definition_hash' => $display['definition']['hash'],
            'profile_hash' => $display['profile_hash'], 'redemption_id' => $s['redemption']['id'],
            'redemption_seal' => $s['redemption']['seal'], 'render_source_hash' => $source->hash(),
            'original_terms_hash' => CanonicalJson::hash($s['profile']['document']['original_terms']),
            'buyer_binding' => $s['buyer_binding'], 'actor_binding' => $actorBinding, 'confirmed_at' => $confirmedAt];
        $a['intent_hash'] = CanonicalJson::hash(['purpose' => 'member-license-confirmation-intent-v1',
            'buyer_binding' => $a['buyer_binding'], 'definition_id' => $a['definition_id'], 'definition_hash' => $a['definition_hash'],
            'redemption_id' => $a['redemption_id'], 'redemption_seal' => $a['redemption_seal'],
            'render_source_hash' => $a['render_source_hash'], 'consumption_terms_hash' => $a['consumption_terms_hash'],
            'authorization_policy_hash' => $a['authorization_policy_hash'], 'display_hash' => $a['display_hash']]);
        MemberGrantDocumentValues::bounded($a);
        $canonical = CanonicalJson::encode($a);
        MemberGrantException::require(strlen($canonical) <= 65536, 'invalid_authorization');

        return json_decode($canonical, true, 32, JSON_THROW_ON_ERROR);
    }

    public static function verifyRetained(array $authorization, MemberOriginalSourceValues $source, MembershipPolicyBinding $policy): array
    {
        MemberGrantDocumentValues::bounded($authorization);
        MemberGrantDocumentValues::keys($authorization, ['schema_version', 'family', 'purpose', 'provenance', 'authorization_id',
            'action', 'record_claim', 'affirmed', 'display_hash', 'intent_hash', 'policy_hash', 'consumption_terms_hash',
            'authorization_policy_hash', 'definition_id', 'definition_hash', 'profile_hash', 'redemption_id', 'redemption_seal',
            'render_source_hash', 'original_terms_hash', 'buyer_binding', 'actor_binding', 'confirmed_at']);
        MemberGrantException::require(is_array($authorization['actor_binding']) && is_string($authorization['authorization_id'])
            && is_string($authorization['confirmed_at']), 'invalid_authorization');
        $expected = self::create($source, $policy, $authorization['actor_binding'], $authorization['authorization_id'], $authorization['confirmed_at']);
        MemberGrantException::require(CanonicalJson::encode($authorization) === CanonicalJson::encode($expected), 'invalid_authorization');

        return $expected;
    }
}
