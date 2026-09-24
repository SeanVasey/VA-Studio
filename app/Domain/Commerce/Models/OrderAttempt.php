<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Frozen resource binding before provider I/O; no provider session or payment is implied. */
final class OrderAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['binding', 'binding_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'inventory_reservation_id' => 'integer', 'promotion_use_id' => 'integer',
            'binding' => 'array', 'created_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order attempt evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Order attempt evidence must be retained.'));
    }
}
