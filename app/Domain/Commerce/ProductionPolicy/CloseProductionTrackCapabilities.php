<?php

namespace App\Domain\Commerce\ProductionPolicy;

use App\Domain\Commerce\ProductionPolicy\Models\ProductionTrackCapabilityCandidate;
use App\Domain\Commerce\ProductionPolicy\Models\ProductionTrackCapabilityClosure;
use App\Models\User;

final class CloseProductionTrackCapabilities
{
    public function review(ProductionTrackCapabilityCandidate $candidate, User $actor): array
    {
        return app(ProductionTrackCapabilities::class)->prepareClose($candidate, $actor);
    }

    public function applyReviewed(array $review, array $reason, User $actor): ProductionTrackCapabilityClosure
    {
        return app(ProductionTrackCapabilities::class)->applyClose($review, $reason, $actor);
    }
}
