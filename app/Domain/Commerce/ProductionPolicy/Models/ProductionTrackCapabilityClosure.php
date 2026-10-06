<?php

namespace App\Domain\Commerce\ProductionPolicy\Models;

class ProductionTrackCapabilityClosure extends ImmutableCapabilityEvidence
{
    protected $table = 'production_track_capability_closures';

    protected function casts(): array
    {
        return ['production_track_capability_candidate_id' => 'integer', 'closed_by' => 'integer', 'created_at' => 'datetime'];
    }
}
