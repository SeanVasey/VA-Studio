<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only: the buyer cancelled their own test order before it was ever sent to the payment provider. */
final class TestBuyerOrderCancellation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'order_attempt_id' => 'integer', 'inventory_reservation_id' => 'integer',
            'promotion_use_id' => 'integer', 'cancelled_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Buyer cancellation evidence is immutable.'));
        self::deleting(fn () => throw new LogicException('Buyer cancellation evidence must be retained.'));
    }
}
