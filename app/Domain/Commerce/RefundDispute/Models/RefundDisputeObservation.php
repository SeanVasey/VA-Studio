<?php

namespace App\Domain\Commerce\RefundDispute\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only staff-requested provider observation. Encrypted evidence retains the reconciliation facts. */
final class RefundDisputeObservation extends Model
{
    public $timestamps = false;

    protected $table = 'refund_dispute_observations';

    protected $guarded = ['id'];

    protected $hidden = ['evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'actor_id' => 'integer', 'sequence' => 'integer', 'paid_minor' => 'integer',
            'succeeded_refund_minor' => 'integer', 'pending_refund_minor' => 'integer', 'observed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Refund and dispute evidence is immutable.'));
        self::deleting(fn () => throw new LogicException('Refund and dispute evidence must be retained.'));
    }
}
