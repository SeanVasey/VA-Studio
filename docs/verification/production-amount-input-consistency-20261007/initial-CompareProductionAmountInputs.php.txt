<?php

namespace App\Domain\Commerce\ProductionPreparation;

use App\Models\User;

/** Read-only staff diagnostics of supplied fiction; no provider, buyer or money authority. */
final class CompareProductionAmountInputs
{
    public function forPacket(string $packetId, array $supplied, User $actor): array
    {
        $requirements = app(ProductionAmountRequirements::class)->forPacket($packetId, $actor);

        return AmountInputConsistencyV1::compare($requirements, $supplied);
    }
}
