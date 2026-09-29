<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PromotionAvailabilityRevision extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['promotion_campaign_id' => 'integer', 'revision' => 'integer', 'enabled' => 'boolean',
            'previous_enabled' => 'boolean', 'actor_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Promotion availability history is immutable.'));
        static::deleting(fn () => throw new LogicException('Promotion availability history must be retained.'));
    }
}
