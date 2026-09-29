<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PromotionAvailability extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['promotion_campaign_id' => 'integer', 'revision' => 'integer', 'enabled' => 'boolean', 'updated_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Use the guarded promotion availability command.'));
        static::deleting(fn () => throw new LogicException('Promotion availability must be retained.'));
    }
}
