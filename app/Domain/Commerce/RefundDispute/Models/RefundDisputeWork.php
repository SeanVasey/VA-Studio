<?php

namespace App\Domain\Commerce\RefundDispute\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Per-order coordination: the ledger sequence and at most one provider-read claim. Not evidence. */
final class RefundDisputeWork extends Model
{
    public $timestamps = false;

    protected $table = 'refund_dispute_work';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'sequence' => 'integer', 'lease_expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $work): void {
            if ($work->isDirty(['id', 'order_id', 'created_at']) || $work->sequence < $work->getOriginal('sequence')) {
                throw new LogicException('Refund and dispute coordination is monotonic.');
            }
        });
        self::deleting(fn () => throw new LogicException('Refund and dispute coordination must be retained.'));
    }
}
