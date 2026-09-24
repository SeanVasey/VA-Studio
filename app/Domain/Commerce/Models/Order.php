<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** Immutable private order intent. This evidence does not confirm payment or grant rights. */
final class Order extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['owner_key', 'idempotency_key_hash', 'payload_ciphertext', 'payload_hash'];

    protected function casts(): array
    {
        return ['quote_id' => 'integer', 'quote_pricing_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function attempt(): HasOne
    {
        return $this->hasOne(OrderAttempt::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Order evidence must be retained.'));
    }
}
