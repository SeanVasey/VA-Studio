<?php

namespace App\Domain\Commerce\RefundDispute\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** The first observation of each provider status; replays never add a second row. */
final class RefundDisputeStatus extends Model
{
    public $timestamps = false;

    protected $table = 'refund_dispute_statuses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['refund_dispute_object_id' => 'integer', 'observation_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Refund and dispute evidence is immutable.'));
        self::deleting(fn () => throw new LogicException('Refund and dispute evidence must be retained.'));
    }
}
