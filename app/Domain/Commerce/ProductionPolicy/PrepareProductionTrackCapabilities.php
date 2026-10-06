<?php

namespace App\Domain\Commerce\ProductionPolicy;

use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyDraft;
use App\Domain\Commerce\ProductionPolicy\Models\ProductionTrackCapabilityCandidate;
use App\Models\User;

final class PrepareProductionTrackCapabilities
{
    public function review(?ProductionTrackCapabilityCandidate $candidate, ProductionTrackPolicyDraft $source, array $machine, User $actor): array
    {
        return app(ProductionTrackCapabilities::class)->prepareSave($candidate, $source, $machine, $actor);
    }
}
