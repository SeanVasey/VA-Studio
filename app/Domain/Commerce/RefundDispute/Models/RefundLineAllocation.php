<?php

namespace App\Domain\Commerce\RefundDispute\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Exact allocation of a succeeded provider refund to an original order line. */
final class RefundLineAllocation extends Model
{
    public $timestamps = false;

    protected $table = 'refund_line_allocations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'refund_dispute_object_id' => 'integer', 'order_line_id' => 'integer', 'amount_minor' => 'integer',
            'actor_id' => 'integer', 'sequence' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Refund and dispute evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Refund and dispute evidence must be retained.'));
    }
}
