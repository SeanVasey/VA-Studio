<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class TestUnsentOrderRelease extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_id', 'evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'order_attempt_id' => 'integer', 'event_id' => 'integer', 'actor_id' => 'integer',
            'inventory_reservation_id' => 'integer', 'promotion_use_id' => 'integer',
            'checkout_window_closed_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Unsent release evidence is immutable.'));
        self::deleting(fn () => throw new LogicException('Unsent release evidence must be retained.'));
    }
}
