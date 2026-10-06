<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class TestUnpaidRelease extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_id', 'evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'order_attempt_id' => 'integer', 'checkout_intent_id' => 'integer', 'checkout_session_id' => 'integer', 'event_id' => 'integer', 'actor_id' => 'integer', 'inventory_reservation_id' => 'integer', 'promotion_use_id' => 'integer', 'released_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Unpaid release evidence is immutable.'));
        self::deleting(fn () => throw new LogicException('Unpaid release evidence must be retained.'));
    }
}
