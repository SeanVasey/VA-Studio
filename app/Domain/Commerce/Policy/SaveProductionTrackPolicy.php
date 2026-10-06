<?php

namespace App\Domain\Commerce\Policy;

use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyDraft;
use App\Models\User;

final class SaveProductionTrackPolicy
{
    public function applyReviewed(array $review, User $actor): ProductionTrackPolicyDraft
    {
        return app(ProductionTrackPolicyAuthoring::class)->applySave($review, $actor);
    }
}
