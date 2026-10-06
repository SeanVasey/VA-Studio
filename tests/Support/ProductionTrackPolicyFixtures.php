<?php

namespace Tests\Support;

use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyDraft;
use App\Domain\Commerce\Policy\PrepareProductionTrackPolicy;
use App\Domain\Commerce\Policy\SaveProductionTrackPolicy;
use App\Models\User;

/** Synthetic authored declarations, never production policy or external approval. */
final class ProductionTrackPolicyFixtures
{
    public static function authored(bool $unresolved = false): array
    {
        $declarations = [];
        foreach (\App\Domain\Commerce\Policy\ProductionTrackPolicyDraft::CATEGORIES as $category) {
            $declarations[$category] = ['state' => $unresolved ? 'unresolved' : 'declared', 'choice' => $unresolved ? null : 'NONBINDING SYNTHETIC '.$category,
                'source_reference' => $unresolved ? null : 'synthetic:'.$category, 'source_sha256' => $unresolved ? null : hash('sha256', 'NONBINDING '.$category),
                'note' => 'Synthetic test source only; real facts and approvals remain unverified.'];
        }

        return ['schema_version' => 1, 'purpose' => 'production_track_policy_draft', 'version' => 'synthetic-v1', 'declarations' => $declarations];
    }

    public static function create(?User $actor = null, ?array $authored = null): ProductionTrackPolicyDraft
    {
        $actor ??= LicenseFixtures::admin();
        $review = app(PrepareProductionTrackPolicy::class)->review(null, $authored ?? self::authored(), $actor);

        return app(SaveProductionTrackPolicy::class)->applyReviewed($review, $actor);
    }
}
