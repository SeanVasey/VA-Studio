<?php

namespace App\Domain\Delivery\Models;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Retained whole-order test verification decision; it grants no download authorization. */
final class TestFulfillmentActivation extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'order_finalization_id' => 'integer',
            'verified_from' => 'immutable_datetime', 'verified_through' => 'immutable_datetime', 'activated_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class, 'order_id'); }
    public function finalization(): BelongsTo { return $this->belongsTo(OrderFinalization::class, 'order_finalization_id'); }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Test fulfillment activation evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Test fulfillment activation evidence must be retained.'));
    }
}
