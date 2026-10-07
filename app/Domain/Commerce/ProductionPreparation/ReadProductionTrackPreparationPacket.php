<?php

namespace App\Domain\Commerce\ProductionPreparation;

use App\Models\User;

final class ReadProductionTrackPreparationPacket
{
    public function read(string $publicId, User $actor): array
    {
        return app(ProductionTrackPreparationPackets::class)->read($publicId, $actor);
    }

    public function recover(string $idempotencyKey, User $actor): ?array
    {
        return app(ProductionTrackPreparationPackets::class)->recover($idempotencyKey, $actor);
    }
}
