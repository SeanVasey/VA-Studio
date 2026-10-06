<?php

namespace App\Domain\Commerce\ProductionPolicy\Models;

class ProductionTrackCapabilityApproval extends ImmutableCapabilityEvidence
{
    protected $table = 'production_track_capability_approvals';

    protected function casts(): array
    {
        return ['production_track_capability_candidate_id' => 'integer', 'reviewed_by' => 'integer', 'created_at' => 'datetime'];
    }
}
