<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Retained local/test finalization evidence; no active delivery authority is created here. */
final class OrderFinalization extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['evidence_ciphertext', 'evidence_hash'];

    protected $attributes = ['mode' => 'test'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'verified_payment_id' => 'integer', 'order_attempt_id' => 'integer', 'confirmed_at' => 'immutable_datetime', 'eligibility_cutoff' => 'immutable_datetime', 'finalized_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(VerifiedPayment::class, 'verified_payment_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(OrderAttempt::class, 'order_attempt_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Finalization evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Finalization evidence must be retained.'));
    }
}
