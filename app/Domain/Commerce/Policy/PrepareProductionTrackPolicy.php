<?php

namespace App\Domain\Commerce\Policy;

use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyDraft;
use App\Models\User;

final class PrepareProductionTrackPolicy
{
    public function review(?ProductionTrackPolicyDraft $draft, array $authored, User $actor): array
    {
        return app(ProductionTrackPolicyAuthoring::class)->prepareSave($draft, $authored, $actor);
    }
}
