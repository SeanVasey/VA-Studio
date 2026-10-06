<?php

namespace App\Domain\Commerce\ProductionPolicy\Models;

class ProductionTrackCapabilityCandidate extends ImmutableCapabilityEvidence
{
    protected $table = 'production_track_capability_candidates';

    protected function casts(): array
    {
        return ['production_track_policy_draft_id' => 'integer', 'production_track_policy_version_id' => 'integer',
            'production_track_policy_source_review_id' => 'integer', 'generation' => 'integer', 'created_by' => 'integer', 'created_at' => 'datetime'];
    }
}
