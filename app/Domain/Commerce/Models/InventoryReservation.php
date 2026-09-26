<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InventoryReservation extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['snapshot', 'snapshot_hash'];

    protected function casts(): array
    {
        return ['quote_id' => 'integer', 'snapshot' => 'array', 'created_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime', 'pending_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'expired_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Use guarded inventory transitions.'));
        static::deleting(fn () => throw new LogicException('Reservation evidence must be retained.'));
    }
}
