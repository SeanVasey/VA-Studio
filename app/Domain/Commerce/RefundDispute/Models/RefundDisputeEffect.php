<?php

namespace App\Domain\Commerce\RefundDispute\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only, reviewable record of every automatic effect and staff decision. */
final class RefundDisputeEffect extends Model
{
    public $timestamps = false;

    protected $table = 'refund_dispute_effects';

    protected $guarded = ['id'];

    protected $hidden = ['request_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'sequence' => 'integer', 'order_line_id' => 'integer', 'license_grant_id' => 'integer',
            'refund_dispute_object_id' => 'integer', 'observation_id' => 'integer', 'actor_id' => 'integer', 'context' => 'array',
            'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Refund and dispute evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Refund and dispute evidence must be retained.'));
    }
}
