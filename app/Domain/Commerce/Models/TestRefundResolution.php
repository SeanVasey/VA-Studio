<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class TestRefundResolution extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['request_record_id' => 'integer', 'order_id' => 'integer', 'order_finalization_id' => 'integer', 'order_attempt_id' => 'integer', 'observed_event_id' => 'integer', 'inventory_reservation_id' => 'integer', 'promotion_use_id' => 'integer', 'actor_id' => 'integer', 'released_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Refund resolution evidence is immutable.'));
        self::deleting(fn () => throw new LogicException('Refund resolution evidence must be retained.'));
    }
}
