<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class PromotionCampaign extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['snapshot', 'snapshot_hash'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function availability(): HasOne
    {
        return $this->hasOne(PromotionAvailability::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Promotion campaign evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Promotion campaign evidence must be retained.'));
    }
}
