<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Quote extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    // Never pass the domain model directly to an HTTP response.
    protected $hidden = ['owner_key', 'idempotency_key_hash', 'request', 'request_hash', 'snapshot', 'snapshot_hash'];

    protected function casts(): array
    {
        return ['request' => 'array', 'snapshot' => 'array', 'subtotal_minor' => 'integer', 'created_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Quote evidence is immutable. Request a new quote.'));
        static::deleting(fn () => throw new LogicException('Quote evidence must be retained.'));
    }
}
