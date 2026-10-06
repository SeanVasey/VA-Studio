<?php

namespace App\Domain\Commerce\ProductionPolicy;

use App\Domain\Commerce\ProductionPolicy\Models\ProductionTrackCapabilityCandidate;
use App\Models\User;

final class SaveProductionTrackCapabilities
{
    public function applyReviewed(array $review, User $actor): ProductionTrackCapabilityCandidate
    {
        return app(ProductionTrackCapabilities::class)->applySave($review, $actor);
    }
}
