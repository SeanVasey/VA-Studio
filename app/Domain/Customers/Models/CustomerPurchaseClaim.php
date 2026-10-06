<?php

namespace App\Domain\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CustomerPurchaseClaim extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'challenge_id' => 'integer', 'account_id' => 'integer', 'claimed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Saved purchase evidence is immutable.'));
        self::deleting(fn () => throw new LogicException('Saved purchase evidence is retained.'));
    }
}
