<?php

namespace App\Domain\Commerce\RefundDispute\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One deduplicated provider refund or dispute, identified by a hash of its provider identifier. */
final class RefundDisputeObject extends Model
{
    public $timestamps = false;

    protected $table = 'refund_dispute_objects';

    protected $guarded = ['id'];

    protected $hidden = ['object_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'amount_minor' => 'integer', 'first_observation_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Refund and dispute evidence is immutable.'));
        self::deleting(fn () => throw new LogicException('Refund and dispute evidence must be retained.'));
    }
}
