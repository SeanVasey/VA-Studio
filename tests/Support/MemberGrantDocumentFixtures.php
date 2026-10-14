<?php

namespace Tests\Support;

use App\Domain\Grants\Member\MemberGrantRenderProfile;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Support\CanonicalJson;

/** Explicit synthetic retained-format values only. No catalog, staff, host or source admission. */
final class MemberGrantDocumentFixtures
{
    public static function profile(): array
    {
        return ['schema_version' => 'member-grant-profile-document-v1', 'family' => MemberGrantIntent::FAMILY,
            'purpose' => MemberGrantIntent::PURPOSE, 'provenance' => 'synthetic_rehearsal',
            'render_profile' => MemberGrantRenderProfile::released('synthetic_rehearsal'), 'original_terms' => MemberOriginalRendererFixtures::terms()];
    }

    public static function definition(?array $profile = null): array
    {
        $profile ??= self::profile();
        $terms = $profile['original_terms'];
        $license = ['id' => 4, 'template_id' => 1, 'name' => 'NONBINDING synthetic license', 'version' => 1, 'type' => 'non-exclusive',
            'authored_source' => $terms['authored_source'], 'source_hash' => hash('sha256', $terms['authored_source']),
            'model_hash' => CanonicalJson::hash($terms['structured_terms']), 'structured_terms' => $terms['structured_terms'],
            'features' => $terms['structured_terms']['features'], 'required_asset_roles' => $terms['structured_terms']['required_asset_roles'],
            'renderer_version' => $terms['renderer_version'], 'render_fixture_hash' => $terms['render_fixture_hash'],
            'submission_hash' => hash('sha256', 'synthetic submission'), 'review_evidence_id' => 1,
            'review_evidence_hash' => hash('sha256', 'synthetic review'), 'approval_reference' => 'synthetic-only', 'approved_by' => 1,
            'approved_at' => '2026-10-13T00:00:00Z', 'effective_from' => null, 'effective_until' => null];
        $assets = [['id' => 1, 'role' => 'master_wav', 'sha256' => hash('sha256', 'synthetic WAV'), 'mime_type' => 'audio/wav',
            'size_bytes' => 100, 'original_name' => 'synthetic.wav', 'parent_asset_id' => 2, 'processing_run_id' => 1]];
        $retention = ['schema_version' => 'member-original-retention-facts-v1', 'reference' => 'synthetic-retention-reference',
            'text' => 'NONBINDING synthetic authored retention text. No host or commercial approval.'];

        return ['schema_version' => 'member-grant-definition-document-v1', 'family' => MemberGrantIntent::FAMILY,
            'purpose' => MemberGrantIntent::PURPOSE, 'version' => 1, 'provenance' => 'synthetic_rehearsal',
            'profile_hash' => CanonicalJson::hash($profile), 'plan_version_id' => '00000000-0000-4000-8000-000000000010',
            'policy_hash' => hash('sha256', 'synthetic plan'), 'license_manifest' => $license, 'asset_manifest' => $assets,
            'original_terms_hash' => CanonicalJson::hash($terms), 'retention_policy' => $retention,
            'storage_policy' => ['schema_version' => 'member-original-storage-policy-v1', 'backend' => 'private_local',
                'layout' => 'production-member-candidate-v1', 'provenance' => 'synthetic_rehearsal',
                'ownership_reference' => 'synthetic-local-only', 'retention_policy_hash' => CanonicalJson::hash($retention)]];
    }
}
