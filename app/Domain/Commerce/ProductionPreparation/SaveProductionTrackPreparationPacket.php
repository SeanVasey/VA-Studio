<?php

namespace App\Domain\Commerce\ProductionPreparation;

use App\Domain\Commerce\ProductionPreparation\Models\ProductionTrackPreparationPacket;
use App\Models\User;

final class SaveProductionTrackPreparationPacket
{
    public function applyReviewed(array $capture, User $actor): ProductionTrackPreparationPacket
    {
        return app(ProductionTrackPreparationPackets::class)->applyReviewed($capture, $actor);
    }
}
