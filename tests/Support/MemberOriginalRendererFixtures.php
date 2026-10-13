<?php

namespace Tests\Support;

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
}
