<?php

namespace Tests\Support;

use App\Domain\Grants\Member\MemberGrantRenderProfile;
use App\Domain\Grants\Member\MemberOriginalRenderInput;
use App\Support\CanonicalJson;

/** Pure synthetic values only; these do not issue admission, ownership or readiness. */
final class MemberOriginalRendererFixtures
{
    public static function terms(): array
    {
        return ['purpose' => 'membership-license-original-terms-v1',
            'authored_source' => "NONBINDING SYNTHETIC MEMBER ORIGINAL FIXTURE.\nFull retained source: Ελληνικά Кириллица.",
            'structured_terms' => ['schema_version' => 1, 'features' => ['Synthetic test WAV deliverable'], 'required_asset_roles' => ['master_wav']],
            'renderer_version' => 'synthetic-license-review-v1', 'render_fixture_hash' => hash('sha256', 'synthetic member review fixture')];
    }

    public static function document(?array $terms = null): array
    {
        $terms ??= self::terms();

        return ['schema_version' => MemberOriginalRenderInput::VERSION, 'family' => MemberOriginalRenderInput::FAMILY,
            'purpose' => MemberOriginalRenderInput::PURPOSE, 'provenance' => 'synthetic_rehearsal',
            'origin_id' => '00000000-0000-4000-8000-000000000001', 'render_source_hash' => hash('sha256', 'synthetic source document'),
            'buyer_account_id' => 1, 'reservation_created_at' => '2026-10-13 00:00:00',
            'selection' => ['track_id' => 1, 'offer_id' => 2, 'offer_revision_id' => 3, 'license_version_id' => 4,
                'selection_hash' => hash('sha256', 'synthetic selection'), 'license_manifest_hash' => hash('sha256', 'synthetic license'),
                'asset_manifest_hash' => hash('sha256', 'synthetic assets'), 'original_terms_hash' => CanonicalJson::hash($terms)],
            'original_terms' => $terms];
    }

    public static function input(): MemberOriginalRenderInput
    {
        return MemberOriginalRenderInput::from(self::document());
    }

    /** Complete pure storage-test values. These shaped seals are not database producer evidence. */
    public static function sourceDocument(): array
    {
        $uuid = static fn (int $id): string => '00000000-0000-4000-8000-'.str_pad((string) $id, 12, '0', STR_PAD_LEFT);
        $hash = static fn (string $label): string => hash('sha256', 'NONBINDING synthetic source fixture '.$label);
        $terms = self::terms();
        $profile = ['schema_version' => 'member-grant-profile-document-v1', 'family' => MemberOriginalRenderInput::FAMILY,
            'purpose' => MemberOriginalRenderInput::PURPOSE, 'provenance' => 'synthetic_rehearsal',
            'render_profile' => MemberGrantRenderProfile::released('synthetic_rehearsal'), 'original_terms' => $terms];
        $license = ['id' => 4, 'template_id' => 1, 'name' => 'NONBINDING synthetic source', 'version' => 1, 'type' => 'non-exclusive',
            'authored_source' => $terms['authored_source'], 'source_hash' => hash('sha256', $terms['authored_source']),
            'model_hash' => CanonicalJson::hash($terms['structured_terms']), 'structured_terms' => $terms['structured_terms'],
            'features' => $terms['structured_terms']['features'], 'required_asset_roles' => ['master_wav'],
            'renderer_version' => $terms['renderer_version'], 'render_fixture_hash' => $terms['render_fixture_hash'],
            'submission_hash' => $hash('submission'), 'review_evidence_id' => 1, 'review_evidence_hash' => $hash('review'),
            'approval_reference' => 'NONBINDING synthetic approval-shaped value, not authority', 'approved_by' => 1,
            'approved_at' => '2026-10-12T00:00:00Z', 'effective_from' => null, 'effective_until' => null];
        $assets = [['id' => 1, 'role' => 'master_wav', 'sha256' => $hash('asset'), 'mime_type' => 'audio/wav',
            'size_bytes' => 128, 'original_name' => 'nonbinding-synthetic.wav', 'parent_asset_id' => 2, 'processing_run_id' => 3]];
        $retention = ['schema_version' => 'member-original-retention-facts-v1', 'reference' => 'NONBINDING storage test',
            'text' => 'NONBINDING synthetic retention facts. No production retention decision.'];
        $definition = ['schema_version' => 'member-grant-definition-document-v1', 'family' => MemberOriginalRenderInput::FAMILY,
            'purpose' => MemberOriginalRenderInput::PURPOSE, 'version' => 1, 'provenance' => 'synthetic_rehearsal',
            'profile_hash' => CanonicalJson::hash($profile), 'plan_version_id' => $uuid(9), 'policy_hash' => $hash('plan policy'),
            'license_manifest' => $license, 'asset_manifest' => $assets, 'original_terms_hash' => CanonicalJson::hash($terms),
            'retention_policy' => $retention, 'storage_policy' => ['schema_version' => 'member-original-storage-policy-v1',
                'backend' => 'private_local', 'layout' => 'production-member-candidate-v1', 'provenance' => 'synthetic_rehearsal',
                'ownership_reference' => 'NONBINDING storage test, no host verification', 'retention_policy_hash' => CanonicalJson::hash($retention)]];
        $buyer = ['schema_version' => 1, 'identity_policy_version' => 'personal-store-customer-identity-v1',
            'account_id' => 1, 'user_id' => 1, 'origin_id' => $uuid(6), 'account_public_id' => $uuid(7),
            'identity_policy_hash' => $hash('identity policy'), 'verification_observation_id' => $uuid(8),
            'verification_observation_hash' => $hash('historical verification'), 'provenance' => 'synthetic_rehearsal'];
        $selection = self::document()['selection'];
        $selection['license_manifest_hash'] = CanonicalJson::hash($license);
        $selection['asset_manifest_hash'] = CanonicalJson::hash($assets);

        return ['schema_version' => 'member-original-source-v1', 'family' => MemberOriginalRenderInput::FAMILY,
            'purpose' => MemberOriginalRenderInput::PURPOSE, 'provenance' => 'synthetic_rehearsal', 'origin_id' => $uuid(1),
            'buyer_binding' => $buyer, 'invoice' => ['source_invoice_hash' => $hash('invoice'), 'invoice_graph_hash' => $hash('invoice graph'),
                'provider_scope_hash' => $hash('provider scope'), 'amount_minor' => 1000, 'currency' => 'GBP',
                'period_start' => '2026-10-01 00:00:00', 'period_end' => '2026-11-01 00:00:00', 'paid_at' => '2026-10-01 00:00:00',
                'plan_version_id' => $uuid(9), 'policy_hash' => $hash('plan policy'), 'original_terms_hash' => $hash('plan terms'),
                'buyer_binding' => $buyer, 'provenance' => 'synthetic_rehearsal'], 'period' => ['id' => $uuid(2), 'seal' => $hash('period seal')],
            'redemption' => ['id' => $uuid(1), 'seal' => $hash('redemption seal'), 'intent_hash' => $hash('intent'),
                'credit_amount' => 1, 'created_at' => '2026-10-13 00:00:00', 'honor_deadline' => '2026-10-13 00:30:00'],
            'reserve_event' => ['id' => $uuid(5), 'seal' => $hash('reserve seal')], 'selection' => $selection,
            'definition' => ['id' => $uuid(4), 'seal' => $hash('definition seal'), 'document' => $definition],
            'profile' => ['id' => $uuid(3), 'seal' => $hash('profile seal'), 'document' => $profile]];
    }
}
