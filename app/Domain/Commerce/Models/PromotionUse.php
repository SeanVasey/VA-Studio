<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PromotionUse extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['promotion_campaign_id' => 'integer', 'quote_pricing_id' => 'integer',
            'created_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'pending_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Use the guarded promotion attempt command.'));
        static::deleting(fn () => throw new LogicException('Promotion usage evidence must be retained.'));
    }
}
