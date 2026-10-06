<?php

namespace App\Domain\Commerce\ProductionPolicy;

use App\Domain\Commerce\ProductionPolicy\Models\ProductionTrackCapabilityApproval;
use App\Domain\Commerce\ProductionPolicy\Models\ProductionTrackCapabilityCandidate;
use App\Models\User;

final class ReviewProductionTrackCapabilities
{
    public function review(ProductionTrackCapabilityCandidate $candidate, User $actor): array
    {
        return app(ProductionTrackCapabilities::class)->prepareReview($candidate, $actor);
    }

    public function applyReviewed(array $review, array $reference, User $actor): ProductionTrackCapabilityApproval
    {
        return app(ProductionTrackCapabilities::class)->applyReview($review, $reference, $actor);
    }
}
