<?php

namespace App\Domain\Commerce\Policy;

use App\Domain\Commerce\Policy\Models\ProductionTrackPolicySourceReview;
use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyVersion;
use App\Models\User;

final class ReviewProductionTrackPolicy
{
    public function review(ProductionTrackPolicyVersion $version, User $actor): array
    {
        return app(ProductionTrackPolicyAuthoring::class)->prepareSourceReview($version, $actor);
    }

    public function applyReviewed(array $review, array $reference, User $actor): ProductionTrackPolicySourceReview
    {
        return app(ProductionTrackPolicyAuthoring::class)->applySourceReview($review, $reference, $actor);
    }
}
