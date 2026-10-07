<?php

namespace App\Domain\Commerce\ProductionPreparation;

use App\Models\User;

/** Historical staff requirements only; no quote, buyer authority or external amount evidence. */
final class ProductionAmountRequirements
{
    public function forPacket(string $packetId, User $actor): array
    {
        return AmountRequirementsV1::project(app(ProductionTrackPreparationPackets::class)->read($packetId, $actor));
    }
}
