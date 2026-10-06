<?php

namespace App\Domain\Commerce\ProductionPreparation;

use App\Domain\Commerce\ProductionPolicy\Models\ProductionTrackCapabilityCandidate;
use App\Models\User;

final class PrepareProductionTrackPreparationPacket
{
    public function review(ProductionTrackCapabilityCandidate $candidate, array $context, array $items, string $idempotencyKey, User $actor): array
    {
        return app(ProductionTrackPreparationPackets::class)->review($candidate, $context, $items, $idempotencyKey, $actor);
    }
}
