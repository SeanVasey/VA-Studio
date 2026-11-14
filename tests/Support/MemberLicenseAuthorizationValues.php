<?php

namespace Tests\Support;

use App\Domain\Grants\Member\MemberOriginalSourceValues;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipPolicyDocument;

/** Explicit synthetic value graphs. No DB absence, confirmation, source or writer authority. */
final class MemberLicenseAuthorizationValues
{
    public static function fixture(): array
    {
        $s = MemberOriginalRendererFixtures::sourceDocument();
        $selection = $s['selection'];
        $rule = ['rule_id' => 'synthetic-rule'];
        foreach (['track_id', 'offer_id', 'offer_revision_id', 'license_version_id',
            'license_manifest_hash', 'asset_manifest_hash', 'original_terms_hash'] as $key) {
            $rule[$key] = $selection[$key];
        }
        $rule['credit_amount'] = $s['redemption']['credit_amount'];
        $document = MembershipPolicyDocument::from(['schema_version' => MembershipPolicyDocument::CONSUMPTION_VERSION,
            'scope' => 'paid_period_credit_activation', 'provenance' => 'synthetic_rehearsal',
            'plan_name' => 'NONBINDING synthetic authorization values', 'terms_reference' => 'synthetic-auth-v3',
            'terms_text' => 'NONBINDING synthetic plan, not an offer or a production approval.',
            'credit_allowance' => 3, 'billing_price_minor' => $s['invoice']['amount_minor'], 'billing_currency' => $s['invoice']['currency'],
            'period_source' => 'verified_invoice_line_service_period',
            'award_terms' => ['expiry' => ['mode' => 'period_end', 'seconds' => 0], 'late_payment' => ['mode' => 'before_period_end', 'seconds' => 0]],
            'redemption_terms' => ['eligibility_rules' => [$rule],
                'honor' => ['duration_seconds' => 1800, 'expiry_mode' => 'cap_at_credit_expiry'],
                'release' => ['before_credit_expiry' => 'available', 'at_or_after_credit_expiry' => 'expired'],
                'available_expiry' => ['mode' => 'expire_available_at_credit_expiry']],
            'consumption_terms' => ['schema_version' => 'membership-consumption-terms-v1', 'purpose' => MemberGrantIntent::PURPOSE,
                'reference' => 'NONBINDING synthetic consumption', 'text' => 'Explicit synthetic credit consumption, no legal-assent claim.',
                'retention_policy' => $s['definition']['document']['retention_policy'], 'source_rule' => 'current_selected_catalog',
                'authorization_policy' => ['schema_version' => 'member-license-authorization-policy-v1',
                    'mode' => 'explicit_owner_confirmation_v1', 'action' => 'activate_member_license',
                    'record_claim' => 'owner_requested_activation', 'display_reference' => 'NONBINDING synthetic request',
                    'display_text' => 'Synthetic owner requests this selected license and reserved credit activation.',
                    'required_sources' => 'selected_license_and_consumption_terms']]]);
        $policy = $document->binding($s['invoice']['plan_version_id']);
        $s['invoice']['policy_hash'] = $policy->policyHash;
        $s['invoice']['original_terms_hash'] = $policy->originalTermsHash;
        $s['definition']['document']['policy_hash'] = $policy->policyHash;

        return ['source' => MemberOriginalSourceValues::from($s), 'policy' => $policy, 'policy_document' => $document,
            'actor' => $s['buyer_binding'], 'authorization_id' => '00000000-0000-4000-8000-000000000010',
            'confirmed_at' => '2026-10-13 00:01:00'];
    }
}
