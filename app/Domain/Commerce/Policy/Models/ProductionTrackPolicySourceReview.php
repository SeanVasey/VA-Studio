<?php

namespace App\Domain\Commerce\Policy\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Acknowledgment of authored source, never external approval or activation. */
class ProductionTrackPolicySourceReview extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['production_track_policy_version_id' => 'integer', 'reviewed_by' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(fn () => throw new LogicException('Use the independent exact-source review command.'));
        static::deleting(fn () => throw new LogicException('Retain immutable source-review evidence.'));
    }
}
