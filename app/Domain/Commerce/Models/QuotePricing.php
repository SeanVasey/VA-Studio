<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QuotePricing extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['snapshot', 'snapshot_hash'];

    protected function casts(): array
    {
        return ['quote_id' => 'integer', 'snapshot' => 'array', 'created_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Pricing evidence is immutable. Request a new quote.'));
        static::deleting(fn () => throw new LogicException('Pricing evidence must be retained.'));
    }
}
