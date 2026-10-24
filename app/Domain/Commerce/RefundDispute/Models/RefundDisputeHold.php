<?php

namespace App\Domain\Commerce\RefundDispute\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A technical download hold. It never changes a license, grant or contract; release is a single guarded change. */
final class RefundDisputeHold extends Model
{
    public $timestamps = false;

    protected $table = 'refund_dispute_holds';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'license_grant_id' => 'integer', 'applied_effect_id' => 'integer',
            'released_effect_id' => 'integer', 'created_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $hold): void {
            if ($hold->isDirty(['id', 'public_id', 'order_id', 'license_grant_id', 'reason', 'applied_effect_id', 'created_at'])
                || $hold->getOriginal('released_at') !== null || ! $hold->isDirty(['released_at', 'released_effect_id'])
                || $hold->released_at === null || $hold->released_effect_id === null) {
                throw new LogicException('A refund or dispute hold changes only once, when it is released.');
            }
        });
        static::deleting(fn () => throw new LogicException('Refund and dispute holds must be retained.'));
    }
}
