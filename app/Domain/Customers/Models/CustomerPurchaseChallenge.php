<?php

namespace App\Domain\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CustomerPurchaseChallenge extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['owner_key', 'proof_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'created_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Purchase possession evidence is immutable.'));
        self::deleting(fn () => throw new LogicException('Purchase possession evidence is retained.'));
    }
}
